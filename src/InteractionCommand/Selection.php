<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

use InvalidArgumentException;
use function array_is_list;

/**
 * A choice to present before invoking the target command again.
 */
final readonly class Selection
{
    /**
     * Ordered non-empty option list.
     *
     * @var non-empty-list<\Crustum\AiInteraction\InteractionCommand\SelectionOption>
     */
    public array $options;

    /**
     * Target command identifier.
     *
     * @var string
     */
    public string $command;

    /**
     * Selection prompt text.
     *
     * @var string
     */
    public string $prompt;

    /**
     * Optional selection description.
     *
     * @var string|null
     */
    public ?string $description;

    /**
     * Creates a selection request.
     *
     * @param string $command Target command identifier.
     * @param string $prompt Selection prompt text.
     * @param array<array-key, \Crustum\AiInteraction\InteractionCommand\SelectionOption> $options Ordered non-empty option list.
     * @param string|null $description Optional selection description.
     * @throws \InvalidArgumentException When options are not an ordered non-empty list.
     */
    public function __construct(
        string $command,
        string $prompt,
        array $options,
        ?string $description = null,
    ) {
        if ($options === [] || !array_is_list($options)) {
            throw new InvalidArgumentException('A selection must offer an ordered non-empty list of options.');
        }

        $this->command = $command;
        $this->prompt = $prompt;
        $this->options = $options;
        $this->description = $description;
    }
}
