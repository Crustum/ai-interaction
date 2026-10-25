<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\Session;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\RemembersConversations;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\AiInteraction\Configuration\ConfigurationStore;
use Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\Session\Session;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use function bin2hex;
use function random_bytes;

/**
 * Unit coverage for the session binding.
 */
final class SessionTest extends TestCase
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
        $this->participantId = 'user-' . bin2hex(random_bytes(4));
    }

    public function testBootStartsAFreshConversationOnBothSides(): void
    {
        $continued = [];
        [$adapter, $agent] = $this->adapter($continued);
        $session = new Session($adapter);

        $id = $session->boot();

        self::assertNotSame('', $id);
        self::assertSame($id, $session->id());
        self::assertSame($id, $adapter->conversationId());
        self::assertSame([$id, $adapter->participant()], $continued);
        self::assertSame($agent, $adapter->agent());
    }

    public function testBootContinuesTheLatestConversation(): void
    {
        $continued = [];
        [$adapter, $agent] = $this->adapter($continued);
        $agentClass = $agent::class;
        $store = $adapter->conversationStore();
        $first = $store->storeConversation('users', $this->participantId, 'First');
        $store->storeUserMessage($first, 'users', $this->participantId, $agentClass, new UserMessage('First'));
        $latest = $store->storeConversation('users', $this->participantId, 'Second');
        $store->storeUserMessage($latest, 'users', $this->participantId, $agentClass, new UserMessage('Second'));
        $session = new Session($adapter);

        $id = $session->boot();

        self::assertSame($latest, $id);
        self::assertSame($latest, $adapter->conversationId());
        self::assertSame([$latest, $adapter->participant()], $continued);
    }

    public function testBootFallsBackToNewestPopulatedSummary(): void
    {
        $continued = [];
        [$adapter] = $this->adapter($continued);
        $store = $adapter->conversationStore();
        $seeded = $store->storeConversation('users', $this->participantId, 'Seeded');
        $store->storeUserMessage($seeded, 'stdClass', $this->participantId, 'OtherAgent', new UserMessage('Seeded'));
        $session = new Session($adapter);

        $id = $session->boot();

        self::assertSame($seeded, $id);
        self::assertSame($seeded, $adapter->conversationId());
        self::assertSame([$seeded, $adapter->participant()], $continued);
    }

    public function testBootRebindsAPreselectedConversation(): void
    {
        $continued = [];
        [$adapter] = $this->adapter($continued);
        $id = $adapter->conversationStore()->storeConversation('users', $this->participantId, 'First');
        $adapter->useConversation($id);
        $continued = [];
        $session = new Session($adapter);

        self::assertSame($id, $session->boot());
        self::assertSame([$id, $adapter->participant()], $continued);
    }

    public function testClearStartsAFreshConversationOnBothSides(): void
    {
        $continued = [];
        [$adapter] = $this->adapter($continued);
        $session = new Session($adapter);
        $first = $session->boot();

        $second = $session->clear();

        self::assertNotSame($first, $second);
        self::assertSame($second, $session->id());
        self::assertSame([$second, $adapter->participant()], $continued);
    }

    public function testResumeSelectsAStoredConversationOnBothSides(): void
    {
        $continued = [];
        [$adapter] = $this->adapter($continued);
        $session = new Session($adapter);
        $session->boot();

        $id = $adapter->conversationStore()->storeConversation('users', $this->participantId, 'Other');

        $session->resume($id);

        self::assertSame($id, $session->id());
        self::assertSame([$id, $adapter->participant()], $continued);
    }

    public function testResumeRejectsForeignConversations(): void
    {
        $continued = [];
        [$adapter] = $this->adapter($continued);
        $session = new Session($adapter);
        $current = $session->boot();
        $foreign = $adapter->conversationStore()->storeConversation('users', $this->participantId . '-other', 'Foreign');

        try {
            $session->resume($foreign);
            self::fail('Foreign conversations must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame($current, $session->id());
            self::assertSame([$current, $adapter->participant()], $continued);
        }
    }

    /**
     * Builds an adapter with a recording remembering agent.
     *
     * @param list<mixed> $continued Recorded continue arguments.
     * @return array{0: \Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter, 1: \Crustum\Ai\Contracts\Agent&\Crustum\Ai\Contracts\RemembersConversations} Adapter and agent.
     */
    private function adapter(array &$continued): array
    {
        $agent = $this->createStubForIntersectionOfInterfaces([Agent::class, RemembersConversations::class]);
        $agent->method('continue')->willReturnCallback(
            static function (string $conversationId, ?object $as = null) use ($agent, &$continued): Agent&RemembersConversations {
                $continued = [$conversationId, $as];

                return $agent;
            },
        );
        $participant = (object)['id' => $this->participantId];
        $adapter = new CakeCommandAdapter(
            $agent,
            new Commands(),
            new DatabaseConversationStore(),
            static function (): void {
            },
            new ConfigurationStore(new InMemoryStorage(), $this->participantId),
            'users',
            $this->participantId,
            $participant,
        );

        return [$adapter, $agent];
    }
}
