<?php

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Models\PlatformStaff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

/**
 * Creates the first platform administrator. The password is always asked interactively:
 * it is never an argument (it would end up in shell history) and never has a default.
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email : Email address used to sign in} {name : Full name} {--support : Create a support user instead of a super admin}';

    protected $description = 'Create a platform staff account (super admin by default). The password is asked interactively.';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $validator = Validator::make(['email' => $email, 'name' => $this->argument('name')], [
            'email' => ['required', 'email', 'max:255', 'unique:platform_staff,email'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('The password is asked interactively; run this command in a terminal.');

            return self::FAILURE;
        }

        $password = password('Password (at least 12 characters, mixed case and a number)', required: true, validate: fn (string $value) => $this->validatePassword($value));
        $confirmation = password('Repeat the password', required: true);

        if ($password !== $confirmation) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $role = $this->option('support') ? PlatformRole::Support : PlatformRole::SuperAdmin;

        PlatformStaff::create(['name' => $this->argument('name'), 'email' => $email, 'password' => $password, 'role' => $role, 'is_active' => true]);

        $this->info("Created {$role->value} {$email}. Two-factor authentication must be set up at the first sign-in.");

        return self::SUCCESS;
    }

    private function validatePassword(string $value): ?string
    {
        $validator = Validator::make(['password' => $value], ['password' => [Password::min(12)->mixedCase()->numbers()]]);

        return $validator->fails() ? $validator->errors()->first('password') : null;
    }
}
