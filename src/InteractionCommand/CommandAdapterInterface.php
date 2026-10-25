<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\ConversationStore;
use Crustum\Ai\Contracts\VerifiesConversationOwnership;
use Crustum\Ai\Messages\UserMessage;
use Crustum\AiInteraction\Configuration\ConfigurationStoreInterface;

/**
 * Presentation-independent operations and invocation lifecycle supplied by an adapter.
 *
 * @template-covariant TOutput
 */
interface CommandAdapterInterface
{
    /**
     * Decides whether a command may run.
     *
     * Refusal returns control to the caller without dispatch or completion.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandInterface $command Command asking for admission.
     * @return bool True when the command may run.
     */
    public function admit(CommandInterface $command): bool;

    /**
     * Receives the technical dispatch outcome.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandExecution $execution Dispatch outcome.
     * @return mixed Template output.
     */
    public function afterExecution(CommandExecution $execution): mixed;

    /**
     * Communicates information to the person through this adapter.
     *
     * @param string $text Information text.
     * @return void
     */
    public function notify(string $text): void;

    /**
     * Communicates a warning to the person through this adapter.
     *
     * @param string $text Warning text.
     * @return void
     */
    public function warn(string $text): void;

    /**
     * Communicates an expected failure to the person.
     *
     * This does not interrupt the command or change execution status.
     *
     * @param string $text Failure text.
     * @return void
     */
    public function error(string $text): void;

    /**
     * Submits a prompt to the adapter agent flow without receiving its answer.
     *
     * @param \Crustum\Ai\Messages\UserMessage $prompt Generated agent prompt.
     * @return void
     */
    public function promptAgent(UserMessage $prompt): void;

    /**
     * Requests a later invocation with the chosen value, then returns immediately.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\Selection $request Pending selection request.
     * @return void
     */
    public function requestSelection(Selection $request): void;

    /**
     * Requests the host loop to run task text as the next turn input, then returns immediately.
     *
     * @param string $text Task text for the host loop to run as the next turn input.
     * @param string|null $origin Task origin label shown instead of the text, null runs silently.
     * @return void
     */
    public function requestTask(string $text, ?string $origin = null): void;

    /**
     * Returns the answering agent.
     *
     * @return \Crustum\Ai\Contracts\Agent Answering agent.
     */
    public function agent(): Agent;

    /**
     * Replaces the answering agent within the current conversation.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Replacement agent.
     * @return void
     */
    public function useAgent(Agent $agent): void;

    /**
     * Returns the selected conversation identifier.
     *
     * @return string|null Selected conversation identifier or null when none is selected.
     */
    public function conversationId(): ?string;

    /**
     * Selects a conversation owned by the adapter participant.
     *
     * The answering agent continues the selected conversation when it remembers.
     *
     * @param string $conversationId Conversation identifier.
     * @return void
     * @throws \InvalidArgumentException When the conversation does not belong to the adapter participant.
     */
    public function useConversation(string $conversationId): void;

    /**
     * Starts a fresh conversation for the adapter participant and selects it.
     *
     * The replaced conversation row is kept where it is stored. The answering
     * agent continues the new conversation when it remembers.
     *
     * @param string|null $title Conversation title.
     * @return string New conversation identifier.
     */
    public function startConversation(?string $title = null): string;

    /**
     * Returns mounted commands in mounting order.
     *
     * @return \Crustum\AiInteraction\InteractionCommand\Commands Mounted commands.
     */
    public function commands(): Commands;

    /**
     * Returns the driver conversation store.
     *
     * @return \Crustum\Ai\Contracts\ConversationStore&\Crustum\Ai\Contracts\VerifiesConversationOwnership Driver conversation store.
     */
    public function conversationStore(): ConversationStore&VerifiesConversationOwnership;

    /**
     * Returns the adapter participant type.
     *
     * @return string|null Participant type or null for anonymous interactions.
     */
    public function participantType(): ?string;

    /**
     * Returns the adapter participant identifier.
     *
     * @return string|int|null Participant identifier or null for anonymous interactions.
     */
    public function participantId(): string|int|null;

    /**
     * Returns the adapter participant.
     *
     * @return object|null Participant object or null for anonymous interactions.
     */
    public function participant(): ?object;

    /**
     * Lists participant conversations, newest first.
     *
     * @return list<\Crustum\Ai\Model\Entity\Conversation> Participant conversations, newest first.
     */
    public function conversationSummaries(): array;

    /**
     * Returns the participant preference store.
     *
     * @return \Crustum\AiInteraction\Configuration\ConfigurationStoreInterface Participant preference store.
     */
    public function configurationStore(): ConfigurationStoreInterface;

    /**
     * Stops the active interaction through this adapter.
     *
     * @return void
     */
    public function stop(): void;
}
