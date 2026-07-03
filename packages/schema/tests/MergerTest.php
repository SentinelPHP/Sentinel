<?php

declare(strict_types=1);

namespace SentinelPHP\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Schema\Merger;

#[CoversClass(Merger::class)]
final class MergerTest extends TestCase
{
    private Merger $merger;

    protected function setUp(): void
    {
        $this->merger = new Merger();
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function props(array $schema): array
    {
        self::assertArrayHasKey('properties', $schema);
        $properties = $schema['properties'];
        self::assertIsArray($properties);
        /** @var array<string, mixed> $properties */

        return $properties;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function prop(array $schema, string $name): array
    {
        $properties = $this->props($schema);
        self::assertArrayHasKey($name, $properties);
        $prop = $properties[$name];
        self::assertIsArray($prop);
        /** @var array<string, mixed> $prop */

        return $prop;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function items(array $schema): array
    {
        self::assertArrayHasKey('items', $schema);
        $items = $schema['items'];
        self::assertIsArray($items);
        /** @var array<string, mixed> $items */

        return $items;
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<array<string, mixed>>
     */
    private function anyOf(array $schema): array
    {
        self::assertArrayHasKey('anyOf', $schema);
        $anyOf = $schema['anyOf'];
        self::assertIsArray($anyOf);
        /** @var list<array<string, mixed>> $anyOf */

        return $anyOf;
    }

    #[Test]
    public function itPreservesSchemaMetadata(): void
    {
        $existing = ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object'];
        $new = ['type' => 'object'];

        $merged = $this->merger->merge($existing, $new);

        self::assertSame('https://json-schema.org/draft/2020-12/schema', $merged['$schema']);
    }

    #[Test]
    public function itMergesIdenticalTypes(): void
    {
        $existing = ['type' => 'string'];
        $new = ['type' => 'string'];

        $merged = $this->merger->merge($existing, $new);

        self::assertSame('string', $merged['type']);
    }

    #[Test]
    public function itWidensIntegerToNumber(): void
    {
        $existing = ['type' => 'integer'];
        $new = ['type' => 'number'];

        $merged = $this->merger->merge($existing, $new);

        self::assertSame('number', $merged['type']);
    }

    #[Test]
    public function itCombinesIncompatibleTypesIntoArray(): void
    {
        $existing = ['type' => 'string'];
        $new = ['type' => 'integer'];

        $merged = $this->merger->merge($existing, $new);

        self::assertIsArray($merged['type']);
        self::assertContains('string', $merged['type']);
        self::assertContains('integer', $merged['type']);
    }

    #[Test]
    public function itHandlesNullableTypes(): void
    {
        $existing = ['type' => ['string', 'null']];
        $new = ['type' => 'string'];

        $merged = $this->merger->merge($existing, $new);

        self::assertIsArray($merged['type']);
        self::assertContains('string', $merged['type']);
        self::assertContains('null', $merged['type']);
    }

    #[Test]
    public function itMergesObjectPropertiesUnion(): void
    {
        $existing = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
            ],
            'required' => ['id', 'name'],
        ];

        $new = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'email' => ['type' => 'string', 'format' => 'email'],
            ],
            'required' => ['id', 'email'],
        ];

        $merged = $this->merger->merge($existing, $new);

        self::assertSame('object', $merged['type']);
        $props = $this->props($merged);
        self::assertArrayHasKey('id', $props);
        self::assertArrayHasKey('name', $props);
        self::assertArrayHasKey('email', $props);
    }

    #[Test]
    public function itMergesRequiredFieldsAsIntersection(): void
    {
        $existing = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
            'required' => ['id', 'name'],
        ];

        $new = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer'], 'email' => ['type' => 'string']],
            'required' => ['id', 'email'],
        ];

        $merged = $this->merger->merge($existing, $new);

        self::assertArrayHasKey('required', $merged);
        self::assertSame(['id'], $merged['required']);
    }

    #[Test]
    public function itRemovesRequiredWhenNoCommonFields(): void
    {
        $existing = [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ];

        $new = [
            'type' => 'object',
            'properties' => ['email' => ['type' => 'string']],
            'required' => ['email'],
        ];

        $merged = $this->merger->merge($existing, $new);

        self::assertArrayNotHasKey('required', $merged);
    }

    #[Test]
    public function itMergesNestedObjectProperties(): void
    {
        $existing = [
            'type' => 'object',
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
            ],
        ];

        $new = [
            'type' => 'object',
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
                    'required' => ['id', 'name'],
                ],
            ],
        ];

        $merged = $this->merger->merge($existing, $new);

        $user = $this->prop($merged, 'user');
        $userProps = $this->props($user);
        self::assertArrayHasKey('id', $userProps);
        self::assertArrayHasKey('name', $userProps);
        self::assertSame(['id'], $user['required']);
    }

    #[Test]
    public function itMergesArrayItemsWithTypeWidening(): void
    {
        $existing = ['type' => 'array', 'items' => ['type' => 'integer']];
        $new = ['type' => 'array', 'items' => ['type' => 'number']];

        $merged = $this->merger->merge($existing, $new);

        $items = $this->items($merged);
        self::assertSame('number', $items['type']);
    }

    #[Test]
    public function itMergesArrayItemsWithIncompatibleTypesIntoAnyOf(): void
    {
        $existing = ['type' => 'array', 'items' => ['type' => 'string']];
        $new = ['type' => 'array', 'items' => ['type' => 'integer']];

        $merged = $this->merger->merge($existing, $new);

        $items = $this->items($merged);
        $anyOf = $this->anyOf($items);
        self::assertCount(2, $anyOf);
    }

    #[Test]
    public function itKeepsFormatWhenIdentical(): void
    {
        $existing = ['type' => 'string', 'format' => 'email'];
        $new = ['type' => 'string', 'format' => 'email'];

        $merged = $this->merger->merge($existing, $new);

        self::assertSame('email', $merged['format']);
    }

    #[Test]
    public function itDropsFormatWhenDifferent(): void
    {
        $existing = ['type' => 'string', 'format' => 'email'];
        $new = ['type' => 'string', 'format' => 'uri'];

        $merged = $this->merger->merge($existing, $new);

        self::assertArrayNotHasKey('format', $merged);
    }

    #[Test]
    public function itMergesAdditionalPropertiesWithOr(): void
    {
        $existing = ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
        $new = ['type' => 'object', 'properties' => [], 'additionalProperties' => true];

        $merged = $this->merger->merge($existing, $new);

        self::assertTrue($merged['additionalProperties']);
    }

    #[Test]
    public function itHandlesComplexNestedMerge(): void
    {
        $existing = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'properties' => [
                        'users' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                    'score' => ['type' => 'integer'],
                                ],
                                'required' => ['id', 'score'],
                            ],
                        ],
                    ],
                    'required' => ['users'],
                ],
            ],
            'required' => ['data'],
        ];

        $new = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'properties' => [
                        'users' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                    'score' => ['type' => 'number'],
                                    'name' => ['type' => 'string'],
                                ],
                                'required' => ['id', 'score', 'name'],
                            ],
                        ],
                    ],
                    'required' => ['users'],
                ],
            ],
            'required' => ['data'],
        ];

        $merged = $this->merger->merge($existing, $new);

        $data = $this->prop($merged, 'data');
        $users = $this->prop($data, 'users');
        $usersItems = $this->items($users);
        $userProps = $this->props($usersItems);

        self::assertArrayHasKey('id', $userProps);
        self::assertArrayHasKey('score', $userProps);
        self::assertArrayHasKey('name', $userProps);
        self::assertIsArray($userProps['score']);
        self::assertSame('number', $userProps['score']['type']);
        self::assertSame(['id', 'score'], $usersItems['required']);
    }
}
