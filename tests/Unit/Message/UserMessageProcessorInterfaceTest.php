<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\Message;

use Crustum\Ai\Messages\UserMessage;
use Crustum\AiInteraction\Message\UserMessageProcessorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the user message processor contract.
 */
final class UserMessageProcessorInterfaceTest extends TestCase
{
    public function testProcessorsReturnNewMessagesWithoutMutatingInput(): void
    {
        $original = new UserMessage('Original text');
        $first = $this->processor('A');
        $second = $this->processor('B');

        $prepared = $first->forAgent($original);
        $prepared = $second->forAgent($prepared);

        $displayed = $second->forDisplay($prepared);
        $displayed = $first->forDisplay($displayed);

        self::assertSame('Original text', $original->content);
        self::assertSame('Original textAB', $prepared->content);
        self::assertSame('Original textABBA', $displayed->content);
        self::assertNotSame($original, $prepared);
        self::assertNotSame($prepared, $displayed);
    }

    /**
     * Creates a processor appending a label in both directions.
     *
     * @param string $label Label to append.
     * @return \Crustum\AiInteraction\Message\UserMessageProcessorInterface Appending processor.
     */
    private function processor(string $label): UserMessageProcessorInterface
    {
        return new class ($label) implements UserMessageProcessorInterface {
            /**
             * Appending processor label.
             *
             * @var string
             */
            private string $label;

            /**
             * Creates an appending processor.
             *
             * @param string $label Label to append.
             */
            public function __construct(string $label)
            {
                $this->label = $label;
            }

            /**
             * Appends the label for the agent.
             *
             * @param \Crustum\Ai\Messages\UserMessage $message Submitted user message.
             * @return \Crustum\Ai\Messages\UserMessage Prepared message.
             */
            public function forAgent(UserMessage $message): UserMessage
            {
                return new UserMessage($message->content . $this->label);
            }

            /**
             * Appends the label for display.
             *
             * @param \Crustum\Ai\Messages\UserMessage $message Complete user message.
             * @return \Crustum\Ai\Messages\UserMessage Display projection.
             */
            public function forDisplay(UserMessage $message): UserMessage
            {
                return new UserMessage($message->content . $this->label);
            }
        };
    }
}
