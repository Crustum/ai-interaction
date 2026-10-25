<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InteractionCommand;

use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\AiInteraction\Agent\SessionTitleAgent;
use Crustum\AiInteraction\InteractionCommand\ConversationMessages;
use PHPUnit\Framework\TestCase;
use function bin2hex;
use function iterator_to_array;
use function random_bytes;

/**
 * Unit coverage for the conversation message provider.
 */
final class ConversationMessagesTest extends TestCase
{
    /**
     * Participant identifier isolating the active test.
     *
     * @var string
     */
    private string $participantId = '';

    /**
     * Creates an isolated participant scope.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->participantId = 'history-' . bin2hex(random_bytes(4));
    }

    public function testMessagesReadLatestConversationMessages(): void
    {
        $store = new DatabaseConversationStore();
        $conversationId = $store->storeConversation('users', $this->participantId, 'History');
        $store->storeUserMessage(
            $conversationId,
            'users',
            $this->participantId,
            SessionTitleAgent::class,
            new UserMessage('First'),
        );
        $store->storeUserMessage(
            $conversationId,
            'users',
            $this->participantId,
            SessionTitleAgent::class,
            new UserMessage('Second'),
        );

        $messages = iterator_to_array(
            (new ConversationMessages($store, $conversationId))->messages(),
            false,
        );

        self::assertCount(2, $messages);
        self::assertSame('First', $messages[0]->content);
        self::assertSame('Second', $messages[1]->content);
    }

    public function testMessagesRespectTheLimit(): void
    {
        $store = new DatabaseConversationStore();
        $conversationId = $store->storeConversation('users', $this->participantId, 'History');
        $store->storeUserMessage(
            $conversationId,
            'users',
            $this->participantId,
            SessionTitleAgent::class,
            new UserMessage('First'),
        );
        $store->storeUserMessage(
            $conversationId,
            'users',
            $this->participantId,
            SessionTitleAgent::class,
            new UserMessage('Second'),
        );

        $messages = iterator_to_array(
            (new ConversationMessages($store, $conversationId, 1))->messages(),
            false,
        );

        self::assertCount(1, $messages);
    }

    public function testMissingConversationReadsEmpty(): void
    {
        $messages = iterator_to_array(
            (new ConversationMessages(new DatabaseConversationStore(), 'missing', 10))->messages(),
            false,
        );

        self::assertSame([], $messages);
    }
}
