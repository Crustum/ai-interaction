<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InteractionCommand;

use Closure;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Exception\StoppedException;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\StreamableAgentResponse;
use Crustum\Ai\Streaming\Event\ReasoningDelta;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\AiInteraction\Configuration\ConfigurationStore;
use Crustum\AiInteraction\InputHistory\InputHistory;
use Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter;
use Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface;
use Crustum\AiInteraction\InteractionCommand\CommandInput;
use Crustum\AiInteraction\InteractionCommand\CommandInterceptor;
use Crustum\AiInteraction\InteractionCommand\CommandInterface;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\TurnNotice;
use Crustum\AiInteraction\InteractionCommand\TurnResult;
use Crustum\AiInteraction\InteractionCommand\TurnRunner;
use Crustum\AiInteraction\InteractionCommand\TurnStopped;
use Crustum\AiInteraction\InteractionCommand\TurnTask;
use Crustum\AiInteraction\Interruption\StopSignal;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function collection;
use function iterator_to_array;
use function time;

/**
 * Unit coverage for the turn runner.
 */
final class TurnRunnerTest extends TestCase
{
    public function testPlainTextStreamsEventsUntouchedAndRecordsHistory(): void
    {
        $parts = $this->parts();
        $delta = new TextDelta('event', 'message', 'Hello.', time());
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([$delta]),
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
        );

        $generator = $runner->run('Hello.');
        $items = iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertSame([$delta], $items);
        self::assertInstanceOf(TurnResult::class, $result);
        self::assertFalse($result->interrupted);
        self::assertFalse($result->stopped);
        self::assertSame(['Hello.'], $this->contents($parts['history']));
    }

    public function testCommandInputDispatchesToOutcomes(): void
    {
        $parts = $this->parts();
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([]),
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
        );

        $generator = $runner->run(new CommandInput('/echo', 'value'));
        $items = iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertCount(1, $items);
        self::assertSame('echo:value', $items[0]->text);
        self::assertSame('notice', $items[0]->kind);
        self::assertInstanceOf(TurnResult::class, $result);
        self::assertFalse($result->stopped);
    }

    public function testSlashTextDispatchesWithPreservedValue(): void
    {
        $parts = $this->parts();
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([]),
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
        );

        $generator = $runner->run('/echo   kept  ');
        $items = iterator_to_array($generator);

        self::assertCount(1, $items);
        self::assertSame('echo:  kept  ', $items[0]->text);
    }

    public function testTaskTextYieldsTaskOutcomeAndCompletesTurn(): void
    {
        $parts = $this->parts();
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([]),
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
        );

        $generator = $runner->run('/task do the thing');
        $items = iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertCount(1, $items);
        self::assertInstanceOf(TurnTask::class, $items[0]);
        self::assertSame('task:do the thing', $items[0]->text);
        self::assertNull($items[0]->origin);
        self::assertInstanceOf(TurnResult::class, $result);
        self::assertFalse($result->interrupted);
        self::assertFalse($result->stopped);
        self::assertSame([], $this->contents($parts['history']));
    }

    public function testPreTurnTransformRewritesCommandValue(): void
    {
        $parts = $this->parts();
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([]),
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
            null,
            null,
            static fn(string|CommandInput $input): string|CommandInput => $input instanceof CommandInput
                ? new CommandInput($input->identifier, $input->value . '?')
                : $input . '?',
        );

        $generator = $runner->run(new CommandInput('/echo', 'hi'));
        $items = iterator_to_array($generator);

        self::assertCount(1, $items);
        self::assertSame('echo:hi?', $items[0]->text);
    }

    public function testPreStreamGateVetoesAgentRunWithNotice(): void
    {
        $parts = $this->parts();
        $streamed = false;
        $agent = $this->createStub(Agent::class);
        $agent->method('stream')->willReturnCallback(
            static function () use (&$streamed): StreamableAgentResponse {
                $streamed = true;

                throw new RuntimeException('Agent must not stream on veto.');
            },
        );
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $agent,
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
            null,
            null,
            null,
            static fn(UserMessage $message): string => 'Over budget, turn skipped.',
        );

        $generator = $runner->run('Hello.');
        $items = iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertFalse($streamed);
        self::assertCount(1, $items);
        self::assertInstanceOf(TurnNotice::class, $items[0]);
        self::assertSame('Over budget, turn skipped.', $items[0]->text);
        self::assertSame('warning', $items[0]->kind);
        self::assertFalse($result->interrupted);
        self::assertFalse($result->stopped);
    }

    public function testPostTurnHookObservesTerminalStates(): void
    {
        $parts = $this->parts();
        $observed = [];
        $hook = static function (TurnResult $result) use (&$observed): void {
            $observed[] = $result;
        };
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([]),
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
            null,
            null,
            null,
            null,
            $hook,
        );

        $plain = $runner->run('Hello.');
        iterator_to_array($plain);
        $plainResult = $plain->getReturn();
        $command = $runner->run(new CommandInput('/echo', 'hi'));
        iterator_to_array($command);
        $commandResult = $command->getReturn();

        self::assertCount(2, $observed);
        self::assertSame($plainResult, $observed[0]);
        self::assertSame($commandResult, $observed[1]);
        self::assertFalse($observed[0]->interrupted);
        self::assertFalse($observed[1]->stopped);
    }

    public function testStopRequestedMidStreamSurfacesAtNextEventBoundary(): void
    {
        $parts = $this->parts();
        $signal = new StopSignal(new InMemoryStorage(), 'turn');
        $agent = $this->createStub(Agent::class);
        $agent->method('stream')->willReturn(
            new StreamableAgentResponse('invocation', static function () use ($signal): iterable {
                yield new TextDelta('event-1', 'message-1', 'One.', time());
                yield new TextDelta('event-2', 'message-2', 'Two.', time());
                $signal->request();
                yield new TextDelta('event-3', 'message-3', 'Unreached.', time());
                yield new TextDelta('event-4', 'message-4', 'Unreached.', time());
            }, new Meta()),
        );
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $agent,
            $parts['history'],
            $signal,
        );

        $generator = $runner->run('Hello.');
        $items = iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertCount(3, $items);
        self::assertSame('One.', $items[0]->delta);
        self::assertSame('Two.', $items[1]->delta);
        self::assertInstanceOf(TurnStopped::class, $items[2]);
        self::assertTrue($result->stopped);
    }

    public function testReasoningEventsPassThroughUntouched(): void
    {
        $parts = $this->parts();
        $reasoning = new ReasoningDelta('event-1', 'reasoning-1', 'Considering.', time());
        $delta = new TextDelta('event-2', 'message-1', 'Answer.', time());
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([$reasoning, $delta]),
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
        );

        $generator = $runner->run('Hello.');
        $items = iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertSame([$reasoning, $delta], $items);
        self::assertFalse($result->interrupted);
        self::assertFalse($result->stopped);
    }

    public function testStopRequestedMidStreamAbortsRemainingEvents(): void
    {
        $parts = $this->parts();
        $signal = new StopSignal(new InMemoryStorage(), 'turn');
        $delta = new TextDelta('event', 'message', 'Hello.', time());
        $agent = $this->createStub(Agent::class);
        $agent->method('stream')->willReturn(
            new StreamableAgentResponse('invocation', static function () use ($signal, $delta): iterable {
                $signal->request();
                yield $delta;
                yield new TextDelta('event', 'message', 'Unreached.', time());
            }, new Meta()),
        );
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $agent,
            $parts['history'],
            $signal,
        );

        $generator = $runner->run('Hello.');
        $items = iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertCount(1, $items);
        self::assertInstanceOf(TurnStopped::class, $items[0]);
        self::assertTrue($result->stopped);
        self::assertSame(['Hello.'], $this->contents($parts['history']));
    }

    public function testApprovalRequestSurfacesNoticeAndInterruptedResult(): void
    {
        $parts = $this->parts();
        $delta = new TextDelta('event', 'message', 'Hello.', time());
        $approval = new ToolApprovalRequest('approval', collection([]), time());
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([$delta, $approval]),
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
        );

        $generator = $runner->run('Hello.');
        $items = iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertCount(3, $items);
        self::assertSame($delta, $items[0]);
        self::assertSame($approval, $items[1]);
        self::assertSame('Agent paused: approval or external tool results are required.', $items[2]->text);
        self::assertInstanceOf(TurnResult::class, $result);
        self::assertTrue($result->interrupted);
        self::assertFalse($result->stopped);
    }

    public function testStopCheckForwardsSignalStateToAgent(): void
    {
        $parts = $this->parts();
        $signal = new StopSignal(new InMemoryStorage(), 'turn');
        $captured = null;
        $agent = $this->createStub(Agent::class);
        $agent->method('stream')->willReturnCallback(
            static function (mixed ...$args) use (&$captured): StreamableAgentResponse {
                $captured = $args[5] ?? null;

                return new StreamableAgentResponse('invocation', static function (): iterable {
                    yield from [];
                }, new Meta());
            },
        );
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $agent,
            $parts['history'],
            $signal,
        );

        $generator = $runner->run('Hello.');
        iterator_to_array($generator);

        self::assertInstanceOf(Closure::class, $captured);
        self::assertFalse($captured());
        $signal->request();
        self::assertTrue($captured());
    }

    public function testStoppedExceptionBecomesTurnStopped(): void
    {
        $parts = $this->parts();
        $agent = $this->createStub(Agent::class);
        $agent->method('stream')->willReturn(
            new StreamableAgentResponse('invocation', static function (): iterable {
                yield from (static function (): iterable {
                    throw new StoppedException('The stream was stopped.');
                })();
            }, new Meta()),
        );
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $agent,
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
        );

        $generator = $runner->run('Hello.');
        $items = iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertCount(1, $items);
        self::assertInstanceOf(TurnStopped::class, $items[0]);
        self::assertTrue($result->stopped);
    }

    public function testCancelledStreamEndBecomesTurnStopped(): void
    {
        $parts = $this->parts();
        $end = new StreamEnd('end', FinishReason::Cancelled->value, new TextUsage(), time());
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([$end]),
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
        );

        $generator = $runner->run('Hello.');
        $items = iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertCount(1, $items);
        self::assertInstanceOf(TurnStopped::class, $items[0]);
        self::assertTrue($result->stopped);
    }

    public function testTurnResultCarriesStreamUsage(): void
    {
        $parts = $this->parts();
        $delta = new TextDelta('event', 'message', 'Hello.', time());
        $end = new StreamEnd('end', FinishReason::Stop->value, new TextUsage(120, 45), time());
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([$delta, $end]),
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
        );

        $generator = $runner->run('Hello.');
        $items = iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertSame([$delta, $end], $items);
        self::assertInstanceOf(TurnResult::class, $result);
        self::assertFalse($result->interrupted);
        self::assertFalse($result->stopped);
        self::assertNotNull($result->usage);
        self::assertSame(120, $result->usage->inputTokens);
        self::assertSame(45, $result->usage->outputTokens);
    }

    public function testCommandTurnResultCarriesNoUsage(): void
    {
        $parts = $this->parts();
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([]),
            $parts['history'],
            new StopSignal(new InMemoryStorage(), 'turn'),
        );

        $generator = $runner->run('/echo value');
        iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertInstanceOf(TurnResult::class, $result);
        self::assertNull($result->usage);
    }

    public function testInterruptionHelpersWithoutSignalRefuse(): void
    {
        $parts = $this->parts();
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([]),
            $parts['history'],
        );

        self::assertFalse($runner->requestInterruption());
        self::assertFalse($runner->wasInterrupted());
    }

    public function testInterruptionHelpersWithSignal(): void
    {
        $parts = $this->parts();
        $signal = new StopSignal(new InMemoryStorage(), 'turn');
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([]),
            $parts['history'],
            $signal,
        );

        self::assertFalse($runner->wasInterrupted());
        self::assertTrue($runner->requestInterruption());
        self::assertTrue($runner->wasInterrupted());
        self::assertTrue($signal->isRequested());
    }

    public function testRunClearsAPreviouslyRequestedStop(): void
    {
        $parts = $this->parts();
        $signal = new StopSignal(new InMemoryStorage(), 'turn');
        $delta = new TextDelta('event', 'message', 'Hello.', time());
        $runner = new TurnRunner(
            $parts['interceptor'],
            $parts['adapter'],
            $this->agent([$delta]),
            $parts['history'],
            $signal,
        );
        $signal->request();

        $generator = $runner->run('Hello.');
        $items = iterator_to_array($generator);
        $result = $generator->getReturn();

        self::assertSame([$delta], $items);
        self::assertFalse($result->stopped);
    }

    /**
     * Builds interceptor, adapter and history parts.
     *
     * @return array{interceptor: \Crustum\AiInteraction\InteractionCommand\CommandInterceptor, adapter: \Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter, history: \Crustum\AiInteraction\InputHistory\InputHistory} Turn parts.
     */
    private function parts(): array
    {
        $storage = new InMemoryStorage();
        $history = new InputHistory($storage);
        $commands = (new Commands())->addCommand([
            new class implements CommandInterface {
                /**
                 * Returns the slash-prefixed identifier.
                 *
                 * @return string Slash-prefixed command name.
                 */
                public function name(): string
                {
                    return '/echo';
                }

                /**
                 * Returns the test description.
                 *
                 * @return string Command description.
                 */
                public function describe(): string
                {
                    return 'Echoes the value.';
                }

                /**
                 * Notifies the raw value.
                 *
                 * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
                 * @param string $value Raw input value.
                 * @return void
                 */
                public function run(CommandAdapterInterface $adapter, string $value): void
                {
                    $adapter->notify('echo:' . $value);
                }
            },
            new class implements CommandInterface {
                /**
                 * Returns the slash-prefixed identifier.
                 *
                 * @return string Slash-prefixed command name.
                 */
                public function name(): string
                {
                    return '/task';
                }

                /**
                 * Returns the test description.
                 *
                 * @return string Command description.
                 */
                public function describe(): string
                {
                    return 'Resolves to task text.';
                }

                /**
                 * Requests the value as task text.
                 *
                 * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
                 * @param string $value Raw input value.
                 * @return void
                 */
                public function run(CommandAdapterInterface $adapter, string $value): void
                {
                    $adapter->requestTask('task:' . $value);
                }
            },
        ]);
        $agent = $this->createStub(Agent::class);
        $adapter = new CakeCommandAdapter(
            $agent,
            $commands,
            new InMemoryConversationStore(),
            static function (): void {
            },
            new ConfigurationStore(new InMemoryStorage(), 'turn-user'),
            'users',
            'turn-user',
            (object)['id' => 'turn-user'],
        );

        return [
            'interceptor' => new CommandInterceptor($commands),
            'adapter' => $adapter,
            'history' => $history,
        ];
    }

    /**
     * Builds an agent streaming canned events.
     *
     * @param list<object> $events Canned stream events.
     * @return \Crustum\Ai\Contracts\Agent Canned agent.
     */
    private function agent(array $events): Agent
    {
        $agent = $this->createStub(Agent::class);
        $agent->method('stream')->willReturn(
            new StreamableAgentResponse('invocation', static function () use ($events): iterable {
                yield from $events;
            }, new Meta()),
        );

        return $agent;
    }

    /**
     * Lists recorded history contents.
     *
     * @param \Crustum\AiInteraction\InputHistory\InputHistory $history Recorded history.
     * @return list<string|null> Recorded contents.
     */
    private function contents(InputHistory $history): array
    {
        $contents = [];

        foreach ($history->entries() as $message) {
            $contents[] = $message->content;
        }

        return $contents;
    }
}
