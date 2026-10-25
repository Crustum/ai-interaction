<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Configuration;

use Crustum\AiInteraction\Model\Entity\AiPreference;
use Crustum\AiInteraction\Model\Table\AiPreferencesTable;
use InvalidArgumentException;
use JsonException;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_decode;
use function json_encode;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

/**
 * Participant preferences backed by the preferences table.
 *
 * Each participant reads and writes only its own rows. Values keep exact PHP
 * types selected by the fallback, mirroring JSON document semantics.
 * Web hosts use this backend; console hosts use the storage-backed default.
 */
final class DbConfigurationStore implements ConfigurationStoreInterface
{
    /**
     * Participant type owning the preferences.
     *
     * @var string|null
     */
    private readonly ?string $participantType;

    /**
     * Participant identifier owning the preferences.
     *
     * @var string|int|null
     */
    private readonly string|int|null $participantId;

    /**
     * Resolved preferences table.
     *
     * @var \Crustum\AiInteraction\Model\Table\AiPreferencesTable|null
     */
    private ?AiPreferencesTable $table = null;

    /**
     * Creates a participant-scoped preference store.
     *
     * @param string|null $participantType Participant type owning the preferences.
     * @param string|int|null $participantId Participant identifier owning the preferences.
     */
    public function __construct(
        ?string $participantType = null,
        string|int|null $participantId = null,
    ) {
        $this->participantType = $participantType;
        $this->participantId = $participantId;
    }

    /**
     * Reads one preference with an exact-type fallback.
     *
     * The fallback selects the exact PHP type; strings must also be non-empty.
     * A null fallback requests a non-empty string or null.
     *
     * @param string $key Preference key.
     * @param mixed $fallback Fallback value selecting the expected type.
     * @return mixed Stored value or the fallback.
     * @throws \InvalidArgumentException When the fallback is not usable JSON data.
     */
    public function read(string $key, mixed $fallback = null): mixed
    {
        $this->guardJson($fallback);
        if ($fallback === '') {
            throw new InvalidArgumentException('A string fallback must be non-empty.');
        }

        $value = $this->storedValue($key);

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
        $table = $this->table();
        $encoded = $this->encode($value);
        $entity = $this->findRow($table, $key);

        if ($entity instanceof AiPreference) {
            $entity = $table->patchEntity($entity, ['value' => $encoded]);
        } else {
            $entity = $table->newEntity([
                'participant_type' => $this->participantType,
                'participant_id' => $this->participantIdAsString(),
                'pref_key' => $key,
                'value' => $encoded,
            ]);
        }

        $table->saveOrFail($entity);
    }

    /**
     * Deletes one preference when it exists.
     *
     * @param string $key Preference key.
     * @return void
     */
    public function delete(string $key): void
    {
        $table = $this->table();
        $entity = $this->findRow($table, $key);

        if (!$entity instanceof AiPreference) {
            return;
        }

        $table->deleteOrFail($entity);
    }

    /**
     * Reads every preference of the current participant in key order.
     *
     * @return array<array-key, mixed> Preference values by key in key order.
     */
    public function entries(): array
    {
        $values = [];

        foreach ($this->rows() as $row) {
            $values[$row->pref_key] = $this->decode($row->value);
        }

        return $values;
    }

    /**
     * Reads one decoded row value.
     *
     * @param string $key Preference key.
     * @return mixed Decoded value or null when missing or unreadable.
     */
    private function storedValue(string $key): mixed
    {
        $entity = $this->findRow($this->table(), $key);

        if (!$entity instanceof AiPreference) {
            return null;
        }

        return $this->decode($entity->value);
    }

    /**
     * Lists participant rows in key order.
     *
     * @return list<\Crustum\AiInteraction\Model\Entity\AiPreference> Participant rows.
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->table()->find()->where($this->scopeConditions())->orderByAsc('pref_key')->all() as $row) {
            if ($row instanceof AiPreference) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Finds one participant row by key.
     *
     * @param \Crustum\AiInteraction\Model\Table\AiPreferencesTable $table Preferences table.
     * @param string $key Preference key.
     * @return \Crustum\AiInteraction\Model\Entity\AiPreference|null Matching row or null.
     */
    private function findRow(AiPreferencesTable $table, string $key): ?AiPreference
    {
        /** @var \Crustum\AiInteraction\Model\Entity\AiPreference|null $row */
        $row = $table->find()->where($this->scopeConditions($key))->first();

        return $row;
    }

    /**
     * Builds participant scope conditions with an optional key.
     *
     * @param string|null $key Preference key.
     * @return array<string, mixed> Query conditions.
     */
    private function scopeConditions(?string $key = null): array
    {
        $conditions = array_merge(
            $this->fieldCondition('participant_type', $this->participantType),
            $this->fieldCondition('participant_id', $this->participantIdAsString()),
        );

        if ($key !== null) {
            $conditions['pref_key'] = $key;
        }

        return $conditions;
    }

    /**
     * Builds a null-safe condition for one scope field.
     *
     * @param string $field Field name.
     * @param string|null $value Field value.
     * @return array<string, mixed> Query conditions.
     */
    private function fieldCondition(string $field, ?string $value): array
    {
        if ($value === null) {
            return [$field . ' IS' => null];
        }

        return [$field => $value];
    }

    /**
     * Resolves the preferences table on the default connection.
     *
     * @return \Crustum\AiInteraction\Model\Table\AiPreferencesTable Preferences table.
     */
    private function table(): AiPreferencesTable
    {
        if (!$this->table instanceof AiPreferencesTable) {
            $this->table = new AiPreferencesTable();
        }

        return $this->table;
    }

    /**
     * Normalizes the participant identifier for storage.
     *
     * @return string|null Participant identifier as a string.
     */
    private function participantIdAsString(): ?string
    {
        if ($this->participantId === null) {
            return null;
        }

        return (string)$this->participantId;
    }

    /**
     * Encodes a value for storage.
     *
     * @param mixed $value JSON-compatible value.
     * @return string Encoded value.
     */
    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Decodes a stored value.
     *
     * @param string $encoded Encoded value.
     * @return mixed Decoded value or null when unreadable.
     */
    private function decode(string $encoded): mixed
    {
        try {
            return json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
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
