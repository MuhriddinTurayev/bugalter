<?php

namespace App\Http\Controllers;

use App\Ai\AiConfig;
use App\Ai\AiException;
use App\Ai\AiSettings;
use App\Enums\AiProvider;
use App\Services\Audit;
use App\Services\CurrentCompany;
use App\Services\Platform\IntegrationSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The owner picks the AI provider for the company and may enter the company's own API key. The key is stored
 * encrypted and never shown again; without one, the platform's key for that provider is used.
 */
class AiSettingsController extends Controller
{
    public function edit(CurrentCompany $company, AiSettings $ai, IntegrationSettings $platform): View
    {
        $this->authorize('manage-users');

        $settings = $company->settings();

        return view('settings.ai', [
            'settings' => $settings,
            'providers' => AiProvider::cases(),
            'platform' => $platform->ai(),
            'platformProviders' => $ai->platformProviders(),
            'effective' => $ai->resolve(),
            'hasOwnKey' => filled($settings->ai_api_key),
        ]);
    }

    public function update(Request $request, CurrentCompany $company, Audit $audit): RedirectResponse
    {
        $this->authorize('manage-users');

        $data = $request->validate([
            'ai_provider' => ['nullable', Rule::enum(AiProvider::class)],
            'ai_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'ai_api_key' => ['nullable', 'string', 'max:500'],
            'remove_key' => ['boolean'],
        ], ['ai_model.regex' => __('ai.settings.model_invalid')], [
            'ai_provider' => __('ai.settings.provider'),
            'ai_model' => __('ai.settings.model'),
            'ai_api_key' => __('ai.settings.api_key'),
        ]);

        $settings = $company->settings();
        $settings->forceFill([
            'ai_provider' => $data['ai_provider'] ?? null,
            'ai_model' => $data['ai_model'] ?? null,
        ]);

        if ($request->boolean('remove_key')) {
            $settings->forceFill(['ai_api_key' => null]);
        } elseif (filled($data['ai_api_key'] ?? null)) {
            $settings->forceFill(['ai_api_key' => trim($data['ai_api_key'])]);
        }

        $settings->save();
        $audit->record('ai.settings_updated', [
            'provider' => $settings->ai_provider ?? 'platform',
            'model' => $settings->ai_model ?? 'default',
            'own_key' => filled($settings->ai_api_key) ? 'yes' : 'no',
        ]);

        return back()->with('status', __('ai.settings.saved'));
    }

    /** Sends one tiny request with the effective configuration and reports whether it worked. */
    public function test(AiSettings $ai): RedirectResponse
    {
        $this->authorize('manage-users');

        $config = $ai->resolve();

        if (! $config instanceof AiConfig) {
            return back()->with('error', __('ai.errors.not_configured'));
        }

        try {
            $turn = $ai->driver($config->provider)->chat($config, 'Reply with the single word OK.', [['role' => 'user', 'content' => 'ping']], []);
        } catch (AiException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', __('ai.settings.test_ok', ['provider' => $config->provider->label(), 'model' => $config->model, 'reply' => mb_substr($turn->text, 0, 40)]));
    }
}
