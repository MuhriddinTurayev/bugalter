<?php

use App\Ai\AiConfig;
use App\Ai\AiSettings;
use App\Ai\AiToolbox;
use App\Ai\Drivers\AnthropicDriver;
use App\Ai\ProposalService;
use App\Enums\AiProvider;
use App\Enums\AutopilotLevel;
use App\Enums\ProposalStatus;
use App\Enums\Role;
use App\Enums\TaxReportStatus;
use App\Models\AiConversation;
use App\Models\AiProposal;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Document;
use App\Models\PlatformSetting;
use App\Models\TaxReport;
use App\Services\CurrentCompany;
use App\Services\Platform\IntegrationSettings;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Carbon::setTestNow('2026-05-10 10:00:00');
    seedTaxRules();
    CompanySetting::create(['name' => 'Test MChJ', 'stir' => '300000009', 'vat_payer' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function enableProvider(AiProvider $provider, string $key = 'sk-platform-secret'): void
{
    $settings = app(IntegrationSettings::class);
    $settings->saveAiProvider($provider, ['enabled' => true, 'model' => null, 'base_url' => null], $key);
    $settings->setDefaultAiProvider($provider);
}

/** An OpenAI-style chat completion response. */
function completion(?string $text, array $toolCalls = []): array
{
    return [
        'choices' => [[
            'finish_reason' => $toolCalls ? 'tool_calls' : 'stop',
            'message' => array_filter(['role' => 'assistant', 'content' => $text, 'tool_calls' => $toolCalls ?: null], fn ($v) => $v !== null),
        ]],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
    ];
}

it('says so when no provider is configured', function () {
    signIn(Role::Owner);

    get(route('ai.index'))->assertOk()->assertSee(__('ai.not_configured_title'));
    postJson(route('ai.message'), ['message' => 'Salom'])->assertStatus(422)->assertJson(['error' => __('ai.errors.not_configured')]);
});

it('answers through ChatGPT with deterministic tools and records the tool calls', function () {
    enableProvider(AiProvider::OpenAi);
    bookDocument('out', 1_000_000, 1200, '2026-04-10');

    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push(completion(null, [[
                'id' => 'call_1', 'type' => 'function',
                'function' => ['name' => 'calculate_tax', 'arguments' => json_encode(['type' => 'vat', 'period_start' => '2026-04-01'])],
            ]]))
            ->push(completion('Aprel uchun QQS: 120 000 soʻm.')),
    ]);

    signIn(Role::Owner);
    $response = postJson(route('ai.message'), ['message' => 'Aprel QQS qancha?'])->assertOk();

    expect($response->json('answer.content'))->toBe('Aprel uchun QQS: 120 000 soʻm.')
        ->and($response->json('answer.tools.0.name'))->toBe(__('ai.tools.calculate_tax'));

    $conversation = AiConversation::first();
    expect($conversation->messages()->count())->toBe(2)
        ->and($conversation->messages->last()->tool_calls[0]['name'])->toBe('calculate_tax')
        ->and($conversation->messages->last()->tool_calls[0]['result'])->toContain('120');

    Http::assertSentCount(2);
    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->hasHeader('Authorization', 'Bearer sk-platform-secret')
            && $body['model'] === 'gpt-5'
            && isset($body['max_completion_tokens'])
            && collect($body['tools'])->pluck('function.name')->contains('calculate_tax');
    });

    // The second request carries the tool result back with the provider's own call id.
    $second = Http::recorded()[1][0]->data();
    expect(collect($second['messages'])->firstWhere('role', 'tool')['tool_call_id'])->toBe('call_1');
});

it('uses the company key over the platform key, stores keys encrypted and never shows them', function () {
    enableProvider(AiProvider::Gemini, 'platform-gemini-key');
    $owner = signIn(Role::Owner);

    $this->put(route('settings.ai.update'), ['ai_provider' => 'deepseek', 'ai_model' => 'deepseek-chat', 'ai_api_key' => 'sk-company-deepseek'])->assertRedirect();

    $config = app(AiSettings::class)->resolve();
    expect($config->provider)->toBe(AiProvider::DeepSeek)
        ->and($config->apiKey)->toBe('sk-company-deepseek')
        ->and($config->source)->toBe('company')
        ->and(DB::table('company_settings')->value('ai_api_key'))->not->toContain('sk-company-deepseek')
        ->and(json_encode(PlatformSetting::find(IntegrationSettings::AI)->value))->not->toContain('platform-gemini-key');

    get(route('settings.ai'))->assertOk()->assertDontSee('sk-company-deepseek')->assertSee(__('ai.settings.key_saved'));
    expect(AuditLog::query()->where('action', 'ai.settings_updated')->first()->details)->not->toHaveKey('api_key');

    signIn(Role::ChiefAccountant);
    get(route('settings.ai'))->assertForbidden();
});

it('hands the chat its settings as real JavaScript, not an uncompiled Blade directive', function () {
    enableProvider(AiProvider::OpenAi);
    signIn(Role::Owner);

    get(route('ai.index'))->assertOk()
        ->assertSee('x-data="aiChat(JSON.parse(', false)
        ->assertDontSee('@js(', false);

    get(route('settings.ai'))->assertOk()->assertDontSee('@js(', false);
});

it('renders confirmation buttons with a working confirm() call', function () {
    signIn(Role::Owner);
    $document = Document::factory()->create();

    get(route('documents.show', $document))->assertOk()
        ->assertSee('onclick="return confirm(&#039;', false)
        ->assertDontSee('@js(', false);
});

it('keeps browsers from filling a saved login into the model and key fields', function () {
    signIn(Role::Owner);

    get(route('settings.ai'))->assertOk()
        ->assertSee('name="ai_api_key"', false)
        ->assertSee('autocomplete="new-password"', false)
        ->assertSee('autocomplete="off"', false);

    // What an autofilled login looks like: an email in the model field.
    $this->put(route('settings.ai.update'), ['ai_provider' => 'gemini', 'ai_model' => 'owner@bugalter.test', 'ai_api_key' => 'x'])
        ->assertSessionHasErrors(['ai_model' => __('ai.settings.model_invalid')]);

    expect(CompanySetting::first()->ai_api_key)->toBeNull();
});

it('builds a correct Messages API request for Claude through the official SDK', function () {
    $history = [];
    $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'tool_use',
        'content' => [
            ['type' => 'text', 'text' => 'Hisoblayman.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_tax_calendar', 'input' => new stdClass],
        ],
        'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
    ]))]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $driver = new AnthropicDriver(new Guzzle(['handler' => $stack]));
    $tools = app(AiToolbox::class)->definitions(['get_tax_calendar', 'calculate_tax']);

    $turn = $driver->chat(new AiConfig(AiProvider::Anthropic, 'claude-opus-5-5', 'sk-ant-test', 'https://api.anthropic.com', 'platform'), 'You are an accountant.', [['role' => 'user', 'content' => 'Kalendar?']], $tools);

    expect($turn->text)->toBe('Hisoblayman.')
        ->and($turn->toolCalls)->toBe([['id' => 'toolu_1', 'name' => 'get_tax_calendar', 'input' => []]])
        ->and($turn->inputTokens)->toBe(50);

    $request = $history[0]['request'];
    $body = json_decode((string) $request->getBody(), true);

    expect((string) $request->getUri())->toBe('https://api.anthropic.com/v1/messages?beta=true')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer sk-ant-test') // how this SDK version sends the key
        ->and($request->getHeaderLine('anthropic-beta'))->toContain('server-side-fallback-2026-07-01')
        ->and($body['model'])->toBe('claude-opus-5-5')
        ->and($body['fallbacks'])->toBe('default')
        ->and($body['output_config'])->toBe(['effort' => 'medium'])
        ->and($body['system'][0]['text'])->toBe('You are an accountant.')
        ->and($body['tools'][1]['name'])->toBe('calculate_tax')
        ->and($body['tools'][1]['input_schema']['required'])->toBe(['type', 'period_start']);
});

it('gives the AI no tool to sign, manage keys, mandates, autopilot or roles', function () {
    $names = collect(app(AiToolbox::class)->definitions())->pluck('name');

    expect($names)->toContain('calculate_tax', 'request_submission', 'propose_transaction_category')
        ->and($names->filter(fn ($n) => preg_match('/sign|key|mandate|autopilot|role|permission|password/', $n))->all())->toBe([]);
});

it('lets the AI ask for a submission but never submit', function () {
    CompanySetting::query()->update(['autopilot_level' => AutopilotLevel::Prepare->value]);
    bookDocument('out', 1_000_000, 1200, '2026-04-10');
    $toolbox = app(AiToolbox::class);

    $prepared = $toolbox->execute('prepare_tax_report', ['type' => 'vat', 'period_start' => '2026-04-01']);
    $result = $toolbox->execute('request_submission', ['report_id' => $prepared['report_id']]);

    expect($result['status'])->toBe('pending_approval')
        ->and(TaxReport::first()->status)->toBe(TaxReportStatus::PendingApproval)
        ->and(TaxReport::first()->prepared_actor)->toBe('ai')
        ->and(AuditLog::query()->where('action', 'tax_report.prepared')->value('actor_type'))->toBe('ai');
});

it('refuses AI drafts while the autopilot is off', function () {
    expect(fn () => app(AiToolbox::class)->execute('prepare_tax_report', ['type' => 'vat', 'period_start' => '2026-04-01']))
        ->toThrow(InvalidArgumentException::class);
});

it('turns AI classifications into proposals and auto-applies only above the owner threshold', function () {
    $rent = Category::factory()->expense()->create(['name' => 'Ijara']);
    $first = bookTransaction('expense', 100_000, '2026-04-02', null);
    $second = bookTransaction('expense', 200_000, '2026-04-03', null);

    $service = app(ProposalService::class);
    $service->proposeCategory($first, $rent, 95, 'Ijara toʻlovi');
    expect($first->fresh()->category_id)->toBeNull(); // auto-apply is off by default

    CompanySetting::query()->update(['ai_auto_apply' => json_encode([AiProposal::CLASSIFY_TRANSACTION => ['enabled' => true, 'min_confidence' => 90]])]);
    app(CurrentCompany::class)->forget();

    $auto = $service->proposeCategory($second, $rent, 92, 'Ijara');
    $low = $service->proposeCategory(bookTransaction('expense', 5_000, '2026-04-04', null), $rent, 60, 'Taxmin');

    expect($auto->status)->toBe(ProposalStatus::AutoApplied)
        ->and($second->fresh()->category_id)->toBe($rent->id)
        ->and($low->status)->toBe(ProposalStatus::Pending)
        ->and(AuditLog::query()->where('action', 'ai.proposal_auto_applied')->value('actor_type'))->toBe('ai');

    signIn(Role::ChiefAccountant);
    $this->patch(route('ai.decide', AiProposal::query()->where('subject_id', $first->id)->first()), ['decision' => 'apply'])->assertRedirect();
    expect($first->fresh()->category_id)->toBe($rent->id);
});
