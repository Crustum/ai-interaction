<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Message;

use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use UnexpectedValueException;
use function array_key_exists;
use function is_string;

/**
 * Builds user messages from generic messages and arrays.
 */
final class UserMessageFactory
{
    /**
     * Returns a user message from any message value.
     *
     * @param \Crustum\Ai\Messages\Message $message Source message.
     * @return \Crustum\Ai\Messages\UserMessage User message.
     * @throws \UnexpectedValueException When the message has no ordinary user role.
     */
    public static function fromMessage(Message $message): UserMessage
    {
        if ($message instanceof UserMessage) {
            return $message;
        }

        if ($message instanceof ToolResultMessage || $message->role !== MessageRole::User) {
            throw new UnexpectedValueException('Only ordinary messages with the user role are supported.');
        }

        return new UserMessage((string)$message->content);
    }

    /**
     * Builds a user message from array data.
     *
     * @param array<string, mixed> $data Message data with role and text content.
     * @return \Crustum\Ai\Messages\UserMessage User message.
     * @throws \UnexpectedValueException When the data has no ordinary user role or text content.
     */
    public static function fromArray(array $data): UserMessage
    {
        $role = $data['role'] ?? null;
        $role = $role instanceof MessageRole ? $role->value : $role;

        if ($role !== 'user' || !array_key_exists('content', $data)) {
            throw new UnexpectedValueException('Only ordinary messages with the user role are supported.');
        }

        $content = $data['content'];

        if (!is_string($content)) {
            throw new UnexpectedValueException('A user message must contain text content.');
        }

        return new UserMessage($content);
    }
}
