<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

/**
 * A command that can run while the agent is working.
 *
 * Implementations must not interfere with state used by the active agent work.
 * Adapters decide whether to admit the command; this marker grants no automatic
 * execution and does not restrict the ordinary adapter controls.
 */
interface ConcurrentCommandInterface extends CommandInterface
{
}
