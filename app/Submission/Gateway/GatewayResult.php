<?php

namespace App\Submission\Gateway;

/**
 * The authority's answer, as far as it is known. status:
 *   accepted   definite acceptance (receipt)
 *   rejected   definite refusal
 *   submitted  received, decision pending
 *   unknown    no reliable answer (timeout, connection drop, server error after sending): never resend blindly
 *   not_found  only from a verification: the authority has no record of the delivery
 */
final class GatewayResult
{
    public const STATUSES = ['accepted', 'rejected', 'submitted', 'unknown', 'not_found'];

    /** @param  list<string>  $errors */
    public function __construct(
        public readonly string $status,
        public readonly ?string $receipt = null,
        public readonly array $errors = [],
        public readonly array $raw = [],
    ) {}
}
