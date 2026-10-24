<?php

use App\Enums\AiProvider;
use App\Enums\PlatformRole;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\PlatformSetting;
use App\Models\TaxDeadline;
use App\Models\TaxRule;
use App\Models\Tenant;
use App\Services\Platform\IntegrationSettings;
use App\Services\Platform\TenantProvisioningService;
use App\Submission\Gateway\HttpGateway;
use App\Submission\Gateway\TaxAuthorityGateway;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(fn () => seedTaxRules());

it('stores AI provider keys encrypted, never shows them, and audits the change', function () {
    signInStaff();

    put(admin('/integrations/ai/anthropic'), ['enabled' => '1', 'api_key' => 'sk-ant-super-secret', 'model' => 'claude-opus-5-5', 'is_default' => '1'])
        ->assertRedirect(admin('/integrations'));

    $settings = app(IntegrationSettings::class);
    expect($settings->aiKey(AiProvider::Anthropic))->toBe('sk-ant-super-secret')
        ->and($settings->ai()['default'])->toBe('anthropic')
        ->and(json_encode(PlatformSetting::find(IntegrationSettings::AI)->value))->not->toContain('sk-ant-super-secret');

    get(admin('/integrations'))->assertOk()
        ->assertSee('Claude (Anthropic)')->assertSee('ChatGPT (OpenAI)')->assertSee('Gemini (Google)')->assertSee('DeepSeek')
        ->assertDontSee('sk-ant-super-secret');

    $log = PlatformAuditLog::query()->where('action', 'integration.ai_updated')->first();
    expect($log->details['key'])->toBe('replaced')->and(json_encode($log->details))->not->toContain('sk-ant');

    // Saving again without a key keeps the stored one.
    put(admin('/integrations/ai/anthropic'), ['enabled' => '1', 'model' => 'claude-sonnet-5-5'])->assertRedirect();
    expect($settings->aiKey(AiProvider::Anthropic))->toBe('sk-ant-super-secret');
});

it('tests a provider connection from the panel', function () {
    signInStaff();
    app(IntegrationSettings::class)->saveAiProvider(AiProvider::DeepSeek, ['enabled' => true], 'sk-deepseek');

    Http::fake(['api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']]])]);

    post(admin('/integrations/ai/deepseek/test'))->assertSessionHas('status');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.deepseek.com/chat/completions' && $request->data()['model'] === 'deepseek-chat');
});

it('configures the tax gateway and the signer, with write-only secrets', function () {
    signInStaff();

    put(admin('/integrations/gateway'), ['driver' => 'http', 'timeout' => 30])->assertSessionHasErrors(['base_url', 'submit_path']);
    put(admin('/integrations/gateway'), ['driver' => 'http', 'base_url' => 'https://operator.example.uz/api', 'submit_path' => '/reports', 'client_id' => 'bugalter', 'client_secret' => 'gw-secret', 'timeout' => 30])->assertRedirect();
    put(admin('/integrations/signer'), ['engine' => 'remote', 'url' => 'https://signer.example.uz', 'token' => 'sig-token'])->assertRedirect();

    $settings = app(IntegrationSettings::class);
    expect($settings->gateway()['driver'])->toBe('http')
        ->and($settings->gatewaySecret())->toBe('gw-secret')
        ->and($settings->signer()['engine'])->toBe('remote')
        ->and(app(TaxAuthorityGateway::class))->toBeInstanceOf(HttpGateway::class);

    get(admin('/integrations'))->assertDontSee('gw-secret')->assertDontSee('sig-token');
});

it('keeps integrations to super admins and lets everyone read the tax rules', function () {
    signInStaff(PlatformRole::Support);

    get(admin('/integrations'))->assertForbidden();
    get(admin('/tax-rules'))->assertOk()->assertSee('vat.standard');
    post(admin('/tax-rules/rules/'.TaxRule::first()->id.'/verify'))->assertForbidden();
});

it('verifies a rule, and a changed value becomes a new unverified version', function () {
    signInStaff();
    $rule = TaxRule::firstWhere('code', 'vat.standard');

    post(admin('/tax-rules/rules/'.$rule->id.'/verify'))->assertRedirect();
    expect($rule->fresh()->isVerified())->toBeTrue();

    // The same value keeps the verification.
    put(admin('/tax-rules/rules/'.$rule->id), ['value' => '12', 'source' => 'Soliq kodeksi'])->assertRedirect();
    expect($rule->fresh()->isVerified())->toBeTrue()->and($rule->fresh()->version)->toBe(1);

    put(admin('/tax-rules/rules/'.$rule->id), ['value' => '12,5'])->assertRedirect();
    expect($rule->fresh()->value)->toBe(1250)
        ->and($rule->fresh()->version)->toBe(2)
        ->and($rule->fresh()->isVerified())->toBeFalse();

    put(admin('/tax-rules/rules/'.$rule->id), ['value' => 'abc'])->assertSessionHasErrors('value');

    expect(PlatformAuditLog::query()->whereIn('action', ['tax_rule.verified', 'tax_rule.updated'])->count())->toBe(3);
});

it('verifies every rule and deadline in one step, for super admins only', function () {
    signInStaff(PlatformRole::Support);
    post(admin('/tax-rules/verify-all'))->assertForbidden();

    signInStaff();
    get(admin('/tax-rules'))->assertSee(__('platform.tax_rules.verify_all'));

    post(admin('/tax-rules/verify-all'))->assertRedirect();

    expect(TaxRule::query()->where('status', '!=', TaxRule::VERIFIED)->count())->toBe(0)
        ->and(TaxDeadline::query()->where('status', '!=', TaxRule::VERIFIED)->count())->toBe(0)
        ->and(PlatformAuditLog::query()->whereIn('action', ['tax_rule.verified', 'tax_deadline.verified'])->count())
        ->toBe(TaxRule::count() + TaxDeadline::count());

    // Nothing left to verify: the button is gone.
    get(admin('/tax-rules'))->assertDontSee(__('platform.tax_rules.verify_all'));
});

it('sets a company subscription end date', function () {
    useSqliteTenancy();
    config(['app.single_company' => false]);
    $plan = Plan::factory()->create();
    app(TenantProvisioningService::class)->provision([
        'name' => 'Acme MChJ', 'stir' => null, 'slug' => 'acme', 'plan_id' => $plan->id,
        'owner_name' => 'Owner', 'owner_email' => 'owner@acme.test', 'owner_phone' => null,
    ]);
    resetRequestContext();
    signInStaff();

    $this->patch(admin('/companies/acme/subscription'), ['subscription_ends_at' => '2030-01-31'])->assertRedirect();
    expect(Tenant::find('acme')->subscription_ends_at->toDateString())->toBe('2030-01-31');

    cleanUpTenants();
});

it('configures the tax cabinet robot with a write-only token and offers the portal gateway', function () {
    signInStaff();

    put(admin('/integrations/portal'), ['driver' => 'robot', 'timeout' => 120])->assertSessionHasErrors('url');
    put(admin('/integrations/portal'), ['driver' => 'robot', 'url' => 'https://robot.example.uz', 'token' => 'robot-secret', 'timeout' => 120])
        ->assertRedirect(admin('/integrations'));

    $settings = app(IntegrationSettings::class);
    expect($settings->portal())->toMatchArray(['driver' => 'robot', 'url' => 'https://robot.example.uz', 'has_token' => true, 'timeout' => 120])
        ->and($settings->portalToken())->toBe('robot-secret')
        ->and(json_encode(PlatformSetting::find(IntegrationSettings::PORTAL)->value))->not->toContain('robot-secret')
        ->and(PlatformAuditLog::query()->where('action', 'integration.portal_updated')->first()->details['token'])->toBe('replaced');

    get(admin('/integrations'))->assertOk()->assertSee(__('platform.integrations.portal_title'))->assertDontSee('robot-secret');

    put(admin('/integrations/gateway'), ['driver' => 'portal', 'timeout' => 30])->assertRedirect();
    expect(app(TaxAuthorityGateway::class)->name())->toBe('portal');
});
