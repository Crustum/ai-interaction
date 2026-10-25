<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Storage;

use function json_encode;
use function strlen;
use const JSON_THROW_ON_ERROR;

/**
 * One JSON document read through storage under its logical key.
 */
final readonly class StoredDocument
{
    /**
     * Creates a stored document value.
     *
     * @param string $key Logical document key.
     * @param array<array-key, mixed> $data Document payload.
     * @param array<string, string> $metadata Caller-owned metadata pairs.
     */
    public function __construct(
        public string $key,
        public array $data,
        public array $metadata,
    ) {
    }

    /**
     * Measures the runtime JSON representation in bytes.
     *
     * @return int Number of bytes in the JSON representation.
     */
    public function size(): int
    {
        return strlen(json_encode($this->data, JSON_THROW_ON_ERROR));
    }
}
