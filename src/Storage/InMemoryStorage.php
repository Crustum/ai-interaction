<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Storage;

use InvalidArgumentException;
use RuntimeException;
use function bin2hex;
use function is_array;
use function json_decode;
use function json_encode;
use function random_bytes;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

/**
 * In-memory storage backend for local ephemeral state.
 */
final class InMemoryStorage extends AbstractStorage
{
    /**
     * Stored documents grouped by namespace and key.
     *
     * @var array<string, array<string, array{data: array<array-key, mixed>, metadata: array<string, string>}>>
     */
    private array $documents = [];

    /**
     * Creates a document in memory.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param array<array-key, mixed> $data Document payload.
     * @param array<string, string> $metadata Caller-owned metadata pairs.
     * @param string|null $key Supplied key or null to generate a new key.
     * @return \Crustum\AiInteraction\Storage\StoredDocument Created document.
     * @throws \RuntimeException When the supplied key already exists.
     */
    protected function createDocument(
        string $namespace,
        array $data,
        array $metadata,
        ?string $key,
    ): StoredDocument {
        if ($key === null) {
            do {
                $key = bin2hex(random_bytes(16));
            } while (isset($this->documents[$namespace][$key]));
        }

        if (isset($this->documents[$namespace][$key])) {
            throw new RuntimeException('The storage key already exists.');
        }

        return $this->writeDocument($namespace, $key, $data, $metadata);
    }

    /**
     * Reads a single document from memory.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @return \Crustum\AiInteraction\Storage\StoredDocument|null Stored document or null when missing.
     */
    protected function readDocument(
        string $namespace,
        string $key,
    ): ?StoredDocument {
        $document = $this->documents[$namespace][$key] ?? null;

        return $document === null
            ? null
            : $this->storedDocument($key, $document);
    }

    /**
     * Writes a document to memory.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @param array<array-key, mixed> $data Document payload.
     * @param array<string, string> $metadata Caller-owned metadata pairs.
     * @return \Crustum\AiInteraction\Storage\StoredDocument Written document.
     * @throws \InvalidArgumentException When data is not a JSON array or object.
     */
    protected function writeDocument(
        string $namespace,
        string $key,
        array $data,
        array $metadata,
    ): StoredDocument {
        $normalized = json_decode(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (!is_array($normalized)) {
            throw new InvalidArgumentException(
                'Stored document data must be a JSON array or object.',
            );
        }

        $metadataSnapshot = [];
        foreach ($metadata as $name => $value) {
            $metadataSnapshot[$name] = $value;
        }

        $this->documents[$namespace][$key] = [
            'data' => $normalized,
            'metadata' => $metadataSnapshot,
        ];

        return $this->storedDocument(
            $key,
            $this->documents[$namespace][$key],
        );
    }

    /**
     * Deletes a document from memory.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @return void
     */
    protected function deleteDocument(string $namespace, string $key): void
    {
        unset($this->documents[$namespace][$key]);
    }

    /**
     * Reads all namespace entries from memory.
     *
     * @param string $namespace Logical namespace owning the documents.
     * @return list<\Crustum\AiInteraction\Storage\StoredDocument> Namespace documents.
     */
    protected function readEntries(string $namespace): iterable
    {
        $documents = $this->documents[$namespace] ?? [];

        $entries = [];

        foreach ($documents as $key => $document) {
            $entries[] = $this->storedDocument((string)$key, $document);
        }

        return $entries;
    }

    /**
     * Builds a stored document value from backend state.
     *
     * @param string $key Logical document key.
     * @param array{data: array<array-key, mixed>, metadata: array<string, string>} $document Backend state.
     * @return \Crustum\AiInteraction\Storage\StoredDocument Stored document value.
     */
    private function storedDocument(
        string $key,
        array $document,
    ): StoredDocument {
        return new StoredDocument(
            $key,
            $document['data'],
            $document['metadata'],
        );
    }
}
