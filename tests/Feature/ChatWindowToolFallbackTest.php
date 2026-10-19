<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ToolSupport;
use App\Livewire\Chat\ChatWindow;
use App\Models\Project;
use App\Services\Ai\AiProviderConfig;
use App\Services\Ai\ToolSupportVerifier;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request as TextRequest;
use Tests\TestCase;

class ChatWindowToolFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_omits_tools_when_the_provider_is_known_to_reject_function_calling(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        app()->instance(ToolSupportVerifier::class, new class extends ToolSupportVerifier
        {
            public function cached(array $config): ?ToolSupport
            {
                return ToolSupport::Unsupported;
            }
        });

        $fake = Prism::fake([
            TextResponseFake::make()->withText('Answered without tools.'),
        ]);

        Livewire::test(ChatWindow::class)
            ->set('input', 'How is the database doing?')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('errorMessage', ChatWindow::TOOLS_UNSUPPORTED_WARNING);

        $fake->assertRequest(function ($recorded): void {
            $this->assertEmpty($recorded[0]->tools());
        });

        $this->assertDatabaseHas('chat_messages', [
            'role' => 'assistant',
            'content' => 'Answered without tools.',
        ]);
    }

    public function test_chat_retries_without_tools_when_the_provider_rejects_function_calling(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        $manager = $this->fakeProviderThatRejectsToolsFirst();

        Livewire::test(ChatWindow::class)
            ->set('input', 'How is the database doing?')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('errorMessage', ChatWindow::TOOLS_UNSUPPORTED_WARNING);

        $this->assertSame(2, $manager->calls);
        $this->assertNotEmpty($manager->requests[0]->tools());
        $this->assertEmpty($manager->requests[1]->tools());

        $this->assertDatabaseHas('chat_messages', [
            'role' => 'assistant',
            'content' => 'Answered without tools.',
        ]);

        $verdict = app(ToolSupportVerifier::class)->cached(AiProviderConfig::resolve(null));

        $this->assertSame(ToolSupport::Unsupported, $verdict);
    }

    public function test_chat_keeps_reporting_provider_errors_when_the_failure_is_not_about_tools(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        app()->instance(PrismManager::class, new class extends PrismManager
        {
            public function __construct() {}

            public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
            {
                return new class extends Provider
                {
                    public function stream(TextRequest $request): Generator
                    {
                        throw PrismException::providerResponseError('Unauthorized', 401, '{"error":{"message":"invalid api key"}}');
                    }
                };
            }
        });

        $component = Livewire::test(ChatWindow::class)
            ->set('input', 'How is the database doing?')
            ->call('send')
            ->assertHasNoErrors();

        $errorMessage = $component->instance()->errorMessage;

        $this->assertNotSame(ChatWindow::TOOLS_UNSUPPORTED_WARNING, $errorMessage);
        $this->assertStringContainsString('rejected the API key', (string) $errorMessage);
    }

    private function fakeProviderThatRejectsToolsFirst(): object
    {
        $manager = new class extends PrismManager
        {
            /** @var array<int, TextRequest> */
            public array $requests = [];

            public int $calls = 0;

            public function __construct() {}

            public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
            {
                $this->calls++;

                return new class($this, $this->calls) extends Provider
                {
                    public function __construct(
                        private readonly object $manager,
                        private readonly int $call,
                    ) {}

                    public function stream(TextRequest $request): Generator
                    {
                        $this->manager->requests[] = $request;

                        if ($this->call === 1) {
                            throw PrismException::providerResponseError(
                                'Bad request',
                                400,
                                '{"error":{"message":"tools is not supported by this model"}}'
                            );
                        }

                        yield new TextDeltaEvent(
                            id: EventID::generate(),
                            timestamp: time(),
                            delta: 'Answered without tools.',
                            messageId: EventID::generate(),
                        );
                    }
                };
            }
        };

        app()->instance(PrismManager::class, $manager);

        return $manager;
    }
}
