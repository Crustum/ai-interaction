# CakePHP AiInteraction Plugin

- [Introduction](#introduction)
- [Installation](#installation)
- [Configuration](#configuration)
- [Turn Runner](#turn-runner)
- [Session](#session)
- [Slash Commands](#slash-commands)
    - [Dispatching](#dispatching)
    - [Built-in Commands](#built-in-commands)
    - [Custom Commands](#custom-commands)
    - [Command Input](#command-input)
- [Command Adapters](#command-adapters)
- [Command Interceptor](#command-interceptor)
- [Message Processing](#message-processing)
- [Input History](#input-history)
- [Preferences](#preferences)
- [Session Titles](#session-titles)
- [Stop Signal](#stop-signal)
- [Host Chat Command](#host-chat-command)

<a name="introduction"></a>
## Introduction

The **AiInteraction** plugin gives CakePHP hosts slash-commands, session titles,
input history, per-user preferences and a response-stop contract — UI-agnostic.
Terminal hosts consume it through `crustum/ai-tui`, web hosts through the command
interceptor. The AI driver itself stays in `crustum/cakephp-ai`; this plugin depends
on it, never the reverse. Conversation rows persist in the driver
(`DatabaseConversationStore`).

<a name="installation"></a>
## Installation

```bash
composer require crustum/ai-interaction
bin/cake plugin load Crustum/AiInteraction
```

Web hosts provision the preferences table through the plugin manifest
(`manifest install` copies namespaced migrations into the host):

```bash
bin/cake manifest install Crustum/AiInteraction
bin/cake migrations migrate
```

Console hosts using only in-memory or file backends may skip it.

<a name="configuration"></a>
## Configuration

The plugin ships no config file and reads no `AiInteraction.*` keys: table names
are fixed (`ai_preferences`), and conversation tables and connections follow the
driver (`Ai.conversations.*`).

<a name="turn-runner"></a>
## Turn Runner

Turns are the central flow: every submitted input becomes one turn — slash-commands
dispatch to turn outcomes, plain text records history and streams driver events
untouched. `TurnRunner` runs one turn as a single iterable. Service outcomes surface
as values: `TurnNotice`, `TurnSelection`, `TurnTask`, `TurnConversation`,
`TurnStopped`. A `TurnTask` carries text the host runs as the next turn input
instead of rendering it. The terminal state returns from iteration:

```php
use Crustum\AiInteraction\InteractionCommand\TurnResult;

$turn = $runner->run($input);

foreach ($turn as $item) {
    // StreamEvent items render as-is; Turn* values drive UI effects.
}

$result = $turn->getReturn();
$result->stopped; // Esc was requested mid-turn
$result->interrupted; // paused for approval results
```

> **Note:** generators are single-use — iterate once, then read `getReturn()`.
> Breaking out early abandons the turn with no result.

Interruption is cooperative: `requestInterruption()` records a stop,
`wasInterrupted()` reads it, and the flag clears on every `run()` start.
A requested stop surfaces at the next streamed event boundary; the agent
stream also receives the shared stop predicate for the driver to poll between
chunks. A driver read blocked with no events flowing cannot observe the stop
until the read unblocks — aborting the transport read itself stays the
driver's responsibility.
`ConversationMessages` supplies display messages for history sync from the driver
store:

```php
use Crustum\AiInteraction\InteractionCommand\ConversationMessages;

$messages = (new ConversationMessages($store, $conversationId))->messages();
```

Hosts extend the turn without forking it through three seams, all driver types
only. The pre-turn transform rewrites the submitted input first:

```php
$turns = new TurnRunner(
    $interceptor,
    $adapter,
    $agent,
    $history,
    $stop,
    preTurn: static fn(string|CommandInput $input): string|CommandInput => $input,
    preStream: static fn(UserMessage $message): ?string => null, // notice text vetoes the run
    postTurn: static function (TurnResult $result): void {},
);
```

Returning notice text from the pre-stream gate skips the agent run and yields
it as a warning; the post-turn hook observes every terminal turn state,
including command-only turns.

<a name="session"></a>
## Session

`Session` owns one current conversation identifier and applies it to the adapter
and the answering agent together, so hosts never bind them separately:

```php
use Crustum\AiInteraction\Session\Session;

$session = new Session($adapter);
$session->boot(); // rebind selected, continue latest, or start fresh
$session->id(); // current identifier
$session->clear(); // fresh conversation on both sides
$session->resume($id); // stored conversation on both sides
```

`boot()` continues the participant's latest conversation on a restart instead of
orphaning history in a new row. Selecting or starting through the adapter always
continues a remembering agent on the same identifier, keeping history sync and
turn persistence on one row.

<a name="slash-commands"></a>
## Slash Commands

<a name="dispatching"></a>
### Dispatching

Mount commands in order; the first exact identifier wins. Dispatch admits the
command, runs it with the preserved raw value, then completes through the adapter.
Unknown identifiers complete as `unknown` without admission; refused admission
returns `null` without dispatch or completion. Exceptions from admission or
completion propagate to the caller and are never retried as command failures:

```php
use Crustum\AiInteraction\InteractionCommand\ClearCommand;
use Crustum\AiInteraction\InteractionCommand\Commands;
use Crustum\AiInteraction\InteractionCommand\HelpCommand;
use Crustum\AiInteraction\InteractionCommand\LeaveCommand;
use Crustum\AiInteraction\InteractionCommand\ResumeCommand;

$commands = (new Commands())->addCommand([
    new ClearCommand(),
    new HelpCommand(),
    new LeaveCommand(),
    new ResumeCommand(),
]);

$commands->run('/resume', '', $adapter);
```

Unknown identifiers complete as `unknown` without admission. Refused admission
returns `null` without dispatch or completion. Command failures keep the original
exception on the execution outcome while the dispatcher stays usable. Mounted
identifiers must start with a slash.

`CommandExecution` is a technical outcome, not a domain result: `completed` means
the invocation returned, while a requested selection or agent response may still
be pending. Command failures do not roll back effects already performed.

<a name="built-in-commands"></a>
### Built-in Commands

- `/clear` starts a fresh conversation, keeping the replaced row where it is stored.
- `/help` lists mounted commands through the adapter.
- `/exit` requests the adapter to stop its interaction.
- `/resume` continues a stored conversation after an ownership check, or requests
  a conversation selection when invoked without a key. With no stored conversations
  it warns instead of selecting.

Each built-in accepts a custom slash-prefixed name:

```php
new HelpCommand('/guide');
new LeaveCommand('/quit');
```

> **Note:** `HelpCommand` and `LeaveCommand` are concurrent commands: adapters may
> admit them while the agent is working.

<a name="custom-commands"></a>
### Custom Commands

```php
use Crustum\AiInteraction\InteractionCommand\CommandAdapterInterface;
use Crustum\AiInteraction\InteractionCommand\CommandInterface;

final readonly class ReviewCommand implements CommandInterface
{
    public function name(): string
    {
        return '/review';
    }

    public function describe(): string
    {
        return 'Reviews the current conversation.';
    }

    /** @param CommandAdapterInterface<mixed> $adapter */
    public function run(CommandAdapterInterface $adapter, string $value): void
    {
        $adapter->notify('Reviewing: ' . $value);
    }
}
```

Commands resolving to task input call `$adapter->requestTask($text)` instead
of notifying: the text surfaces as a `TurnTask` outcome for the host loop to
run, never as chat text. Pass an `$origin` label (`requestTask($text,
'/review')`) to show where the task came from — the host renders a `❯ /review`
line instead of the text; without it the turn runs silently.
```

<a name="command-input"></a>
### Command Input

`CommandInput` carries a dispatched identifier with its raw value, preserving
option values exactly as offered by selections:

```php
use Crustum\AiInteraction\InteractionCommand\CommandInput;

$input = new CommandInput('/resume', 'stored-key');
$input->identifier; // '/resume'
$input->value; // 'stored-key'
```

Turns accept `string|CommandInput`.

<a name="command-adapters"></a>
## Command Adapters

Adapters supply the presentation-independent invocation lifecycle: admission,
completion output, `notify`/`warn`/`error` messaging, agent prompts, selection
requests, the answering agent, the selected conversation, mounted commands, the
driver conversation store, the preference store, participant context and stop.

`CakeCommandAdapter` is the request-scoped host adapter. Completion returns
response data instead of rendering anything:

```php
$response = $commands->run('/help', '', $adapter);
// [
//     'identifier' => '/help',
//     'status' => 'completed',
//     'error' => null,
//     'notices' => [...],
//     'warnings' => [...],
//     'errors' => [...],
//     'selection' => null,
//     'stopped' => false,
//     'conversationId' => '...',
// ]
```

`startConversation()` stores a fresh driver row for the adapter participant and
selects it. `useConversation()` only selects conversations belonging to the
participant. `conversationSummaries()` lists owned conversations, newest first.

The `errors` list holds expected failures reported by commands; the singular
`error` field holds the dispatch exception message, if any.

<a name="command-interceptor"></a>
## Command Interceptor

Hosts route `/`-prefixed input through commands before agent runs, preserving the
raw value after the identifier. Ordinary input bypasses dispatch with `null`:

```php
use Crustum\AiInteraction\InteractionCommand\CommandInterceptor;

$interceptor = new CommandInterceptor($commands);
$output = $interceptor->intercept($input, $adapter);

if ($output === null) {
    // Ordinary input: continue to the agent flow.
}
```

<a name="message-processing"></a>
## Message Processing

`UserMessageProcessorInterface` transforms complete user messages in two
directions: `forAgent()` prepares ordinary submitted input before it enters the
agent flow (commands and their prepared prompts bypass it; throwing rejects the
submission), while `forDisplay()` projects a message for rendering without
changing saved history. Both return a new message and must not modify the input.
Repeated display calls with the same message must produce the same view; display
need not reverse preparation:

```php
use Crustum\AiInteraction\Message\UserMessageProcessors;

$processing = (new UserMessageProcessors())->addProcessor([$first, $second]);
$prepared = $processing->forAgent($submitted);
$display = $processing->forDisplay($prepared);
```

Preparation applies processors in registration order, display in reverse order.
An empty pipeline returns an unchanged copy. `UserMessageFactory` builds user
messages from generic messages (`fromMessage()`, passing user messages through)
and from `{role, content}` arrays (`fromArray()`); other roles are rejected.

<a name="input-history"></a>
## Input History

`InputHistory` records submitted text (including command syntax, never generated
prompts), navigates older/newer with draft restoration, and scopes every
participant to its own document:

```php
use Crustum\AiInteraction\InputHistory\InputHistory;
use Crustum\AiInteraction\Storage\FileStorage;

$history = new InputHistory(new FileStorage($directory), 'users', $userId);
$history->record(new UserMessage($line));
$older = $history->older();
$newer = $history->newer();
```

Blank inputs are ignored and consecutive exact duplicates collapse.

The same instance optionally provides recall navigation with an in-memory draft;
only the input sequence is shared, positions and drafts stay per instance:

```php
$recalled = $history->older(new UserMessage('Unsubmitted draft'));
$newer = $history->newer(); // Restores the draft past the newest input.
$history->isNavigating();
$history->leave(); // Discards the navigation position and saved draft.
```

Record original submissions, including command syntax; never record prompts
generated by commands. A web frontend can use only `entries()` and navigate
locally without a backend call for each key.

<a name="preferences"></a>
## Preferences

Both backends share `ConfigurationStoreInterface` with identical typed semantics:
the fallback selects the exact PHP type, and a `null` fallback requests a
non-empty string or `null`.

```php
$store->write('model', 'chosen-model');
$store->read('model', 'default-model'); // 'chosen-model'
$store->read('temperature', 0.0); // float or 0.0
$store->delete('model');
$store->entries(); // all participant preferences
```

Keys are literal strings, not paths or nested expressions. Integer and float are
distinct types with no coercion, and `'0'`, `0`, `0.0`, `false` and `[]` stay valid
for their types. Reads never repair stored data; returned arrays are detached,
so later mutations need an explicit write. Storage failures propagate to the
caller. The DB backend returns entries in key order.

- `ConfigurationStore` persists JSON documents per user (in-memory default, file
  storage for console hosts).
- `DbConfigurationStore` persists rows in `ai_preferences`
  (`participant_type`, `participant_id`, `pref_key`) for web hosts.

<a name="session-titles"></a>
## Session Titles

`SessionTitleGenerator` projects conversation text and asks any agent for a title,
returning `null` when no topic emerged or the conversation is empty (without
calling the agent):

```php
use Crustum\AiInteraction\Agent\SessionTitleAgent;
use Crustum\AiInteraction\Session\SessionTitleGenerator;

$title = (new SessionTitleGenerator(new SessionTitleAgent(), $messages))->generate();
```

`SessionTitleAgent` carries fixed instructions plus a nullable 80-character title
schema; `SessionTitleResult` holds the optional title. The host decides when to
run generation (for example after successful turns) and persists the title on the
driver conversation row; provider errors propagate to the host.

<a name="stop-signal"></a>
## Stop Signal

`StopSignal` is a shared stop flag under a host-chosen key, visible across
processes. Streaming loops poll it between chunks:

```php
use Crustum\AiInteraction\Interruption\StopSignal;

$signal = new StopSignal($storage, 'turn');
$signal->request();
$signal->isRequested(); // true
$signal->clear();

$shouldStop = $signal->stopCallback($onPoll, 0.01);
$shouldStop(); // true once, then clears itself
```

> **Note:** the flag alone does not abort in-flight provider calls. Aborting
> streaming reads is the driver's responsibility; the flag observes stops between
> chunks and turns.

Clear the signal before streaming and request it to stop. Separate endpoints (or
keyboard handlers) share storage at the same location under the same
application-chosen key — web hosts need separate server workers so both run
concurrently. Use a conversation key when turns run serialized; concurrent
streams need different keys, otherwise a late stop can hit the new response:

```php
use Crustum\AiInteraction\Interruption\StopSignal;
use Crustum\AiInteraction\Storage\FileStorage;

$stopSignal = new StopSignal(new FileStorage('/app/interaction-state'), $chatId);
$stopSignal->clear();

foreach ($agent->stream($prompt, stopCheck: $stopSignal->stopCallback()) as $event) {
    // Render events; the driver closes the transport on stop.
}
```

The callback reads storage at most once per interval (default 0.01 seconds) —
there is no background task. An optional hook runs before each check, letting a
terminal host yield to input processing. Invoking the callback consumes a
requested stop; `isRequested()` only reads. Repeated requests and clears are
idempotent, and constructing the signal writes nothing. Wire the callback as the
driver stop predicate (`stream()` takes it directly); without it the flag is
only observed between chunks and turns.

Limits: this stops an HTTP response, not a whole tool-using turn — local tools
continue and follow-up inference can start. A stop cannot abort a blocked read,
and remote billing cancellation is not guaranteed. Storage failures propagate
to the host. An unconsumed flag remains until cleared before the next stream.

<a name="host-chat-command"></a>
## Host Chat Command

`bin/cake ai agent` runs a readline chat loop with history, slash dispatch and a
blocking agent flow:

```bash
bin/cake ai agent --agent="App\Ai\Agents\Assistant" --user=7 --type=users
```

`/exit` (or end of input) ends the session with a success exit code.
