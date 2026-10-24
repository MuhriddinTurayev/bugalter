<?php

namespace App\Submission\Signing;

use App\Models\SigningKey;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * The signing vault (PLAN section 9). Each key and its password are encrypted with a fresh per-key data key;
 * the data key is encrypted with the master key (SIGNING_MASTER_KEY), which never touches the database.
 * Decrypted material exists only inside use(), in memory, and is wiped afterwards.
 */
class KeyVault
{
    /** @param  array{label: string, owner_name: string, owner_tin: ?string, serial_number: ?string, valid_from: ?string, valid_to: string}  $meta */
    public function store(string $keyContents, string $password, array $meta, ?User $uploadedBy): SigningKey
    {
        $dataKey = random_bytes(32);
        $data = new Encrypter($dataKey, 'aes-256-gcm');

        try {
            return SigningKey::create($meta + [
                'status' => 'active',
                'encrypted_key' => $data->encryptString(base64_encode($keyContents)),
                'encrypted_password' => $data->encryptString($password),
                'encrypted_data_key' => $this->master()->encryptString(base64_encode($dataKey)),
                'fingerprint' => hash('sha256', $keyContents),
                'uploaded_by' => $uploadedBy?->id,
            ]);
        } finally {
            $this->wipe($dataKey);
        }
    }

    /**
     * Runs a callback with the decrypted key and password. The callback must not store or log them.
     *
     * @template T
     *
     * @param  callable(string, string): T  $callback
     * @return T
     */
    public function use(SigningKey $key, callable $callback): mixed
    {
        if (! $key->isUsable()) {
            throw new RuntimeException(__('autopilot.errors.key_unusable'));
        }

        $dataKey = $this->decode($this->master()->decryptString($key->encrypted_data_key));
        $data = new Encrypter($dataKey, 'aes-256-gcm');
        $contents = $this->decode($data->decryptString($key->encrypted_key));
        $password = $data->decryptString($key->encrypted_password);

        try {
            if (! hash_equals($key->fingerprint, hash('sha256', $contents))) {
                throw new RuntimeException(__('autopilot.errors.key_tampered'));
            }

            return $callback($contents, $password);
        } finally {
            $this->wipe($dataKey);
            $this->wipe($contents);
            $this->wipe($password);
        }
    }

    private function master(): Encrypter
    {
        $configured = (string) config('signing.master_key');

        if ($configured !== '') {
            $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : $configured;

            if ($key === false || strlen($key) !== 32) {
                throw new RuntimeException('SIGNING_MASTER_KEY must be 32 bytes ("base64:" + 32 random bytes in base64).');
            }

            return new Encrypter($key, 'aes-256-gcm');
        }

        // A real master key is mandatory outside local development and tests.
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('SIGNING_MASTER_KEY is not set. The signing vault cannot be used.');
        }

        return new Encrypter(hash('sha256', config('app.key').'|signing-vault', true), 'aes-256-gcm');
    }

    private function decode(string $base64): string
    {
        $bytes = base64_decode($base64, true);

        return $bytes === false ? throw new RuntimeException(__('autopilot.errors.key_tampered')) : $bytes;
    }

    /**
     * Overwrites secret material in memory; the variable is null afterwards.
     *
     * @param-out null $value
     */
    private function wipe(?string &$value): void
    {
        if ($value !== null && function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }

        $value = null;
    }
}
