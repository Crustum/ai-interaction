<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

/**
 * Task-text turn outcome for the host loop.
 *
 * The dispatched command resolved to task input: the host runs the text as
 * the next turn input instead of rendering it.
 */
final readonly class TurnTask
{
    /**
     * Task text for the host loop to run as the next turn input.
     *
     * @var string
     */
    public string $text;

    /**
     * Task origin label shown instead of the text, null runs silently.
     *
     * @var string|null
     */
    public ?string $origin;

    /**
     * Creates a task-text turn outcome value.
     *
     * @param string $text Task text for the host loop to run as the next turn input.
     * @param string|null $origin Task origin label shown instead of the text, null runs silently.
     */
    public function __construct(string $text, ?string $origin = null)
    {
        $this->text = $text;
        $this->origin = $origin;
    }
}
