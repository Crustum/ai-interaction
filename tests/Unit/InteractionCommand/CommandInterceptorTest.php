<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InteractionCommand;

use Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface;
use Crustum\AiInteraction\InteractionCommand\CommandInterceptor;
use Crustum\AiInteraction\InteractionCommand\CommandInterface;
use Crustum\AiInteraction\InteractionCommand\Commands;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the command interceptor.
 */
final class CommandInterceptorTest extends TestCase
{
    public function testOrdinaryInputBypassesDispatch(): void
    {
        $commands = (new Commands())->addCommand([
            $this->command('/resume', 'resumed'),
            $this->command('/help', 'helped'),
        ]);
        $adapter = new RecordingCommandAdapter($commands);
        $interceptor = new CommandInterceptor($commands);

        self::assertNull($interceptor->intercept('hello', $adapter));
        self::assertNull($interceptor->intercept('', $adapter));
        self::assertNull($interceptor->intercept(' /resume', $adapter));
        self::assertSame([], $adapter->notices);
    }

    public function testSlashPrefixedInputDispatchesWithPreservedValue(): void
    {
        $commands = (new Commands())->addCommand([$this->command('/resume', 'resumed')]);
        $adapter = new RecordingCommandAdapter($commands);
        $interceptor = new CommandInterceptor($commands);

        $execution = $interceptor->intercept('/resume   stored key  ', $adapter);

        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertSame(['resumed:  stored key  '], $adapter->notices);
    }

    public function testBareIdentifierDispatchesWithEmptyValue(): void
    {
        $commands = (new Commands())->addCommand([$this->command('/help', 'helped')]);
        $adapter = new RecordingCommandAdapter($commands);
        $interceptor = new CommandInterceptor($commands);

        $execution = $interceptor->intercept('/help', $adapter);

        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertSame(['helped:'], $adapter->notices);
    }

    public function testUnknownSlashInputCompletesAsUnknown(): void
    {
        $commands = new Commands();
        $adapter = new RecordingCommandAdapter($commands);
        $interceptor = new CommandInterceptor($commands);

        $execution = $interceptor->intercept('/missing value', $adapter);

        self::assertNotNull($execution);
        self::assertSame('unknown', $execution->status);
        self::assertSame('/missing', $execution->identifier);
    }

    /**
     * Builds a notifying command.
     *
     * @param string $name Slash-prefixed identifier.
     * @param string $label Notice label.
     * @return \Crustum\AiInteraction\InteractionCommand\CommandInterface Test command.
     */
    private function command(string $name, string $label): CommandInterface
    {
        return new class ($name, $label) implements CommandInterface {
            /**
             * Creates a notifying command.
             *
             * @param string $name Slash-prefixed identifier.
             * @param string $label Notice label.
             */
            public function __construct(private string $name, private string $label)
            {
            }

            /**
             * Returns the slash-prefixed identifier.
             *
             * @return string Slash-prefixed command name.
             */
            public function name(): string
            {
                return $this->name;
            }

            /**
             * Returns the test description.
             *
             * @return string Command description.
             */
            public function describe(): string
            {
                return 'A test Command';
            }

            /**
             * Notifies the label with the raw value.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
             * @param string $value Raw input value.
             * @return void
             */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                $adapter->notify($this->label . ':' . $value);
            }
        };
    }
}
