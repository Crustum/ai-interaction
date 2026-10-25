<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Session;

use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\AgentResponse;
use Crustum\Ai\Responses\StructuredAgentResponse;
use JsonException;
use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function trim;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Generates a topic title from conversation messages without modifying them.
 */
final readonly class SessionTitleGenerator
{
    /**
     * Answering agent producing title results.
     *
     * @var \Crustum\Ai\Contracts\Agent
     */
    private Agent $agent;

    /**
     * Conversation messages titled by the agent.
     *
     * @var iterable<int, mixed>
     */
    private iterable $messages;

    /**
     * Creates a title generator.
     *
     * @param \Crustum\Ai\Contracts\Agent $agent Answering agent producing title results.
     * @param iterable<int, mixed> $messages Conversation messages titled by the agent.
     */
    public function __construct(
        Agent $agent,
        iterable $messages = [],
    ) {
        $this->agent = $agent;
        $this->messages = $messages;
    }

    /**
     * Generates a topic title or null when no topic emerged.
     *
     * Empty conversations return null without calling the agent.
     *
     * @return string|null Topic title or null.
     */
    public function generate(): ?string
    {
        $conversation = $this->conversation($this->messages);

        if ($conversation === []) {
            return null;
        }

        $response = $this->agent->prompt(
            new UserMessage(json_encode($conversation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        );

        return $this->title($response);
    }

    /**
     * Projects messages into role and text pairs.
     *
     * @param iterable<int, mixed> $messages Conversation messages.
     * @return list<array{role: string, content: string}> Text pairs, oldest first.
     */
    private function conversation(iterable $messages): array
    {
        $conversation = [];

        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                continue;
            }

            if ($message->role !== MessageRole::User && $message->role !== MessageRole::Assistant) {
                continue;
            }

            $text = trim((string)$message->content);

            if ($text === '') {
                if ($message instanceof UserMessage && $message->attachments->count() > 0) {
                    $text = '[Attachment]';
                } else {
                    continue;
                }
            }

            $conversation[] = ['role' => $message->role->value, 'content' => $text];
        }

        return $conversation;
    }

    /**
     * Extracts a title from an agent response.
     *
     * @param \Crustum\Ai\Responses\AgentResponse $response Agent response.
     * @return string|null Topic title or null.
     */
    private function title(AgentResponse $response): ?string
    {
        if ($response instanceof StructuredAgentResponse) {
            $title = $response->toArray()['title'] ?? null;
        } else {
            $title = $this->decodedTitle($response->text);
        }

        if (!is_string($title)) {
            return null;
        }

        $title = trim($title);

        return $title === '' ? null : $title;
    }

    /**
     * Decodes a title from response text.
     *
     * @param string $text Response text.
     * @return mixed Decoded title value or null.
     */
    private function decodedTitle(string $text): mixed
    {
        try {
            $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || !array_key_exists('title', $decoded)) {
            return null;
        }

        return $decoded['title'];
    }
}
