<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InputHistory;

use Crustum\Ai\Messages\UserMessage;
use Crustum\AiInteraction\InputHistory\InputHistory;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use function array_map;

/**
 * Unit coverage for input history navigation.
 */
final class InputHistoryNavigationTest extends TestCase
{
    public function testTwoComposersRecallTheSameSequenceWithIndependentDraftsAndPositions(): void
    {
        $storage = new InMemoryStorage();
        $history = new InputHistory($storage, 'users', 7);
        $first = new InputHistory($storage, 'users', 7);
        $second = new InputHistory($storage, 'users', 7);

        self::assertNull($first->older());
        self::assertNull($second->newer());

        $history->record(new UserMessage('oldest'));
        $history->record(new UserMessage('middle'));
        $history->record(new UserMessage('newest'));

        self::assertSame('newest', $first->older(new UserMessage('first draft'))?->content);
        self::assertSame('middle', $first->older()?->content);
        self::assertSame('newest', $second->older(new UserMessage('second draft'))?->content);
        self::assertSame('oldest', $first->older()?->content);
        self::assertSame('oldest', $first->older(new UserMessage('ignored draft'))?->content);
        self::assertSame('second draft', $second->newer()?->content);
        self::assertFalse($second->isNavigating());
        self::assertTrue($first->isNavigating());
        self::assertSame('middle', $first->newer()?->content);
        self::assertSame('newest', $first->newer()?->content);
        self::assertSame('first draft', $first->newer()?->content);
        self::assertNull($first->newer());
    }

    public function testLeavingNavigationDiscardsThePreviousDraftAndStartsAtNewest(): void
    {
        $history = new InputHistory(new InMemoryStorage(), 'users', 7);
        $history->record(new UserMessage('remembered'));

        $navigation = $history;

        self::assertSame('remembered', $navigation->older(new UserMessage('discarded draft'))?->content);
        $navigation->leave();

        self::assertFalse($navigation->isNavigating());
        self::assertNull($navigation->newer());
        self::assertSame('remembered', $navigation->older()?->content);
        self::assertSame('', $navigation->newer()?->content);
        self::assertSame(['remembered'], array_map(
            static fn(UserMessage $message): ?string => $message->content,
            $history->entries(),
        ));
    }
}
