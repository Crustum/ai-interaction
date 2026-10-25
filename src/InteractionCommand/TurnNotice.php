<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

/**
 * Informational turn outcome for display.
 */
final readonly class TurnNotice
{
    /**
     * Notice text.
     *
     * @var string
     */
    public string $text;

    /**
     * Notice kind: `notice`, `warning` or `error`.
     *
     * @var string
     */
    public string $kind;

    /**
     * Creates a turn notice value.
     *
     * @param string $text Notice text.
     * @param string $kind Notice kind: `notice`, `warning` or `error`.
     */
    public function __construct(
        string $text,
        string $kind = 'notice',
    ) {
        $this->text = $text;
        $this->kind = $kind;
    }
}
