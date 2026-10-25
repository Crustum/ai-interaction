<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InteractionCommand;

use Cake\Collection\Collection;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Contracts\VerifiesConversationOwnership;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Responses\AgentResponse;
use Throwable;
use function bin2hex;
use function random_bytes;

/**
 * In-memory driver conversation store stub for adapter tests.
 */
final class InMemoryConversationStore implements ConversationStore, VerifiesConversationOwnership
{
    /**
     * Stored conversations by identifier.
     *
     * @var array<string, array{participantType: string|null, participantId: string|int|null, agent: string|null}>
     */
    private array $conversations = [];

    /**
     * Stored conversation counter.
     *
     * @var int
     */
    private int $storedConversations = 0;

    /**
     * Returns the participant latest conversation identifier.
     *
     * @param string $participantType Participant type.
     * @param string|int $participantId Participant identifier.
     * @param class-string<\Crustum\Ai\Contracts\Agent> $agent Agent class name.
     * @return string|null Latest conversation identifier or null.
     */
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        $latest = null;

        foreach ($this->conversations as $id => $conversation) {
            if (
                $conversation['participantType'] === $participantType
                && (string)$conversation['participantId'] === (string)$participantId
            ) {
                $latest = $id;
            }
        }

        return $latest;
    }

    /**
     * Stores a new conversation and returns its identifier.
     *
     * @param string|null $participantType Participant type.
     * @param string|int|null $participantId Participant identifier.
     * @param string $title Conversation title.
     * @param string|null $id Pre-allocated conversation identifier.
     * @return string Conversation identifier.
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        $this->storedConversations++;
        $conversationId = $id ?? 'conversation-' . $this->storedConversations;
        $this->conversations[$conversationId] = [
            'participantType' => $participantType,
            'participantId' => $participantId,
            'agent' => null,
        ];

        return $conversationId;
    }

    /**
     * Stores a user message and returns its identifier.
     *
     * @param string $conversationId Conversation identifier.
     * @param string|null $participantType Participant type.
     * @param string|int|null $participantId Participant identifier.
     * @param class-string<\Crustum\Ai\Contracts\Agent> $agent Agent class name.
     * @param \Crustum\Ai\Messages\UserMessage $message User message.
     * @return string Message identifier.
     */
    public function storeUserMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        string $agent,
        UserMessage $message,
    ): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Stores the assistant turn.
     *
     * @param string $conversationId Conversation identifier.
     * @param string|null $participantType Participant type.
     * @param string|int|null $participantId Participant identifier.
     * @param \Crustum\Ai\Prompts\AgentPrompt $prompt Agent prompt.
     * @param \Crustum\Ai\Responses\AgentResponse $response Agent response.
     * @param \Throwable|null $exception Run failure cause.
     * @return string|null Nothing was stored.
     */
    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response,
        ?Throwable $exception = null,
    ): ?string {
        return null;
    }

    /**
     * Returns the latest conversation messages.
     *
     * @param string $conversationId Conversation identifier.
     * @param int $limit Maximum number of messages.
     * @return \Cake\Collection\Collection<int<0, max>, \Crustum\Ai\Messages\Message> Latest messages.
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return new Collection([]);
    }

    /**
     * Records resolved approval results.
     *
     * @param string $conversationId Conversation identifier.
     * @param array<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults Resolved tool results.
     * @return void
     */
    public function storeApprovalResults(
        string $conversationId,
        array $toolResults,
    ): void {
    }

    /**
     * Checks conversation ownership by the participant.
     *
     * @param string $conversationId Conversation identifier.
     * @param string|null $participantType Participant type.
     * @param string|int|null $participantId Participant identifier.
     * @return bool True when the conversation belongs to the participant.
     */
    public function conversationBelongsTo(string $conversationId, ?string $participantType, string|int|null $participantId): bool
    {
        $conversation = $this->conversations[$conversationId] ?? null;

        return $conversation !== null
            && $conversation['participantType'] === $participantType
            && (string)$conversation['participantId'] === (string)$participantId;
    }
}
