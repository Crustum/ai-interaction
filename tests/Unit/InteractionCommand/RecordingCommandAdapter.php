<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InteractionCommand;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Contracts\RemembersConversations;
use Crustum\Ai\Contracts\VerifiesConversationOwnership;
use Crustum\Ai\Messages\UserMessage;
use Crustum\AiInteraction\Configuration\ConfigurationStore;
use Crustum\AiInteraction\Configuration\ConfigurationStoreInterface;
use Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface;
use Crustum\AiInteraction\InteractionCommand\CommandExecution;
use Crustum\AiInteraction\InteractionCommand\CommandInterface;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\Selection;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use InvalidArgumentException;
use LogicException;

/**
 * Recording adapter for interaction command tests.
 *
 * @implements \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<CommandExecution>
 */
class RecordingCommandAdapter implements CommandAdapterInterface
{
    /**
     * Whether admission was requested.
     *
     * @var bool
     */
    public bool $admitted = false;

    /**
     * Received information texts.
     *
     * @var list<string>
     */
    public array $notices = [];

    /**
     * Received warning texts.
     *
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * Received failure texts.
     *
     * @var list<string>
     */
    public array $errors = [];

    /**
     * Prompts submitted to the agent flow.
     *
     * @var list<\Crustum\Ai\Messages\UserMessage>
     */
    public array $prompts = [];

    /**
     * Requested selections.
     *
     * @var list<\Crustum\AiInteraction\InteractionCommand\Selection>
     */
    public array $selections = [];

    /**
     * Requested task texts.
     *
     * @var list<string>
     */
    public array $tasks = [];

    /**
     * Requested task origins parallel to task texts.
     *
     * @var list<string|null>
     */
    public array $taskOrigins = [];

    /**
     * Last dispatch outcome.
     *
     * @var \Crustum\AiInteraction\InteractionCommand\CommandExecution|null
     */
    public ?CommandExecution $lastExecution = null;

    /**
     * Whether a stop was requested.
     *
     * @var bool
     */
    public bool $stopped = false;

    /**
     * Started conversation counter.
     *
     * @var int
     */
    private int $startedConversations = 0;

    /**
     * Selected conversation identifier.
     *
     * @var string|null
     */
    private ?string $conversationId;

    /**
     * Answering agent.
     *
     * @var \Crustum\Ai\Contracts\Agent|null
     */
    private ?Agent $agent;

    /**
     * Mounted commands.
     *
     * @var \Crustum\AiInteraction\InteractionCommand\Commands
     */
    private Commands $mounted;

    /**
     * Driver conversation store stub.
     *
     * @var \Crustum\Ai\Contracts\ConversationStore&\Crustum\Ai\Contracts\VerifiesConversationOwnership
     */
    private ConversationStore&VerifiesConversationOwnership $conversations;

    /**
     * Staged conversation summaries.
     *
     * @var list<\Crustum\Ai\Model\Entity\Conversation>
     */
    public array $conversationSummaries = [];

    /**
     * Participant preference store.
     *
     * @var \Crustum\AiInteraction\Configuration\ConfigurationStoreInterface
     */
    private ConfigurationStoreInterface $preferences;

    /**
     * Creates a recording adapter.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\Commands|null $mounted Mounted commands.
     * @param \Crustum\Ai\Contracts\Agent|null $agent Answering agent.
     * @param string|null $conversationId Selected conversation identifier.
     * @param string|null $participantType Participant type.
     * @param string|int|null $participantId Participant identifier.
     * @param object|null $participant Participant object.
     */
    public function __construct(
        ?Commands $mounted = null,
        ?Agent $agent = null,
        ?string $conversationId = null,
        private ?string $participantType = null,
        private string|int|null $participantId = null,
        private ?object $participant = null,
    ) {
        $this->mounted = $mounted ?? new Commands();
        $this->agent = $agent;
        $this->conversationId = $conversationId;
        $this->conversations = new InMemoryConversationStore();
        $this->preferences = new ConfigurationStore(new InMemoryStorage(), 'test-adapter-recording');
    }

    /**
     * Admits every command and records admission.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandInterface $command Command asking for admission.
     * @return bool True when the command may run.
     */
    public function admit(CommandInterface $command): bool
    {
        $this->admitted = true;

        return true;
    }

    /**
     * Records the dispatch outcome.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandExecution $execution Dispatch outcome.
     * @return \Crustum\AiInteraction\InteractionCommand\CommandExecution Recorded outcome.
     */
    public function afterExecution(CommandExecution $execution): mixed
    {
        $this->lastExecution = $execution;

        return $execution;
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
     * Records a prompt for the agent flow.
     *
     * @param \Crustum\Ai\Messages\UserMessage $prompt Generated agent prompt.
     * @return void
     */
    public function promptAgent(UserMessage $prompt): void
    {
        $this->prompts[] = $prompt;
    }

    /**
     * Records a selection request.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\Selection $request Pending selection request.
     * @return void
     */
    public function requestSelection(Selection $request): void
    {
        $this->selections[] = $request;
    }

    /**
     * Records a task text request.
     *
     * @param string $text Task text for the host loop to run as the next turn input.
     * @param string|null $origin Task origin label shown instead of the text, null runs silently.
     * @return void
     */
    public function requestTask(string $text, ?string $origin = null): void
    {
        $this->tasks[] = $text;
        $this->taskOrigins[] = $origin;
    }

    /**
     * Returns the answering agent.
     *
     * @return \Crustum\Ai\Contracts\Agent Answering agent.
     * @throws \LogicException When no agent was set.
     */
    public function agent(): Agent
    {
        if (!$this->agent instanceof Agent) {
            throw new LogicException('No agent was set on the adapter.');
        }

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
     * Selects a conversation identifier and continues the agent when it remembers.
     *
     * @param string $conversationId Conversation identifier.
     * @return void
     * @throws \InvalidArgumentException When the identifier is empty.
     */
    public function useConversation(string $conversationId): void
    {
        if ($conversationId === '') {
            throw new InvalidArgumentException('A conversation identifier must not be empty.');
        }

        $this->conversationId = $conversationId;

        if ($this->agent instanceof RemembersConversations) {
            $this->agent = $this->agent->continue($conversationId, $this->participant);
        }
    }

    /**
     * Starts a fresh conversation, selects it and continues the agent when it remembers.
     *
     * @param string|null $title Conversation title.
     * @return string New conversation identifier.
     */
    public function startConversation(?string $title = null): string
    {
        $this->startedConversations++;
        $this->conversationId = 'conversation-' . $this->startedConversations;

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
     * Returns the driver conversation store stub.
     *
     * @return \Crustum\Ai\Contracts\ConversationStore&\Crustum\Ai\Contracts\VerifiesConversationOwnership Driver conversation store stub.
     */
    public function conversationStore(): ConversationStore&VerifiesConversationOwnership
    {
        return $this->conversations;
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
     * Returns staged conversation summaries.
     *
     * @return list<\Crustum\Ai\Model\Entity\Conversation> Staged conversation summaries.
     */
    public function conversationSummaries(): array
    {
        return $this->conversationSummaries;
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
}
