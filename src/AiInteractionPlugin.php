<?php
declare(strict_types=1);

namespace Crustum\AiInteraction;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\MiddlewareQueue;
use Override;

/**
 * Plugin for Crustum/AiInteraction
 */
class AiInteractionPlugin extends BasePlugin
{
    /**
     * @inheritDoc
     */
    #[Override]
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue;
    }

    /**
     * Registers plugin console commands.
     *
     * @param \Cake\Console\CommandCollection $commands Command collection.
     * @return \Cake\Console\CommandCollection Command collection.
     */
    #[Override]
    public function console(CommandCollection $commands): CommandCollection
    {
        return $commands->add('ai agent', Command\AiChatCommand::class);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function services(ContainerInterface $container): void
    {
    }
}
