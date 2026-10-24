<?php

namespace App\Portal;

/**
 * The document the tax authority asks to be signed for one report: its exact bytes and the values read from
 * the filled form. AuthorityDocumentCheck compares those values with the approved report before signing.
 */
final class AuthorityDocument
{
    /** @param  array<string, scalar|null>  $fields */
    public function __construct(
        public readonly string $session,
        public readonly string $id,
        public readonly string $content,
        public readonly array $fields,
    ) {}

    public function hash(): string
    {
        return hash('sha256', $this->content);
    }
}
