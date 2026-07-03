<?php

declare(strict_types=1);

namespace SentinelPHP\Drift\Diff;

final class JsonDiff implements JsonDiffInterface
{
    public function generateDiff(?array $expected, ?array $actual): DiffResult
    {
        $added = [];
        $removed = [];
        $changed = [];

        $this->compareRecursive($expected ?? [], $actual ?? [], '', $added, $removed, $changed);

        return new DiffResult($added, $removed, $changed);
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @param list<DiffEntry> $added
     * @param list<DiffEntry> $removed
     * @param list<DiffEntry> $changed
     */
    private function compareRecursive(
        array $expected,
        array $actual,
        string $currentPath,
        array &$added,
        array &$removed,
        array &$changed,
    ): void {
        $allKeys = array_unique(array_merge(array_keys($expected), array_keys($actual)));

        foreach ($allKeys as $key) {
            $path = $currentPath === '' ? (string) $key : $currentPath . '.' . $key;
            $expectedExists = array_key_exists($key, $expected);
            $actualExists = array_key_exists($key, $actual);

            if (!$expectedExists && $actualExists) {
                $added[] = new DiffEntry($path, null, $actual[$key], 'added');
                continue;
            }

            if ($expectedExists && !$actualExists) {
                $removed[] = new DiffEntry($path, $expected[$key], null, 'removed');
                continue;
            }

            $expectedValue = $expected[$key];
            $actualValue = $actual[$key];

            if (is_array($expectedValue) && is_array($actualValue)) {
                if ($this->isAssociativeArray($expectedValue) || $this->isAssociativeArray($actualValue)) {
                    /** @var array<string, mixed> $expectedNested */
                    $expectedNested = $expectedValue;
                    /** @var array<string, mixed> $actualNested */
                    $actualNested = $actualValue;
                    $this->compareRecursive($expectedNested, $actualNested, $path, $added, $removed, $changed);
                } elseif ($expectedValue !== $actualValue) {
                    $changed[] = new DiffEntry($path, $expectedValue, $actualValue, 'changed');
                }
            } elseif ($expectedValue !== $actualValue) {
                $changed[] = new DiffEntry($path, $expectedValue, $actualValue, 'changed');
            }
        }
    }

    /**
     * @param array<mixed> $array
     */
    private function isAssociativeArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
