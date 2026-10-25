<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\InteractionCommand;

use Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface;
use Crustum\AiInteraction\InteractionCommand\CommandInterface;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\Selection;
use Crustum\AiInteraction\InteractionCommand\SelectionOption;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

/**
 * Unit coverage for terminal selections.
 */
final class SelectionTest extends TestCase
{
    public function testSelectionSerializesOrderedOptionsAndReceivesTheValueInANewInvocation(): void
    {
        $request = new Selection('/choose', 'Pick a value', [
            new SelectionOption('007', 'Visible label', 'Detailed description'),
            new SelectionOption(' raw value ', 'Another label'),
        ]);
        $command = new class ($request) implements CommandInterface {
            /**
             * Creates a selection-driven command.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\Selection $request Selection request.
             */
            public function __construct(private Selection $request)
            {
            }

            /**
             * Returns the slash-prefixed identifier.
             *
             * @return string Slash-prefixed command name.
             */
            public function name(): string
            {
                return '/choose';
            }

            /**
             * Returns the test description.
             *
             * @return string Command description.
             */
            public function describe(): string
            {
                return 'Select a value.';
            }

            /**
             * Requests a selection on empty input, notifies the chosen value otherwise.
             *
             * @param \Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface<mixed> $adapter Active adapter.
             * @param string $value Raw input value.
             * @return void
             */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                if ($value === '') {
                    $adapter->requestSelection($this->request);
                    $adapter->notify('First invocation finished.');

                    return;
                }

                $adapter->notify($value);
            }
        };
        $commands = (new Commands())->addCommand([$command]);
        $first = new RecordingCommandAdapter($commands);

        self::assertSame('completed', $commands->run('/choose', '', $first)?->status);
        self::assertSame(['First invocation finished.'], $first->notices);
        self::assertSame([$request], $first->selections);
        self::assertEquals([
            'command' => '/choose',
            'prompt' => 'Pick a value',
            'options' => [
                ['value' => '007', 'label' => 'Visible label', 'description' => 'Detailed description'],
                ['value' => ' raw value ', 'label' => 'Another label', 'description' => null],
            ],
            'description' => null,
        ], json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

        $second = new RecordingCommandAdapter($commands);
        $execution = $commands->run($request->command, $request->options[1]->value, $second);

        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertSame([' raw value '], $second->notices);
        self::assertSame([], $second->selections);
    }

    public function testSelectionRequiresAnOrderedNonEmptyOptionList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A selection must offer an ordered non-empty list of options.');

        new Selection('/choose', 'Pick a value', []);
    }
}
