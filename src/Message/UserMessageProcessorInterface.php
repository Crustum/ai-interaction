<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Message;

use Crustum\Ai\Messages\UserMessage;

/**
 * Preparation and display projection of complete user messages.
 */
interface UserMessageProcessorInterface
{
    /**
     * Prepares ordinary submitted input before it is queued for the agent.
     *
     * Commands and their prepared prompts bypass this method.
     * Throw to reject submission; the caller decides how to report the error.
     * Return a new message without modifying the input or its content blocks.
     *
     * @param \Crustum\Ai\Messages\UserMessage $message Submitted user message.
     * @return \Crustum\Ai\Messages\UserMessage Prepared message for the agent.
     */
    public function forAgent(UserMessage $message): UserMessage;

    /**
     * Projects a complete message for display without changing saved history.
     *
     * Accept unknown content unchanged; do not modify the input or its blocks.
     * Repeated calls with the same original message must produce the same view.
     * This projection need not be an inverse of forAgent().
     *
     * @param \Crustum\Ai\Messages\UserMessage $message Complete user message.
     * @return \Crustum\Ai\Messages\UserMessage Display projection.
     */
    public function forDisplay(UserMessage $message): UserMessage;
}
