<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\RemembersConversations;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\AiInteraction\Agent\SessionTitleAgent;
use Crustum\AiInteraction\Configuration\ConfigurationStore;
use Crustum\AiInteraction\InputHistory\InputHistory;
use Crustum\AiInteraction\InteractionCommand\CakeCommandAdapter;
use Crustum\AiInteraction\InteractionCommand\ClearCommand;
use Crustum\AiInteraction\InteractionCommand\CommandInterceptor;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\HelpCommand;
use Crustum\AiInteraction\InteractionCommand\LeaveCommand;
use Crustum\AiInteraction\InteractionCommand\ResumeCommand;
use Crustum\AiInteraction\InteractionCommand\Selection;
use Crustum\AiInteraction\Session\ConversationTitler;
use Crustum\AiInteraction\Session\Session;
use Crustum\AiInteraction\Storage\InMemoryStorage;
use Crustum\AiInteraction\Storage\StorageInterface;
use function fgets;
use function function_exists;
use function is_string;
use function is_subclass_of;
use function preg_match;
use function readline;
use function readline_add_history;
use function rtrim;
use function str_starts_with;
use function trim;
use const STDIN;

/**
 * Host chat command reading input lines until exit.
 */
class AiChatCommand extends Command
{
    /**
     * Returns the command name.
     *
     * @return string Command name.
     */
    public static function defaultName(): string
    {
        return 'ai agent';
    }

    /**
     * Builds the option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Option parser.
     * @return \Cake\Console\ConsoleOptionParser Configured parser.
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Chat with an agent using slash-commands.')
            ->addOption('agent', [
                'short' => 'a',
                'help' => 'Agent class name answering prompts.',
                'required' => true,
            ])
            ->addOption('user', [
                'short' => 'u',
                'help' => 'Participant uuid scoping history and conversations.',
            ])
            ->addOption('type', [
                'short' => 't',
                'help' => 'Participant type.',
                'default' => 'console',
            ])
            ->addOption('titles', [
                'boolean' => true,
                'help' => 'Generate conversation titles from the first turns.',
                'default' => false,
            ]);
    }

    /**
     * Runs the chat loop.
     *
     * @param \Cake\Console\Arguments $args Command arguments.
     * @param \Cake\Console\ConsoleIo $io Console input-output.
     * @return int|null Exit code.
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $agentClass = $args->getOption('agent');

        if (!is_string($agentClass) || $agentClass === '' || !is_subclass_of($agentClass, Agent::class)) {
            $io->error('An answering agent class name is required.');

            return static::CODE_ERROR;
        }

        $userId = $args->getOption('user');

        if (!is_string($userId) || $userId === '') {
            $io->error('A participant user uuid is required. Pass --user with your user identifier.');

            return static::CODE_ERROR;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $userId) !== 1) {
            $io->error('The participant identifier must be a uuid matching your users table.');

            return static::CODE_ERROR;
        }

        $participantType = $args->getOption('type');
        $participantType = is_string($participantType) && $participantType !== '' ? $participantType : 'console';

        $participant = (object)['id' => $userId];

        $agent = $this->makeAgent($agentClass);

        if ($agent instanceof RemembersConversations) {
            $agent = $agent->forUser($participant);
        }

        $store = new DatabaseConversationStore();
        $commands = (new Commands())->addCommand([
            new ClearCommand(),
            new HelpCommand(),
            new LeaveCommand(),
            new ResumeCommand(),
        ]);
        $adapter = new CakeCommandAdapter(
            $agent,
            $commands,
            $store,
            static function (Agent $agent, UserMessage $prompt): void {
                $agent->prompt($prompt);
            },
            new ConfigurationStore(new InMemoryStorage(), $userId),
            $participantType,
            $userId,
            $participant,
        );
        $interceptor = new CommandInterceptor($commands);
        $history = new InputHistory($this->storage());
        $session = new Session($adapter);
        $session->boot();

        $titler = new ConversationTitler($this->titleAgent((bool)$args->getOption('titles')));

        $io->out('Type /help for commands, /exit to stop.');

        while (true) {
            $line = $this->readInput('> ');

            if ($line === false) {
                break;
            }

            if (trim($line) === '') {
                continue;
            }

            $history->record(new UserMessage($line));

            if (str_starts_with($line, '/')) {
                /** @var array{identifier: string, status: string, error: ?string, notices: list<string>, warnings: list<string>, errors: list<string>, selection: mixed, stopped: bool, conversationId: ?string} $response */
                $response = $interceptor->intercept($line, $adapter);
                $this->printResponse($io, $response);

                if ($response['stopped'] === true) {
                    break;
                }

                continue;
            }

            $io->out($agent->prompt(new UserMessage($line))->text);
            $titler->titleUntitled($adapter);
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Builds the title agent when naming is enabled.
     *
     * @param bool $enabled True when conversation titles should be generated.
     * @return \Crustum\Ai\Contracts\Agent|null Title agent or null when disabled.
     */
    protected function titleAgent(bool $enabled): ?Agent
    {
        return $enabled ? new SessionTitleAgent() : null;
    }

    /**
     * Builds the answering agent.
     *
     * @param class-string<\Crustum\Ai\Contracts\Agent> $class Agent class name.
     * @return \Crustum\Ai\Contracts\Agent Answering agent.
     */
    protected function makeAgent(string $class): Agent
    {
        return new $class();
    }

    /**
     * Resolves input history storage.
     *
     * @return \Crustum\AiInteraction\Storage\StorageInterface History storage.
     */
    protected function storage(): StorageInterface
    {
        return new InMemoryStorage();
    }

    /**
     * Reads one input line.
     *
     * @param string $prompt Input prompt.
     * @return string|false Input line or false on end of input.
     */
    protected function readInput(string $prompt): string|false
    {
        if (function_exists('readline')) {
            $line = readline($prompt);

            if ($line !== false && $line !== '' && function_exists('readline_add_history')) {
                readline_add_history($line);
            }

            return $line;
        }

        echo $prompt;
        $line = fgets(STDIN);

        if ($line === false) {
            return false;
        }

        return rtrim($line, "\r\n");
    }

    /**
     * Prints command response data.
     *
     * @param \Cake\Console\ConsoleIo $io Console input-output.
     * @param array{identifier: string, status: string, error: ?string, notices: list<string>, warnings: list<string>, errors: list<string>, selection: mixed, stopped: bool, conversationId: ?string} $response Response data.
     * @return void
     */
    private function printResponse(ConsoleIo $io, array $response): void
    {
        foreach ($response['notices'] as $notice) {
            $io->out($notice);
        }

        foreach ($response['warnings'] as $warning) {
            $io->warning($warning);
        }

        foreach ($response['errors'] as $error) {
            $io->error($error);
        }

        $selection = $response['selection'];

        if ($selection instanceof Selection) {
            foreach ($selection->options as $option) {
                $io->out($option->label . ' (' . $option->value . ')');
            }
        }
    }
}
