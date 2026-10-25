<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InteractionCommand;

use Crustum\AiInteraction\InteractionCommand\CommandInput;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for slash-command invocation values.
 */
final class CommandInputTest extends TestCase
{
    public function testConstructorStoresIdentifierAndValue(): void
    {
        $input = new CommandInput('/resume', 'stored-key');

        self::assertSame('/resume', $input->identifier);
        self::assertSame('stored-key', $input->value);
    }

    public function testValuesPreserveRawOptionText(): void
    {
        $input = new CommandInput('/choose', '  raw value ');

        self::assertSame('/choose', $input->identifier);
        self::assertSame('  raw value ', $input->value);
    }

    public function testInstancesAreIndependent(): void
    {
        $first = new CommandInput('/resume', 'first');
        $second = new CommandInput('/resume', 'second');

        self::assertNotSame($first, $second);
        self::assertSame('first', $first->value);
        self::assertSame('second', $second->value);
    }
}
