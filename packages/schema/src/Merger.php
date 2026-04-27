<?php

declare(strict_types=1);

namespace SentinelPHP\Schema;

final class Merger implements MergerInterface
{
    public function merge(array $existing, array $new): array
    {
        $merged = $existing;

        if (isset($new['$schema']) && !isset($merged['$schema'])) {
            $merged['$schema'] = $new['$schema'];
        }

        /** @var string|list<string>|null $existingTypeRaw */
        $existingTypeRaw = $existing['type'] ?? null;
        /** @var string|list<string>|null $newTypeRaw */
        $newTypeRaw = $new['type'] ?? null;

        $existingType = $this->normalizeType($existingTypeRaw);
        $newType = $this->normalizeType($newTypeRaw);

        $mergedType = $this->mergeTypes($existingType, $newType);
        $merged['type'] = $this->denormalizeType($mergedType);

        if ($this->isObjectType($existingType) && $this->isObjectType($newType)) {
            $merged = $this->mergeObjectSchemas($merged, $existing, $new);
        }

        if ($this->isArrayType($existingType) && $this->isArrayType($newType)) {
            $merged = $this->mergeArraySchemas($merged, $existing, $new);
        }

        $merged = $this->mergeFormats($merged, $existing, $new);

        return $merged;
    }

    /**
     * @param string|list<string>|null $type
     * @return list<string>
     */
    private function normalizeType(string|array|null $type): array
    {
        if ($type === null) {
            return [];
        }

        if (is_string($type)) {
            return [$type];
        }

        /** @var list<string> $type */
        return $type;
    }

    /**
     * @param list<string> $types
     * @return string|list<string>
     */
    private function denormalizeType(array $types): string|array
    {
        $types = array_unique($types);
        $types = array_values($types);

        if (count($types) === 1) {
            return $types[0];
        }

        return $types;
    }

    /**
     * @param list<string> $existingTypes
     * @param list<string> $newTypes
     * @return list<string>
     */
    private function mergeTypes(array $existingTypes, array $newTypes): array
    {
        $merged = [];

        $allTypes = array_unique(array_merge($existingTypes, $newTypes));

        $hasInteger = in_array('integer', $allTypes, true);
        $hasNumber = in_array('number', $allTypes, true);

        foreach ($allTypes as $type) {
            if ($type === 'integer' && $hasNumber) {
                continue;
            }
            $merged[] = $type;
        }

        return array_values(array_unique($merged));
    }

    /**
     * @param list<string> $types
     */
    private function isObjectType(array $types): bool
    {
        return in_array('object', $types, true);
    }

    /**
     * @param list<string> $types
     */
    private function isArrayType(array $types): bool
    {
        return in_array('array', $types, true);
    }

    /**
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $new
     * @return array<string, mixed>
     */
    private function mergeObjectSchemas(array $merged, array $existing, array $new): array
    {
        $existingProps = $existing['properties'] ?? [];
        $newProps = $new['properties'] ?? [];

        if ($existingProps instanceof \stdClass) {
            $existingProps = [];
        }
        if ($newProps instanceof \stdClass) {
            $newProps = [];
        }

        /** @var array<string, array<string, mixed>> $existingProps */
        /** @var array<string, array<string, mixed>> $newProps */

        $mergedProps = $this->mergeProperties($existingProps, $newProps);
        if (!empty($mergedProps)) {
            $merged['properties'] = $mergedProps;
        } else {
            unset($merged['properties']);
        }

        $existingRequired = $existing['required'] ?? [];
        $newRequired = $new['required'] ?? [];

        /** @var list<string> $existingRequired */
        /** @var list<string> $newRequired */

        $merged['required'] = $this->mergeRequired($existingRequired, $newRequired);

        if (empty($merged['required'])) {
            unset($merged['required']);
        }

        if (isset($existing['additionalProperties']) || isset($new['additionalProperties'])) {
            $merged['additionalProperties'] = ($existing['additionalProperties'] ?? true)
                || ($new['additionalProperties'] ?? true);
        }

        return $merged;
    }

    /**
     * @param array<string, array<string, mixed>> $existingProps
     * @param array<string, array<string, mixed>> $newProps
     * @return array<string, array<string, mixed>>
     */
    private function mergeProperties(array $existingProps, array $newProps): array
    {
        $merged = $existingProps;

        foreach ($newProps as $key => $newPropSchema) {
            if (!isset($merged[$key])) {
                $merged[$key] = $newPropSchema;
            } else {
                $merged[$key] = $this->merge($merged[$key], $newPropSchema);
            }
        }

        return $merged;
    }

    /**
     * @param list<string> $existingRequired
     * @param list<string> $newRequired
     * @return list<string>
     */
    private function mergeRequired(array $existingRequired, array $newRequired): array
    {
        if (empty($existingRequired) || empty($newRequired)) {
            return [];
        }

        return array_values(array_intersect($existingRequired, $newRequired));
    }

    /**
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $new
     * @return array<string, mixed>
     */
    private function mergeArraySchemas(array $merged, array $existing, array $new): array
    {
        $existingItems = $existing['items'] ?? null;
        $newItems = $new['items'] ?? null;

        if ($existingItems === null && $newItems === null) {
            return $merged;
        }

        if ($existingItems === null || $existingItems instanceof \stdClass || (is_array($existingItems) && empty($existingItems))) {
            if ($newItems !== null && !($newItems instanceof \stdClass) && !(is_array($newItems) && empty($newItems))) {
                $merged['items'] = $newItems;
            }
            return $merged;
        }

        if ($newItems === null || $newItems instanceof \stdClass || (is_array($newItems) && empty($newItems))) {
            $merged['items'] = $existingItems;
            return $merged;
        }

        /** @var array<string, mixed> $existingItems */
        /** @var array<string, mixed> $newItems */

        $merged['items'] = $this->mergeArrayItems($existingItems, $newItems);

        return $merged;
    }

    /**
     * @param array<string, mixed> $existingItems
     * @param array<string, mixed> $newItems
     * @return array<string, mixed>
     */
    private function mergeArrayItems(array $existingItems, array $newItems): array
    {
        $existingAnyOf = $existingItems['anyOf'] ?? null;
        $newAnyOf = $newItems['anyOf'] ?? null;

        if ($existingAnyOf !== null && $newAnyOf !== null) {
            /** @var list<array<string, mixed>> $existingAnyOf */
            /** @var list<array<string, mixed>> $newAnyOf */
            return ['anyOf' => $this->mergeAnyOf($existingAnyOf, $newAnyOf)];
        }

        if ($existingAnyOf !== null) {
            /** @var list<array<string, mixed>> $existingAnyOf */
            return ['anyOf' => $this->mergeAnyOf($existingAnyOf, [$newItems])];
        }

        if ($newAnyOf !== null) {
            /** @var list<array<string, mixed>> $newAnyOf */
            return ['anyOf' => $this->mergeAnyOf([$existingItems], $newAnyOf)];
        }

        if ($this->schemasAreCompatible($existingItems, $newItems)) {
            return $this->merge($existingItems, $newItems);
        }

        return ['anyOf' => [$existingItems, $newItems]];
    }

    /**
     * @param list<array<string, mixed>> $existingAnyOf
     * @param list<array<string, mixed>> $newAnyOf
     * @return list<array<string, mixed>>
     */
    private function mergeAnyOf(array $existingAnyOf, array $newAnyOf): array
    {
        $merged = $existingAnyOf;

        foreach ($newAnyOf as $newSchema) {
            $foundMatch = false;

            foreach ($merged as $index => $existingSchema) {
                if ($this->schemasAreCompatible($existingSchema, $newSchema)) {
                    $merged[$index] = $this->merge($existingSchema, $newSchema);
                    $foundMatch = true;
                    break;
                }
            }

            if (!$foundMatch) {
                $merged[] = $newSchema;
            }
        }

        /** @var list<array<string, mixed>> $merged */
        return $merged;
    }

    /**
     * @param array<string, mixed> $schema1
     * @param array<string, mixed> $schema2
     */
    private function schemasAreCompatible(array $schema1, array $schema2): bool
    {
        /** @var string|list<string>|null $type1Raw */
        $type1Raw = $schema1['type'] ?? null;
        /** @var string|list<string>|null $type2Raw */
        $type2Raw = $schema2['type'] ?? null;

        $type1 = $this->normalizeType($type1Raw);
        $type2 = $this->normalizeType($type2Raw);

        $baseType1 = $this->getBaseType($type1);
        $baseType2 = $this->getBaseType($type2);

        if ($baseType1 === $baseType2) {
            return true;
        }

        if (
            ($baseType1 === 'integer' && $baseType2 === 'number')
            || ($baseType1 === 'number' && $baseType2 === 'integer')
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $types
     */
    private function getBaseType(array $types): ?string
    {
        $nonNullTypes = array_filter($types, fn (string $t) => $t !== 'null');

        if (empty($nonNullTypes)) {
            return null;
        }

        return reset($nonNullTypes) ?: null;
    }

    /**
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $new
     * @return array<string, mixed>
     */
    private function mergeFormats(array $merged, array $existing, array $new): array
    {
        $existingFormat = $existing['format'] ?? null;
        $newFormat = $new['format'] ?? null;

        if ($existingFormat === null && $newFormat === null) {
            unset($merged['format']);
            return $merged;
        }

        if ($existingFormat === $newFormat) {
            $merged['format'] = $existingFormat;
            return $merged;
        }

        unset($merged['format']);
        return $merged;
    }
}
