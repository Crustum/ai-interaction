<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

/**
 * Pending selection turn outcome for display.
 */
final readonly class TurnSelection
{
    /**
     * Pending selection request.
     *
     * @var \Crustum\AiInteraction\InteractionCommand\Selection
     */
    public Selection $request;

    /**
     * Creates a turn selection value.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\Selection $request Pending selection request.
     */
    public function __construct(Selection $request)
    {
        $this->request = $request;
    }
}
