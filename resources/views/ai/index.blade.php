@php
    $messages = $current
        ? $current->messages->map(fn ($m) => [
            'role' => $m->role,
            'content' => $m->content,
            'is_error' => $m->is_error,
            'tools' => collect($m->tool_calls ?? [])->map(fn ($c) => ['name' => __('ai.tools.'.$c['name']), 'is_error' => $c['is_error']])->all(),
            'model' => $m->model,
        ])->values()
        : collect();

    // Handed to the Alpine component; built here because @js() is not compiled inside a component tag's attributes.
    $chat = \Illuminate\Support\Js::from([
        'url' => route('ai.message'),
        'conversationId' => $current?->id,
        'messages' => $messages,
        'failed' => __('ai.errors.failed'),
    ]);
@endphp

<x-layouts.app :title="__('ai.title')">
    <x-ui.page-header :title="__('ai.title')" :subtitle="$config ? __('ai.subtitle_on', ['provider' => $config->provider->label(), 'model' => $config->model]) : __('ai.subtitle_off')">
        <x-slot:actions>
            @can('view-reports')
                <x-ui.button :href="route('ai.proposals')" variant="secondary" icon="check">
                    {{ __('ai.proposals') }}@if ($pendingProposals > 0) <span class="rounded-full bg-brand-600 px-1.5 text-xs text-white">{{ $pendingProposals }}</span>@endif
                </x-ui.button>
            @endcan
            <x-ui.button :href="route('ai.index')" icon="plus">{{ __('ai.new_conversation') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[16rem_1fr]">
        <aside class="order-2 lg:order-1">
            <x-ui.card padding="p-2">
                <p class="label px-3 pt-2">{{ __('ai.history') }}</p>
                @forelse ($conversations as $conversation)
                    <div @class(['group flex items-center gap-1 rounded-2xl px-3 py-2 transition hover:bg-soft', 'bg-soft' => $current?->id === $conversation->id])>
                        <a href="{{ route('ai.index', ['c' => $conversation->id]) }}" class="min-w-0 grow">
                            <span class="block truncate text-sm font-medium">{{ $conversation->title }}</span>
                            <span class="text-xs text-muted">{{ $conversation->updated_at->format('d.m.Y H:i') }}</span>
                        </a>
                        <form method="post" action="{{ route('ai.destroy', $conversation) }}" class="opacity-0 transition group-hover:opacity-100">@csrf @method('DELETE')
                            <button class="grid size-7 place-items-center rounded-full text-muted hover:bg-white hover:text-red-600" title="{{ __('ui.common.delete') }}" onclick="return confirm(@js(__('ai.confirm_delete')))"><x-ui.icon name="trash" class="size-3.5" /></button>
                        </form>
                    </div>
                @empty
                    <p class="px-3 py-4 text-sm text-muted">{{ __('ai.no_history') }}</p>
                @endforelse
            </x-ui.card>
        </aside>

        <div class="order-1 lg:order-2">
            @if (! $config)
                <x-ui.card>
                    <x-ui.empty-state :title="__('ai.not_configured_title')" :text="__('ai.not_configured_text')" icon="robot">
                        @can('manage-users')
                            <x-ui.button :href="route('settings.ai')" icon="key">{{ __('ai.configure') }}</x-ui.button>
                        @endcan
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <x-ui.card padding="p-0" class="flex h-[calc(100vh-15rem)] min-h-[28rem] flex-col"
                           :x-data="'aiChat('.$chat.')'">
                    <div x-ref="messages" class="flex-1 space-y-4 overflow-y-auto p-5 sm:p-6">
                        <template x-if="messages.length === 0">
                            <div class="mx-auto max-w-xl py-6 text-center">
                                <span class="mx-auto mb-4 grid size-14 place-items-center rounded-full bg-brand-50 text-brand-600"><x-ui.icon name="sparkles" class="size-6" /></span>
                                <p class="font-semibold">{{ __('ai.welcome_title') }}</p>
                                <p class="mt-1 text-sm text-muted">{{ __('ai.welcome_text') }}</p>
                                <div class="mt-5 flex flex-wrap justify-center gap-2">
                                    @foreach (__('ai.suggestions') as $suggestion)
                                        <button type="button" class="rounded-full bg-soft px-3.5 py-2 text-xs font-semibold transition hover:bg-brand-50 hover:text-brand-700" @click="ask(@js($suggestion))">{{ $suggestion }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </template>

                        <template x-for="(message, index) in messages" :key="index">
                            <div :class="message.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                                <div :class="message.role === 'user'
                                        ? 'max-w-[85%] rounded-3xl rounded-br-lg bg-brand-600 px-4 py-2.5 text-sm text-white'
                                        : (message.is_error ? 'max-w-[85%] rounded-3xl rounded-bl-lg bg-red-50 px-4 py-2.5 text-sm text-red-700 ring-1 ring-red-200' : 'max-w-[85%] rounded-3xl rounded-bl-lg bg-soft px-4 py-2.5 text-sm')">
                                    <div x-show="message.role === 'user'" x-text="message.content" class="whitespace-pre-line"></div>
                                    <div x-show="message.role !== 'user'" x-html="format(message.content)" class="leading-relaxed"></div>
                                    <div x-show="message.tools && message.tools.length" class="mt-2 flex flex-wrap gap-1">
                                        <template x-for="tool in message.tools">
                                            <span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-muted ring-1 ring-line" :class="tool.is_error ? '!text-red-600' : ''" x-text="tool.name"></span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div x-show="busy" x-cloak class="flex justify-start">
                            <div class="flex items-center gap-2 rounded-3xl rounded-bl-lg bg-soft px-4 py-3 text-sm text-muted">
                                <span class="size-2 animate-bounce rounded-full bg-brand-500"></span>
                                <span class="size-2 animate-bounce rounded-full bg-brand-500 [animation-delay:150ms]"></span>
                                <span class="size-2 animate-bounce rounded-full bg-brand-500 [animation-delay:300ms]"></span>
                                <span class="ml-1">{{ __('ai.thinking') }}</span>
                            </div>
                        </div>
                        <p x-show="error" x-cloak x-text="error" class="rounded-2xl bg-red-50 px-4 py-2.5 text-sm text-red-700 ring-1 ring-red-200"></p>
                    </div>

                    <form class="flex items-end gap-2 border-t border-line p-3 sm:p-4" @submit.prevent="send()">
                        <textarea x-ref="input" x-model="draft" rows="1" maxlength="4000" :disabled="busy"
                                  @keydown.enter.prevent="if (!$event.shiftKey) send(); else draft += '\n'"
                                  class="field max-h-40 min-h-[2.75rem] resize-none" placeholder="{{ __('ai.placeholder') }}" aria-label="{{ __('ai.placeholder') }}"></textarea>
                        <x-ui.button type="submit" icon="send" ::disabled="busy || draft.trim() === ''">{{ __('ai.send') }}</x-ui.button>
                    </form>
                </x-ui.card>
                <p class="mt-3 text-xs text-muted">{{ __('ai.disclaimer') }}</p>
            @endif
        </div>
    </div>
</x-layouts.app>
