<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Feature;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Model\Table\ConversationsTable;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\AiInteraction\Command\AiChatCommand;
use Crustum\AiInteraction\Test\Feature\Support\StubChatAgent;
use PHPUnit\Framework\TestCase;
use function implode;

/**
 * Feature coverage for the host chat command.
 */
final class AiChatCommandTest extends TestCase
{
    public function testChatAnswersPromptsAndRunsSlashCommands(): void
    {
        $output = new StubConsoleOutput();
        $io = new ConsoleIo($output, new StubConsoleOutput());
        $command = new CannedAiChatCommand();
        $command->lines = ['hello', '/help', '/clear', '/exit'];

        $code = $command->run(
            ['ai agent', '--agent', StubChatAgent::class, '--user', '01933c1e-8f5c-7c0d-9a1b-2d3e4f5a6b7c'],
            $io,
        );

        self::assertSame(AiChatCommand::CODE_SUCCESS, $code);
        $messages = $output->messages();
        self::assertContains('Type /help for commands, /exit to stop.', $messages);
        self::assertContains('Canned answer.', $messages);
        self::assertContains('/help — Lists what can be typed here.', $messages);
    }

    public function testChatEndsOnEndOfInput(): void
    {
        $output = new StubConsoleOutput();
        $io = new ConsoleIo($output, new StubConsoleOutput());
        $command = new CannedAiChatCommand();
        $command->lines = [];

        $code = $command->run(
            ['ai agent', '--agent', StubChatAgent::class, '--user', '01933c1e-8f5c-7c0d-9a1b-2d3e4f5a6b7c'],
            $io,
        );

        self::assertSame(AiChatCommand::CODE_SUCCESS, $code);
        self::assertContains('Type /help for commands, /exit to stop.', $output->messages());
    }

    public function testChatRequiresAnAgent(): void
    {
        $error = new StubConsoleOutput();
        $io = new ConsoleIo(new StubConsoleOutput(), $error);
        $command = new CannedAiChatCommand();
        $command->lines = [];

        $code = $command->run(['ai agent'], $io);

        self::assertSame(AiChatCommand::CODE_ERROR, $code);
        self::assertStringContainsString('agent', implode("\n", $error->messages()));
    }

    public function testChatRequiresAParticipant(): void
    {
        $error = new StubConsoleOutput();
        $io = new ConsoleIo(new StubConsoleOutput(), $error);
        $command = new CannedAiChatCommand();
        $command->lines = [];

        $code = $command->run(['ai agent', '--agent', StubChatAgent::class], $io);

        self::assertSame(AiChatCommand::CODE_ERROR, $code);
        self::assertStringContainsString('--user', implode("\n", $error->messages()));
    }

    public function testChatRejectsANonUuidParticipant(): void
    {
        $error = new StubConsoleOutput();
        $io = new ConsoleIo(new StubConsoleOutput(), $error);
        $command = new CannedAiChatCommand();
        $command->lines = [];

        $code = $command->run(['ai agent', '--agent', StubChatAgent::class, '--user', 'console'], $io);

        self::assertSame(AiChatCommand::CODE_ERROR, $code);
        self::assertStringContainsString('uuid', implode("\n", $error->messages()));
    }

    public function testChatTitlesConversationWhenEnabled(): void
    {
        $userId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $store = new DatabaseConversationStore();
        $conversationId = $store->storeConversation('console', $userId, 'New conversation');
        $store->storeUserMessage($conversationId, 'console', $userId, StubChatAgent::class, new UserMessage('Seeded topic'));

        $titles = $this->createMock(Agent::class);
        $titles->expects(self::once())->method('prompt')->willReturn(
            new AgentResponse('invocation', '{"title":"Seeded topic"}', new TextUsage(), new Meta()),
        );

        $output = new StubConsoleOutput();
        $io = new ConsoleIo($output, new StubConsoleOutput());
        $command = new CannedAiChatCommand();
        $command->lines = ['hello', '/exit'];
        $command->titles = $titles;

        $code = $command->run(
            ['ai agent', '--agent', StubChatAgent::class, '--user', $userId, '--titles'],
            $io,
        );

        self::assertSame(AiChatCommand::CODE_SUCCESS, $code);
        self::assertSame($conversationId, $store->latestConversationId('console', $userId, StubChatAgent::class));
        self::assertSame('Seeded topic', (new ConversationsTable())->get($conversationId)->title);
    }

    public function testChatSkipsNamingWhenDisabled(): void
    {
        $userId = 'ffffffff-0000-4111-8222-333333333333';
        $store = new DatabaseConversationStore();
        $conversationId = $store->storeConversation('console', $userId, 'New conversation');
        $store->storeUserMessage($conversationId, 'console', $userId, StubChatAgent::class, new UserMessage('Seeded topic'));

        $titles = $this->createMock(Agent::class);
        $titles->expects(self::never())->method('prompt');

        $output = new StubConsoleOutput();
        $io = new ConsoleIo($output, new StubConsoleOutput());
        $command = new CannedAiChatCommand();
        $command->lines = ['hello', '/exit'];
        $command->titles = $titles;

        $code = $command->run(
            ['ai agent', '--agent', StubChatAgent::class, '--user', $userId],
            $io,
        );

        self::assertSame(AiChatCommand::CODE_SUCCESS, $code);
    }
}
