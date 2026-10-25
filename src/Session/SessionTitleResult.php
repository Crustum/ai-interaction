<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Session;

/**
 * Short topic title result.
 */
final readonly class SessionTitleResult
{
    /**
     * Short topic title, or null when no concrete topic emerged.
     *
     * @var string|null
     */
    public ?string $title;

    /**
     * Creates a title result value.
     *
     * @param string|null $title Short topic title, or null when no concrete topic emerged.
     */
    public function __construct(
        ?string $title = null,
    ) {
        $this->title = $title;
    }
}
