<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Session;

use Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface;
use function is_string;

/**
 * Owner of the current conversation identifier.
 *
 * Applies one identifier to the adapter and the answering agent together, so
 * hosts never bind them separately.
 */
final class Session
{
    /**
     * Active adapter holding the participant and the selection.
     *
     * @var \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed>
     */
    private CommandAdapterInterface $adapter;

    /**
     * Creates a session over an adapter.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter holding the participant and the selection.
     */
    public function __construct(CommandAdapterInterface $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Boots the session: rebinds the selected conversation, continues the
     * latest one, or starts a fresh conversation — always on both sides.
     *
     * The scoped latest lookup can miss conversations whose messages were
     * stored under another participant type derivation, so a miss falls back
     * to the newest populated summary instead of starting another row.
     *
     * @return string Current conversation identifier.
     */
    public function boot(): string
    {
        $selected = $this->adapter->conversationId();

        if ($selected !== null) {
            $this->adapter->useConversation($selected);

            return $selected;
        }

        $participantType = $this->adapter->participantType();
        $participantId = $this->adapter->participantId();

        if ($participantType !== null && $participantId !== null) {
            $latest = $this->adapter->conversationStore()->latestConversationId(
                $participantType,
                $participantId,
                $this->adapter->agent()::class,
            );

            if ($latest !== null) {
                $this->adapter->useConversation($latest);

                return $latest;
            }

            $summaries = $this->adapter->conversationSummaries();
            $fallback = $summaries[0]->id ?? null;

            if (is_string($fallback) && $fallback !== '') {
                $this->adapter->useConversation($fallback);

                return $fallback;
            }
        }

        return $this->adapter->startConversation();
    }

    /**
     * Returns the current conversation identifier.
     *
     * @return string|null Current conversation identifier or null.
     */
    public function id(): ?string
    {
        return $this->adapter->conversationId();
    }

    /**
     * Starts a fresh conversation on both sides.
     *
     * @return string New conversation identifier.
     */
    public function clear(): string
    {
        return $this->adapter->startConversation();
    }

    /**
     * Resumes a stored conversation on both sides.
     *
     * @param string $conversationId Conversation identifier.
     * @return void
     * @throws \InvalidArgumentException When the conversation does not belong to the adapter participant.
     */
    public function resume(string $conversationId): void
    {
        $this->adapter->useConversation($conversationId);
    }
}
