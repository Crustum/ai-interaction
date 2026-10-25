<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\Message;

use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\Message;
use Crustum\Ai\Messages\MessageRole;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\AiInteraction\Message\UserMessageFactory;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use function collection;

/**
 * Unit coverage for the user message factory.
 */
final class UserMessageFactoryTest extends TestCase
{
    public function testExistingUserMessageIsReturnedDirectly(): void
    {
        $message = new UserMessage('hello');

        self::assertSame($message, UserMessageFactory::fromMessage($message));
    }

    public function testGenericUserMessageIsConverted(): void
    {
        $message = new Message(MessageRole::User, 'hello');

        $converted = UserMessageFactory::fromMessage($message);

        self::assertNotSame($message, $converted);
        self::assertSame('hello', $converted->content);
    }

    public function testArrayIsDeserialized(): void
    {
        $message = UserMessageFactory::fromArray([
            'role' => 'user',
            'content' => 'hello',
        ]);

        self::assertSame('hello', $message->content);
    }

    public function testAssistantMessageIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        UserMessageFactory::fromMessage(new AssistantMessage('hello'));
    }

    public function testToolResultIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        UserMessageFactory::fromMessage(new ToolResultMessage(collection([])));
    }

    public function testOtherRoleInArrayIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        UserMessageFactory::fromArray(['role' => 'assistant', 'content' => 'hello']);
    }

    public function testMissingContentInArrayIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        UserMessageFactory::fromArray(['role' => 'user']);
    }
}
