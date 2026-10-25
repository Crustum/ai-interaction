<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

use Closure;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Exception\StoppedException;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Streaming\Event\StreamEnd;
use Crustum\Ai\Streaming\Event\ToolApprovalRequest;
use Crustum\AiInteraction\InputHistory\InputHistory;
use Crustum\AiInteraction\Interruption\StopSignal;
use Crustum\AiInteraction\Message\UserMessageProcessors;
use Crustum\AiInteraction\Session\ConversationTitler;
use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Runs one interaction turn as a single iterable result.
 *
 * Command input dispatches through commands; plain text records history and
 * streams agent events untouched. Service outcomes surface as turn values.
 * The terminal turn state returns from iteration.
 */
final class TurnRunner
{
    /**
     * Command interceptor.
     *
     * @var \Crustum\AiInteraction\InteractionCommand\CommandInterceptor
     */
    private CommandInterceptor $interceptor;

    /**
     * Active adapter.
     *
     * @var \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed>
     */
    private CommandAdapterInterface $adapter;

    /**
     * Answering agent.
     *
     * @var \Crustum\Ai\Contracts\Agent
     */
    private Agent $agent;

    /**
     * Submitted input history.
     *
     * @var \Crustum\AiInteraction\InputHistory\InputHistory
     */
    private InputHistory $history;

    /**
     * Stop signal observed between chunks.
     *
     * @var \Crustum\AiInteraction\Interruption\StopSignal|null
     */
    private ?StopSignal $stop;

    /**
     * Optional message pipeline applied before agent runs.
     *
     * @var \Crustum\AiInteraction\Message\UserMessageProcessors|null
     */
    private ?UserMessageProcessors $processors;

    /**
     * Title agent naming untitled conversations, null when disabled.
     *
     * @var \Crustum\Ai\Contracts\Agent|null
     */
    private ?Agent $titleAgent;

    /**
     * Pre-turn input transform, receives and returns the submitted input.
     *
     * @var \Closure|null
     */
    private ?Closure $preTurn;

    /**
     * Pre-stream gate, notice text vetoes the agent run.
     *
     * @var \Closure|null
     */
    private ?Closure $preStream;

    /**
     * Post-turn hook observing the terminal turn state.
     *
     * @var \Closure|null
     */
    private ?Closure $postTurn;

    /**
     * Creates a turn runner.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandInterceptor $interceptor Command interceptor.
     * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
     * @param \Crustum\Ai\Contracts\Agent $agent Answering agent.
     * @param \Crustum\AiInteraction\InputHistory\InputHistory $history Submitted input history.
     * @param \Crustum\AiInteraction\Interruption\StopSignal|null $stop Stop signal observed between chunks.
     * @param \Crustum\AiInteraction\Message\UserMessageProcessors|null $processors Optional message pipeline.
     * @param \Crustum\Ai\Contracts\Agent|null $titles Title agent naming untitled conversations, null disables naming.
     * @param \Closure|null $preTurn Pre-turn input transform, receives and returns the submitted input.
     * @param \Closure|null $preStream Pre-stream gate, notice text vetoes the agent run.
     * @param \Closure|null $postTurn Post-turn hook observing the terminal turn state.
     */
    public function __construct(
        CommandInterceptor $interceptor,
        CommandAdapterInterface $adapter,
        Agent $agent,
        InputHistory $history,
        ?StopSignal $stop = null,
        ?UserMessageProcessors $processors = null,
        ?Agent $titles = null,
        ?Closure $preTurn = null,
        ?Closure $preStream = null,
        ?Closure $postTurn = null,
    ) {
        $this->interceptor = $interceptor;
        $this->adapter = $adapter;
        $this->agent = $agent;
        $this->history = $history;
        $this->stop = $stop;
        $this->processors = $processors;
        $this->titleAgent = $titles;
        $this->preTurn = $preTurn;
        $this->preStream = $preStream;
        $this->postTurn = $postTurn;
    }

    /**
     * Runs one interaction turn as a single iterable result.
     *
     * The agent stream observes the shared stop signal, so an interruption
     * requested mid-turn aborts the driver stream and surfaces as a stopped
     * turn instead of an error.
     *
     * Interruption is observed at the next streamed event boundary; the agent
     * stream also receives the shared stop predicate for the driver to poll
     * between chunks. A driver read blocked with no events flowing cannot
     * observe the stop until the read unblocks — aborting the transport read
     * itself stays the driver's responsibility.
     *
     * The pre-turn transform rewrites the submitted input first, the
     * pre-stream gate may veto the agent run with a notice, and the post-turn
     * hook observes every terminal turn state.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandInput|string $input Submitted input.
     * @return iterable<\Crustum\Ai\Streaming\Event\StreamEvent|\Crustum\AiInteraction\InteractionCommand\TurnNotice|\Crustum\AiInteraction\InteractionCommand\TurnSelection|\Crustum\AiInteraction\InteractionCommand\TurnTask|\Crustum\AiInteraction\InteractionCommand\TurnConversation|\Crustum\AiInteraction\InteractionCommand\TurnStopped> Turn items.
     */
    public function run(string|CommandInput $input): iterable
    {
        $result = yield from $this->runTurn($input);

        $this->postTurn?->__invoke($result);

        return $result;
    }

    /**
     * Runs the turn pipeline, yielding driver events and turn outcomes.
     *
     * @param \Crustum\AiInteraction\InteractionCommand\CommandInput|string $input Submitted input.
     * @return iterable<\Crustum\Ai\Streaming\Event\StreamEvent|\Crustum\AiInteraction\InteractionCommand\TurnNotice|\Crustum\AiInteraction\InteractionCommand\TurnSelection|\Crustum\AiInteraction\InteractionCommand\TurnTask|\Crustum\AiInteraction\InteractionCommand\TurnConversation|\Crustum\AiInteraction\InteractionCommand\TurnStopped> Turn items.
     */
    private function runTurn(string|CommandInput $input): iterable
    {
        $this->stop?->clear();

        if ($this->preTurn instanceof Closure) {
            $input = ($this->preTurn)($input);
        }

        if ($input instanceof CommandInput) {
            yield from $this->outcomes(
                $this->interceptor->commands()->run($input->identifier, $input->value, $this->adapter),
            );

            return new TurnResult(stopped: $this->wasInterrupted());
        }

        if (str_starts_with($input, '/')) {
            yield from $this->outcomes($this->interceptor->intercept($input, $this->adapter));

            return new TurnResult(stopped: $this->wasInterrupted());
        }

        $message = new UserMessage($input);
        $this->history->record($message);

        if ($this->processors instanceof UserMessageProcessors) {
            $message = $this->processors->forAgent($message);
        }

        if ($this->preStream instanceof Closure) {
            $veto = ($this->preStream)($message);

            if (is_string($veto) && $veto !== '') {
                yield new TurnNotice($veto, 'warning');

                return new TurnResult(stopped: $this->wasInterrupted());
            }
        }

        $stream = $this->agent->stream($message, stopCheck: $this->wasInterrupted(...));
        $interrupted = false;
        $usage = null;

        try {
            foreach ($stream as $event) {
                if ($this->wasInterrupted()) {
                    yield new TurnStopped();

                    return new TurnResult(stopped: true, usage: $usage);
                }

                if ($event instanceof StreamEnd && $event->reason === FinishReason::Cancelled->value) {
                    yield new TurnStopped();

                    return new TurnResult(stopped: true, usage: $usage);
                }

                if ($event instanceof StreamEnd) {
                    $usage = $event->usage;
                }

                if ($event instanceof ToolApprovalRequest) {
                    $interrupted = true;
                }

                yield $event;
            }
        } catch (StoppedException) {
            yield new TurnStopped();

            return new TurnResult(stopped: true, usage: $usage);
        }

        if ($interrupted) {
            yield new TurnNotice('Agent paused: approval or external tool results are required.');
        } else {
            $this->maybeTitleConversation();
        }

        return new TurnResult($interrupted, $this->wasInterrupted(), $usage);
    }

    /**
     * Requests turn interruption.
     *
     * @return bool True when a stop signal accepts the request.
     */
    public function requestInterruption(): bool
    {
        if (!$this->stop instanceof StopSignal) {
            return false;
        }

        $this->stop->request();

        return true;
    }

    /**
     * Reports requested interruption.
     *
     * @return bool True when interruption was requested.
     */
    public function wasInterrupted(): bool
    {
        return $this->stop?->isRequested() ?? false;
    }

    /**
     * Names the current conversation when it is still untitled.
     *
     * @return void
     */
    private function maybeTitleConversation(): void
    {
        if ($this->wasInterrupted()) {
            return;
        }

        (new ConversationTitler($this->titleAgent))->titleUntitled($this->adapter);
    }

    /**
     * Maps adapter output to turn outcomes.
     *
     * @param mixed $output Adapter output.
     * @return iterable<\Crustum\AiInteraction\InteractionCommand\TurnNotice|\Crustum\AiInteraction\InteractionCommand\TurnSelection|\Crustum\AiInteraction\InteractionCommand\TurnTask|\Crustum\AiInteraction\InteractionCommand\TurnConversation|\Crustum\AiInteraction\InteractionCommand\TurnStopped> Turn outcomes.
     */
    private function outcomes(mixed $output): iterable
    {
        if (!is_array($output)) {
            return;
        }

        foreach ($output['notices'] ?? [] as $text) {
            if (is_string($text)) {
                yield new TurnNotice($text);
            }
        }

        foreach ($output['warnings'] ?? [] as $text) {
            if (is_string($text)) {
                yield new TurnNotice($text, 'warning');
            }
        }

        foreach ($output['errors'] ?? [] as $text) {
            if (is_string($text)) {
                yield new TurnNotice($text, 'error');
            }
        }

        $error = $output['error'] ?? null;

        if (is_string($error) && $error !== '') {
            yield new TurnNotice($error, 'error');
        }

        $selection = $output['selection'] ?? null;

        if ($selection instanceof Selection) {
            yield new TurnSelection($selection);
        }

        $task = $output['task'] ?? null;

        if (is_string($task) && $task !== '') {
            $origin = $output['origin'] ?? null;

            if (!is_string($origin) || $origin === '') {
                $origin = null;
            }

            yield new TurnTask($task, $origin);
        }

        $conversationId = $output['conversationId'] ?? null;

        if (is_string($conversationId) && $conversationId !== '') {
            yield new TurnConversation($conversationId);
        }

        if (($output['stopped'] ?? false) === true) {
            yield new TurnStopped();
        }
    }
}
