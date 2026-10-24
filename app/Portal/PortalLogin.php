<?php

namespace App\Portal;

/** A portal session waiting for its login signature. */
final class PortalLogin
{
    public function __construct(
        public readonly string $session,
        public readonly string $challenge,
    ) {}
}
