<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\InteractionCommand;

/**
 * Submitted slash-command invocation.
 *
 * Carries the dispatched command identifier with the chosen raw value,
 * preserving option values exactly as offered by selections.
 */
final readonly class CommandInput
{
    /**
     * Dispatched slash-prefixed command identifier.
     *
     * @var string
     */
    public string $identifier;

    /**
     * Raw text supplied with the invocation.
     *
     * @var string
     */
    public string $value;

    /**
     * Creates a slash-command invocation value.
     *
     * @param string $identifier Dispatched slash-prefixed command identifier.
     * @param string $value Raw text supplied with the invocation.
     */
    public function __construct(
        string $identifier,
        string $value,
    ) {
        $this->identifier = $identifier;
        $this->value = $value;
    }
}
