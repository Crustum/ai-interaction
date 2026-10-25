<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Configuration;

/**
 * Participant preference storage contract.
 *
 * Implementations share identical typed read semantics: the fallback selects
 * the exact PHP type, and strings must also be non-empty.
 */
interface ConfigurationStoreInterface
{
    /**
     * Reads one preference with an exact-type fallback.
     *
     * The fallback selects the exact PHP type; strings must also be non-empty.
     * A null fallback requests a non-empty string or null.
     *
     * @param string $key Preference key.
     * @param mixed $fallback Fallback value selecting the expected type.
     * @return mixed Stored value or the fallback.
     * @throws \InvalidArgumentException When the fallback is not usable data.
     */
    public function read(string $key, mixed $fallback = null): mixed;

    /**
     * Writes one preference immediately.
     *
     * @param string $key Preference key.
     * @param mixed $value Preference value.
     * @return void
     * @throws \InvalidArgumentException When the value is not storable.
     */
    public function write(string $key, mixed $value): void;

    /**
     * Deletes one preference when it exists.
     *
     * @param string $key Preference key.
     * @return void
     */
    public function delete(string $key): void;

    /**
     * Reads every preference of the current participant.
     *
     * @return array<array-key, mixed> Preference values by key.
     */
    public function entries(): array;
}
