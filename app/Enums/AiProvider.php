<?php

namespace App\Enums;

/** Supported AI providers. Claude goes through the official Anthropic SDK, the others through an OpenAI-compatible API. */
enum AiProvider: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case DeepSeek = 'deepseek';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'ChatGPT (OpenAI)',
            self::Anthropic => 'Claude (Anthropic)',
            self::Gemini => 'Gemini (Google)',
            self::DeepSeek => 'DeepSeek',
        };
    }

    /** A starting point only: every model name is editable in the panel, because providers rename models. */
    public function defaultModel(): string
    {
        return match ($this) {
            self::OpenAi => 'gpt-5',
            self::Anthropic => 'claude-opus-5-5',
            self::Gemini => 'gemini-2.5-flash',
            self::DeepSeek => 'deepseek-chat',
        };
    }

    public function defaultBaseUrl(): string
    {
        return match ($this) {
            self::OpenAi => 'https://api.openai.com/v1',
            self::Anthropic => 'https://api.anthropic.com',
            self::Gemini => 'https://generativelanguage.googleapis.com/v1beta/openai',
            self::DeepSeek => 'https://api.deepseek.com',
        };
    }

    /** Where the owner of an account creates an API key. */
    public function keysUrl(): string
    {
        return match ($this) {
            self::OpenAi => 'https://platform.openai.com/api-keys',
            self::Anthropic => 'https://console.anthropic.com/settings/keys',
            self::Gemini => 'https://aistudio.google.com/apikey',
            self::DeepSeek => 'https://platform.deepseek.com/api_keys',
        };
    }
}
