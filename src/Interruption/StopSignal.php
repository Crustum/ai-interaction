<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Interruption;

use Closure;
use Crustum\AiInteraction\Storage\StorageInterface;
use InvalidArgumentException;
use UnexpectedValueException;
use function hrtime;
use function is_bool;
use function is_finite;

/**
 * Shared stop signal under a key chosen by the host application.
 *
 * The flag is visible across processes. Streaming loops poll it between chunks;
 * aborting provider calls stays the driver's responsibility.
 */
final readonly class StopSignal
{
    /**
     * Storage namespace for stop flags.
     *
     * @var string
     */
    private const string NAMESPACE = 'response-stops';

    /**
     * Storage backend holding stop flags.
     *
     * @var \Crustum\AiInteraction\Storage\StorageInterface
     */
    private StorageInterface $storage;

    /**
     * Flag key chosen by the host application.
     *
     * @var string
     */
    private string $key;

    /**
     * Creates a stop signal.
     *
     * @param \Crustum\AiInteraction\Storage\StorageInterface $storage Storage backend holding stop flags.
     * @param string $key Flag key chosen by the host application.
     */
    public function __construct(StorageInterface $storage, string $key)
    {
        $this->storage = $storage;
        $this->key = $key;
    }

    /**
     * Creates a polling callback reporting requested stops.
     *
     * @param (\Closure(): void)|null $onPoll Lets the host process pending input.
     * @param float $pollInterval Minimum seconds between storage polls.
     * @return \Closure(): bool Stop predicate for streaming loops.
     * @throws \InvalidArgumentException When the polling interval is not finite and non-negative.
     */
    public function stopCallback(?Closure $onPoll = null, float $pollInterval = 0.01): Closure
    {
        if (!is_finite($pollInterval) || $pollInterval < 0) {
            throw new InvalidArgumentException('The polling interval must be finite and non-negative.');
        }

        $nextPoll = 0.0;

        return function () use ($onPoll, $pollInterval, &$nextPoll): bool {
            $now = hrtime(true) / 1_000_000_000;
            if ($now < $nextPoll) {
                return false;
            }

            $nextPoll = $now + $pollInterval;
            $onPoll?->__invoke();
            if (!$this->isRequested()) {
                return false;
            }

            $this->clear();

            return true;
        };
    }

    /**
     * Requests a stop.
     *
     * @return void
     */
    public function request(): void
    {
        $this->storage->write(self::NAMESPACE, $this->key, ['requested' => true]);
    }

    /**
     * Reports whether a stop was requested.
     *
     * @return bool True when a stop was requested.
     * @throws \UnexpectedValueException When the stored flag is not a boolean.
     */
    public function isRequested(): bool
    {
        $document = $this->storage->read(self::NAMESPACE, $this->key);
        $requested = $document?->data['requested'] ?? false;
        if (!is_bool($requested)) {
            throw new UnexpectedValueException('A stop flag must contain a boolean requested value.');
        }

        return $requested;
    }

    /**
     * Clears a requested stop.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->storage->delete(self::NAMESPACE, $this->key);
    }
}
