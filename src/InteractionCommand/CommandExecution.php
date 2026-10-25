<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

use Throwable;

/**
 * Technical dispatch outcome delivered to the adapter after execution.
 *
 * Completed invocations may still have a pending selection or agent response.
 */
final readonly class CommandExecution
{
    /**
     * Dispatch identifier.
     *
     * @var string
     */
    public string $identifier;

    /**
     * Dispatch status name.
     *
     * @var string
     */
    public string $status;

    /**
     * Dispatch failure cause.
     *
     * @var \Throwable|null
     */
    public ?Throwable $exception;

    /**
     * Creates a dispatch outcome value.
     *
     * @param string $identifier Dispatched identifier.
     * @param string $status Dispatch status name.
     * @param \Throwable|null $exception Dispatch failure cause.
     */
    private function __construct(
        string $identifier,
        string $status,
        ?Throwable $exception = null,
    ) {
        $this->identifier = $identifier;
        $this->status = $status;
        $this->exception = $exception;
    }

    /**
     * Creates a completed dispatch outcome.
     *
     * @param string $identifier Dispatched identifier.
     * @return self Completed outcome.
     */
    public static function completed(string $identifier): self
    {
        return new self($identifier, 'completed');
    }

    /**
     * Creates an unknown dispatch outcome.
     *
     * @param string $identifier Dispatched identifier.
     * @return self Unknown outcome.
     */
    public static function unknown(string $identifier): self
    {
        return new self($identifier, 'unknown');
    }

    /**
     * Creates a failed dispatch outcome.
     *
     * @param string $identifier Dispatched identifier.
     * @param \Throwable $exception Dispatch failure cause.
     * @return self Failed outcome.
     */
    public static function failed(string $identifier, Throwable $exception): self
    {
        return new self($identifier, 'failed', $exception);
    }
}
