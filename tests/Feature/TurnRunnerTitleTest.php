<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Feature;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\AiInteraction\Configuration\ConfigurationStore;
use Crustum\AiInteraction\InputHistory\InputHistory;
use Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter;
use Crustum\AiInteraction\InteractionCommand\CommandInterceptor;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\RenamesConversations;
use Crustum\AiInteraction\InteractionCommand\TurnRunner;
use Crustum\AiInteraction\Interruption\StopSignal;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use function bin2hex;
use function iterator_to_array;
use function random_bytes;
use function time;

/**
 * Feature coverage for the optional conversation title hook.
 */
final class TurnRunnerTitleTest extends TestCase
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
        $this->participantId = 'title-' . bin2hex(random_bytes(4));
    }

    public function testUntitledConversationIsNamedAfterSuccessfulTurn(): void
    {
        $titles = $this->createMock(Agent::class);
        $titles->expects(self::once())->method('prompt')->willReturn(
            new AgentResponse('invocation', '{"title":"Orders migration"}', new TextUsage(), new Meta()),
        );
        $adapter = $this->adapter();
        $conversationId = $adapter->startConversation();
        $this->seedMessage($adapter, $conversationId, 'Migrate the orders table');

        $runner = $this->runner($adapter, $titles);
        iterator_to_array($runner->run('Migrate the orders table'));

        self::assertSame('Orders migration', $adapter->conversationTitle($conversationId));
    }

    public function testNamingStaysDisabledWithoutTitleAgent(): void
    {
        $titles = $this->createMock(Agent::class);
        $titles->expects(self::never())->method('prompt');
        $adapter = $this->adapter();
        $conversationId = $adapter->startConversation();
        $this->seedMessage($adapter, $conversationId, 'Migrate the orders table');

        $runner = $this->runner($adapter);
        iterator_to_array($runner->run('Migrate the orders table'));

        self::assertSame(RenamesConversations::DEFAULT_TITLE, $adapter->conversationTitle($conversationId));
    }

    public function testTitledConversationKeepsItsTitle(): void
    {
        $titles = $this->createMock(Agent::class);
        $titles->expects(self::never())->method('prompt');
        $adapter = $this->adapter();
        $conversationId = $adapter->startConversation();
        $this->seedMessage($adapter, $conversationId, 'Migrate the orders table');
        $adapter->renameConversation($conversationId, 'Kept title');

        $runner = $this->runner($adapter, $titles);
        iterator_to_array($runner->run('Migrate the orders table'));

        self::assertSame('Kept title', $adapter->conversationTitle($conversationId));
    }

    public function testStoppedTurnSkipsNaming(): void
    {
        $titles = $this->createMock(Agent::class);
        $titles->expects(self::never())->method('prompt');
        $signal = new StopSignal(new InMemoryStorage(), 'title-test');
        $agent = $this->createStub(Agent::class);
        $agent->method('stream')->willReturn(
            new StreamableAgentResponse('invocation', static function () use ($signal): iterable {
                $signal->request();
                yield new TextDelta('event', 'message', 'Hello.', time());
            }, new Meta()),
        );
        $adapter = $this->adapter();
        $conversationId = $adapter->startConversation();
        $this->seedMessage($adapter, $conversationId, 'Migrate the orders table');

        $runner = new TurnRunner(
            new CommandInterceptor(new Commands()),
            $adapter,
            $agent,
            new InputHistory(new InMemoryStorage()),
            $signal,
            null,
            $titles,
        );
        iterator_to_array($runner->run('Migrate the orders table'));

        self::assertSame(RenamesConversations::DEFAULT_TITLE, $adapter->conversationTitle($conversationId));
    }

    /**
     * Builds an adapter with a stub agent.
     *
     * @return \Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter Host adapter.
     */
    private function adapter(): CakeCommandAdapter
    {
        $agent = $this->createStub(Agent::class);

        return new CakeCommandAdapter(
            $agent,
            new Commands(),
            new DatabaseConversationStore(),
            static function (): void {
            },
            new ConfigurationStore(new InMemoryStorage(), $this->participantId),
            'users',
            $this->participantId,
            (object)['id' => $this->participantId],
        );
    }

    /**
     * Builds a turn runner over the given adapter.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter $adapter Host adapter.
     * @param \Crustum\Ai\Contracts\Agent|null $titles Title agent or null when disabled.
     * @return \Crustum\AiInteraction\InteractionCommand\TurnRunner Turn runner.
     */
    private function runner(CakeCommandAdapter $adapter, ?Agent $titles = null): TurnRunner
    {
        $agent = $this->createStub(Agent::class);
        $agent->method('stream')->willReturn(
            new StreamableAgentResponse('invocation', static function (): iterable {
                yield new TextDelta('event', 'message', 'Hello.', time());
            }, new Meta()),
        );

        return new TurnRunner(
            new CommandInterceptor(new Commands()),
            $adapter,
            $agent,
            new InputHistory(new InMemoryStorage()),
            new StopSignal(new InMemoryStorage(), 'title-test'),
            null,
            $titles,
        );
    }

    /**
     * Seeds one user message into a conversation.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter $adapter Host adapter.
     * @param string $conversationId Conversation identifier.
     * @param string $text Message text.
     * @return void
     */
    private function seedMessage(CakeCommandAdapter $adapter, string $conversationId, string $text): void
    {
        $adapter->conversationStore()->storeUserMessage(
            $conversationId,
            'users',
            $this->participantId,
            Agent::class,
            new UserMessage($text),
        );
    }
}
