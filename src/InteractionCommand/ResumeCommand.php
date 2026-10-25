<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

use Crustum\Ai\Contracts\RemembersConversations;
use Crustum\Ai\Model\Entity\Conversation;

/**
 * Offers the stored conversations so a person can resume one.
 *
 * A host application mounts it under `resume` or a name of its own.
 * A list with nothing in it is not worth entering, so it is said in the
 * conversation instead. The conversations become selection options here.
 */
final readonly class ResumeCommand implements CommandInterface
{
    /**
     * Presentation-neutral identifier.
     *
     * @var string
     */
    private string $name;

    /**
     * Creates a resume command.
     *
     * @param string $name Presentation-neutral identifier.
     */
    public function __construct(string $name = '/resume')
    {
        $this->name = $name;
    }

    /**
     * Returns the slash-prefixed identifier it answers to.
     *
     * @return string Slash-prefixed command name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Returns one line for a listing of what can be typed here.
     *
     * @return string Command description.
     */
    public function describe(): string
    {
        return 'Lets you choose a stored conversation to resume.';
    }

    /**
     * Resumes the keyed conversation or requests a conversation selection.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
     * @param string $value Raw text supplied by the adapter, preserved by dispatch.
     * @return void
     */
    public function run(CommandAdapterInterface $adapter, string $value): void
    {
        if ($value !== '') {
            $this->resumeByKey($adapter, $value);

            return;
        }

        $summaries = $adapter->conversationSummaries();

        if ($summaries === []) {
            $adapter->warn('There is no earlier conversation to return to yet.');

            return;
        }

        $options = [];

        foreach ($summaries as $conversation) {
            $options[] = new SelectionOption(
                $conversation->id,
                $this->label($adapter, $conversation),
                $this->describeAge($conversation),
            );
        }

        $adapter->requestSelection(new Selection($this->name(), 'Conversations', $options));
    }

    /**
     * Continues the keyed conversation after an ownership check.
     *
     * Selection also continues the answering agent through the adapter.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
     * @param string $key Conversation identifier.
     * @return void
     */
    private function resumeByKey(CommandAdapterInterface $adapter, string $key): void
    {
        if (
            !$adapter->conversationStore()->conversationBelongsTo(
                $key,
                $adapter->participantType(),
                $adapter->participantId(),
            )
        ) {
            $adapter->error('No conversation is named by that key.');

            return;
        }

        if (!$adapter->agent() instanceof RemembersConversations) {
            $adapter->error('The current agent cannot continue conversations.');

            return;
        }

        $adapter->useConversation($key);
    }

    /**
     * Resolves the display label, preferring stored titles.
     *
     * Untitled conversations show their opening user message so every option
     * stays recognizable.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
     * @param \Crustum\Ai\Model\Entity\Conversation $conversation Conversation summary.
     * @return string Display label.
     */
    private function label(CommandAdapterInterface $adapter, Conversation $conversation): string
    {
        $title = $conversation->title;

        if ($title !== '' && $title !== RenamesConversations::DEFAULT_TITLE) {
            return $title;
        }

        if ($adapter instanceof CakeCommandAdapter) {
            return $adapter->conversationExcerpt($conversation->id) ?? RenamesConversations::DEFAULT_TITLE;
        }

        return $title === '' ? RenamesConversations::DEFAULT_TITLE : $title;
    }

    /**
     * Describes a conversation age for selection display.
     *
     * @param \Crustum\Ai\Model\Entity\Conversation $conversation Conversation summary.
     * @return string|null Relative age or null when unknown.
     */
    private function describeAge(Conversation $conversation): ?string
    {
        $modified = $conversation->modified;

        if ($modified === null) {
            return null;
        }

        return $modified->timeAgoInWords();
    }
}
