<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Storage;

use InvalidArgumentException;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Common portable identifier and metadata behaviour.
 */
abstract class AbstractStorage implements StorageInterface
{
    /**
     * Creates a document with a supplied key or a new generated key.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param array<array-key, mixed> $data Document payload.
     * @param array<string, string> $metadata Caller-owned metadata pairs.
     * @param string|null $key Supplied key or null to generate a new key.
     * @return \Crustum\AiInteraction\Storage\StoredDocument Created document.
     * @throws \InvalidArgumentException When namespace, key or metadata is not portable.
     */
    final public function create(
        string $namespace,
        array $data,
        array $metadata = [],
        ?string $key = null,
    ): StoredDocument {
        $this->guardIdentifier($namespace, 'namespace');
        $this->guardMetadata($metadata);

        if ($key !== null) {
            $this->guardIdentifier($key, 'key');
        }

        $document = $this->createDocument(
            $namespace,
            $data,
            $metadata,
            $key,
        );
        $this->guardIdentifier($document->key, 'key');

        return $document;
    }

    /**
     * Reads a single document by namespace and key.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @return \Crustum\AiInteraction\Storage\StoredDocument|null Stored document or null when missing.
     * @throws \InvalidArgumentException When namespace or key is not portable.
     */
    final public function read(
        string $namespace,
        string $key,
    ): ?StoredDocument {
        $this->guardIdentifier($namespace, 'namespace');
        $this->guardIdentifier($key, 'key');

        return $this->readDocument($namespace, $key);
    }

    /**
     * Writes a document, replacing any existing document under the key.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @param array<array-key, mixed> $data Document payload.
     * @param array<string, string> $metadata Caller-owned metadata pairs.
     * @return \Crustum\AiInteraction\Storage\StoredDocument Written document.
     * @throws \InvalidArgumentException When namespace, key or metadata is not portable.
     */
    final public function write(
        string $namespace,
        string $key,
        array $data,
        array $metadata = [],
    ): StoredDocument {
        $this->guardIdentifier($namespace, 'namespace');
        $this->guardIdentifier($key, 'key');
        $this->guardMetadata($metadata);

        return $this->writeDocument(
            $namespace,
            $key,
            $data,
            $metadata,
        );
    }

    /**
     * Removes a document if it exists.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @return void
     * @throws \InvalidArgumentException When namespace or key is not portable.
     */
    final public function delete(string $namespace, string $key): void
    {
        $this->guardIdentifier($namespace, 'namespace');
        $this->guardIdentifier($key, 'key');

        $this->deleteDocument($namespace, $key);
    }

    /**
     * Lists the namespace documents in adapter-defined order.
     *
     * @param string $namespace Logical namespace owning the documents.
     * @param array<string, string> $metadata Exact AND filter.
     * @return iterable<\Crustum\AiInteraction\Storage\StoredDocument> Matching documents.
     * @throws \InvalidArgumentException When namespace or metadata is not portable.
     */
    final public function entries(string $namespace, array $metadata = []): iterable
    {
        $this->guardIdentifier($namespace, 'namespace');

        $this->guardMetadata($metadata);

        return $this->guardEntries($this->readEntries($namespace), $metadata);
    }

    /**
     * Creates a document in the adapter backend.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param array<array-key, mixed> $data Document payload.
     * @param array<string, string> $metadata Caller-owned metadata pairs.
     * @param string|null $key Supplied key or null to generate a new key.
     * @return \Crustum\AiInteraction\Storage\StoredDocument Created document.
     */
    abstract protected function createDocument(
        string $namespace,
        array $data,
        array $metadata,
        ?string $key,
    ): StoredDocument;

    /**
     * Reads a single document from the adapter backend.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @return \Crustum\AiInteraction\Storage\StoredDocument|null Stored document or null when missing.
     */
    abstract protected function readDocument(
        string $namespace,
        string $key,
    ): ?StoredDocument;

    /**
     * Writes a document to the adapter backend.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @param array<array-key, mixed> $data Document payload.
     * @param array<string, string> $metadata Caller-owned metadata pairs.
     * @return \Crustum\AiInteraction\Storage\StoredDocument Written document.
     */
    abstract protected function writeDocument(
        string $namespace,
        string $key,
        array $data,
        array $metadata,
    ): StoredDocument;

    /**
     * Deletes a document from the adapter backend.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @return void
     */
    abstract protected function deleteDocument(
        string $namespace,
        string $key,
    ): void;

    /**
     * Reads all namespace entries from the adapter backend.
     *
     * @param string $namespace Logical namespace owning the documents.
     * @return iterable<\Crustum\AiInteraction\Storage\StoredDocument> Backend documents.
     */
    abstract protected function readEntries(string $namespace): iterable;

    /**
     * Validates a namespace or key identifier.
     *
     * @param string $identifier Identifier value to validate.
     * @param string $name Identifier kind used in the error message.
     * @return void
     * @throws \InvalidArgumentException When the identifier is not safe.
     */
    final protected function guardIdentifier(
        string $identifier,
        string $name,
    ): void {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException(
                sprintf('The storage %s is not a safe identifier.', $name),
            );
        }
    }

    /**
     * Validates caller-owned metadata pairs.
     *
     * @param array<array-key, mixed> $metadata Metadata pairs to validate.
     * @return void
     * @throws \InvalidArgumentException When metadata names or values are not portable.
     */
    final protected function guardMetadata(array $metadata): void
    {
        foreach ($metadata as $name => $value) {
            if (
                !is_string($name)
                || !is_string($value)
                || preg_match('/^[a-z][a-zA-Z0-9]*$/D', $name) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Storage metadata must contain camelCase string pairs.',
                );
            }
        }
    }

    /**
     * Filters backend entries by exact metadata match.
     *
     * @param iterable<\Crustum\AiInteraction\Storage\StoredDocument> $entries Backend documents.
     * @param array<string, string> $metadata Exact AND filter.
     * @return iterable<\Crustum\AiInteraction\Storage\StoredDocument> Matching documents.
     */
    private function guardEntries(iterable $entries, array $metadata): iterable
    {
        foreach ($entries as $document) {
            $this->guardIdentifier($document->key, 'key');

            foreach ($metadata as $name => $value) {
                if (($document->metadata[$name] ?? null) !== $value) {
                    continue 2;
                }
            }

            yield $document;
        }
    }
}
