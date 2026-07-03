<?php

declare(strict_types=1);

namespace SentinelPHP\Dto\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Dto\GeneratedEnum;
use SentinelPHP\Dto\MappedType;
use SentinelPHP\Dto\PropertyDefinition;
use SentinelPHP\Dto\TypeMapper;

#[CoversClass(TypeMapper::class)]
#[CoversClass(MappedType::class)]
#[CoversClass(GeneratedEnum::class)]
#[CoversClass(PropertyDefinition::class)]
final class TypeMapperTest extends TestCase
{
    private TypeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new TypeMapper('App\\Dto\\Enum');
    }

    /**
     * @param array<string, mixed> $definition
     */
    #[Test]
    #[DataProvider('primitiveTypeProvider')]
    public function itMapsPrimitiveTypes(array $definition, string $expectedNative): void
    {
        $result = $this->mapper->mapType($definition);

        self::assertSame($expectedNative, $result->nativeType);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function primitiveTypeProvider(): iterable
    {
        yield 'string' => [['type' => 'string'], 'string'];
        yield 'integer' => [['type' => 'integer'], 'int'];
        yield 'number' => [['type' => 'number'], 'float'];
        yield 'boolean' => [['type' => 'boolean'], 'bool'];
    }

    #[Test]
    public function itMapsNullableTypes(): void
    {
        $result = $this->mapper->mapType(['type' => 'string'], false);

        self::assertSame('?string', $result->nativeType);
    }

    #[Test]
    public function itMapsDateTimeFormat(): void
    {
        $result = $this->mapper->mapType(['type' => 'string', 'format' => 'date-time']);

        self::assertSame('\DateTimeImmutable', $result->nativeType);
        self::assertContains('\DateTimeImmutable', $result->imports);
    }

    #[Test]
    public function itMapsDateFormat(): void
    {
        $result = $this->mapper->mapType(['type' => 'string', 'format' => 'date']);

        self::assertSame('\DateTimeImmutable', $result->nativeType);
    }

    #[Test]
    public function itMapsUuidFormat(): void
    {
        $result = $this->mapper->mapType(['type' => 'string', 'format' => 'uuid']);

        self::assertSame('string', $result->nativeType);
        self::assertSame('string UUID', $result->docblockType);
    }

    #[Test]
    public function itMapsEmailFormat(): void
    {
        $result = $this->mapper->mapType(['type' => 'string', 'format' => 'email']);

        self::assertSame('string', $result->nativeType);
    }

    #[Test]
    public function itMapsArrayType(): void
    {
        $result = $this->mapper->mapType(['type' => 'array', 'items' => ['type' => 'string']]);

        self::assertSame('array', $result->nativeType);
        self::assertSame('array<string>', $result->docblockType);
    }

    #[Test]
    public function itMapsArrayOfIntegers(): void
    {
        $result = $this->mapper->mapType(['type' => 'array', 'items' => ['type' => 'integer']]);

        self::assertSame('array', $result->nativeType);
        self::assertSame('array<int>', $result->docblockType);
    }

    #[Test]
    public function itMapsArrayWithoutItems(): void
    {
        $result = $this->mapper->mapType(['type' => 'array']);

        self::assertSame('array', $result->nativeType);
        self::assertSame('array<mixed>', $result->docblockType);
    }

    #[Test]
    public function itMapsObjectType(): void
    {
        $result = $this->mapper->mapType([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ], true, 'address', 'User');

        self::assertNotNull($result->nestedSchema);
    }

    #[Test]
    public function itMapsEnumType(): void
    {
        $result = $this->mapper->mapType([
            'type' => 'string',
            'enum' => ['active', 'inactive', 'pending'],
        ], true, 'status', 'User');

        self::assertNotNull($result->generatedEnum);
        self::assertSame(['active', 'inactive', 'pending'], $result->generatedEnum->cases);
    }

    #[Test]
    public function itMapsUnionTypes(): void
    {
        $result = $this->mapper->mapType(['type' => ['string', 'integer']]);

        self::assertSame('string|int', $result->nativeType);
    }

    #[Test]
    public function itMapsNullableUnionTypes(): void
    {
        $result = $this->mapper->mapType(['type' => ['string', 'null']]);

        self::assertSame('?string', $result->nativeType);
    }

    #[Test]
    public function itHandlesOneOf(): void
    {
        $result = $this->mapper->mapType([
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ]);

        self::assertSame('string|int', $result->nativeType);
    }

    #[Test]
    public function itHandlesAnyOf(): void
    {
        $result = $this->mapper->mapType([
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'boolean'],
            ],
        ]);

        self::assertSame('string|bool', $result->nativeType);
    }

    #[Test]
    public function mappedTypeRequiresDocblock(): void
    {
        $withDocblock = new MappedType('array', 'array<int, string>');
        $withoutDocblock = new MappedType('string', null);
        $sameDocblock = new MappedType('string', 'string');

        self::assertTrue($withDocblock->requiresDocblock());
        self::assertFalse($withoutDocblock->requiresDocblock());
        self::assertFalse($sameDocblock->requiresDocblock());
    }

    #[Test]
    public function mappedTypeHasImports(): void
    {
        $withImports = new MappedType('string', null, ['\DateTimeImmutable']);
        $withoutImports = new MappedType('string');

        self::assertTrue($withImports->hasImports());
        self::assertFalse($withoutImports->hasImports());
    }

    #[Test]
    public function mappedTypeWithNestedClassName(): void
    {
        $type = new MappedType(
            'Address',
            null,
            [],
            null,
            'Address',
            ['type' => 'object'],
            false
        );

        self::assertSame('Address', $type->nestedClassName);
        self::assertFalse($type->isArrayOfNested);
    }

    #[Test]
    public function generatedEnumProperties(): void
    {
        $enum = new GeneratedEnum(
            enumName: 'UserStatus',
            namespace: 'App\\Dto\\Enum',
            backingType: 'string',
            cases: ['active', 'inactive'],
            phpCode: '<?php enum UserStatus: string {}'
        );

        self::assertSame('UserStatus', $enum->enumName);
        self::assertSame('App\\Dto\\Enum', $enum->namespace);
        self::assertSame(['active', 'inactive'], $enum->cases);
        self::assertSame('string', $enum->backingType);
        self::assertSame('App\\Dto\\Enum\\UserStatus', $enum->getFullyQualifiedName());
        self::assertSame('App/Dto/Enum/UserStatus.php', $enum->getRelativeFilePath());
    }

    #[Test]
    public function propertyDefinitionConstruction(): void
    {
        $mappedType = new MappedType('string');
        $property = new PropertyDefinition(
            name: 'email',
            mappedType: $mappedType,
            isRequired: true,
            defaultValue: PropertyDefinition::NO_DEFAULT,
            description: 'User email address',
            jsonKey: 'email_address'
        );

        self::assertSame('email', $property->name);
        self::assertSame($mappedType, $property->mappedType);
        self::assertTrue($property->isRequired);
        self::assertSame(PropertyDefinition::NO_DEFAULT, $property->defaultValue);
        self::assertSame('User email address', $property->description);
        self::assertSame('email_address', $property->jsonKey);
    }

    #[Test]
    public function propertyDefinitionHasDefaultValue(): void
    {
        $mappedType = new MappedType('string');

        $withDefault = new PropertyDefinition('name', $mappedType, true, 'default');
        $withoutDefault = new PropertyDefinition('name', $mappedType, true, PropertyDefinition::NO_DEFAULT);
        $withNullDefault = new PropertyDefinition('name', $mappedType, true, null);

        self::assertTrue($withDefault->hasDefaultValue());
        self::assertFalse($withoutDefault->hasDefaultValue());
        self::assertTrue($withNullDefault->hasDefaultValue());
    }

    #[Test]
    public function itResolvesRefWithRootSchema(): void
    {
        $rootSchema = [
            '$defs' => [
                'Address' => [
                    'type' => 'object',
                    'properties' => [
                        'street' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $this->mapper->setRootSchema($rootSchema);
        $result = $this->mapper->mapType(['$ref' => '#/$defs/Address'], true, 'address', 'User');
        $this->mapper->clearRootSchema();

        self::assertNotNull($result->nestedSchema);
    }
}
