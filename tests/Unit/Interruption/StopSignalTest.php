<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\Interruption;

use Crustum\AiInteraction\Interruption\StopSignal;
use Crustum\AiInteraction\Storage\FileStorage;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use Crustum\AiInteraction\Storage\StorageInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use function bin2hex;
use function is_dir;
use function is_link;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;
use const INF;
use const NAN;

/**
 * Unit coverage for the stop signal contract.
 */
final class StopSignalTest extends TestCase
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
            . '/ai-interaction-stop-'
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
     * Builds storage for the active case.
     *
     * @param bool $files Use file-backed storage.
     * @return \Crustum\AiInteraction\Storage\StorageInterface Storage backend.
     */
    private function storage(bool $files): StorageInterface
    {
        if ($files) {
            return new FileStorage($this->directory);
        }

        return new InMemoryStorage();
    }

    #[DataProvider('storageKinds')]
    public function testRequestClearAndIsRequested(bool $files): void
    {
        $signal = new StopSignal($this->storage($files), 'turn');

        self::assertFalse($signal->isRequested());
        $signal->request();
        self::assertTrue($signal->isRequested());
        $signal->clear();
        self::assertFalse($signal->isRequested());
        $signal->clear();
        self::assertFalse($signal->isRequested());
    }

    #[DataProvider('storageKinds')]
    public function testSignalsAreIsolatedByKey(bool $files): void
    {
        $storage = $this->storage($files);
        $first = new StopSignal($storage, 'first');
        $second = new StopSignal($storage, 'second');

        $first->request();

        self::assertTrue($first->isRequested());
        self::assertFalse($second->isRequested());
    }

    public function testStopCallbackReportsAndClearsARequestedStop(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'turn');
        $polled = 0;
        $callback = $signal->stopCallback(static function () use (&$polled): void {
            $polled++;
        }, 0.0);

        self::assertFalse($callback());
        self::assertSame(1, $polled);
        $signal->request();
        self::assertTrue($callback());
        self::assertSame(2, $polled);
        self::assertFalse($signal->isRequested());
        self::assertFalse($callback());
    }

    public function testStopCallbackWithoutPollHook(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'turn');
        $callback = $signal->stopCallback(null, 0.0);

        self::assertFalse($callback());
        $signal->request();
        self::assertTrue($callback());
    }

    public function testStopCallbackRejectsInvalidIntervals(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'turn');

        foreach ([NAN, INF, -INF, -0.5] as $interval) {
            try {
                $signal->stopCallback(null, $interval);
                self::fail('Invalid polling intervals must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertFalse($signal->isRequested());
            }
        }
    }

    public function testCorruptFlagsAreRejected(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('response-stops', 'turn', ['requested' => 'yes']);

        $signal = new StopSignal($storage, 'turn');

        $this->expectException(UnexpectedValueException::class);

        $signal->isRequested();
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
