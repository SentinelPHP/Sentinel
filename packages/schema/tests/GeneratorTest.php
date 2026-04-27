<?php

declare(strict_types=1);

namespace SentinelPHP\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Schema\Config\GeneratorConfig;
use SentinelPHP\Schema\Generator;

#[CoversClass(Generator::class)]
#[CoversClass(GeneratorConfig::class)]
final class GeneratorTest extends TestCase
{
    private Generator $generator;

    protected function setUp(): void
    {
        $this->generator = new Generator();
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
     * @return list<string>
     */
    private function required(array $schema): array
    {
        self::assertArrayHasKey('required', $schema);
        $required = $schema['required'];
        self::assertIsArray($required);
        /** @var list<string> $required */

        return $required;
    }

    #[Test]
    public function itGeneratesSchemaWithDraft2020Header(): void
    {
        $schema = $this->generator->generate(['key' => 'value']);

        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
    }

    #[Test]
    public function itInfersStringType(): void
    {
        $schema = $this->generator->generate(['name' => 'John']);

        self::assertSame('string', $this->prop($schema, 'name')['type']);
    }

    #[Test]
    public function itInfersIntegerType(): void
    {
        $schema = $this->generator->generate(['age' => 30]);

        self::assertSame('integer', $this->prop($schema, 'age')['type']);
    }

    #[Test]
    public function itInfersNumberTypeForFloats(): void
    {
        $schema = $this->generator->generate(['price' => 19.99]);

        self::assertSame('number', $this->prop($schema, 'price')['type']);
    }

    #[Test]
    public function itInfersBooleanType(): void
    {
        $schema = $this->generator->generate(['active' => true]);

        self::assertSame('boolean', $this->prop($schema, 'active')['type']);
    }

    #[Test]
    public function itInfersNullType(): void
    {
        $schema = $this->generator->generate(['deleted_at' => null]);

        self::assertSame('null', $this->prop($schema, 'deleted_at')['type']);
    }

    #[Test]
    public function itMarksAllFieldsAsRequired(): void
    {
        $schema = $this->generator->generate([
            'id' => 1,
            'name' => 'Test',
            'active' => true,
        ]);

        $required = $this->required($schema);
        self::assertContains('id', $required);
        self::assertContains('name', $required);
        self::assertContains('active', $required);
        self::assertCount(3, $required);
    }

    #[Test]
    public function itSetsAdditionalPropertiesToFalse(): void
    {
        $schema = $this->generator->generate(['key' => 'value']);

        self::assertFalse($schema['additionalProperties']);
    }

    #[Test]
    public function itHandlesNestedObjects(): void
    {
        $schema = $this->generator->generate([
            'user' => [
                'id' => 1,
                'profile' => [
                    'bio' => 'Hello',
                ],
            ],
        ]);

        $user = $this->prop($schema, 'user');
        self::assertSame('object', $user['type']);
        self::assertSame('integer', $this->prop($user, 'id')['type']);
        $profile = $this->prop($user, 'profile');
        self::assertSame('object', $profile['type']);
        self::assertSame('string', $this->prop($profile, 'bio')['type']);
    }

    #[Test]
    public function itHandlesHomogeneousArrays(): void
    {
        $schema = $this->generator->generate([
            'tags' => ['php', 'symfony', 'api'],
        ]);

        $tags = $this->prop($schema, 'tags');
        self::assertSame('array', $tags['type']);
        self::assertSame('string', $this->items($tags)['type']);
    }

    #[Test]
    public function itHandlesArrayOfObjects(): void
    {
        $schema = $this->generator->generate([
            'users' => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
        ]);

        $users = $this->prop($schema, 'users');
        self::assertSame('array', $users['type']);
        $usersItems = $this->items($users);
        self::assertSame('object', $usersItems['type']);
        self::assertSame('integer', $this->prop($usersItems, 'id')['type']);
        self::assertSame('string', $this->prop($usersItems, 'name')['type']);
    }

    #[Test]
    public function itHandlesTopLevelArray(): void
    {
        $schema = $this->generator->generate([
            ['id' => 1],
            ['id' => 2],
        ]);

        self::assertSame('array', $schema['type']);
        self::assertSame('object', $this->items($schema)['type']);
    }

    #[Test]
    #[DataProvider('primitiveTypesProvider')]
    public function itCorrectlyInfersPrimitiveTypes(mixed $value, string $expectedType): void
    {
        $schema = $this->generator->generate(['value' => $value]);

        self::assertSame($expectedType, $this->prop($schema, 'value')['type']);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function primitiveTypesProvider(): array
    {
        return [
            'string' => ['hello', 'string'],
            'empty string' => ['', 'string'],
            'integer zero' => [0, 'integer'],
            'positive integer' => [42, 'integer'],
            'negative integer' => [-10, 'integer'],
            'float' => [3.14, 'number'],
            'negative float' => [-2.5, 'number'],
            'true' => [true, 'boolean'],
            'false' => [false, 'boolean'],
            'null' => [null, 'null'],
        ];
    }

    #[Test]
    public function itDetectsDateTimeFormat(): void
    {
        $schema = $this->generator->generate(['created_at' => '2024-01-15T10:30:00Z']);

        $createdAt = $this->prop($schema, 'created_at');
        self::assertSame('string', $createdAt['type']);
        self::assertSame('date-time', $createdAt['format']);
    }

    #[Test]
    public function itDetectsUuidFormat(): void
    {
        $schema = $this->generator->generate(['id' => '550e8400-e29b-41d4-a716-446655440000']);

        $id = $this->prop($schema, 'id');
        self::assertSame('string', $id['type']);
        self::assertSame('uuid', $id['format']);
    }

    #[Test]
    public function itDetectsEmailFormat(): void
    {
        $schema = $this->generator->generate(['email' => 'user@example.com']);

        $email = $this->prop($schema, 'email');
        self::assertSame('string', $email['type']);
        self::assertSame('email', $email['format']);
    }

    #[Test]
    public function itDetectsUriFormat(): void
    {
        $schema = $this->generator->generate(['url' => 'https://example.com/path']);

        $url = $this->prop($schema, 'url');
        self::assertSame('string', $url['type']);
        self::assertSame('uri', $url['format']);
    }

    #[Test]
    #[DataProvider('formatDetectionProvider')]
    public function itCorrectlyDetectsFormats(string $value, ?string $expectedFormat): void
    {
        $schema = $this->generator->generate(['value' => $value]);

        $valueProp = $this->prop($schema, 'value');
        self::assertSame('string', $valueProp['type']);

        if ($expectedFormat === null) {
            self::assertArrayNotHasKey('format', $valueProp);
        } else {
            self::assertSame($expectedFormat, $valueProp['format']);
        }
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function formatDetectionProvider(): array
    {
        return [
            'ISO 8601 with Z' => ['2024-01-15T10:30:00Z', 'date-time'],
            'ISO 8601 with offset' => ['2024-01-15T10:30:00+05:30', 'date-time'],
            'UUID lowercase' => ['a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'uuid'],
            'UUID uppercase' => ['A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11', 'uuid'],
            'email simple' => ['test@example.com', 'email'],
            'HTTP URL' => ['http://example.com', 'uri'],
            'HTTPS URL' => ['https://example.com', 'uri'],
            'plain string' => ['hello world', null],
            'date only' => ['2024-01-15', null],
            'partial UUID' => ['550e8400-e29b', null],
        ];
    }

    #[Test]
    public function itOmitsRequiredArrayWhenStrictModeIsDisabled(): void
    {
        $config = new GeneratorConfig(strictMode: false);
        $schema = $this->generator->generate(['id' => 1, 'name' => 'Test'], $config);

        self::assertArrayNotHasKey('required', $schema);
    }

    #[Test]
    public function itAllowsNullTypesWhenNullableFieldsIsEnabled(): void
    {
        $config = new GeneratorConfig(nullableFields: true);
        $schema = $this->generator->generate(['name' => 'John'], $config);

        self::assertSame(['string', 'null'], $this->prop($schema, 'name')['type']);
    }

    #[Test]
    public function itSetsAdditionalPropertiesToTrueWhenConfigured(): void
    {
        $config = new GeneratorConfig(additionalProperties: true);
        $schema = $this->generator->generate(['key' => 'value'], $config);

        self::assertTrue($schema['additionalProperties']);
    }

    #[Test]
    public function itUsesPermissiveConfigCorrectly(): void
    {
        $config = GeneratorConfig::permissive();
        $schema = $this->generator->generate(['id' => 1, 'name' => 'Test'], $config);

        self::assertArrayNotHasKey('required', $schema);
        self::assertTrue($schema['additionalProperties']);
        self::assertSame(['integer', 'null'], $this->prop($schema, 'id')['type']);
    }

    #[Test]
    public function itUsesStrictConfigCorrectly(): void
    {
        $config = GeneratorConfig::strict();
        $schema = $this->generator->generate(['id' => 1, 'name' => 'Test'], $config);

        self::assertArrayHasKey('required', $schema);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame('integer', $this->prop($schema, 'id')['type']);
    }
}
