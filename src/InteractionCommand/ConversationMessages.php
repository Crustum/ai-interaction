<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

use Crustum\Ai\Contracts\ConversationStore;

/**
 * Conversation display messages for history sync.
 */
final readonly class ConversationMessages
{
    /**
     * Driver conversation store.
     *
     * @var \Crustum\Ai\Contracts\ConversationStore
     */
    private ConversationStore $store;

    /**
     * Conversation identifier.
     *
     * @var string
     */
    private string $conversationId;

    /**
     * Maximum number of messages.
     *
     * @var int
     */
    private int $limit;

    /**
     * Creates a conversation message provider.
     *
     * @param \Crustum\Ai\Contracts\ConversationStore $store Driver conversation store.
     * @param string $conversationId Conversation identifier.
     * @param int $limit Maximum number of messages.
     */
    public function __construct(
        ConversationStore $store,
        string $conversationId,
        int $limit = 50,
    ) {
        $this->store = $store;
        $this->conversationId = $conversationId;
        $this->limit = $limit;
    }

    /**
     * Reads the latest conversation messages.
     *
     * @return iterable<int, \Crustum\Ai\Messages\Message> Latest messages.
     */
    public function messages(): iterable
    {
        return $this->store->getLatestConversationMessages($this->conversationId, $this->limit);
    }
}
