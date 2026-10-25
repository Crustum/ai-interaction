<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Storage;

/**
 * Persists JSON documents under namespaced logical keys.
 *
 * Adapters own JSON encoding, physical naming and discovery. Caller-owned
 * metadata uses camelCase names and string values.
 */
interface StorageInterface
{
    /**
     * Creates a document with a supplied key or a new generated key.
     *
     * An existing key fails without replacing its document.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param array<array-key, mixed> $data Document payload.
     * @param array<string, string> $metadata Caller-owned metadata pairs.
     * @param string|null $key Supplied key or null to generate a new key.
     * @return \Crustum\AiInteraction\Storage\StoredDocument Created document.
     */
    public function create(
        string $namespace,
        array $data,
        array $metadata = [],
        ?string $key = null,
    ): StoredDocument;

    /**
     * Reads a single document by namespace and key.
     *
     * A missing document returns null without creating storage state.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @return \Crustum\AiInteraction\Storage\StoredDocument|null Stored document or null when missing.
     */
    public function read(
        string $namespace,
        string $key,
    ): ?StoredDocument;

    /**
     * Writes a document, replacing any existing document under the key.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @param array<array-key, mixed> $data Document payload.
     * @param array<string, string> $metadata Caller-owned metadata pairs.
     * @return \Crustum\AiInteraction\Storage\StoredDocument Written document.
     */
    public function write(
        string $namespace,
        string $key,
        array $data,
        array $metadata = [],
    ): StoredDocument;

    /**
     * Removes a document if it exists.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @return void
     */
    public function delete(string $namespace, string $key): void;

    /**
     * Lists the namespace documents in adapter-defined order.
     *
     * @param string $namespace Logical namespace owning the documents.
     * @param array<string, string> $metadata Exact AND filter.
     * @return iterable<\Crustum\AiInteraction\Storage\StoredDocument> Matching documents.
     */
    public function entries(string $namespace, array $metadata = []): iterable;
}
