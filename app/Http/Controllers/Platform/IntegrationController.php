<?php

namespace App\Http\Controllers\Platform;

use App\Ai\AiConfig;
use App\Ai\AiException;
use App\Ai\AiSettings;
use App\Enums\AiProvider;
use App\Http\Controllers\Controller;
use App\Services\Platform\IntegrationSettings;
use App\Services\Platform\PlatformAudit;
use App\Support\PlatformUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Integrations entered by the platform owner: AI provider keys (ChatGPT, Claude, Gemini, DeepSeek), the tax
 * authority gateway and the signer service. Secrets are write-only: the page shows only whether one is set.
 */
class IntegrationController extends Controller
{
    public function __construct(private readonly IntegrationSettings $settings, private readonly PlatformAudit $audit) {}

    public function index(): View
    {
        return view('platform.integrations', [
            'ai' => $this->settings->ai(),
            'providers' => AiProvider::cases(),
            'gateway' => $this->settings->gateway(),
            'signer' => $this->settings->signer(),
            'portal' => $this->settings->portal(),
        ]);
    }

    public function updateAi(Request $request, string $provider): RedirectResponse
    {
        $provider = AiProvider::tryFrom($provider) ?? abort(404);

        $data = $request->validate([
            'enabled' => ['boolean'],
            'model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'base_url' => ['nullable', 'url:https', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'remove_key' => ['boolean'],
            'is_default' => ['boolean'],
        ], ['model.regex' => __('ai.settings.model_invalid')], [
            'model' => __('platform.integrations.model'),
            'base_url' => __('platform.integrations.base_url'),
            'api_key' => __('platform.integrations.api_key'),
        ]);

        $key = $request->boolean('remove_key') ? '' : (filled($data['api_key'] ?? null) ? trim($data['api_key']) : null);
        $this->settings->saveAiProvider($provider, ['enabled' => $request->boolean('enabled'), 'model' => $data['model'] ?? null, 'base_url' => $data['base_url'] ?? null], $key);

        if ($request->boolean('is_default')) {
            $this->settings->setDefaultAiProvider($provider);
        } elseif ($this->settings->ai()['default'] === $provider->value && ! $request->boolean('enabled')) {
            $this->settings->setDefaultAiProvider(null);
        }

        $this->audit->record('integration.ai_updated', null, null, [
            'provider' => $provider->value,
            'enabled' => $request->boolean('enabled'),
            'model' => $data['model'] ?? $provider->defaultModel(),
            'key' => $key === null ? 'unchanged' : ($key === '' ? 'removed' : 'replaced'),
            'default' => $request->boolean('is_default'),
        ]);

        return redirect(PlatformUrl::route('integrations'))->with('status', __('platform.integrations.saved', ['name' => $provider->label()]));
    }

    public function testAi(string $provider, AiSettings $ai): RedirectResponse
    {
        $provider = AiProvider::tryFrom($provider) ?? abort(404);
        $config = $this->settings->ai()['providers'][$provider->value];
        $key = $this->settings->aiKey($provider);

        if ($key === null) {
            return back()->with('error', __('platform.integrations.no_key'));
        }

        try {
            $turn = $ai->driver($provider)->chat(new AiConfig($provider, $config['model'], $key, $config['base_url'], 'platform'), 'Reply with the single word OK.', [['role' => 'user', 'content' => 'ping']], []);
        } catch (AiException $e) {
            return back()->with('error', $provider->label().': '.$e->getMessage());
        }

        return back()->with('status', __('platform.integrations.test_ok', ['name' => $provider->label(), 'model' => $config['model'], 'reply' => mb_substr($turn->text, 0, 40)]));
    }

    public function updateGateway(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'driver' => ['required', Rule::in(['sandbox', 'http', 'portal', 'desktop'])],
            'base_url' => ['nullable', 'required_if:driver,http', 'url:https', 'max:255'],
            'submit_path' => ['nullable', 'required_if:driver,http', 'string', 'max:255', 'regex:/^\/?[A-Za-z0-9._~\/-]*$/'],
            'status_path' => ['nullable', 'string', 'max:255', 'regex:/^\/?[A-Za-z0-9._~\/-]*$/'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:1000'],
            'timeout' => ['required', 'integer', 'min:5', 'max:120'],
        ], [], collect(['driver', 'base_url', 'submit_path', 'status_path', 'client_id', 'client_secret', 'timeout'])
            ->mapWithKeys(fn (string $f) => [$f => __('platform.integrations.gateway_fields.'.$f)])->all());

        $secret = filled($data['client_secret'] ?? null) ? trim($data['client_secret']) : null;
        $this->settings->saveGateway($data, $secret);

        $this->audit->record('integration.gateway_updated', null, null, [
            'driver' => $data['driver'],
            'base_url' => $data['base_url'] ?? null,
            'secret' => $secret === null ? 'unchanged' : 'replaced',
        ]);

        return redirect(PlatformUrl::route('integrations'))->with('status', __('platform.integrations.gateway_saved'));
    }

    public function updateSigner(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'engine' => ['required', Rule::in(['sandbox', 'remote'])],
            'url' => ['nullable', 'required_if:engine,remote', 'url:https', 'max:255'],
            'token' => ['nullable', 'string', 'max:1000'],
        ], [], collect(['engine', 'url', 'token'])->mapWithKeys(fn (string $f) => [$f => __('platform.integrations.signer_fields.'.$f)])->all());

        $token = filled($data['token'] ?? null) ? trim($data['token']) : null;
        $this->settings->saveSigner($data['engine'], $data['url'] ?? null, $token);

        $this->audit->record('integration.signer_updated', null, null, ['engine' => $data['engine'], 'token' => $token === null ? 'unchanged' : 'replaced']);

        return redirect(PlatformUrl::route('integrations'))->with('status', __('platform.integrations.signer_saved'));
    }

    public function updatePortal(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'driver' => ['required', Rule::in(['sandbox', 'robot'])],
            'url' => ['nullable', 'required_if:driver,robot', 'url:https', 'max:255'],
            'token' => ['nullable', 'string', 'max:1000'],
            'timeout' => ['required', 'integer', 'min:30', 'max:600'],
        ], [], collect(['driver', 'url', 'token', 'timeout'])->mapWithKeys(fn (string $f) => [$f => __('platform.integrations.portal_fields.'.$f)])->all());

        $token = filled($data['token'] ?? null) ? trim($data['token']) : null;
        $this->settings->savePortal($data['driver'], $data['url'] ?? null, (int) $data['timeout'], $token);

        $this->audit->record('integration.portal_updated', null, null, ['driver' => $data['driver'], 'url' => $data['url'] ?? null, 'token' => $token === null ? 'unchanged' : 'replaced']);

        return redirect(PlatformUrl::route('integrations'))->with('status', __('platform.integrations.portal_saved'));
    }
}
