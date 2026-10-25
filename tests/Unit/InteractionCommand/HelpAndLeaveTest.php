<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InteractionCommand;

use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\ConcurrentCommandInterface;
use Crustum\AiInteraction\InteractionCommand\HelpCommand;
use Crustum\AiInteraction\InteractionCommand\LeaveCommand;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the shared help and leave commands.
 */
final class HelpAndLeaveTest extends TestCase
{
    public function testSharedCommandsUseTheAdaptersPresentationAndStopEffects(): void
    {
        $help = new HelpCommand('/guide');
        $leave = new LeaveCommand('/quit');
        self::assertInstanceOf(ConcurrentCommandInterface::class, $help);
        self::assertInstanceOf(ConcurrentCommandInterface::class, $leave);
        $commands = (new Commands())->addCommand([$help, $leave]);
        $adapter = new RecordingCommandAdapter($commands);

        self::assertSame('completed', $commands->run('/guide', '', $adapter)?->status);
        self::assertSame([
            '/guide — Lists what can be typed here.',
            '/quit — Stops the interaction.',
        ], $adapter->notices);
        self::assertFalse($adapter->stopped);
        self::assertSame('unknown', $commands->run('/missing', '', $adapter)?->status);
        self::assertSame('completed', $commands->run('/quit', '', $adapter)?->status);
        self::assertTrue($adapter->stopped);
    }
}
