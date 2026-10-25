<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Feature;

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\AiManager;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Providers\OpenAiCompatibleProvider;
use Crustum\Ai\Registry\ProviderRegistry;
use Crustum\AiInteraction\Agent\SessionTitleAgent;
use Crustum\AiInteraction\Session\SessionTitleGenerator;
use Crustum\AiInteraction\Session\SessionTitleResult;
use Crustum\JsonSchema\Contracts\JsonSchema;
use Crustum\JsonSchema\Types\StringType;
use PHPUnit\Framework\TestCase;
use function collection;
use function str_contains;

/**
 * Feature coverage for session title generation.
 */
final class SessionTitleTest extends TestCase
{
    /**
     * Configures an isolated Ai manager with a test provider.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Ai::setManager(new AiManager(new ProviderRegistry()));
        Configure::write('Ai.defaultProvider', 'openai');
        Configure::write('Ai.providers.openai', [
            'className' => OpenAiCompatibleProvider::class,
            'name' => 'openai',
            'driver' => 'openai',
            'url' => 'https://api.example.com/openai/v1',
            'key' => 'test-key',
            'models' => [
                'text' => [
                    'default' => 'test-model',
                ],
            ],
        ]);
    }

    public function testGenerationUsesHistoryTextWithoutChangingSources(): void
    {
        SessionTitleAgent::fake([['title' => 'Report review']]);
        $input = new UserMessage('Expanded skill instructions');

        $title = (new SessionTitleGenerator(new SessionTitleAgent(), [
            $input,
            new AssistantMessage('The report needs revision.'),
            new ToolResultMessage(collection([])),
        ]))->generate();

        self::assertSame('Report review', $title);
        SessionTitleAgent::assertPrompted(
            static fn(AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Expanded skill instructions'),
        );
        SessionTitleAgent::assertPrompted(
            static fn(AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'The report needs revision.'),
        );
        SessionTitleAgent::assertPromptedTimes(1);
        self::assertSame('Expanded skill instructions', $input->content);
    }

    public function testNoTopicReturnsNull(): void
    {
        SessionTitleAgent::fake([['title' => null]]);

        self::assertNull(
            (new SessionTitleGenerator(new SessionTitleAgent(), [new UserMessage('ciao, /caveman')]))->generate(),
        );
    }

    public function testEmptyConversationDoesNotCallTheAgent(): void
    {
        SessionTitleAgent::fake([]);

        self::assertNull((new SessionTitleGenerator(new SessionTitleAgent()))->generate());
        self::assertNull(
            (new SessionTitleGenerator(new SessionTitleAgent(), [new UserMessage('   ')]))->generate(),
        );
        SessionTitleAgent::assertNeverPrompted();
    }

    public function testTitleAgentExposesInstructionsAndTitleSchema(): void
    {
        $agent = new SessionTitleAgent();

        self::assertStringContainsString('80 characters', $agent->instructions());

        $builder = $this->createStub(JsonSchema::class);
        $builder->method('string')->willReturn(new StringType());

        $schema = $agent->schema($builder);
        self::assertArrayHasKey('title', $schema);
        self::assertInstanceOf(StringType::class, $schema['title']);
    }

    public function testTitleResultCarriesAnOptionalTitle(): void
    {
        self::assertSame('Report review', (new SessionTitleResult('Report review'))->title);
        self::assertNull((new SessionTitleResult())->title);
    }
}
