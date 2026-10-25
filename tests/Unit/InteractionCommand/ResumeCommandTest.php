<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InteractionCommand;

use Cake\I18n\DateTime;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\RemembersConversations;
use Crustum\Ai\Model\Entity\Conversation;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\ResumeCommand;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the resume command.
 */
final class ResumeCommandTest extends TestCase
{
    public function testResumeByKeyContinuesTheOwnedConversation(): void
    {
        $participant = (object)['id' => 'user-1'];
        $continued = [];
        $agent = $this->rememberingAgent($continued);
        $adapter = new RecordingCommandAdapter(
            agent: $agent,
            participantType: 'users',
            participantId: 'user-1',
            participant: $participant,
        );
        $conversationId = $adapter->conversationStore()->storeConversation('users', 'user-1', 'First');
        $commands = (new Commands())->addCommand([new ResumeCommand()]);

        $execution = $commands->run('/resume', $conversationId, $adapter);

        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertSame([$conversationId, $participant], $continued);
        self::assertSame($conversationId, $adapter->conversationId());
        self::assertSame([], $adapter->errors);
        self::assertSame([], $adapter->selections);
    }

    public function testResumeByUnknownKeyErrors(): void
    {
        $continued = [];
        $adapter = new RecordingCommandAdapter(
            agent: $this->rememberingAgent($continued),
            participantType: 'users',
            participantId: 'user-1',
            participant: (object)['id' => 'user-1'],
        );
        $commands = (new Commands())->addCommand([new ResumeCommand()]);

        $execution = $commands->run('/resume', 'missing', $adapter);

        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertSame(['No conversation is named by that key.'], $adapter->errors);
        self::assertNull($adapter->conversationId());
        self::assertSame([], $continued);
    }

    public function testResumeByForeignKeyErrors(): void
    {
        $continued = [];
        $adapter = new RecordingCommandAdapter(
            agent: $this->rememberingAgent($continued),
            participantType: 'users',
            participantId: 'user-1',
            participant: (object)['id' => 'user-1'],
        );
        $foreignId = $adapter->conversationStore()->storeConversation('users', 'user-2', 'Foreign');
        $commands = (new Commands())->addCommand([new ResumeCommand()]);

        $execution = $commands->run('/resume', $foreignId, $adapter);

        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertSame(['No conversation is named by that key.'], $adapter->errors);
        self::assertNull($adapter->conversationId());
        self::assertSame([], $continued);
    }

    public function testResumeWithoutConversationsWarns(): void
    {
        $continued = [];
        $adapter = new RecordingCommandAdapter(
            agent: $this->rememberingAgent($continued),
            participantType: 'users',
            participantId: 'user-1',
            participant: (object)['id' => 'user-1'],
        );
        $commands = (new Commands())->addCommand([new ResumeCommand()]);

        $execution = $commands->run('/resume', '', $adapter);

        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertSame(['There is no earlier conversation to return to yet.'], $adapter->warnings);
        self::assertSame([], $adapter->selections);
        self::assertSame([], $continued);
    }

    public function testResumeWithoutKeyRequestsASelection(): void
    {
        $continued = [];
        $adapter = new RecordingCommandAdapter(
            agent: $this->rememberingAgent($continued),
            participantType: 'users',
            participantId: 'user-1',
            participant: (object)['id' => 'user-1'],
        );
        $adapter->conversationSummaries = [
            new Conversation(['id' => 'first', 'title' => 'First', 'modified' => new DateTime('-1 day')]),
            new Conversation(['id' => 'second', 'title' => '', 'modified' => null]),
        ];
        $commands = (new Commands())->addCommand([new ResumeCommand('/return')]);

        $execution = $commands->run('/return', '', $adapter);

        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertCount(1, $adapter->selections);
        $request = $adapter->selections[0];
        self::assertSame('/return', $request->command);
        self::assertSame('Conversations', $request->prompt);
        self::assertSame(['first', 'second'], [$request->options[0]->value, $request->options[1]->value]);
        self::assertSame(['First', 'New conversation'], [$request->options[0]->label, $request->options[1]->label]);
        self::assertIsString($request->options[0]->description);
        self::assertNull($request->options[1]->description);
        self::assertSame([], $continued);
    }

    public function testResumeRequiresARememberingAgent(): void
    {
        $agent = $this->createStub(Agent::class);
        $adapter = new RecordingCommandAdapter(
            agent: $agent,
            participantType: 'users',
            participantId: 'user-1',
            participant: (object)['id' => 'user-1'],
        );
        $conversationId = $adapter->conversationStore()->storeConversation('users', 'user-1', 'First');
        $commands = (new Commands())->addCommand([new ResumeCommand()]);

        $execution = $commands->run('/resume', $conversationId, $adapter);

        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertSame(['The current agent cannot continue conversations.'], $adapter->errors);
        self::assertNull($adapter->conversationId());
    }

    /**
     * Builds an agent recording continue calls.
     *
     * @param list<mixed> $continued Recorded continue arguments.
     * @return \Crustum\Ai\Contracts\Agent&\Crustum\Ai\Contracts\RemembersConversations Recording agent.
     */
    private function rememberingAgent(array &$continued): Agent&RemembersConversations
    {
        $agent = $this->createStubForIntersectionOfInterfaces([Agent::class, RemembersConversations::class]);
        $agent->method('continue')->willReturnCallback(
            static function (string $conversationId, ?object $as = null) use ($agent, &$continued): Agent&RemembersConversations {
                $continued = [$conversationId, $as];

                return $agent;
            },
        );

        return $agent;
    }
}
