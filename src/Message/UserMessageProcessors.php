<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Message;

use Crustum\Ai\Messages\UserMessage;
use InvalidArgumentException;
use function array_reverse;
use function is_array;

/**
 * Composes preparation in registration order and display in reverse order.
 */
final class UserMessageProcessors implements UserMessageProcessorInterface
{
    /**
     * Registered processors in registration order.
     *
     * @var list<\Crustum\AiInteraction\Message\UserMessageProcessorInterface>
     */
    private array $processors = [];

    /**
     * Registers processors before running the host.
     *
     * Registration mutates this collection.
     *
     * @param \Crustum\AiInteraction\Message\UserMessageProcessorInterface|list<\Crustum\AiInteraction\Message\UserMessageProcessorInterface> $processors Processor or list of processors.
     * @return self This collection.
     * @throws \InvalidArgumentException When a member is not a user message processor.
     */
    public function addProcessor(UserMessageProcessorInterface|array $processors): self
    {
        foreach (is_array($processors) ? $processors : [$processors] as $processor) {
            $this->processors[] = $this->requireProcessor($processor);
        }

        return $this;
    }

    /**
     * Returns registered processors in registration order.
     *
     * @return list<\Crustum\AiInteraction\Message\UserMessageProcessorInterface> Registered processors.
     */
    public function all(): array
    {
        return $this->processors;
    }

    /**
     * Validates a single registered member.
     *
     * @param mixed $processor Registered member.
     * @return \Crustum\AiInteraction\Message\UserMessageProcessorInterface Validated processor.
     * @throws \InvalidArgumentException When the member is not a user message processor.
     */
    private function requireProcessor(mixed $processor): UserMessageProcessorInterface
    {
        if (!$processor instanceof UserMessageProcessorInterface) {
            throw new InvalidArgumentException('A user-message processor must implement UserMessageProcessorInterface.');
        }

        return $processor;
    }

    /**
     * Prepares submitted input through every processor in order.
     *
     * @param \Crustum\Ai\Messages\UserMessage $input Submitted user message.
     * @return \Crustum\Ai\Messages\UserMessage Prepared message.
     */
    public function forAgent(UserMessage $input): UserMessage
    {
        $input = clone $input;

        foreach ($this->processors as $processor) {
            $input = $processor->forAgent($input);
        }

        return $input;
    }

    /**
     * Projects a complete message through every processor in reverse order.
     *
     * @param \Crustum\Ai\Messages\UserMessage $content Complete user message.
     * @return \Crustum\Ai\Messages\UserMessage Display projection.
     */
    public function forDisplay(UserMessage $content): UserMessage
    {
        $content = clone $content;

        foreach (array_reverse($this->processors) as $processor) {
            $content = $processor->forDisplay($content);
        }

        return $content;
    }
}
