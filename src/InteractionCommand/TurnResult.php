<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

use Crustum\Ai\Responses\Data\TextUsage;

/**
 * Terminal turn state returned from turn iteration.
 */
final readonly class TurnResult
{
    /**
     * Whether the turn paused for approval results.
     *
     * @var bool
     */
    public bool $interrupted;

    /**
     * Whether the turn stopped on request.
     *
     * @var bool
     */
    public bool $stopped;

    /**
     * Metered token usage, or null when no agent run produced any.
     *
     * @var \Crustum\Ai\Responses\Data\TextUsage|null
     */
    public ?TextUsage $usage;

    /**
     * Creates a turn result value.
     *
     * @param bool $interrupted Whether the turn paused for approval results.
     * @param bool $stopped Whether the turn stopped on request.
     * @param \Crustum\Ai\Responses\Data\TextUsage|null $usage Metered token usage, or null when no agent run produced any.
     */
    public function __construct(bool $interrupted = false, bool $stopped = false, ?TextUsage $usage = null)
    {
        $this->interrupted = $interrupted;
        $this->stopped = $stopped;
        $this->usage = $usage;
    }
}
