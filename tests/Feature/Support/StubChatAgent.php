<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Feature\Support;

use Closure;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\AgentInput;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\QueuedAgentResponse;
use Crustum\Ai\Responses\StreamableAgentResponse;
use LogicException;

/**
 * Stub answering agent for host command tests.
 */
final class StubChatAgent implements Agent
{
    /**
     * Returns stub instructions.
     *
     * @return string Stub instructions.
     */
    public function instructions(): string
    {
        return 'Stub instructions.';
    }

    /**
     * Answers with canned text.
     *
     * @param \Crustum\Ai\Contracts\AgentInput|\Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Approvals\Decisions|string $prompt User prompt.
     * @param array $attachments Optional attachments.
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider AI provider.
     * @param string|null $model Specific model.
     * @param int|null $timeout Request timeout in seconds.
     * @return \Crustum\Ai\Responses\AgentResponse Canned response.
     */
    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        return new AgentResponse('stub-invocation', 'Canned answer.', new TextUsage(), new Meta());
    }

    /**
     * Rejects streaming answers.
     *
     * @param \Crustum\Ai\Contracts\AgentInput|\Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Approvals\Decisions|string $prompt User prompt.
     * @param array $attachments Optional attachments.
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider AI provider.
     * @param string|null $model Specific model.
     * @param int|null $timeout Request timeout in seconds.
     * @param \Closure|null $stopCheck Stop predicate.
     * @return \Crustum\Ai\Responses\StreamableAgentResponse Never returns.
     * @throws \LogicException Always.
     */
    public function stream(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
        ?Closure $stopCheck = null,
    ): StreamableAgentResponse {
        throw new LogicException('Streaming answers are not supported.');
    }

    /**
     * Rejects queued answers.
     *
     * @param \Crustum\Ai\Contracts\AgentInput|\Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Approvals\Decisions|string $prompt User prompt.
     * @param array $attachments Optional attachments.
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider AI provider.
     * @param string|null $model Specific model.
     * @return \Crustum\Ai\Responses\QueuedAgentResponse Never returns.
     * @throws \LogicException Always.
     */
    public function queue(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new LogicException('Queued answers are not supported.');
    }

    /**
     * Rejects broadcast answers.
     *
     * @param \Crustum\Ai\Contracts\AgentInput|\Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Approvals\Decisions|string $prompt User prompt.
     * @param mixed $channels Broadcast channels.
     * @param array $attachments Optional attachments.
     * @param bool $now Immediate broadcast flag.
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider AI provider.
     * @param string|null $model Specific model.
     * @return \Crustum\Ai\Responses\StreamableAgentResponse Never returns.
     * @throws \LogicException Always.
     */
    public function broadcast(
        AgentInput|UserMessage|Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        bool $now = false,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new LogicException('Broadcast answers are not supported.');
    }

    /**
     * Rejects immediate broadcast answers.
     *
     * @param \Crustum\Ai\Contracts\AgentInput|\Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Approvals\Decisions|string $prompt User prompt.
     * @param mixed $channels Broadcast channels.
     * @param array $attachments Optional attachments.
     * @param bool $now Immediate broadcast flag.
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider AI provider.
     * @param string|null $model Specific model.
     * @return \Crustum\Ai\Responses\StreamableAgentResponse Never returns.
     * @throws \LogicException Always.
     */
    public function broadcastNow(
        AgentInput|UserMessage|Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        throw new LogicException('Broadcast answers are not supported.');
    }

    /**
     * Rejects queued broadcast answers.
     *
     * @param \Crustum\Ai\Contracts\AgentInput|\Crustum\Ai\Messages\UserMessage|\Crustum\Ai\Approvals\Decisions|string $prompt User prompt.
     * @param mixed $channels Broadcast channels.
     * @param array $attachments Optional attachments.
     * @param bool $now Immediate broadcast flag.
     * @param \Crustum\Ai\Enums\Lab|array|string|null $provider AI provider.
     * @param string|null $model Specific model.
     * @return \Crustum\Ai\Responses\QueuedAgentResponse Never returns.
     * @throws \LogicException Always.
     */
    public function broadcastOnQueue(
        AgentInput|UserMessage|Decisions|string $prompt,
        mixed $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        throw new LogicException('Broadcast answers are not supported.');
    }
}
