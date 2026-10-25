<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\Message;

use Crustum\Ai\Messages\UserMessage;
use Crustum\AiInteraction\Message\UserMessageProcessorInterface;
use Crustum\AiInteraction\Message\UserMessageProcessors;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit coverage for user message processor pipelines.
 */
final class UserMessageProcessorsTest extends TestCase
{
    public function testCompleteMessagesAreComposedInPreparationAndReverseDisplayOrder(): void
    {
        $original = new UserMessage('Original text');
        $first = $this->processor('A');
        $second = $this->processor('B');
        $processing = (new UserMessageProcessors())->addProcessor([$first, $second]);

        $prepared = $processing->forAgent($original);
        $displayed = $processing->forDisplay($prepared);

        self::assertSame('Original textAB', $prepared->content);
        self::assertSame('Original textABBA', $displayed->content);
        self::assertSame('Original text', $original->content);
        self::assertNotSame($original, $prepared);
        self::assertNotSame($prepared, $displayed);
    }

    public function testAnEmptyPipelineReturnsAnUnchangedCopy(): void
    {
        $original = new UserMessage('Original text');
        $processing = new UserMessageProcessors();

        self::assertEquals($original, $processing->forAgent($original));
        self::assertEquals($original, $processing->forDisplay($original));
        self::assertNotSame($original, $processing->forAgent($original));
        self::assertNotSame($original, $processing->forDisplay($original));
    }

    public function testIncrementalRegistrationJoinsTheSameOrderedPipeline(): void
    {
        $first = $this->processor('A');
        $second = $this->processor('B');
        $third = $this->processor('C');
        $fourth = $this->processor('D');
        $processing = (new UserMessageProcessors())->addProcessor($first);

        self::assertSame($processing, $processing->addProcessor($second));
        self::assertSame($processing, $processing->addProcessor([$third, $fourth]));
        self::assertSame([$first, $second, $third, $fourth], $processing->all());

        $original = new UserMessage('Original text');
        $prepared = $processing->forAgent($original);
        $displayed = $processing->forDisplay($prepared);

        self::assertSame('Original textABCD', $prepared->content);
        self::assertSame('Original textABCDDCBA', $displayed->content);
        self::assertSame('Original text', $original->content);
    }

    public function testInvalidMembersAreRejectedAtRegistration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new UserMessageProcessors())->addProcessor([new stdClass()]);
    }

    /**
     * Builds an appending processor.
     *
     * @param string $label Label appended in both directions.
     * @return \Crustum\AiInteraction\Message\UserMessageProcessorInterface Appending processor.
     */
    private function processor(string $label): UserMessageProcessorInterface
    {
        $processor = $this->createStub(UserMessageProcessorInterface::class);
        $processor->method('forAgent')->willReturnCallback(
            static fn(UserMessage $message): UserMessage => new UserMessage($message->content . $label),
        );
        $processor->method('forDisplay')->willReturnCallback(
            static fn(UserMessage $message): UserMessage => new UserMessage($message->content . $label),
        );

        return $processor;
    }
}
