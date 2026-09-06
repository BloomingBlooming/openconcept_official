<?php

declare(strict_types=1);

require_once __DIR__ . '/DrawingOcrMetadata.php';

final class DrawingMetadataExtractor
{
    private const NO_REVISION = '-';
    private const VISION_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];
    private const CAD_EXTENSIONS = ['dxf', 'dwg', 'step', 'stp', 'iges', 'igs'];
    private const AI_FIELD_LABELS = [
        'drawing_no' => '図面番号',
        'revision_code' => '改訂番号',
        'title' => '品名',
    ];
    private const MISSING_DRAWING_WARNING = '図面番号を特定できませんでした。未承認図面で入力してください。';

    public function __construct(private ?DrawingVisionClient $visionClient = null)
    {
    }

    /** @return array{fields: array<string, string>, source: string, confidence: int, warnings: array<int, string>, warning_items: array<int, array{key: string, parameters: array<string, int|string>}>, model: string, response_id: string, ai_fields: array<int, string>, ocr_regions: array<int, array<string, int|string>>} */
    public function extract(
        string $path,
        string $originalName,
        string $extension,
        string $detectedMimeType,
        callable $beforeSend
    ): array
    {
        $extension = strtolower(trim($extension));
        $fields = $this->filenameFields($originalName);
        if ($fields['title'] === '') {
            $fields['title'] = $this->clean((string) pathinfo($originalName, PATHINFO_FILENAME), 160);
        }

        $result = [
            'fields' => $fields,
            'source' => 'filename',
            // DBとの互換性のためconfidenceキーを使うが、値はAIの精度ではなく3項目の取得率。
            'confidence' => 0,
            'warnings' => [],
            'warning_items' => [],
            'model' => '',
            'response_id' => '',
            'ai_fields' => [],
            'ocr_regions' => [],
        ];
        if ($fields['drawing_no'] === '') {
            $this->addWarning($result, 'warning.missingDrawing', [], self::MISSING_DRAWING_WARNING);
        }

        if (in_array($extension, self::CAD_EXTENSIONS, true)) {
            $this->addWarning(
                $result,
                'warning.cadUnsupported',
                ['extension' => strtoupper($extension)],
                strtoupper($extension) . 'はAI簡易読み取りの対象外です。未承認図面でファイル名候補を確認・訂正してください。'
            );
            return $this->uniqueWarnings($result);
        }
        if (!in_array($extension, self::VISION_EXTENSIONS, true)) {
            return $this->uniqueWarnings($result);
        }
        if ($this->visionClient === null || !$this->visionClient->isConfigured()) {
            $this->addWarning($result, 'warning.apiKeyMissing', [], 'OpenAI APIキーが未設定のため、ファイル名候補を未承認図面へ保存しました。');
            return $this->uniqueWarnings($result);
        }

        try {
            $vision = $this->visionClient->analyze(
                $path,
                $originalName,
                $extension,
                $detectedMimeType,
                $beforeSend
            );
            $result['model'] = (string) ($vision['model'] ?? '');
            $result['response_id'] = (string) ($vision['response_id'] ?? '');
            $result['ocr_regions'] = DrawingOcrMetadata::normalize($vision['ocr_regions'] ?? []);
            $visionFieldCount = 0;
            foreach (self::AI_FIELD_LABELS as $field => $label) {
                $value = trim((string) ($vision[$field] ?? ''));
                if ($field === 'revision_code' && ($value === '' || $value === self::NO_REVISION)) {
                    $result['fields'][$field] = self::NO_REVISION;
                    $this->addWarning($result, 'warning.revisionDefaulted', [], 'AIで改訂番号を特定できなかったため、「-」を設定しました。');
                    continue;
                }
                if ($value === '') {
                    if ($result['fields'][$field] !== '') {
                        $this->addWarning(
                            $result,
                            'warning.fieldFromFilename',
                            ['field' => $field],
                            'AIでは' . $label . 'を特定できなかったため、ファイル名からの候補を残しました。'
                        );
                    } else {
                        $this->addWarning(
                            $result,
                            'warning.fieldMissing',
                            ['field' => $field],
                            $label . 'をAIで特定できませんでした。未承認図面で入力してください。'
                        );
                    }
                    continue;
                }
                $result['fields'][$field] = $value;
                $result['ai_fields'][] = $field;
                $visionFieldCount++;
            }
            if ($visionFieldCount > 0) {
                $result['source'] = 'openai_vision';
                $result['confidence'] = match ($visionFieldCount) {
                    3 => 100,
                    2 => 67,
                    default => 33,
                };
                $this->addWarning(
                    $result,
                    'warning.partial',
                    ['count' => $visionFieldCount],
                    sprintf('AI取得は3項目中%d項目です。この割合は読み取り精度や信頼度を示しません。承認前に図面と照合してください。', $visionFieldCount)
                );
            } else {
                $this->addWarning($result, 'warning.noAiFields', [], 'AIで3項目を取得できなかったため、ファイル名からの候補を未承認図面へ保存しました。');
            }
            if ($result['fields']['drawing_no'] !== '') {
                $result['warnings'] = array_values(array_filter(
                    $result['warnings'],
                    static fn(string $warning): bool => $warning !== self::MISSING_DRAWING_WARNING
                ));
                $result['warning_items'] = array_values(array_filter(
                    $result['warning_items'],
                    static fn(array $warning): bool => ($warning['key'] ?? '') !== 'warning.missingDrawing'
                ));
            }
        } catch (LengthException|InvalidArgumentException $exception) {
            $reason = $this->clean($exception->getMessage(), 300);
            $this->addWarning(
                $result,
                'warning.skipped',
                [],
                'AI簡易読み取りを行わず、ファイル名からの候補を未承認図面へ保存しました。'
                    . ($reason !== '' ? '理由: ' . $reason : '')
            );
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && in_array($exception->getCode(), [401, 403], true)) {
                throw $exception;
            }
            $diagnostic = preg_replace('/\bsk-[A-Za-z0-9_-]{8,}\b/', '[REDACTED]', $this->clean($exception->getMessage(), 300)) ?? '';
            error_log('[drawing-manager] GPT-5.6 Luna Vision request failed: ' . get_class($exception) . ($diagnostic !== '' ? ': ' . $diagnostic : ''));
            $this->addWarning($result, 'warning.visionFailed', [], 'GPT-5.6 LunaによるAI簡易読み取りを完了できなかったため、ファイル名からの候補を未承認図面へ保存しました。');
        }

        return $this->uniqueWarnings($result);
    }

    /** @return array<string, string> */
    private function filenameFields(string $originalName): array
    {
        $base = trim((string) pathinfo($originalName, PATHINFO_FILENAME));
        $parts = preg_split('/[_＿\s]+/u', $base, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $drawingNo = '';
        foreach ($parts as $part) {
            if (preg_match('/^(?=.*\d)[A-Z0-9][A-Z0-9.\/-]{2,79}$/i', $part) === 1) {
                $drawingNo = $this->clean($part, 80);
                break;
            }
        }
        if ($drawingNo === '' && preg_match('/(?=.*\d)([A-Z0-9]+(?:[-.\/][A-Z0-9]+)+)/i', $base, $match) === 1) {
            $drawingNo = $this->clean((string) $match[1], 80);
        }

        $revision = '';
        foreach ($parts as $index => $part) {
            if ($part === $drawingNo || $index === 0) {
                continue;
            }
            if (preg_match('/^REV[-.]?([A-Z]|\d{1,3})$/i', $part, $match) === 1) {
                $revision = strtoupper((string) $match[1]);
                break;
            }
            if (preg_match('/^([A-Z])$/i', $part, $match) === 1) {
                $revision = strtoupper((string) $match[1]);
                break;
            }
        }

        $titleParts = array_values(array_filter($parts, static function (string $part) use ($drawingNo, $revision): bool {
            if ($part === $drawingNo || ($revision !== '' && strtoupper($part) === $revision)) {
                return false;
            }
            return preg_match('/^(?:REV[-.]?)?[A-Z0-9]{1,3}$/i', $part) !== 1;
        }));

        return [
            'drawing_no' => $drawingNo,
            'title' => $this->clean(implode(' ', $titleParts), 160),
            'revision_code' => $revision !== '' ? $revision : self::NO_REVISION,
            'customer' => '',
            'material' => '',
            'process' => '',
        ];
    }

    /**
     * @param array{fields: array<string, string>, source: string, confidence: int, warnings: array<int, string>, warning_items: array<int, array{key: string, parameters: array<string, int|string>}>, model: string, response_id: string, ai_fields: array<int, string>, ocr_regions: array<int, array<string, int|string>>} $result
     * @return array{fields: array<string, string>, source: string, confidence: int, warnings: array<int, string>, warning_items: array<int, array{key: string, parameters: array<string, int|string>}>, model: string, response_id: string, ai_fields: array<int, string>, ocr_regions: array<int, array<string, int|string>>}
     */
    private function uniqueWarnings(array $result): array
    {
        $result['warnings'] = array_values(array_unique(array_filter($result['warnings'], static fn(string $warning): bool => $warning !== '')));
        $seen = [];
        $result['warning_items'] = array_values(array_filter(
            $result['warning_items'],
            static function (array $warning) use (&$seen): bool {
                $signature = json_encode($warning, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($signature) || isset($seen[$signature])) {
                    return false;
                }
                $seen[$signature] = true;
                return true;
            }
        ));
        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, int|string> $parameters
     */
    private function addWarning(array &$result, string $key, array $parameters, string $legacyMessage): void
    {
        $result['warnings'][] = $legacyMessage;
        $result['warning_items'][] = ['key' => $key, 'parameters' => $parameters];
    }

    private function clean(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }
}
