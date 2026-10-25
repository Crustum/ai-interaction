<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

/**
 * Starts a new conversation, leaving the previous one where it is stored.
 *
 * A host application mounts it under `clear` or a name of its own.
 * Starting a conversation selects a fresh conversation row.
 * Nothing here deletes the conversation the new one replaced.
 */
final readonly class ClearCommand implements CommandInterface
{
    /**
     * Presentation-neutral identifier.
     *
     * @var string
     */
    private string $name;

    /**
     * Creates a clear command.
     *
     * @param string $name Presentation-neutral identifier.
     */
    public function __construct(string $name = '/clear')
    {
        $this->name = $name;
    }

    /**
     * Returns the slash-prefixed identifier it answers to.
     *
     * @return string Slash-prefixed command name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Returns one line for a listing of what can be typed here.
     *
     * @return string Command description.
     */
    public function describe(): string
    {
        return 'Starts a new conversation, leaving the current one stored.';
    }

    /**
     * Starts a fresh conversation through the adapter.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
     * @param string $value Raw text supplied by the adapter, preserved by dispatch.
     * @return void
     */
    public function run(CommandAdapterInterface $adapter, string $value): void
    {
        $adapter->startConversation();
    }
}
