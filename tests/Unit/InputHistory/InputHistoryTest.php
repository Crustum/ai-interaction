<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InputHistory;

use Crustum\Ai\Messages\UserMessage;
use Crustum\AiInteraction\InputHistory\InputHistory;
use Crustum\AiInteraction\Storage\FileStorage;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use Crustum\AiInteraction\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use function array_map;
use function bin2hex;
use function is_dir;
use function is_link;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

/**
 * Unit coverage for participant-scoped input histories.
 */
final class InputHistoryTest extends TestCase
{
    /**
     * Isolated storage directory for file-backed cases.
     *
     * @var string
     */
    private string $directory = '';

    /**
     * Creates an isolated storage directory.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir()
            . '/ai-interaction-input-history-'
            . bin2hex(random_bytes(6));
    }

    /**
     * Removes the isolated storage directory.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    /**
     * Provides storage backends.
     *
     * @return array<string, array{bool}> Storage backends.
     */
    public static function storageKinds(): array
    {
        return ['memory' => [false], 'files' => [true]];
    }

    /**
     * Builds a history sharing the given storage.
     *
     * @param bool $files Use file-backed storage.
     * @param \Crustum\AiInteraction\Storage\StorageInterface $storage Shared storage backend.
     * @return \Crustum\AiInteraction\InputHistory\InputHistory Default history.
     */
    private function history(bool $files, StorageInterface $storage): InputHistory
    {
        if ($files) {
            return new InputHistory(new FileStorage($this->directory), 'users', 7);
        }

        return new InputHistory($storage, 'users', 7);
    }

    /**
     * Builds storage for the default participant scope.
     *
     * @param bool $files Use file-backed storage.
     * @return \Crustum\AiInteraction\Storage\InMemoryStorage|\Crustum\AiInteraction\Storage\FileStorage Scoped storage.
     */
    private function storage(bool $files): InMemoryStorage|FileStorage
    {
        if ($files) {
            return new FileStorage($this->directory);
        }

        return new InMemoryStorage();
    }

    /**
     * Lists stored contents oldest first.
     *
     * @param \Crustum\AiInteraction\InputHistory\InputHistory $history History instance.
     * @return list<string|null> Stored contents.
     */
    private function contents(InputHistory $history): array
    {
        return array_map(static fn(UserMessage $message): ?string => $message->content, $history->entries());
    }

    #[DataProvider('storageKinds')]
    public function testExistingAdaptersSeeEachOthersSubmissionsWithoutLosingEntries(bool $files): void
    {
        $storage = $this->storage($files);
        $first = new InputHistory($storage, 'users', 7);
        $second = new InputHistory($files ? new FileStorage($this->directory) : $storage, 'users', 7);

        self::assertSame([], $first->entries());
        self::assertSame([], $second->entries());

        $first->record(new UserMessage('  Message exactly as submitted  '));
        self::assertSame(['  Message exactly as submitted  '], $this->contents($second));

        $second->record(new UserMessage('/resume session-key'));
        $first->record(new UserMessage("Another\nmessage"));
        $expected = [
            '  Message exactly as submitted  ',
            '/resume session-key',
            "Another\nmessage",
        ];

        self::assertSame($expected, $this->contents($first));
        self::assertSame($expected, $this->contents($second));
        self::assertSame($expected, self::contents($this->history($files, $storage)));
    }

    #[DataProvider('storageKinds')]
    public function testBlankInputsAndOnlyConsecutiveExactDuplicatesAreIgnoredAcrossAdapters(bool $files): void
    {
        $storage = $this->storage($files);
        $first = new InputHistory($storage, 'users', 7);
        $second = new InputHistory($files ? new FileStorage($this->directory) : $storage, 'users', 7);

        $first->record(new UserMessage(" \n\t "));
        self::assertSame([], $second->entries());

        $first->record(new UserMessage('same'));
        $second->record(new UserMessage('same'));
        $first->record(new UserMessage(''));
        $second->record(new UserMessage('different'));
        $first->record(new UserMessage('same'));
        $second->record(new UserMessage(' same '));

        self::assertSame(
            ['same', 'different', 'same', ' same '],
            $this->contents($first),
        );
    }

    #[DataProvider('storageKinds')]
    public function testHistoriesAreIsolatedByParticipant(bool $files): void
    {
        $storage = $this->storage($files);
        $alice = new InputHistory($storage, 'users', 7);
        $bob = new InputHistory($files ? new FileStorage($this->directory) : $storage, 'users', 9);
        $anonymous = new InputHistory($files ? new FileStorage($this->directory) : $storage);

        $alice->record(new UserMessage('alice input'));
        $bob->record(new UserMessage('bob input'));
        $anonymous->record(new UserMessage('anonymous input'));

        self::assertSame(['alice input'], $this->contents($alice));
        self::assertSame(['bob input'], $this->contents($bob));
        self::assertSame(['anonymous input'], $this->contents($anonymous));
        self::assertSame(['alice input'], $this->contents(new InputHistory(
            $files ? new FileStorage($this->directory) : $storage,
            'users',
            7,
        )));
    }

    public function testStoredStringsAreRejected(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('input-history', 'entries', ['old text entry']);

        $this->expectException(UnexpectedValueException::class);

        (new InputHistory($storage))->entries();
    }

    public function testStoredAssistantMessagesAreRejectedAsInput(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('input-history', 'entries', [['role' => 'assistant', 'content' => 'invalid']]);

        $this->expectException(UnexpectedValueException::class);

        (new InputHistory($storage))->entries();
    }

    public function testStoredEntriesWithoutTextAreRejectedAsInput(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('input-history', 'entries', [['content' => null]]);

        $this->expectException(UnexpectedValueException::class);

        (new InputHistory($storage))->entries();
    }

    /**
     * Removes an isolated directory tree.
     *
     * @param string $directory Directory path.
     * @return void
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            if (is_link($directory)) {
                unlink($directory);
            }

            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
