<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * Transforms between a newline-separated string and a list of strings.
 *
 * @implements DataTransformerInterface<list<string>, string>
 */
final class StringListTransformer implements DataTransformerInterface
{
    /**
     * @param non-empty-string $separator
     */
    public function __construct(
        private readonly string $separator = "\n",
    ) {
    }

    /**
     * Transforms a list of strings to a newline-separated string for display.
     *
     * @param list<string>|null $value
     */
    public function transform(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }

        return implode($this->separator, $value);
    }

    /**
     * Transforms a newline-separated string back to a list of strings.
     *
     * @return list<string>
     */
    public function reverseTransform(mixed $value): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        $lines = explode($this->separator, (string) $value);
        $result = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $result[] = $line;
            }
        }

        return $result;
    }
}
