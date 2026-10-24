<?php

namespace App\Submission\Signing;

final class SignatureResult
{
    public function __construct(
        public readonly string $signer,
        public readonly string $documentHash,
        public readonly string $signature,
        public readonly ?int $signingKeyId = null,
        public readonly bool $legallyValid = false,
    ) {}
}
