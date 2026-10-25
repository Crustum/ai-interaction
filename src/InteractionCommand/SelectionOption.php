<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

/**
 * A stable value and a textual label ready for presentation.
 */
final readonly class SelectionOption
{
    /**
     * Stable option value.
     *
     * @var string
     */
    public string $value;

    /**
     * Visible option label.
     *
     * @var string
     */
    public string $label;

    /**
     * Optional option description.
     *
     * @var string|null
     */
    public ?string $description;

    /**
     * Creates a selection option.
     *
     * @param string $value Stable option value.
     * @param string $label Visible option label.
     * @param string|null $description Optional option description.
     */
    public function __construct(
        string $value,
        string $label,
        ?string $description = null,
    ) {
        $this->value = $value;
        $this->label = $label;
        $this->description = $description;
    }
}
