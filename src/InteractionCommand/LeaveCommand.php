<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

/**
 * Requests that the adapter stop its interaction.
 */
final readonly class LeaveCommand implements ConcurrentCommandInterface
{
    /**
     * Presentation-neutral identifier.
     *
     * @var string
     */
    private string $name;

    /**
     * Creates a leave command.
     *
     * @param string $name Name it answers to, including the leading slash.
     */
    public function __construct(string $name = '/exit')
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
        return 'Stops the interaction.';
    }

    /**
     * Requests a stop through the adapter.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
     * @param string $value Raw text supplied by the adapter, preserved by dispatch.
     * @return void
     */
    public function run(CommandAdapterInterface $adapter, string $value): void
    {
        $adapter->stop();
    }
}
