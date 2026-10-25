<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InteractionCommand;

use Closure;
use Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface;
use Crustum\AiInteraction\InteractionCommand\CommandExecution;
use Crustum\AiInteraction\InteractionCommand\CommandInterface;
use Crustum\AiInteraction\InteractionCommand\Commands;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit coverage for the command dispatcher.
 */
final class CommandsTest extends TestCase
{
    public function testDispatchPreservesRawArgumentsAndMountingOrderWithFirstDuplicateWinning(): void
    {
        $received = null;
        $first = $this->command('/review', static function (string $value) use (&$received): void {
            $received = $value;
        });
        $duplicate = $this->command('/review', static function (): never {
            self::fail('The duplicate must not execute.');
        });
        $last = $this->command('/last', static function (): void {
        });
        $commands = (new Commands())->addCommand([$first, $duplicate, $last]);
        $value = " \tline one\n  line two \t";

        $execution = $commands->run('/review', $value, new RecordingCommandAdapter());

        self::assertSame([$first, $duplicate, $last], $commands->all());
        self::assertSame($first, $commands->named('/review'));
        self::assertSame($value, $received);
        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertSame('/review', $execution->identifier);
        self::assertNull($execution->exception);
    }

    public function testLookupIsExactWithoutPrefixOrCaseNormalization(): void
    {
        $commands = (new Commands())->addCommand([$this->command('/review', static function (): never {
            self::fail('An unknown identifier must not execute.');
        })]);

        foreach (['/missing', 'review', '/Review'] as $identifier) {
            $execution = $commands->run($identifier, '', new RecordingCommandAdapter());
            self::assertNotNull($execution);
            self::assertSame('unknown', $execution->status);
            self::assertSame($identifier, $execution->identifier);
            self::assertNull($execution->exception);
        }
    }

    public function testFailureKeepsOriginalExceptionAndDispatcherRemainsUsable(): void
    {
        $failure = new RuntimeException('Command failed');
        $commands = (new Commands())->addCommand([
            $this->command('/broken', static function () use ($failure): never {
                throw $failure;
            }),
            $this->command('/healthy', static function (): void {
            }),
        ]);
        $adapter = new RecordingCommandAdapter();
        $execution = $commands->run('/broken', '', $adapter);

        self::assertNotNull($execution);
        self::assertSame('failed', $execution->status);
        self::assertSame('/broken', $execution->identifier);
        self::assertSame($failure, $execution->exception);
        self::assertSame('completed', $commands->run('/healthy', '', $adapter)?->status);
    }

    public function testUnknownIdentifierCompletesWithoutAdmissionAndReturnsTheAdaptersOutputUnchanged(): void
    {
        $output = CommandExecution::completed('/adapter-output');
        $adapter = new class ($output) extends RecordingCommandAdapter {
            /**
             * Creates an adapter with canned completion output.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandExecution $output Canned output.
             */
            public function __construct(private CommandExecution $output)
            {
                parent::__construct();
            }

            /**
             * Rejects admission for every command.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandInterface $command Command asking for admission.
             * @return bool Never returns.
             * @throws \RuntimeException Always.
             */
            public function admit(CommandInterface $command): bool
            {
                throw new RuntimeException('Unknown identifiers cannot request admission.');
            }

            /**
             * Returns canned output after asserting the unknown outcome.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandExecution $execution Dispatch outcome.
             * @return mixed Canned output.
             */
            public function afterExecution(CommandExecution $execution): mixed
            {
                TestCase::assertSame('unknown', $execution->status);
                TestCase::assertSame('/missing', $execution->identifier);
                TestCase::assertNull($execution->exception);

                return $this->output;
            }
        };

        self::assertSame($output, (new Commands())->run('/missing', '', $adapter));
    }

    public function testRefusalUsesTheFirstMatchingCommandWithoutDispatchOrCompletion(): void
    {
        $first = $this->command('/duplicate', static function (): never {
            self::fail('The refused Command must not execute.');
        });
        $duplicate = $this->command('/duplicate', static function (): never {
            self::fail('Admission must not fall through to a duplicate.');
        });
        $adapter = new class ($first) extends RecordingCommandAdapter {
            /**
             * Creates an adapter refusing one command.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandInterface $refused Refused command.
             */
            public function __construct(private CommandInterface $refused)
            {
                parent::__construct();
            }

            /**
             * Refuses the configured command only.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandInterface $command Command asking for admission.
             * @return bool False for the refused command, true otherwise.
             */
            public function admit(CommandInterface $command): bool
            {
                if ($command === $this->refused) {
                    $this->warn('Refused by this Adapter.');

                    return false;
                }

                return true;
            }

            /**
             * Fails when completion runs after a refusal.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandExecution $execution Dispatch outcome.
             * @return mixed Never returns.
             * @throws \RuntimeException Always.
             */
            public function afterExecution(CommandExecution $execution): mixed
            {
                throw new RuntimeException('Refusal must not complete an invocation.');
            }
        };

        self::assertNull(((new Commands())->addCommand([$first, $duplicate]))->run('/duplicate', '', $adapter));
        self::assertSame(['Refused by this Adapter.'], $adapter->warnings);
        self::assertSame([], $adapter->notices);
    }

    public function testCompletionReceivesTheOutcomeAfterCommandEffectsAndReturnsItsOwnOutput(): void
    {
        $failure = new RuntimeException('Command failed after its notice.');
        $output = CommandExecution::completed('/adapter-output');
        $commands = (new Commands())->addCommand([
            $this->command('/healthy', static function (string $value, CommandAdapterInterface $adapter): void {
                $adapter->notify($value);
            }),
            $this->command('/broken', static function (string $value, CommandAdapterInterface $adapter) use ($failure): never {
                $adapter->notify($value);
                throw $failure;
            }),
        ]);

        foreach (['/healthy' => null, '/broken' => $failure] as $identifier => $exception) {
            $adapter = new class ($identifier, $exception, $output) extends RecordingCommandAdapter {
                /**
                 * Creates an adapter asserting one completion.
                 *
                 * @param string $identifier Expected identifier.
                 * @param \RuntimeException|null $exception Expected failure cause.
                 * @param \Crustum\AiInteraction\InteractionCommand\CommandExecution $output Canned output.
                 */
                public function __construct(
                    private string $identifier,
                    private ?RuntimeException $exception,
                    private CommandExecution $output,
                ) {
                    parent::__construct();
                }

                /**
                 * Asserts the outcome then returns canned output.
                 *
                 * @param \Crustum\AiInteraction\InteractionCommand\CommandExecution $execution Dispatch outcome.
                 * @return mixed Canned output.
                 */
                public function afterExecution(CommandExecution $execution): mixed
                {
                    TestCase::assertSame(['Effect survives completion.'], $this->notices);
                    TestCase::assertSame($this->identifier, $execution->identifier);
                    TestCase::assertSame($this->exception instanceof RuntimeException ? 'failed' : 'completed', $execution->status);
                    TestCase::assertSame($this->exception, $execution->exception);

                    return $this->output;
                }
            };

            self::assertSame($output, $commands->run($identifier, 'Effect survives completion.', $adapter));
        }
    }

    public function testAdmissionExceptionsPropagateWithoutInvokingOrCompletingTheCommand(): void
    {
        $failure = new RuntimeException('Admission failed.');
        $adapter = new class ($failure) extends RecordingCommandAdapter {
            /**
             * Creates an adapter failing admission.
             *
             * @param \RuntimeException $failure Admission failure.
             */
            public function __construct(private RuntimeException $failure)
            {
                parent::__construct();
            }

            /**
             * Fails admission for every command.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandInterface $command Command asking for admission.
             * @return bool Never returns.
             * @throws \RuntimeException Always.
             */
            public function admit(CommandInterface $command): bool
            {
                throw $this->failure;
            }

            /**
             * Fails when completion runs after an admission failure.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandExecution $execution Dispatch outcome.
             * @return mixed Never returns.
             * @throws \RuntimeException Always.
             */
            public function afterExecution(CommandExecution $execution): mixed
            {
                throw new RuntimeException('Admission failures do not complete.');
            }
        };
        $commands = (new Commands())->addCommand($this->command('/review', static function (): never {
            self::fail('The Command cannot run after an admission failure.');
        }));

        try {
            $commands->run('/review', '', $adapter);
            self::fail('The admission failure must reach the caller.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }
    }

    public function testCompletionExceptionsPropagateWithoutBeingRetriedAsCommandFailures(): void
    {
        $failure = new RuntimeException('Completion failed.');
        $commands = (new Commands())->addCommand([
            $this->command('/healthy', static function (): void {
            }),
            $this->command('/broken', static function (): never {
                throw new RuntimeException('A Command failure.');
            }),
        ]);

        foreach (['/healthy', '/broken', '/unknown'] as $identifier) {
            $adapter = new class ($failure) extends RecordingCommandAdapter {
                /**
                 * Creates an adapter failing completion.
                 *
                 * @param \RuntimeException $failure Completion failure.
                 */
                public function __construct(private RuntimeException $failure)
                {
                    parent::__construct();
                }

                /**
                 * Fails completion for every outcome.
                 *
                 * @param \Crustum\AiInteraction\InteractionCommand\CommandExecution $execution Dispatch outcome.
                 * @return mixed Never returns.
                 * @throws \RuntimeException Always.
                 */
                public function afterExecution(CommandExecution $execution): mixed
                {
                    if ($execution->exception === $this->failure) {
                        throw new RuntimeException('Completion was retried as a Command failure.');
                    }

                    throw $this->failure;
                }
            };

            try {
                $commands->run($identifier, '', $adapter);
                self::fail('The completion failure must reach the caller.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
        }
    }

    public function testSlashlessIdentifiersAreRejectedInEveryMountingForm(): void
    {
        foreach (['review', ''] as $name) {
            $command = $this->command($name, static function (): void {
            });
            foreach ([$command, [$command]] as $mount) {
                try {
                    (new Commands())->addCommand($mount);
                    self::fail('A slashless identifier must fail at mounting.');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('slash', $exception->getMessage());
                }
            }
        }
    }

    public function testSlashRequirementDoesNotIntroduceAdditionalIdentifierGrammar(): void
    {
        foreach (['/', '//review', '/Review', '/two words'] as $name) {
            $command = $this->command($name, static function (): void {
            });
            self::assertSame($command, ((new Commands())->addCommand($command))->named($name));
        }
    }

    /**
     * Builds a closure-backed command.
     *
     * @param string $name Slash-prefixed identifier.
     * @param \Closure(string, \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed>): void $run Command body.
     * @return \Crustum\AiInteraction\InteractionCommand\CommandInterface Test command.
     */
    private function command(string $name, Closure $run): CommandInterface
    {
        return new class ($name, $run) implements CommandInterface {
            /**
             * Creates a closure-backed command.
             *
             * @param string $name Slash-prefixed identifier.
             * @param \Closure(string, \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed>): void $run Command body.
             */
            public function __construct(private string $name, private Closure $run)
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
             * Runs the backing closure.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
             * @param string $value Raw input value.
             * @return void
             */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                ($this->run)($value, $adapter);
            }
        };
    }
}
