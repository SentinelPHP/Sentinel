<?php

declare(strict_types=1);

namespace SentinelPHP\Drift\Diff;

interface JsonDiffInterface
{
    /**
     * Generate a diff between two JSON structures.
     *
     * @param array<string, mixed>|null $expected The expected JSON structure
     * @param array<string, mixed>|null $actual The actual JSON structure
     * @return DiffResult The diff result containing added, removed, and changed entries
     */
    public function generateDiff(?array $expected, ?array $actual): DiffResult;
}
