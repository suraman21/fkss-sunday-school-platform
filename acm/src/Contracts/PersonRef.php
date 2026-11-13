<?php
/**
 * ACM contracts (S2): resolved person handle. Minimal identification
 * fields only — full records stay behind permission-checked reads.
 */

namespace SSMS\Acm\Contracts;

final class PersonRef
{
    public function __construct(
        public readonly int $personId,
        public readonly string $uuid,
        public readonly string $fullNameAm,
        public readonly ?string $fullNameEn = null,
    ) {
    }
}
