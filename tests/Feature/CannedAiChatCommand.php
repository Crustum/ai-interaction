<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Feature;

use Crustum\Ai\Contracts\Agent;
use Crustum\AiInteraction\Command\AiChatCommand;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use Crustum\AiInteraction\Storage\StorageInterface;
use function array_shift;

/**
 * Host chat command with canned input lines.
 */
final class CannedAiChatCommand extends AiChatCommand
{
    /**
     * Canned input lines.
     *
     * @var list<string>
     */
    public array $lines = [];

    /**
     * Title agent override.
     *
     * @var \Crustum\Ai\Contracts\Agent|null
     */
    public ?Agent $titles = null;

    /**
     * Reads the next canned input line.
     *
     * @param string $prompt Input prompt.
     * @return string|false Canned line or false when lines run out.
     */
    protected function readInput(string $prompt): string|false
    {
        $line = array_shift($this->lines);

        return $line ?? false;
    }

    /**
     * Returns the title agent override when naming is enabled.
     *
     * @param bool $enabled True when conversation titles should be generated.
     * @return \Crustum\Ai\Contracts\Agent|null Title agent override or null.
     */
    protected function titleAgent(bool $enabled): ?Agent
    {
        return $enabled ? $this->titles : null;
    }

    /**
     * Resolves isolated history storage.
     *
     * @return \Crustum\AiInteraction\Storage\StorageInterface Isolated history storage.
     */
    protected function storage(): StorageInterface
    {
        return new InMemoryStorage();
    }
}
