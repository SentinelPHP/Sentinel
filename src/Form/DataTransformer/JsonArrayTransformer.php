<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Transforms between a JSON string and an associative array.
 *
 * @implements DataTransformerInterface<array<string, mixed>|null, string>
 */
final class JsonArrayTransformer implements DataTransformerInterface
{
    public function __construct(
        private readonly bool $prettyPrint = true,
    ) {
    }

    /**
     * Transforms an array to a JSON string for display in the form.
     *
     * @param array<string, mixed>|null $value
     */
    public function transform(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }

        $flags = JSON_THROW_ON_ERROR;
        if ($this->prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            return json_encode($value, $flags);
        } catch (\JsonException $e) {
            throw new TransformationFailedException('Unable to encode value as JSON.', 0, $e);
        }
    }

    /**
     * Transforms a JSON string back to an array.
     *
     * @return array<string, mixed>|null
     */
    public function reverseTransform(mixed $value): ?array
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (\JsonException $e) {
            throw new TransformationFailedException(
                'Invalid JSON format.',
                0,
                $e
            );
        }
    }
}
