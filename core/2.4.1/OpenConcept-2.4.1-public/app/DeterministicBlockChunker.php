<?php

declare(strict_types=1);

final class DeterministicBlockChunker
{
    public const ID = 'openconcept-block-boundary';
    public const VERSION = '1.0.0';

    public function __construct(private readonly int $targetCharacters = 2400)
    {
        if ($targetCharacters < 256 || $targetCharacters > 20000) {
            throw new InvalidArgumentException('Standard RAG chunk target must be between 256 and 20000 characters.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function chunk(DocumentSnapshot $document): array
    {
        $chunks = [];
        $pending = [];
        $pendingLength = 0;
        $headingPath = [];
        foreach ($document->blocks as $block) {
            if (!$block instanceof DocumentBlock) {
                continue;
            }
            $text = trim($block->text);
            if ($text === '') {
                continue;
            }
            $isHeading = str_contains(strtolower($block->type), 'heading');
            if ($isHeading) {
                $this->flush($document, $pending, $headingPath, $chunks);
                $pending = [$block];
                $pendingLength = $this->length($text);
                $metadataPath = is_array($block->metadata['heading_path'] ?? null)
                    ? array_values(array_filter($block->metadata['heading_path'], 'is_string'))
                    : [];
                $headingPath = [...$metadataPath, $text];
                continue;
            }
            $nextLength = $pendingLength + ($pending === [] ? 0 : 2) + $this->length($text);
            if ($pending !== [] && $nextLength > $this->targetCharacters) {
                $this->flush($document, $pending, $headingPath, $chunks);
                $pending = [];
                $pendingLength = 0;
            }
            $pending[] = $block;
            $pendingLength += ($pendingLength === 0 ? 0 : 2) + $this->length($text);
        }
        $this->flush($document, $pending, $headingPath, $chunks);
        return $chunks;
    }

    /**
     * @param list<DocumentBlock> $pending
     * @param list<string> $headingPath
     * @param list<array<string, mixed>> $chunks
     */
    private function flush(DocumentSnapshot $document, array &$pending, array $headingPath, array &$chunks): void
    {
        if ($pending === []) {
            return;
        }
        $blockIds = array_map(static fn (DocumentBlock $block): string => $block->blockId, $pending);
        $body = implode("\n\n", array_map(static fn (DocumentBlock $block): string => trim($block->text), $pending));
        $context = $headingPath === [] ? '' : implode(' > ', $headingPath);
        $text = trim(($context !== '' && !str_starts_with($body, end($headingPath) ?: '') ? $context . "\n\n" : '') . $body);
        $identity = implode('|', [
            self::ID,
            self::VERSION,
            $document->documentId,
            $document->revisionId,
            implode(',', $blockIds),
            hash('sha256', $text),
        ]);
        $chunks[] = [
            'chunk_id' => 'chunk_' . substr(hash('sha256', $identity), 0, 32),
            'block_ids' => $blockIds,
            'text' => $text,
            'heading_path' => $headingPath,
            'position' => $pending[0]->position,
        ];
        $pending = [];
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
