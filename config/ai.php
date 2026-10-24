<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider Keys For Local Seeding
    |--------------------------------------------------------------------------
    |
    | Keys are entered in the platform panel (Integrations) and stored encrypted in the database. For local
    | development, `php artisan db:seed` also copies these environment keys into the panel settings, so a
    | developer does not have to type them after every `migrate:fresh --seed`. Never commit a key.
    |
    */

    'seed_keys' => [
        'openai' => env('OPENAI_API_KEY'),
        'anthropic' => env('ANTHROPIC_API_KEY'),
        'gemini' => env('GEMINI_API_KEY'),
        'deepseek' => env('DEEPSEEK_API_KEY'),
    ],

];
