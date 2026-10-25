<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Configuration;

use Crustum\AiInteraction\Storage\StorageInterface;
use Crustum\AiInteraction\Storage\StoredDocument;
use InvalidArgumentException;
use JsonException;
use function array_key_exists;
use function hash;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_encode;
use const JSON_THROW_ON_ERROR;

/**
 * Participant preferences backed by document storage.
 *
 * Each user reads and writes only its own document. In-memory storage is the
 * default backend; file storage suits console hosts.
 */
final class ConfigurationStore implements ConfigurationStoreInterface
{
    /**
     * Storage namespace for preference documents.
     *
     * @var string
     */
    private const string NAMESPACE = 'preferences';

    /**
     * Storage backend holding preference documents.
     *
     * @var \Crustum\AiInteraction\Storage\StorageInterface
     */
    private readonly StorageInterface $storage;

    /**
     * User owning the preferences.
     *
     * @var string
     */
    private readonly string $userId;

    /**
     * Creates a user-scoped preference store.
     *
     * @param \Crustum\AiInteraction\Storage\StorageInterface $storage Storage backend holding preference documents.
     * @param string $userId User owning the preferences.
     */
    public function __construct(
        StorageInterface $storage,
        string $userId,
    ) {
        $this->storage = $storage;
        $this->userId = $userId;
    }

    /**
     * Reads one preference with an exact-type fallback.
     *
     * The fallback selects the exact PHP type; strings must also be non-empty.
     * A null fallback requests a non-empty string or null.
     *
     * @param string $key Preference key.
     * @param mixed $fallback Fallback value selecting the expected type.
     * @return ($fallback is null ? non-empty-string|null : ($fallback is string ? non-empty-string : ($fallback is int ? int : ($fallback is float ? float : ($fallback is bool ? bool : array<array-key, mixed>))))) Stored value or the fallback.
     * @throws \InvalidArgumentException When the fallback is not usable JSON data.
     */
    public function read(string $key, mixed $fallback = null): mixed
    {
        $this->guardJson($fallback);
        if ($fallback === '') {
            throw new InvalidArgumentException('A string fallback must be non-empty.');
        }

        $values = $this->entries();

        $value = $values[$key] ?? null;

        return match (true) {
            $fallback === null => is_string($value) && $value !== '' ? $value : null,
            is_string($fallback) => is_string($value) && $value !== '' ? $value : $fallback,
            is_int($fallback) => is_int($value) ? $value : $fallback,
            is_float($fallback) => is_float($value) ? $value : $fallback,
            is_bool($fallback) => is_bool($value) ? $value : $fallback,
            is_array($fallback) => is_array($value) ? $value : self::snapshotArray($fallback),
            default => throw new InvalidArgumentException('Unsupported configuration fallback type.'),
        };
    }

    /**
     * Writes one preference immediately.
     *
     * @param string $key Preference key.
     * @param mixed $value JSON-compatible preference value.
     * @return void
     * @throws \InvalidArgumentException When the value is not usable JSON data.
     */
    public function write(string $key, mixed $value): void
    {
        $this->guardJson($value);
        $values = $this->entries();
        $values[$key] = $value;
        $this->storage->write(
            self::NAMESPACE,
            $this->storageKey(),
            self::snapshotArray($values),
            ['userId' => $this->userId],
        );
    }

    /**
     * Deletes one preference when it exists.
     *
     * @param string $key Preference key.
     * @return void
     */
    public function delete(string $key): void
    {
        $values = $this->entries();
        if (!array_key_exists($key, $values)) {
            return;
        }

        unset($values[$key]);
        $this->storage->write(
            self::NAMESPACE,
            $this->storageKey(),
            self::snapshotArray($values),
            ['userId' => $this->userId],
        );
    }

    /**
     * Reads every preference of the current user.
     *
     * @return array<array-key, mixed> Preference values by key.
     */
    public function entries(): array
    {
        $document = $this->storage->read(self::NAMESPACE, $this->storageKey());

        if (!$document instanceof StoredDocument || ($document->metadata['userId'] ?? null) !== $this->userId) {
            return [];
        }

        return self::snapshotArray($document->data);
    }

    /**
     * Resolves the storage key for the current user.
     *
     * @return string User storage key.
     */
    private function storageKey(): string
    {
        return hash('sha256', $this->userId);
    }

    /**
     * Copies each validated value so nested references cannot mutate it.
     *
     * @param array<array-key, mixed> $values Validated values.
     * @return array<array-key, mixed> Detached copy.
     */
    private static function snapshotArray(array $values): array
    {
        $snapshot = [];
        foreach ($values as $key => $value) {
            $snapshot[$key] = is_array($value) ? self::snapshotArray($value) : $value;
        }

        return $snapshot;
    }

    /**
     * Rejects values that cannot round-trip through JSON.
     *
     * @param mixed $value Candidate value.
     * @return void
     * @throws \InvalidArgumentException When the value is not JSON-compatible.
     */
    private function guardJson(mixed $value): void
    {
        self::guardData($value);

        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new InvalidArgumentException('Configuration values must be JSON-compatible data.', $jsonException->getCode(), previous: $jsonException);
        }
    }

    /**
     * Rejects objects and over-deep structures before encoding.
     *
     * @param mixed $value Candidate value.
     * @param int $depth Current nesting depth.
     * @return void
     * @throws \InvalidArgumentException When the value shape is not storable.
     */
    private static function guardData(mixed $value, int $depth = 0): void
    {
        if ($depth > 512) {
            throw new InvalidArgumentException('Configuration values exceed the JSON nesting limit.');
        }

        if (is_object($value)) {
            throw new InvalidArgumentException(
                'Configuration values must use PHP arrays rather than objects.',
            );
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::guardData($item, $depth + 1);
            }
        }
    }
}
