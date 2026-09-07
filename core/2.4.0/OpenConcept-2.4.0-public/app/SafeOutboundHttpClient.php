<?php

declare(strict_types=1);

require_once __DIR__ . '/RagProviderException.php';

/**
 * Small fail-closed HTTP client for administrator-configured RAG/LLM endpoints.
 * Redirects are disabled and resolved addresses are pinned to prevent DNS
 * rebinding. Secrets are never included in raised exception messages.
 */
final class SafeOutboundHttpClient
{
    /** @var null|Closure(array<string, mixed>): array<string, mixed> */
    private ?Closure $transport;

    public function __construct(
        private readonly bool $allowPrivateNetwork = false,
        private readonly bool $verifyTls = true,
        private readonly bool $allowHttp = false,
        ?callable $transport = null
    ) {
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $connectTimeout = 10,
        int $timeout = 30,
        int $maximumBytes = 4194304
    ): array {
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
            throw new InvalidArgumentException('Outbound HTTP method is unsupported.');
        }
        if ($connectTimeout < 1 || $connectTimeout > 120 || $timeout < 1 || $timeout > 600
            || $maximumBytes < 1024 || $maximumBytes > 67108864) {
            throw new InvalidArgumentException('Outbound HTTP limits are invalid.');
        }
        $target = $this->validateTarget($url, $this->transport === null);
        $safeHeaders = $this->validateHeaders($headers);
        if ($this->transport !== null) {
            $response = ($this->transport)([
                'method' => $method,
                'url' => $url,
                'headers' => $safeHeaders,
                'body' => $body,
                'connect_timeout' => $connectTimeout,
                'timeout' => $timeout,
                'maximum_bytes' => $maximumBytes,
            ]);
            return $this->normalizeResponse($response, $maximumBytes);
        }
        if (!function_exists('curl_init')) {
            throw new OutboundHttpException('outbound_client_unavailable', 'PHP cURL is required for outbound RAG and LLM connections.');
        }

        $responseBody = '';
        $responseHeaders = [];
        $tooLarge = false;
        $curl = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $safeHeaders,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_PROTOCOLS => $target['scheme'] === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_USERAGENT => 'OpenConcept-RAG/1.0',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    if ($name !== '' && !in_array($name, ['set-cookie', 'authorization', 'proxy-authorization'], true)) {
                        $responseHeaders[$name] = trim(substr($line, $separator + 1));
                    }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$tooLarge, $maximumBytes): int {
                if (strlen($responseBody) + strlen($chunk) > $maximumBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($target['resolved_ip'] !== null) {
            $port = $target['port'] ?? ($target['scheme'] === 'https' ? 443 : 80);
            $pinnedIp = str_contains($target['resolved_ip'], ':')
                ? '[' . $target['resolved_ip'] . ']'
                : $target['resolved_ip'];
            $options[CURLOPT_RESOLVE] = [$target['host'] . ':' . $port . ':' . $pinnedIp];
        }
        $caBundle = trim((string) getenv('OPENCONCEPT_CA_BUNDLE'));
        if ($this->verifyTls && $caBundle !== '') {
            $options[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($curl);
        curl_close($curl);
        if ($tooLarge) {
            throw new OutboundHttpException('outbound_response_too_large', 'Outbound HTTP response exceeded the configured size limit.');
        }
        if ($ok === false || $curlError !== 0) {
            $code = match ($curlError) {
                28 => 'outbound_timeout',
                35, 51, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 => 'outbound_tls_failed',
                5, 6 => 'outbound_dns_failed',
                7 => 'outbound_connection_refused',
                default => 'outbound_unreachable',
            };
            throw new OutboundHttpException($code, 'Outbound HTTP request failed.', ['curl_code' => $curlError]);
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
    }

    /**
     * Security options for callers that need cURL features such as multipart
     * upload or server-sent events. The URL is validated and DNS-pinned using
     * the same policy as request().
     *
     * @return array<int, mixed>
     */
    public function curlSecurityOptions(string $url): array
    {
        $target = $this->validateTarget($url, true);
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_PROTOCOLS => $target['scheme'] === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => 0,
        ];
        if ($target['resolved_ip'] !== null) {
            $port = $target['port'] ?? ($target['scheme'] === 'https' ? 443 : 80);
            $pinnedIp = str_contains($target['resolved_ip'], ':')
                ? '[' . $target['resolved_ip'] . ']'
                : $target['resolved_ip'];
            $options[CURLOPT_RESOLVE] = [$target['host'] . ':' . $port . ':' . $pinnedIp];
        }
        $caBundle = trim((string) getenv('OPENCONCEPT_CA_BUNDLE'));
        if ($this->verifyTls && $caBundle !== '') {
            if (!is_file($caBundle) || !is_readable($caBundle)) {
                throw new OutboundHttpException('outbound_tls_failed', 'OPENCONCEPT_CA_BUNDLE is not readable.');
            }
            $options[CURLOPT_CAINFO] = $caBundle;
        }
        return $options;
    }

    /** @return array{scheme: string, host: string, port: int|null, resolved_ip: string|null} */
    private function validateTarget(string $url, bool $resolveDns): array
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20]/', $url) === 1) {
            throw new OutboundHttpException('outbound_url_invalid', 'Outbound endpoint URL is invalid.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new OutboundHttpException('outbound_url_invalid', 'Outbound endpoint URL is invalid.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, ['https', 'http'], true) || $host === ''
            || ($scheme === 'http' && !$this->allowHttp)) {
            throw new OutboundHttpException(
                $scheme === 'http' ? 'outbound_http_not_allowed' : 'outbound_url_invalid',
                'Outbound endpoints must use an allowed HTTP scheme.'
            );
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new OutboundHttpException('outbound_url_invalid', 'Outbound endpoint port is invalid.');
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : null;
        if ($ip !== null && !$this->allowPrivateNetwork && !$this->isPublicAddress($ip)) {
            throw new OutboundHttpException(
                'outbound_private_network_not_allowed',
                'Private-network RAG and LLM endpoints require explicit administrator approval.'
            );
        }
        if (!$resolveDns || $ip !== null) {
            return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'resolved_ip' => $ip];
        }
        $addresses = [];
        foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $candidate = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($candidate !== '') {
                $addresses[] = $candidate;
            }
        }
        if ($addresses === []) {
            $fallback = gethostbyname($host);
            if ($fallback !== $host) {
                $addresses[] = $fallback;
            }
        }
        if ($addresses === []) {
            throw new OutboundHttpException('outbound_dns_failed', 'Outbound endpoint DNS resolution failed.');
        }
        if (!$this->allowPrivateNetwork) {
            foreach ($addresses as $address) {
                if (!$this->isPublicAddress($address)) {
                    throw new OutboundHttpException(
                        'outbound_private_network_not_allowed',
                        'Outbound endpoint resolved to a private or reserved network.'
                    );
                }
            }
        }
        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'resolved_ip' => $addresses[0]];
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** @param list<string> $headers @return list<string> */
    private function validateHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $header) {
            if (!is_string($header) || preg_match('/[\r\n]/', $header) === 1 || !str_contains($header, ':')) {
                throw new InvalidArgumentException('Outbound HTTP header is invalid.');
            }
            $result[] = $header;
        }
        return $result;
    }

    /** @param array<string, mixed> $response @return array{status: int, headers: array<string, string>, body: string} */
    private function normalizeResponse(array $response, int $maximumBytes): array
    {
        $body = (string) ($response['body'] ?? '');
        if (strlen($body) > $maximumBytes) {
            throw new OutboundHttpException('outbound_response_too_large', 'Outbound HTTP response exceeded the configured size limit.');
        }
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        return ['status' => (int) ($response['status'] ?? 0), 'headers' => $headers, 'body' => $body];
    }
}
