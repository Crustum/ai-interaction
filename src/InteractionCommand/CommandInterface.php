<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

/**
 * A named operation whose effects use the active adapter.
 */
interface CommandInterface
{
    /**
     * Returns the slash-prefixed identifier it answers to.
     *
     * @return string Slash-prefixed command name.
     */
    public function name(): string;

    /**
     * Returns one line for a listing of what can be typed here.
     *
     * @return string Command description.
     */
    public function describe(): string;

    /**
     * Runs the command with adapter-supplied raw text.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
     * @param string $value Raw text supplied by the adapter, preserved by dispatch.
     * @return void
     */
    public function run(CommandAdapterInterface $adapter, string $value): void;
}
