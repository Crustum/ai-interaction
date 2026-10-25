<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Session;

use Crustum\Ai\Contracts\Agent;
use Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface;
use Crustum\AiInteraction\InteractionCommand\ConversationMessages;
use Crustum\AiInteraction\InteractionCommand\RenamesConversations;
use Throwable;
use function in_array;
use function is_string;

/**
 * Names untitled conversations from their transcript.
 *
 * Disabled when no title agent is given. Titled conversations keep their
 * title, and a null generation result retries on the next turn.
 */
final readonly class ConversationTitler
{
    /**
     * Creates a titler.
     *
     * @param \Crustum\Ai\Contracts\Agent|null $titles Title agent or null when disabled.
     */
    public function __construct(
        private ?Agent $titles = null,
    ) {
    }

    /**
     * Names the adapter conversation when it is still untitled.
     *
     * Generation failures never break the turn they follow.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
     * @return void
     */
    public function titleUntitled(CommandAdapterInterface $adapter): void
    {
        if (!$this->titles instanceof Agent || !$adapter instanceof RenamesConversations) {
            return;
        }

        $conversationId = $adapter->conversationId();

        if (!is_string($conversationId) || $conversationId === '') {
            return;
        }

        $current = $adapter->conversationTitle($conversationId);

        if (!in_array($current, [null, '', RenamesConversations::DEFAULT_TITLE], true)) {
            return;
        }

        try {
            $messages = (new ConversationMessages($adapter->conversationStore(), $conversationId))->messages();
            $title = (new SessionTitleGenerator($this->titles, $messages))->generate();
        } catch (Throwable) {
            return;
        }

        if ($title !== null && $title !== '') {
            $adapter->renameConversation($conversationId, $title);
        }
    }
}
