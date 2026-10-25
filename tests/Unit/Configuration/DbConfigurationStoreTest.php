<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\Configuration;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Crustum\AiInteraction\Configuration\DbConfigurationStore;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;
use stdClass;
use function bin2hex;
use function fclose;
use function fopen;
use function is_resource;
use function random_bytes;
use const INF;
use const NAN;

/**
 * Unit coverage for the database-backed preference store.
 */
final class DbConfigurationStoreTest extends TestCase
{
    /**
     * Fixture aliases managed by CakePHP.
     *
     * @var array<string>
     */
    protected array $fixtures = ['plugin.Crustum/AiInteraction.AiPreferences'];

    /**
     * Participant type owning test preferences.
     *
     * @var string
     */
    private string $participantType = 'users';

    /**
     * Participant identifier owning test preferences.
     *
     * @var string
     */
    private string $participantId = '';

    /**
     * Creates an isolated participant scope.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->participantId = 'test-' . bin2hex(random_bytes(4));
    }

    /**
     * Builds a store for the isolated participant.
     *
     * @return \Crustum\AiInteraction\Configuration\DbConfigurationStore Isolated store.
     */
    private function store(): DbConfigurationStore
    {
        return new DbConfigurationStore($this->participantType, $this->participantId);
    }

    public function testPreferencesTableExistsWithExpectedColumns(): void
    {
        $columns = ConnectionManager::get('test')->getSchemaCollection()->describe('ai_preferences')->columns();

        foreach (['id', 'participant_type', 'participant_id', 'pref_key', 'value', 'created', 'modified'] as $column) {
            self::assertContains($column, $columns);
        }
    }

    public function testInvalidFallbackIsRejectedEvenWhenTheStoredValueIsUsable(): void
    {
        $store = $this->store();
        $store->write('model', 'chosen-model');
        foreach (['', INF, NAN, "\xB1", new stdClass(), [new stdClass()]] as $fallback) {
            try {
                $store->read('model', $fallback);
                self::fail('Fallbacks must be usable JSON data.');
            } catch (InvalidArgumentException) {
                self::assertSame('chosen-model', $store->read('model'));
            }
        }
    }

    public function testFallbackDeterminesTheExactTypeWithoutCoercion(): void
    {
        $store = $this->store();
        foreach (
            [
            [3, 0, '3'],
            [0, 12, 0.0],
            [1.5, 0.0, 1],
            [0.0, 2.5, '2.5'],
            [true, false, 0],
            [false, true, 'false'],
            [['default'], [], 'tools'],
            [[], ['nested' => [true, null, 12]], false],
            ] as [$fallback, $valid, $invalid]
        ) {
            self::assertSame($fallback, $store->read('missing', $fallback));
            $store->write('preference', $valid);
            self::assertSame($valid, $store->read('preference', $fallback));
            $store->write('preference', $invalid);
            self::assertSame($fallback, $store->read('preference', $fallback));
            $store->write('preference', null);
            self::assertSame($fallback, $store->read('preference', $fallback));
        }
    }

    public function testStringFallbackResolvesUnusablePreferencesWithoutChangingThem(): void
    {
        $store = $this->store();
        foreach ([null, '', 0, false, []] as $value) {
            $store->write('model', $value);
            self::assertSame('default-model', $store->read('model', 'default-model'));
            self::assertNull($store->read('model'));
            self::assertSame(['model' => $value], $store->entries());
        }

        foreach (['chosen-model', '0', ' '] as $value) {
            $store->write('model', $value);
            self::assertSame($value, $store->read('model', 'default-model'));
            self::assertSame($value, $store->read('model'));
        }
    }

    public function testPreferencesAreWrittenImmediatelyAndReopened(): void
    {
        $store = $this->store();
        self::assertNull($store->read('missing'));
        self::assertSame('fallback', $store->read('model', 'fallback'));
        self::assertSame([], $store->entries());
        $store->write('model', 'first');
        $store->write('temperature', 1.0);
        $store->write('model', 'second');
        $store->write('nothing', null);

        $reopened = new DbConfigurationStore($this->participantType, $this->participantId);
        self::assertSame('second', $reopened->read('model'));
        self::assertSame('fallback', $reopened->read('nothing', 'fallback'));
        self::assertSame(['model' => 'second', 'nothing' => null, 'temperature' => 1.0], $reopened->entries());
    }

    public function testDeletingOnePreferencePreservesOtherPreferencesAndParticipants(): void
    {
        $alice = new DbConfigurationStore($this->participantType, $this->participantId . '-alice');
        $bob = new DbConfigurationStore($this->participantType, $this->participantId . '-bob');
        $bob->delete('model');
        self::assertSame([], $bob->entries());
        $alice->write('model', 'alice');
        self::assertNull($bob->read('model'));
        $bob->write('model', 'bob');
        $alice->write('tools', ['search']);
        $alice->delete('missing');
        self::assertSame('alice', $alice->read('model'));
        $alice->delete('model');
        $alice->delete('model');
        self::assertSame('fallback', $alice->read('model', 'fallback'));
        self::assertSame(['tools' => ['search']], $alice->entries());
        self::assertSame(['model' => 'bob'], $bob->entries());
        $alice->delete('tools');
        self::assertSame([], $alice->entries());
    }

    public function testInvalidValuesAreRejectedWithoutChangingConfiguration(): void
    {
        $store = $this->store();
        $store->write('valid', 'original');

        $recursive = [];
        $recursive['self'] = &$recursive;
        $resource = fopen('php://memory', 'r');
        $object = new class implements JsonSerializable {
            /**
             * Rejects serialization.
             *
             * @return mixed Never returns.
             * @throws \RuntimeException Always.
             */
            public function jsonSerialize(): mixed
            {
                throw new RuntimeException('Arbitrary objects must never be serialized.');
            }
        };

        try {
            foreach ([new stdClass(), ['nested' => $object], INF, NAN, "\xB1", $resource, $recursive] as $value) {
                try {
                    $store->write('valid', $value);
                    self::fail('Invalid values must be rejected.');
                } catch (InvalidArgumentException) {
                    self::assertSame('original', $store->read('valid'));
                }

                try {
                    $store->write('invalid', $value);
                    self::fail('Invalid initial values must be rejected.');
                } catch (InvalidArgumentException) {
                    self::assertNull($store->read('invalid'));
                }
            }
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function testValuesAndReturnedArraysAreDetached(): void
    {
        $store = $this->store();
        $nested = ['enabled' => true, 'limit' => 12, 'list' => [null, 'original']];
        $store->write('options', ['nested' => &$nested]);
        $nested['list'][1] = 'external mutation';
        $read = $store->read('options', []);
        $read['nested'] = 'changed';
        $entries = $store->entries();
        $entries['options'] = 'changed';
        self::assertSame([
            'nested' => ['enabled' => true, 'limit' => 12, 'list' => [null, 'original']],
        ], $store->read('options', []));
    }

    public function testPreferenceKeysAreLiteral(): void
    {
        $store = new DbConfigurationStore($this->participantType, 'owner/with spaces-' . $this->participantId);
        foreach (['', 'a.b', '../model', '0'] as $key) {
            $store->write($key, 'value:' . $key);
            self::assertSame('value:' . $key, $store->read($key));
        }

        self::assertSame(
            ['' => 'value:', '../model' => 'value:../model', 0 => 'value:0', 'a.b' => 'value:a.b'],
            $store->entries(),
        );
    }
}
