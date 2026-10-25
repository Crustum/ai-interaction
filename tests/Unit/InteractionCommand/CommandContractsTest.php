<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InteractionCommand;

use Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface;
use Crustum\AiInteraction\InteractionCommand\CommandExecution;
use Crustum\AiInteraction\InteractionCommand\CommandInterface;
use Crustum\AiInteraction\InteractionCommand\ConcurrentCommandInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit coverage for command contracts and dispatch outcomes.
 */
final class CommandContractsTest extends TestCase
{
    public function testExecutionFactoriesExposeIdentifierStatusAndCause(): void
    {
        $completed = CommandExecution::completed('/clear');
        self::assertSame('/clear', $completed->identifier);
        self::assertSame('completed', $completed->status);
        self::assertNull($completed->exception);

        $unknown = CommandExecution::unknown('/missing');
        self::assertSame('/missing', $unknown->identifier);
        self::assertSame('unknown', $unknown->status);
        self::assertNull($unknown->exception);

        $cause = new RuntimeException('Command failed.');
        $failed = CommandExecution::failed('/clear', $cause);
        self::assertSame('/clear', $failed->identifier);
        self::assertSame('failed', $failed->status);
        self::assertSame($cause, $failed->exception);
    }

    public function testConcurrentCommandExtendsCommandContract(): void
    {
        $command = new class implements ConcurrentCommandInterface {
            /**
             * Returns the command name.
             *
             * @return string Command name.
             */
            public function name(): string
            {
                return '/watch';
            }

            /**
             * Returns the command description.
             *
             * @return string Command description.
             */
            public function describe(): string
            {
                return 'Watches while the agent works.';
            }

            /**
             * Runs the command.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
             * @param string $value Raw input value.
             * @return void
             */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                $adapter->notify($value);
            }
        };

        self::assertInstanceOf(CommandInterface::class, $command);
        self::assertSame('/watch', $command->name());
        self::assertSame('Watches while the agent works.', $command->describe());
    }

    public function testCommandRunsThroughAdapterLifecycle(): void
    {
        $command = new class implements CommandInterface {
            /**
             * Returns the command name.
             *
             * @return string Command name.
             */
            public function name(): string
            {
                return '/inspect';
            }

            /**
             * Returns the command description.
             *
             * @return string Command description.
             */
            public function describe(): string
            {
                return 'Exercises the adapter lifecycle.';
            }

            /**
             * Runs the command.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
             * @param string $value Raw input value.
             * @return void
             */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                if (!$adapter->admit($this)) {
                    return;
                }

                $adapter->notify($this->name());
                $adapter->warn($value);
                $adapter->error('An expected failure.');
                $adapter->afterExecution(CommandExecution::completed($this->name()));
                $adapter->stop();
            }
        };

        $adapter = new RecordingCommandAdapter();
        $command->run($adapter, 'A warning.');

        self::assertTrue($adapter->admitted);
        self::assertSame(['/inspect'], $adapter->notices);
        self::assertSame(['A warning.'], $adapter->warnings);
        self::assertSame(['An expected failure.'], $adapter->errors);
        self::assertSame('completed', $adapter->lastExecution?->status);
        self::assertTrue($adapter->stopped);
    }
}
