<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

/**
 * Adapter renaming stored conversations.
 *
 * Driver stores keep titles write-once, so hosts that generate titles after
 * the first turn depend on this adapter-level capability instead.
 */
interface RenamesConversations
{
    /**
     * Fallback title stored for conversations started without one.
     *
     * @var string
     */
    public const string DEFAULT_TITLE = 'New conversation';

    /**
     * Returns the stored title of a conversation.
     *
     * @param string $conversationId Conversation identifier.
     * @return string|null Stored title or null when unknown.
     */
    public function conversationTitle(string $conversationId): ?string;

    /**
     * Replaces the stored title of a conversation.
     *
     * @param string $conversationId Conversation identifier.
     * @param string $title Replacement title.
     * @return void
     */
    public function renameConversation(string $conversationId, string $title): void;
}
