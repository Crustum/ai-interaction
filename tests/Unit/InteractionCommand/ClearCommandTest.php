<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InteractionCommand;

use Crustum\AiInteraction\InteractionCommand\ClearCommand;
use Crustum\AiInteraction\InteractionCommand\Commands;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the clear command.
 */
final class ClearCommandTest extends TestCase
{
    public function testClearStartsAFreshConversationAndKeepsThePreviousRow(): void
    {
        $commands = (new Commands())->addCommand([new ClearCommand()]);
        $adapter = new RecordingCommandAdapter($commands);
        $adapter->useConversation('conversation-current');

        $execution = $commands->run('/clear', '', $adapter);

        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertNotSame('conversation-current', $adapter->conversationId());
        self::assertNotNull($adapter->conversationId());
    }

    public function testRepeatedClearKeepsMovingForward(): void
    {
        $commands = (new Commands())->addCommand([new ClearCommand()]);
        $adapter = new RecordingCommandAdapter($commands);

        $commands->run('/clear', '', $adapter);
        $first = $adapter->conversationId();
        $commands->run('/clear', '', $adapter);
        $second = $adapter->conversationId();

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first, $second);
    }
}
