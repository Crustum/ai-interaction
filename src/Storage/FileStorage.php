<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Storage;

use RuntimeException;
use Throwable;
use UnexpectedValueException;
use function basename;
use function bin2hex;
use function clearstatcache;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function json_decode;
use function json_encode;
use function link;
use function mkdir;
use function random_bytes;
use function realpath;
use function rename;
use function restore_error_handler;
use function set_error_handler;
use function strlen;
use function substr;
use function tempnam;
use function unlink;
use const DIRECTORY_SEPARATOR;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * File-backed storage backend for local ephemeral state.
 */
final class FileStorage extends AbstractStorage
{
    /**
     * File extension used for stored documents.
     *
     * @var string
     */
    private const string FILE_EXTENSION = '.json';

    /**
     * Storage root directory.
     *
     * @var string
     */
    private readonly string $root;

    /**
     * Creates a file-backed storage backend.
     *
     * @param string $root Storage root directory.
     */
    public function __construct(string $root)
    {
        $this->root = $root;
    }

    /**
     * Creates a document as a new file.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param array<array-key, mixed> $data Document payload.
     * @param array<string, string> $metadata Caller-owned metadata pairs.
     * @param string|null $key Supplied key or null to generate a new key.
     * @return \Crustum\AiInteraction\Storage\StoredDocument Created document.
     * @throws \RuntimeException When the document cannot be created.
     */
    protected function createDocument(
        string $namespace,
        array $data,
        array $metadata,
        ?string $key,
    ): StoredDocument {
        $directory = $this->namespaceDirectory($namespace);
        $temporary = $this->prepareTemporaryDocument($directory, $data, $metadata);

        try {
            do {
                $candidate = $key ?? bin2hex(random_bytes(16));
                $path = $this->path($directory, $candidate);

                if ($this->quietLink($temporary, $path)) {
                    return $this->storedDocument($path, $candidate);
                }

                if (!file_exists($path) && !is_link($path)) {
                    throw new RuntimeException('The stored document could not be created.');
                }

                if ($key !== null) {
                    throw new RuntimeException('The storage key already exists.');
                }
            } while (true);
        } finally {
            unlink($temporary);
        }
    }

    /**
     * Reads a single document file.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @return \Crustum\AiInteraction\Storage\StoredDocument|null Stored document or null when missing.
     */
    protected function readDocument(
        string $namespace,
        string $key,
    ): ?StoredDocument {
        if (!is_dir($this->root)) {
            return null;
        }

        $directory = $this->existingNamespaceDirectory($namespace);

        if ($directory === null) {
            return null;
        }

        $path = $this->path($directory, $key);

        if (!file_exists($path) && !is_link($path)) {
            return null;
        }

        try {
            return $this->storedDocument($path, $key);
        } catch (RuntimeException $runtimeException) {
            clearstatcache(true, $path);
            if (!file_exists($path) && !is_link($path)) {
                return null;
            }

            throw $runtimeException;
        }
    }

    /**
     * Writes a document file atomically.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @param array<array-key, mixed> $data Document payload.
     * @param array<string, string> $metadata Caller-owned metadata pairs.
     * @return \Crustum\AiInteraction\Storage\StoredDocument Written document.
     * @throws \RuntimeException When the document cannot be replaced.
     */
    protected function writeDocument(
        string $namespace,
        string $key,
        array $data,
        array $metadata,
    ): StoredDocument {
        $directory = $this->namespaceDirectory($namespace);
        $path = $this->path($directory, $key);

        if (is_link($path)) {
            throw new RuntimeException('A storage key cannot be a symbolic link.');
        }

        if (file_exists($path) && !is_file($path)) {
            throw new RuntimeException('The stored document is not a regular file.');
        }

        $temporary = $this->prepareTemporaryDocument($directory, $data, $metadata);

        try {
            $document = $this->storedDocument($temporary, $key);
            if (!rename($temporary, $path)) {
                throw new RuntimeException('The stored document could not be replaced.');
            }

            return $document;
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Encodes a temporary document file.
     *
     * @param string $directory Namespace directory.
     * @param array<array-key, mixed> $data Document payload.
     * @param array<string, string> $metadata Caller-owned metadata pairs.
     * @return string Temporary file path.
     * @throws \RuntimeException When the temporary file cannot be written.
     */
    private function prepareTemporaryDocument(string $directory, array $data, array $metadata): string
    {
        $encoded = json_encode(
            ['metadata' => $metadata, 'data' => $data],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );
        $temporary = tempnam($directory, '.ai-interaction-');

        if ($temporary === false) {
            throw new RuntimeException('A temporary storage file could not be created.');
        }

        try {
            $written = file_put_contents($temporary, $encoded, LOCK_EX);

            if ($written === false || $written !== strlen($encoded)) {
                throw new RuntimeException('The stored document could not be written.');
            }

            return $temporary;
        } catch (Throwable $throwable) {
            unlink($temporary);
            throw $throwable;
        }
    }

    /**
     * Deletes a document file.
     *
     * @param string $namespace Logical namespace owning the document.
     * @param string $key Logical document key.
     * @return void
     * @throws \RuntimeException When the document cannot be deleted.
     */
    protected function deleteDocument(string $namespace, string $key): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $directory = $this->existingNamespaceDirectory($namespace);

        if ($directory === null) {
            return;
        }

        $path = $this->path($directory, $key);

        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_link($path) || !is_file($path)) {
            clearstatcache(true, $path);
            if (!file_exists($path) && !is_link($path)) {
                return;
            }

            throw new RuntimeException(
                'A stored document must be a regular file.',
            );
        }

        if (!$this->quietUnlink($path)) {
            clearstatcache(true, $path);
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException(
                    'The stored document could not be deleted.',
                );
            }
        }
    }

    /**
     * Reads all namespace entries from files.
     *
     * @param string $namespace Logical namespace owning the documents.
     * @return list<\Crustum\AiInteraction\Storage\StoredDocument> Namespace documents.
     */
    protected function readEntries(string $namespace): iterable
    {
        if (!is_dir($this->root)) {
            return [];
        }

        $directory = $this->existingNamespaceDirectory($namespace);

        if ($directory === null) {
            return [];
        }

        $paths = glob(
            $directory . DIRECTORY_SEPARATOR . '*' . self::FILE_EXTENSION,
        ) ?: [];
        $entries = [];

        foreach ($paths as $path) {
            $key = substr(
                basename($path),
                0,
                -strlen(self::FILE_EXTENSION),
            );
            $document = $this->readDocument($namespace, $key);
            if ($document instanceof StoredDocument) {
                $entries[] = $document;
            }
        }

        return $entries;
    }

    /**
     * Builds a document file path.
     *
     * @param string $directory Namespace directory.
     * @param string $key Logical document key.
     * @return string Document file path.
     */
    private function path(string $directory, string $key): string
    {
        return $directory
            . DIRECTORY_SEPARATOR
            . $key
            . self::FILE_EXTENSION;
    }

    /**
     * Builds a stored document value from a file.
     *
     * @param string $path Document file path.
     * @param string $key Logical document key.
     * @return \Crustum\AiInteraction\Storage\StoredDocument Stored document value.
     * @throws \RuntimeException When the file cannot be read.
     * @throws \UnexpectedValueException When the file shape is invalid.
     */
    private function storedDocument(
        string $path,
        string $key,
    ): StoredDocument {
        if (is_link($path)) {
            throw new RuntimeException('A storage key cannot be a symbolic link.');
        }

        if (!is_file($path)) {
            throw new RuntimeException('The stored document is not a regular file.');
        }

        $encoded = $this->quietRead($path);

        if ($encoded === false) {
            throw new RuntimeException('The stored document could not be read.');
        }

        $document = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        if (
            !is_array($document)
            || !isset($document['metadata'], $document['data'])
            || !is_array($document['metadata'])
            || !is_array($document['data'])
        ) {
            throw new UnexpectedValueException(
                'A stored document must contain metadata and data.',
            );
        }

        $metadata = [];

        foreach ($document['metadata'] as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new UnexpectedValueException(
                    'Stored document metadata must contain string pairs.',
                );
            }

            $metadata[$name] = $value;
        }

        return new StoredDocument(
            $key,
            $document['data'],
            $metadata,
        );
    }

    /**
     * Resolves a namespace directory, creating it when needed.
     *
     * @param string $namespace Logical namespace owning the documents.
     * @return string Resolved namespace directory.
     * @throws \RuntimeException When the directory cannot be created or resolved.
     */
    private function namespaceDirectory(string $namespace): string
    {
        if (!is_dir($this->root) && !$this->quietMkdir($this->root, true) && !is_dir($this->root)) {
            throw new RuntimeException('The storage root could not be created.');
        }

        $root = realpath($this->root);

        if ($root === false) {
            throw new RuntimeException('The storage root could not be resolved.');
        }

        $directory = $root . DIRECTORY_SEPARATOR . $namespace;

        if (is_link($directory)) {
            throw new RuntimeException(
                'A storage namespace cannot be a symbolic link.',
            );
        }

        if (!is_dir($directory) && !$this->quietMkdir($directory, false) && !is_dir($directory)) {
            throw new RuntimeException(
                'The storage namespace could not be created.',
            );
        }

        $resolved = realpath($directory);

        if ($resolved === false || dirname($resolved) !== $root) {
            throw new RuntimeException(
                'The storage namespace is outside the configured root.',
            );
        }

        return $resolved;
    }

    /**
     * Resolves an existing namespace directory.
     *
     * @param string $namespace Logical namespace owning the documents.
     * @return string|null Resolved directory or null when missing.
     * @throws \RuntimeException When the directory is unsafe or cannot be resolved.
     */
    private function existingNamespaceDirectory(string $namespace): ?string
    {
        $root = realpath($this->root);

        if ($root === false) {
            throw new RuntimeException('The storage root could not be resolved.');
        }

        $directory = $root . DIRECTORY_SEPARATOR . $namespace;

        if (is_link($directory)) {
            throw new RuntimeException(
                'A storage namespace cannot be a symbolic link.',
            );
        }

        if (!file_exists($directory)) {
            return null;
        }

        $resolved = realpath($directory);

        if (
            $resolved === false
            || !is_dir($resolved)
            || dirname($resolved) !== $root
        ) {
            throw new RuntimeException(
                'The storage namespace is outside the configured root.',
            );
        }

        return $resolved;
    }

    /**
     * Creates a hard link while suppressing race warnings.
     *
     * @param string $from Source file path.
     * @param string $to Target link path.
     * @return bool True on success.
     */
    private function quietLink(string $from, string $to): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            return link($from, $to);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Deletes a file while suppressing race warnings.
     *
     * @param string $path File path.
     * @return bool True on success.
     */
    private function quietUnlink(string $path): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            return unlink($path);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Reads a file while suppressing race warnings.
     *
     * @param string $path File path.
     * @return string|false File contents or false on failure.
     */
    private function quietRead(string $path): string|false
    {
        set_error_handler(static fn(): bool => true);
        try {
            return file_get_contents($path);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Creates a directory while suppressing race warnings.
     *
     * @param string $path Directory path.
     * @param bool $recursive Create parent directories.
     * @return bool True on success.
     */
    private function quietMkdir(string $path, bool $recursive): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            return mkdir($path, 0777, $recursive);
        } finally {
            restore_error_handler();
        }
    }
}
