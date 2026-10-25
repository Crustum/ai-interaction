<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

use function str_starts_with;
use function strpos;
use function substr;

/**
 * Dispatches slash-prefixed input through commands before agent runs.
 *
 * Hosts run ordinary input through their agent flow and route only `/`-prefixed
 * input here, preserving the raw value after the identifier.
 */
final readonly class CommandInterceptor
{
    /**
     * Mounted commands.
     *
     * @var \Crustum\AiInteraction\InteractionCommand\Commands
     */
    private Commands $commands;

    /**
     * Creates a command interceptor.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\Commands $commands Mounted commands.
     */
    public function __construct(Commands $commands)
    {
        $this->commands = $commands;
    }

    /**
     * Returns mounted commands.
     *
     * @return \Crustum\AiInteraction\InteractionCommand\Commands Mounted commands.
     */
    public function commands(): Commands
    {
        return $this->commands;
    }

    /**
     * Runs slash-prefixed input through dispatch.
     *
     * @param string $input Submitted input text.
     * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
     * @return mixed Adapter output for commands, null for ordinary input.
     */
    public function intercept(string $input, CommandAdapterInterface $adapter): mixed
    {
        if (!str_starts_with($input, '/')) {
            return null;
        }

        [$identifier, $value] = $this->split($input);

        return $this->commands->run($identifier, $value, $adapter);
    }

    /**
     * Splits input into an identifier and a preserved raw value.
     *
     * @param string $input Slash-prefixed input text.
     * @return array{string, string} Identifier and raw value pair.
     */
    private function split(string $input): array
    {
        $position = strpos($input, ' ');

        if ($position === false) {
            return [$input, ''];
        }

        return [substr($input, 0, $position), substr($input, $position + 1)];
    }
}
