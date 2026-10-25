<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\Storage;

use Crustum\AiInteraction\Storage\FileStorage;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function array_column;
use function array_diff;
use function array_values;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_link;
use function iterator_to_array;
use function mkdir;
use function random_bytes;
use function restore_error_handler;
use function rmdir;
use function scandir;
use function set_error_handler;
use function strlen;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

/**
 * Unit coverage for file-backed storage documents.
 */
final class FileStorageTest extends TestCase
{
    /**
     * Isolated storage directory for the active test.
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
            . '/ai-interaction-storage-'
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

    public function testStoredMetadataAreDetachedFromExternalReferences(): void
    {
        $storage = new FileStorage($this->directory);
        $project = 'created';
        $created = $storage->create('metadata', [], ['project' => &$project]);
        $project = 'external change';
        self::assertSame(['project' => 'created'], $created->metadata);
        self::assertSame(['project' => 'created'], $storage->read('metadata', $created->key)?->metadata);
        self::assertCount(1, iterator_to_array($storage->entries('metadata', ['project' => 'created'])));

        $written = $storage->write('metadata', $created->key, [], ['project' => &$project]);
        $project = 'another external change';
        self::assertSame('another external change', $project);
        self::assertSame(['project' => 'external change'], $written->metadata);
        self::assertSame(['project' => 'external change'], $storage->read('metadata', $created->key)->metadata);
        self::assertCount(1, iterator_to_array($storage->entries('metadata', ['project' => 'external change'])));
        self::assertSame([], iterator_to_array($storage->entries('metadata', ['project' => 'another external change'])));
    }

    public function testSuppliedKeysCannotBeCreatedTwice(): void
    {
        $storage = new FileStorage($this->directory);
        $created = $storage->create('demo', ['value' => 'first'], key: 'chosen');
        self::assertSame('chosen', $created->key);
        try {
            $storage->create('demo', ['value' => 'second'], key: 'chosen');
            self::fail('An existing key must not be overwritten.');
        } catch (RuntimeException) {
            self::assertSame(['value' => 'first'], $storage->read('demo', 'chosen')?->data);
        }
    }

    public function testEntriesFilterExactMetadataWithAnd(): void
    {
        $storage = new FileStorage($this->directory);
        $match = $storage->create('demo', [], ['userId' => 'alice', 'project' => 'one', 'extra' => 'yes']);
        $storage->create('demo', [], ['userId' => 'alice', 'project' => 'two']);
        $storage->create('demo', [], ['userId' => 'bob', 'project' => 'one']);
        $storage->create('demo', [], ['userId' => 'alice']);
        $storage->create('demo', [], ['userId' => 'Alice', 'project' => 'one']);

        $entries = iterator_to_array($storage->entries('demo', ['userId' => 'alice', 'project' => 'one']));
        self::assertSame([$match->key], array_column($entries, 'key'));
        self::assertCount(5, iterator_to_array($storage->entries('demo')));
        self::assertSame([], iterator_to_array($storage->entries('demo', ['missing' => ''])));
    }

    public function testRepeatedCreatesPublishOnlyOneCompleteDocument(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->create('race', ['winner' => 'first'], key: 'shared');
        try {
            $storage->create('race', ['winner' => 'second'], key: 'shared');
            self::fail('An existing key must not be overwritten.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('The storage key already exists.', $runtimeException->getMessage());
        }

        self::assertSame(['winner' => 'first'], $storage->read('race', 'shared')?->data);
    }

    public function testNumericStringKeysRemainStringsWhenListed(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->write('demo', '123', ['value' => 'numeric key']);

        $entries = iterator_to_array($storage->entries('demo'));

        self::assertCount(1, $entries);
        self::assertSame('123', $entries[0]->key);
        self::assertSame(['value' => 'numeric key'], $entries[0]->data);
        self::assertEquals($storage->read('demo', '123'), $entries[0]);
    }

    public function testInterleavedReadersAndDeletersTreatDisappearingDocumentsAsMissing(): void
    {
        $storage = new FileStorage($this->directory);

        for ($index = 0; $index < 25; $index++) {
            $storage->write('race', 'shared', ['value' => $index]);
            self::assertSame(['value' => $index], $storage->read('race', 'shared')?->data);
            self::assertCount(1, iterator_to_array($storage->entries('race')));
            $storage->delete('race', 'shared');
            self::assertNull($storage->read('race', 'shared'));
            self::assertSame([], iterator_to_array($storage->entries('race')));
        }

        self::assertNull($storage->read('race', 'shared'));
    }

    public function testCreateReturnsANewKeyAndWritesItsJsonFile(): void
    {
        $storage = new FileStorage($this->directory);
        $document = $storage->create(
            'sessions',
            ['value' => 'conversation'],
        );

        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{32}$/D',
            $document->key,
        );
        self::assertSame(['value' => 'conversation'], $document->data);
        self::assertFileExists(
            $this->directory
                . '/sessions/'
                . $document->key
                . '.json',
        );
    }

    public function testReadingAMissingValueDoesNotCreateTheRoot(): void
    {
        $storage = new FileStorage($this->directory);

        self::assertNull($storage->read('sessions', 'missing'));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testListingAMissingNamespaceDoesNotCreateTheRoot(): void
    {
        $storage = new FileStorage($this->directory);

        self::assertSame([], iterator_to_array($storage->entries('sessions')));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testNamespacesAreSeparateDirectories(): void
    {
        $storage = new FileStorage($this->directory);

        $storage->write('sessions', 'known', ['value' => 'conversation']);
        $storage->write('input-history', 'known', ['value' => 'commands']);

        self::assertSame(
            ['value' => 'conversation'],
            $storage->read('sessions', 'known')?->data,
        );
        self::assertSame(
            ['value' => 'commands'],
            $storage->read('input-history', 'known')?->data,
        );
    }

    public function testWritingAnExistingValueAtomicallyReplacesItsFile(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->write('sessions', 'known', ['value' => 'before']);

        $written = $storage->write(
            'sessions',
            'known',
            ['value' => 'after'],
        );

        self::assertSame(
            ['value' => 'after'],
            $written->data,
        );
        self::assertSame(
            ['known.json'],
            array_values(array_diff(
                scandir($this->directory . '/sessions') ?: [],
                ['.', '..'],
            )),
        );
    }

    /**
     * Verifies unsafe namespaces are rejected.
     *
     * @param string $component Unsafe namespace value.
     * @return void
     */
    #[DataProvider('unsafeComponents')]
    public function testUnsafeNamespacesAreRejected(string $component): void
    {
        $storage = new FileStorage($this->directory);

        $this->expectException(InvalidArgumentException::class);

        $storage->write($component, 'key', ['value']);
    }

    /**
     * Verifies unsafe keys are rejected.
     *
     * @param string $component Unsafe key value.
     * @return void
     */
    #[DataProvider('unsafeComponents')]
    public function testUnsafeKeysAreRejected(string $component): void
    {
        $storage = new FileStorage($this->directory);

        $this->expectException(InvalidArgumentException::class);

        $storage->write('namespace', $component, ['value']);
    }

    /**
     * Provides unsafe path components.
     *
     * @return \Generator<string, array{string}> Unsafe components.
     */
    public static function unsafeComponents(): Generator
    {
        yield 'empty' => [''];
        yield 'current directory' => ['.'];
        yield 'parent directory' => ['..'];
        yield 'traversal' => ['../outside'];
        yield 'embedded traversal' => ['safe/../../outside'];
        yield 'absolute path' => ['/outside'];
        yield 'backslash traversal' => ['..\\outside'];
        yield 'backslash separator' => ['safe\\outside'];
        yield 'null byte' => ["safe\0outside"];
    }

    public function testDotsThatDoNotNameADirectoryBoundaryRemainValid(): void
    {
        $storage = new FileStorage($this->directory);

        $storage->write('version..sessions', 'history..json', ['value']);

        self::assertSame(
            ['value'],
            $storage->read('version..sessions', 'history..json')?->data,
        );
    }

    public function testASymbolicLinkCannotRedirectANamespaceOutsideTheRoot(): void
    {
        $outside = $this->directory . '-outside';
        mkdir($this->directory);
        mkdir($outside);
        $linked = $this->quietSymlink($outside, $this->directory . '/sessions');

        if ($linked) {
            try {
                $this->expectException(RuntimeException::class);
                (new FileStorage($this->directory))->write(
                    'sessions',
                    'known',
                    ['value'],
                );
            } finally {
                $this->quietUnlink($this->directory . '/sessions');
                rmdir($outside);
            }

            return;
        }

        $storage = new FileStorage($this->directory);
        $storage->write('sessions', 'known', ['value']);
        self::assertSame(['value'], $storage->read('sessions', 'known')?->data);
        self::assertFileExists($this->directory . '/sessions/known.json');
        $this->removeDirectory($outside);
    }

    public function testASymbolicLinkCannotRedirectAKeyOutsideTheRoot(): void
    {
        $outside = $this->directory . '-outside';
        mkdir($this->directory . '/sessions', 0777, true);
        file_put_contents($outside, 'untouched');
        $linked = $this->quietSymlink($outside, $this->directory . '/sessions/known.json');

        if ($linked) {
            try {
                $this->expectException(RuntimeException::class);
                (new FileStorage($this->directory))->write(
                    'sessions',
                    'known',
                    ['replacement'],
                );
            } finally {
                $this->quietUnlink($this->directory . '/sessions/known.json');
                unlink($outside);
            }

            return;
        }

        $storage = new FileStorage($this->directory);
        $storage->write('sessions', 'known', ['replacement']);
        self::assertSame(['replacement'], $storage->read('sessions', 'known')?->data);
        self::assertSame('untouched', file_get_contents($outside));
        unlink($outside);
    }

    public function testEntriesHideTheFileExtensionAndExposeJsonSize(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->write('sessions', 'known', ['value' => 'conversation']);

        $entries = iterator_to_array($storage->entries('sessions'));

        self::assertCount(1, $entries);
        self::assertSame('known', $entries[0]->key);
        self::assertSame(['value' => 'conversation'], $entries[0]->data);
        self::assertSame(
            strlen('{"value":"conversation"}'),
            $entries[0]->size(),
        );
    }

    public function testMetadataRoundTripsWithData(): void
    {
        $storage = new FileStorage($this->directory);
        $written = $storage->write(
            'sessions',
            'known',
            ['value' => 'conversation'],
            ['lastUsedAt' => '2026-09-03T12:00:00+00:00'],
        );

        self::assertSame(
            ['lastUsedAt' => '2026-09-03T12:00:00+00:00'],
            $written->metadata,
        );
        self::assertSame(
            $written->metadata,
            $storage->read('sessions', 'known')?->metadata,
        );
    }

    public function testDeleteIsIdempotent(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->write('sessions', 'known', ['value']);

        $storage->delete('sessions', 'known');
        $storage->delete('sessions', 'known');

        self::assertNull($storage->read('sessions', 'known'));
    }

    /**
     * Creates a symlink while suppressing platform warnings.
     *
     * @param string $target Link target.
     * @param string $link Link path.
     * @return bool True on success.
     */
    private function quietSymlink(string $target, string $link): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            return symlink($target, $link);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Deletes a link while suppressing race warnings.
     *
     * @param string $path Link path.
     * @return void
     */
    private function quietUnlink(string $path): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            if (is_link($path) || file_exists($path)) {
                unlink($path);
            }
        } finally {
            restore_error_handler();
        }
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
