<?php

declare(strict_types=1);

namespace SentinelPHP\Schema;

interface MergerInterface
{
    /**
     * Merge two JSON schemas together.
     *
     * - Union of all observed fields
     * - Type widening (e.g., integer → number if both seen)
     * - Array type unification
     * - Required fields become intersection (only required if seen in all samples)
     *
     * @param array<string, mixed> $existing The existing schema
     * @param array<string, mixed> $new The new schema to merge
     * @return array<string, mixed> The merged schema
     */
    public function merge(array $existing, array $new): array;
}
