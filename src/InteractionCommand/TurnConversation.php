<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

/**
 * Selected conversation turn outcome for display.
 */
final readonly class TurnConversation
{
    /**
     * Selected conversation identifier.
     *
     * @var string
     */
    public string $conversationId;

    /**
     * Creates a turn conversation value.
     *
     * @param string $conversationId Selected conversation identifier.
     */
    public function __construct(string $conversationId)
    {
        $this->conversationId = $conversationId;
    }
}
