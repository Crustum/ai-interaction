<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

use InvalidArgumentException;
use Throwable;
use function is_array;
use function str_starts_with;

/**
 * Mounted commands in order, with the first matching identifier winning.
 */
final class Commands
{
    /**
     * Mounted commands in mounting order.
     *
     * @var list<\Crustum\AiInteraction\InteractionCommand\CommandInterface>
     */
    private array $commands = [];

    /**
     * Mounts commands before running the adapter.
     *
     * Mounting mutates this collection.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandInterface|array<array-key, mixed> $commands Command or list of commands.
     * @return self This collection.
     * @throws \InvalidArgumentException When a mounted member is not a command or its identifier is invalid.
     */
    public function addCommand(CommandInterface|array $commands): self
    {
        foreach (is_array($commands) ? $commands : [$commands] as $command) {
            $this->commands[] = $this->requireCommand($command);
        }

        return $this;
    }

    /**
     * Validates a single mounted member.
     *
     * @param mixed $command Mounted member.
     * @return \Crustum\AiInteraction\InteractionCommand\CommandInterface Validated command.
     * @throws \InvalidArgumentException When the member is not a command or its identifier is invalid.
     */
    private function requireCommand(mixed $command): CommandInterface
    {
        if (!$command instanceof CommandInterface) {
            throw new InvalidArgumentException('A mounted Command must implement CommandInterface.');
        }

        if (!str_starts_with($command->name(), '/')) {
            throw new InvalidArgumentException('A mounted Command identifier must start with a slash.');
        }

        return $command;
    }

    /**
     * Returns mounted commands in mounting order.
     *
     * @return list<\Crustum\AiInteraction\InteractionCommand\CommandInterface> Mounted commands.
     */
    public function all(): array
    {
        return $this->commands;
    }

    /**
     * Finds the first command with an exact identifier.
     *
     * @param string $identifier Slash-prefixed identifier.
     * @return \Crustum\AiInteraction\InteractionCommand\CommandInterface|null First match or null.
     */
    public function named(string $identifier): ?CommandInterface
    {
        foreach ($this->commands as $command) {
            if ($command->name() === $identifier) {
                return $command;
            }
        }

        return null;
    }

    /**
     * Dispatches an identifier through admission, execution and completion.
     *
     * @template TOutput
     * @param string $identifier Slash-prefixed identifier.
     * @param string $value Raw text supplied by the adapter, preserved by dispatch.
     * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<TOutput> $adapter Active adapter.
     * @return mixed Adapter output, or null when admission refuses the command. Null is also returned when admission refuses the command.
     */
    public function run(
        string $identifier,
        string $value,
        CommandAdapterInterface $adapter,
    ): mixed {
        $command = $this->named($identifier);

        if (!$command instanceof CommandInterface) {
            return $adapter->afterExecution(CommandExecution::unknown($identifier));
        }

        if (!$adapter->admit($command)) {
            return null;
        }

        try {
            $command->run($adapter, $value);

            $execution = CommandExecution::completed($identifier);
        } catch (Throwable $throwable) {
            $execution = CommandExecution::failed($identifier, $throwable);
        }

        return $adapter->afterExecution($execution);
    }
}
