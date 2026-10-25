<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Feature;

use Cake\I18n\DateTime;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Model\Table\ConversationsTable;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\AiInteraction\Configuration\ConfigurationStore;
use Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter;
use Crustum\AiInteraction\InteractionCommand\ClearCommand;
use Crustum\AiInteraction\InteractionCommand\CommandExecution;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\HelpCommand;
use Crustum\AiInteraction\InteractionCommand\LeaveCommand;
use Crustum\AiInteraction\InteractionCommand\RenamesConversations;
use Crustum\AiInteraction\InteractionCommand\ResumeCommand;
use Crustum\AiInteraction\InteractionCommand\Selection;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use function bin2hex;
use function mb_strlen;
use function random_bytes;

/**
 * Feature coverage for the host command adapter.
 */
final class CakeCommandAdapterTest extends TestCase
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
        $this->participantId = 'host-' . bin2hex(random_bytes(4));
    }

    /**
     * Builds an adapter with a stub agent.
     *
     * @param list<string> $prompts Collected prompt texts.
     * @return \Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter Host adapter.
     */
    private function adapter(array &$prompts): CakeCommandAdapter
    {
        $agent = $this->createStub(Agent::class);
        $agent->method('prompt')->willReturnCallback(
            static function (UserMessage $message) use (&$prompts): AgentResponse {
                $prompts[] = (string)$message->content;

                return new AgentResponse('invocation', 'answer', new TextUsage(), new Meta());
            },
        );

        return new CakeCommandAdapter(
            $agent,
            (new Commands())->addCommand([new ClearCommand(), new HelpCommand(), new LeaveCommand()]),
            new DatabaseConversationStore(),
            static function (Agent $agent, UserMessage $prompt) use (&$prompts): void {
                $prompts[] = (string)$prompt->content;
                $agent->prompt($prompt);
            },
            new ConfigurationStore(new InMemoryStorage(), $this->participantId),
            'users',
            $this->participantId,
            (object)['id' => $this->participantId],
        );
    }

    public function testHelpListsCommandsAndClearStartsAFreshConversation(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);

        $help = $adapter->commands()->run('/help', '', $adapter);

        self::assertSame('completed', $help['status']);
        self::assertSame([
            '/clear — Starts a new conversation, leaving the current one stored.',
            '/help — Lists what can be typed here.',
            '/exit — Stops the interaction.',
        ], $help['notices']);
        self::assertNull($help['selection']);
        self::assertFalse($help['stopped']);
        self::assertNull($help['conversationId']);

        $clear = $adapter->commands()->run('/clear', '', $adapter);

        self::assertSame('completed', $clear['status']);
        self::assertNotNull($clear['conversationId']);
        self::assertSame($clear['conversationId'], $adapter->conversationId());
        self::assertTrue(
            $adapter->conversationStore()->conversationBelongsTo(
                (string)$clear['conversationId'],
                'users',
                $this->participantId,
            ),
        );
        self::assertSame([], $prompts);
    }

    public function testLeaveStopsTheInteraction(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);

        $response = $adapter->commands()->run('/exit', '', $adapter);

        self::assertSame('completed', $response['status']);
        self::assertTrue($response['stopped']);
    }

    public function testUseConversationRejectsForeignConversations(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);
        $foreignId = $adapter->conversationStore()->storeConversation('users', 'other-user', 'Foreign');

        $this->expectExceptionMessage('The selected conversation does not belong to this participant.');

        $adapter->useConversation($foreignId);
    }

    public function testConversationSummariesListOwnedConversationsNewestFirst(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);
        $store = $adapter->conversationStore();
        $firstId = $store->storeConversation('users', $this->participantId, 'First');
        $secondId = $store->storeConversation('users', $this->participantId, 'Second');
        $store->storeConversation('users', 'other-user', 'Foreign');
        $store->storeConversation('users', $this->participantId, 'Empty');
        $store->storeUserMessage($firstId, 'users', $this->participantId, Agent::class, new UserMessage('First question'));
        $store->storeUserMessage($secondId, 'users', $this->participantId, Agent::class, new UserMessage('Second question'));

        $conversations = new ConversationsTable();
        $second = $conversations->get($secondId);
        $second->modified = new DateTime('+1 day');

        $conversations->saveOrFail($second);

        $summaries = $adapter->conversationSummaries();

        self::assertCount(2, $summaries);
        self::assertSame('Second', $summaries[0]->title);
        self::assertSame('First', $summaries[1]->title);
    }

    public function testConversationExcerptUsesOpeningUserMessage(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);
        $store = $adapter->conversationStore();
        $conversationId = $store->storeConversation('users', $this->participantId, RenamesConversations::DEFAULT_TITLE);
        $store->storeUserMessage(
            $conversationId,
            'users',
            $this->participantId,
            Agent::class,
            new UserMessage('How do I bake a migration for the orders table in CakePHP?'),
        );
        $emptyId = $store->storeConversation('users', $this->participantId, RenamesConversations::DEFAULT_TITLE);

        self::assertSame(
            'How do I bake a migration for the orders table in CakePHP?',
            $adapter->conversationExcerpt($conversationId),
        );
        self::assertNull($adapter->conversationExcerpt($emptyId));

        $longId = $store->storeConversation('users', $this->participantId, RenamesConversations::DEFAULT_TITLE);
        $store->storeUserMessage(
            $longId,
            'users',
            $this->participantId,
            Agent::class,
            new UserMessage('Please explain in detail how database migrations work in CakePHP applications step by step.'),
        );

        $excerpt = $adapter->conversationExcerpt($longId);

        self::assertIsString($excerpt);
        self::assertSame(60, mb_strlen($excerpt));
        self::assertStringEndsWith('…', $excerpt);
    }

    public function testResumeLabelsUntitledConversationsWithExcerpts(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);
        $store = $adapter->conversationStore();
        $conversationId = $store->storeConversation('users', $this->participantId, RenamesConversations::DEFAULT_TITLE);
        $store->storeUserMessage(
            $conversationId,
            'users',
            $this->participantId,
            Agent::class,
            new UserMessage('Migrate the orders table'),
        );

        $output = (new Commands())->addCommand([new ResumeCommand()])->run('/resume', '', $adapter);
        $selection = $output['selection'];

        self::assertInstanceOf(Selection::class, $selection);
        self::assertCount(1, $selection->options);
        self::assertSame($conversationId, $selection->options[0]->value);
        self::assertSame('Migrate the orders table', $selection->options[0]->label);
    }

    public function testRenameConversationUpdatesOwnedTitles(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);
        $conversationId = $adapter->conversationStore()->storeConversation('users', $this->participantId, 'Draft');

        $adapter->renameConversation($conversationId, 'Final title');

        self::assertSame('Final title', $adapter->conversationTitle($conversationId));
        self::assertNull($adapter->conversationTitle('missing'));
    }

    public function testRenameConversationRejectsForeignConversations(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);
        $foreignId = $adapter->conversationStore()->storeConversation('users', 'other-user', 'Foreign');

        $this->expectExceptionMessage('The selected conversation does not belong to this participant.');

        $adapter->renameConversation($foreignId, 'Taken');
    }

    public function testConversationSummariesIncludeCrossTypeMessages(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);
        $store = $adapter->conversationStore();
        $conversationId = $store->storeConversation('users', $this->participantId, 'Cross');
        $store->storeUserMessage($conversationId, 'stdClass', $this->participantId, Agent::class, new UserMessage('Hi'));

        $summaries = $adapter->conversationSummaries();

        self::assertCount(1, $summaries);
        self::assertSame($conversationId, $summaries[0]->id);
    }

    public function testPromptAgentSubmitsThroughTheHostCallback(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);
        $adapter->promptAgent(new UserMessage('Generated prompt.'));

        self::assertSame(['Generated prompt.', 'Generated prompt.'], $prompts);
    }

    public function testRequestedTaskSurfacesInHostResponseData(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);
        $adapter->requestTask('first');
        $adapter->requestTask('second');

        $response = $adapter->afterExecution(CommandExecution::completed('/task'));

        self::assertSame('second', $response['task']);
        self::assertNull($response['origin']);
    }

    public function testRequestedTaskOriginSurfacesInHostResponseData(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);
        $adapter->requestTask('do the thing', '/task');

        $response = $adapter->afterExecution(CommandExecution::completed('/task'));

        self::assertSame('do the thing', $response['task']);
        self::assertSame('/task', $response['origin']);
    }

    public function testUnknownIdentifierCompletesWithoutAdmission(): void
    {
        $prompts = [];
        $adapter = $this->adapter($prompts);

        $response = $adapter->commands()->run('/missing', '', $adapter);

        self::assertSame('unknown', $response['status']);
        self::assertSame('/missing', $response['identifier']);
        self::assertNull($response['error']);
    }
}
