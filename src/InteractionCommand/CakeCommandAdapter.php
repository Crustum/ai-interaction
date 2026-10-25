<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

use Closure;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Contracts\RemembersConversations;
use Crustum\Ai\Contracts\VerifiesConversationOwnership;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Model\Entity\Conversation;
use Crustum\Ai\Model\Entity\ConversationMessage;
use Crustum\Ai\Model\Table\ConversationMessagesTable;
use Crustum\Ai\Model\Table\ConversationsTable;
use Crustum\AiInteraction\Configuration\ConfigurationStoreInterface;
use InvalidArgumentException;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function trim;

/**
 * Request-scoped adapter for host applications.
 *
 * The host application supplies agent execution through a submit callback and
 * receives response data from completion. Conversations persist in the driver
 * conversation store.
 *
 * @implements \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<array{identifier: string, status: string, error: ?string, notices: list<string>, warnings: list<string>, errors: list<string>, selection: ?\Crustum\AiInteraction\InteractionCommand\Selection, task: ?string, origin: ?string, stopped: bool, conversationId: ?string}>
 */
final class CakeCommandAdapter implements CommandAdapterInterface, RenamesConversations
{
    /**
     * Received information texts.
     *
     * @var list<string>
     */
    private array $notices = [];

    /**
     * Received warning texts.
     *
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * Received failure texts.
     *
     * @var list<string>
     */
    private array $errors = [];

    /**
     * Pending selection request.
     *
     * @var \Crustum\AiInteraction\InteractionCommand\Selection|null
     */
    private ?Selection $selection = null;

    /**
     * Pending task text for the host loop.
     *
     * @var string|null
     */
    private ?string $task = null;

    /**
     * Pending task origin label, null runs silently.
     *
     * @var string|null
     */
    private ?string $taskOrigin = null;

    /**
     * Whether a stop was requested.
     *
     * @var bool
     */
    private bool $stopped = false;

    /**
     * Answering agent.
     *
     * @var \Crustum\Ai\Contracts\Agent
     */
    private Agent $agent;

    /**
     * Selected conversation identifier.
     *
     * @var string|null
     */
    private ?string $conversationId;

    /**
     * Creates a host adapter.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Answering agent.
     * @param \Crustum\AiInteraction\InteractionCommand\Commands $mounted Mounted commands.
     * @param \Crustum\Ai\Contracts\ConversationStore&\Crustum\Ai\Contracts\VerifiesConversationOwnership $store Driver conversation store.
     * @param \Closure(\Crustum\Ai\Contracts\Agent, \Crustum\Ai\Messages\UserMessage): void $submitPrompt Prompt submission callback.
     * @param \Crustum\AiInteraction\Configuration\ConfigurationStoreInterface $preferences Participant preference store.
     * @param string|null $participantType Participant type.
     * @param string|int|null $participantId Participant identifier.
     * @param object|null $participant Participant object.
     * @param string|null $conversationId Selected conversation identifier.
     */
    public function __construct(
        Agent $agent,
        private Commands $mounted,
        private ConversationStore&VerifiesConversationOwnership $store,
        private Closure $submitPrompt,
        private ConfigurationStoreInterface $preferences,
        private ?string $participantType = null,
        private string|int|null $participantId = null,
        private ?object $participant = null,
        ?string $conversationId = null,
    ) {
        $this->agent = $agent;
        $this->conversationId = $conversationId;
    }

    /**
     * Admits every command.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandInterface $command Command asking for admission.
     * @return bool True when the command may run.
     */
    public function admit(CommandInterface $command): bool
    {
        return true;
    }

    /**
     * Builds host response data from the dispatch outcome.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandExecution $execution Dispatch outcome.
     * @return array{identifier: string, status: string, error: ?string, notices: list<string>, warnings: list<string>, errors: list<string>, selection: ?\Crustum\AiInteraction\InteractionCommand\Selection, task: ?string, origin: ?string, stopped: bool, conversationId: ?string} Host response data.
     */
    public function afterExecution(CommandExecution $execution): mixed
    {
        return [
            'identifier' => $execution->identifier,
            'status' => $execution->status,
            'error' => $execution->exception?->getMessage(),
            'notices' => $this->notices,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'selection' => $this->selection,
            'task' => $this->task,
            'origin' => $this->taskOrigin,
            'stopped' => $this->stopped,
            'conversationId' => $this->conversationId,
        ];
    }

    /**
     * Records information text.
     *
     * @param string $text Information text.
     * @return void
     */
    public function notify(string $text): void
    {
        $this->notices[] = $text;
    }

    /**
     * Records warning text.
     *
     * @param string $text Warning text.
     * @return void
     */
    public function warn(string $text): void
    {
        $this->warnings[] = $text;
    }

    /**
     * Records failure text.
     *
     * @param string $text Failure text.
     * @return void
     */
    public function error(string $text): void
    {
        $this->errors[] = $text;
    }

    /**
     * Submits a prompt through the host callback.
     *
     * @param \Crustum\Ai\Messages\UserMessage $prompt Generated agent prompt.
     * @return void
     */
    public function promptAgent(UserMessage $prompt): void
    {
        ($this->submitPrompt)($this->agent, $prompt);
    }

    /**
     * Records a selection request.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\Selection $request Pending selection request.
     * @return void
     */
    public function requestSelection(Selection $request): void
    {
        $this->selection = $request;
    }

    /**
     * Records task text for the host loop.
     *
     * @param string $text Task text for the host loop to run as the next turn input.
     * @param string|null $origin Task origin label shown instead of the text, null runs silently.
     * @return void
     */
    public function requestTask(string $text, ?string $origin = null): void
    {
        $this->task = $text;
        $this->taskOrigin = $origin;
    }

    /**
     * Returns the answering agent.
     *
     * @return \Crustum\Ai\Contracts\Agent Answering agent.
     */
    public function agent(): Agent
    {
        return $this->agent;
    }

    /**
     * Replaces the answering agent.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Replacement agent.
     * @return void
     */
    public function useAgent(Agent $agent): void
    {
        $this->agent = $agent;
    }

    /**
     * Returns the selected conversation identifier.
     *
     * @return string|null Selected conversation identifier or null.
     */
    public function conversationId(): ?string
    {
        return $this->conversationId;
    }

    /**
     * Selects a conversation owned by the adapter participant.
     *
     * The answering agent continues the selected conversation when it remembers.
     *
     * @param string $conversationId Conversation identifier.
     * @return void
     * @throws \InvalidArgumentException When the conversation does not belong to the adapter participant.
     */
    public function useConversation(string $conversationId): void
    {
        if (
            !$this->store->conversationBelongsTo(
                $conversationId,
                $this->participantType,
                $this->participantId,
            )
        ) {
            throw new InvalidArgumentException('The selected conversation does not belong to this participant.');
        }

        $this->conversationId = $conversationId;

        if ($this->agent instanceof RemembersConversations) {
            $this->agent = $this->agent->continue($conversationId, $this->participant);
        }
    }

    /**
     * Starts a fresh conversation for the adapter participant and selects it.
     *
     * The answering agent continues the new conversation when it remembers.
     *
     * @param string|null $title Conversation title.
     * @return string New conversation identifier.
     */
    public function startConversation(?string $title = null): string
    {
        $this->conversationId = $this->store->storeConversation(
            $this->participantType,
            $this->participantId,
            $title ?? RenamesConversations::DEFAULT_TITLE,
        );

        if ($this->agent instanceof RemembersConversations) {
            $this->agent = $this->agent->continue($this->conversationId, $this->participant);
        }

        return $this->conversationId;
    }

    /**
     * Returns mounted commands.
     *
     * @return \Crustum\AiInteraction\InteractionCommand\Commands Mounted commands.
     */
    public function commands(): Commands
    {
        return $this->mounted;
    }

    /**
     * Returns the driver conversation store.
     *
     * @return \Crustum\Ai\Contracts\ConversationStore&\Crustum\Ai\Contracts\VerifiesConversationOwnership Driver conversation store.
     */
    public function conversationStore(): ConversationStore&VerifiesConversationOwnership
    {
        return $this->store;
    }

    /**
     * Returns the participant preference store.
     *
     * @return \Crustum\AiInteraction\Configuration\ConfigurationStoreInterface Participant preference store.
     */
    public function configurationStore(): ConfigurationStoreInterface
    {
        return $this->preferences;
    }

    /**
     * Returns the adapter participant type.
     *
     * @return string|null Participant type or null for anonymous interactions.
     */
    public function participantType(): ?string
    {
        return $this->participantType;
    }

    /**
     * Returns the adapter participant identifier.
     *
     * @return string|int|null Participant identifier or null for anonymous interactions.
     */
    public function participantId(): string|int|null
    {
        return $this->participantId;
    }

    /**
     * Returns the adapter participant.
     *
     * @return object|null Participant object or null for anonymous interactions.
     */
    public function participant(): ?object
    {
        return $this->participant;
    }

    /**
     * Lists participant conversations holding messages, newest first.
     *
     * Empty conversations never started stay out of listings, matching the
     * upstream summaries contract.
     *
     * @return list<\Crustum\Ai\Model\Entity\Conversation> Participant conversations, newest first.
     */
    public function conversationSummaries(): array
    {
        $summaries = [];
        $populated = $this->populatedConversationIds();

        foreach (
            $this->conversationsTable()->find()
            ->where($this->scopeConditions())
            ->orderByDesc('modified')
            ->all() as $conversation
        ) {
            if ($conversation instanceof Conversation && isset($populated[$conversation->id])) {
                $summaries[] = $conversation;
            }
        }

        return $summaries;
    }

    /**
     * Records a stop request.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * Returns the opening user message excerpt of a conversation.
     *
     * @param string $conversationId Conversation identifier.
     * @param int $length Maximum excerpt length in characters.
     * @return string|null Excerpt or null when the conversation has no user message.
     */
    public function conversationExcerpt(string $conversationId, int $length = 60): ?string
    {
        /** @var \Crustum\Ai\Model\Entity\ConversationMessage|null $record */
        $record = (new ConversationMessagesTable())->find()
            ->where([
                'conversation_id' => $conversationId,
                'role' => MessageRole::User->value,
            ])
            ->orderByAsc('id')
            ->first();

        if (!$record instanceof ConversationMessage) {
            return null;
        }

        $text = trim((string)$record->content);

        if ($text === '') {
            return null;
        }

        $singleLine = (string)preg_replace('/\s+/', ' ', $text);

        if (mb_strlen($singleLine) <= $length) {
            return $singleLine;
        }

        return mb_substr($singleLine, 0, $length - 1) . '…';
    }

    /**
     * Returns the stored title of a conversation.
     *
     * @param string $conversationId Conversation identifier.
     * @return string|null Stored title or null when unknown.
     */
    public function conversationTitle(string $conversationId): ?string
    {
        /** @var \Crustum\Ai\Model\Entity\Conversation|null $conversation */
        $conversation = $this->conversationsTable()->find()
            ->select(['title'])
            ->where(['id' => $conversationId])
            ->first();

        return $conversation?->title;
    }

    /**
     * Replaces the stored title of a participant conversation.
     *
     * @param string $conversationId Conversation identifier.
     * @param string $title Replacement title.
     * @return void
     * @throws \InvalidArgumentException When the conversation does not belong to the adapter participant.
     */
    public function renameConversation(string $conversationId, string $title): void
    {
        if (
            !$this->store->conversationBelongsTo(
                $conversationId,
                $this->participantType,
                $this->participantId,
            )
        ) {
            throw new InvalidArgumentException('The selected conversation does not belong to this participant.');
        }

        $conversations = $this->conversationsTable();
        $conversation = $conversations->get($conversationId);
        $conversation->title = $title;

        $conversations->saveOrFail($conversation);
    }

    /**
     * Resolves the driver conversations table.
     *
     * @return \Crustum\Ai\Model\Table\ConversationsTable Driver conversations table.
     */
    private function conversationsTable(): ConversationsTable
    {
        return new ConversationsTable();
    }

    /**
     * Lists conversation identifiers holding at least one message.
     *
     * Messages scope by participant identifier only: the driver derives the
     * participant type from the participant object while hosts scope by an
     * explicit type option, so requiring both would hide conversations whose
     * messages were stored under the other derivation.
     *
     * @return array<string, true> Populated conversation identifiers.
     */
    private function populatedConversationIds(): array
    {
        $populated = [];
        $conditions = ['conversation_id IS NOT NULL'];

        if ($this->participantId === null) {
            $conditions += $this->scopeConditions();
        } else {
            $conditions['participant_id'] = (string)$this->participantId;
        }

        $rows = (new ConversationMessagesTable())->find()
            ->select(['conversation_id'])
            ->distinct()
            ->where($conditions)
            ->all();

        foreach ($rows as $row) {
            $identifier = $row->conversation_id ?? null;

            if (is_string($identifier) && $identifier !== '') {
                $populated[$identifier] = true;
            }
        }

        return $populated;
    }

    /**
     * Builds participant scope conditions.
     *
     * @return array<string, mixed> Query conditions.
     */
    private function scopeConditions(): array
    {
        $conditions = [];

        if ($this->participantType === null) {
            $conditions['participant_type IS'] = null;
        } else {
            $conditions['participant_type'] = $this->participantType;
        }

        $participantId = $this->participantId === null ? null : (string)$this->participantId;

        if ($participantId === null) {
            $conditions['participant_id IS'] = null;
        } else {
            $conditions['participant_id'] = $participantId;
        }

        return $conditions;
    }
}
