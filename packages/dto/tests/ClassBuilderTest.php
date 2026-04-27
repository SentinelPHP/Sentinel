<?php

declare(strict_types=1);

namespace SentinelPHP\Dto\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Dto\Builder\ClassBuilder;
use SentinelPHP\Dto\MappedType;
use SentinelPHP\Dto\PropertyDefinition;

#[CoversClass(ClassBuilder::class)]
final class ClassBuilderTest extends TestCase
{
    private ClassBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ClassBuilder();
    }

    #[Test]
    public function itBuildsBasicClass(): void
    {
        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->build();

        self::assertStringContainsString('namespace App\\Dto;', $code);
        self::assertStringContainsString('class UserDto', $code);
    }

    #[Test]
    public function itBuildsReadonlyFinalClass(): void
    {
        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setReadonly(true)
            ->setFinal(true)
            ->build();

        self::assertStringContainsString('final readonly class UserDto', $code);
    }

    #[Test]
    public function itBuildsNonReadonlyClass(): void
    {
        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setReadonly(false)
            ->setFinal(false)
            ->build();

        self::assertStringContainsString('class UserDto', $code);
        self::assertStringNotContainsString('readonly', $code);
        self::assertStringNotContainsString('final', $code);
    }

    #[Test]
    public function itAddsUseStatements(): void
    {
        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addUseStatement('DateTimeImmutable')
            ->addUseStatement('App\\Entity\\User')
            ->build();

        self::assertStringContainsString('use DateTimeImmutable;', $code);
        self::assertStringContainsString('use App\\Entity\\User;', $code);
    }

    #[Test]
    public function itDeduplicatesUseStatements(): void
    {
        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addUseStatement('DateTimeImmutable')
            ->addUseStatement('DateTimeImmutable')
            ->build();

        self::assertSame(1, substr_count($code, 'use DateTimeImmutable;'));
    }

    #[Test]
    public function itAddsClassDocblock(): void
    {
        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setClassDocblock('User data transfer object')
            ->build();

        self::assertStringContainsString('/**', $code);
        self::assertStringContainsString('User data transfer object', $code);
    }

    #[Test]
    public function itAddsClassAttributes(): void
    {
        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addClassAttribute('ApiResource')
            ->build();

        self::assertStringContainsString('#[ApiResource]', $code);
    }

    #[Test]
    public function itAddsProperties(): void
    {
        $mappedType = new MappedType('string');
        $property = new PropertyDefinition('name', $mappedType, true);

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addProperty($property)
            ->build();

        self::assertStringContainsString('public string $name', $code);
    }

    #[Test]
    public function itAddsNullableProperties(): void
    {
        $mappedType = new MappedType('?string');
        $property = new PropertyDefinition('nickname', $mappedType, false);

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addProperty($property)
            ->build();

        self::assertStringContainsString('?string $nickname', $code);
    }

    #[Test]
    public function itAddsPropertiesWithDefaults(): void
    {
        $mappedType = new MappedType('string');
        $property = new PropertyDefinition('status', $mappedType, true, 'active');

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addProperty($property)
            ->build();

        self::assertStringContainsString("= 'active'", $code);
    }

    #[Test]
    public function itAddsPropertiesWithNullDefault(): void
    {
        $mappedType = new MappedType('?string');
        $property = new PropertyDefinition('nickname', $mappedType, false, null);

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addProperty($property)
            ->build();

        self::assertStringContainsString('= null', $code);
    }

    #[Test]
    public function itAddsPropertyDocblocks(): void
    {
        $mappedType = new MappedType('array', 'array<string>');
        $property = new PropertyDefinition('tags', $mappedType, true, PropertyDefinition::NO_DEFAULT, 'List of tags');

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addProperty($property)
            ->build();

        self::assertStringContainsString('@var array<string>', $code);
        self::assertStringContainsString('List of tags', $code);
    }

    #[Test]
    public function itExtendsBaseClass(): void
    {
        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setBaseClass('App\\Dto\\BaseDto')
            ->build();

        self::assertStringContainsString('extends BaseDto', $code);
        self::assertStringContainsString('use App\\Dto\\BaseDto;', $code);
    }

    #[Test]
    public function itImplementsInterfaces(): void
    {
        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addInterface('JsonSerializable')
            ->build();

        self::assertStringContainsString('implements JsonSerializable', $code);
    }

    #[Test]
    public function itImplementsMultipleInterfaces(): void
    {
        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addInterface('JsonSerializable')
            ->addInterface('Stringable')
            ->build();

        self::assertStringContainsString('implements JsonSerializable, Stringable', $code);
    }

    #[Test]
    public function itAddsTraits(): void
    {
        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addTrait('App\\Traits\\TimestampTrait')
            ->build();

        self::assertStringContainsString('use TimestampTrait;', $code);
    }

    #[Test]
    public function itGeneratesGetters(): void
    {
        $mappedType = new MappedType('string');
        $property = new PropertyDefinition('name', $mappedType, true);

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setReadonly(false)
            ->addProperty($property)
            ->setGenerateGetters(true)
            ->build();

        self::assertStringContainsString('public function getName(): string', $code);
        self::assertStringContainsString('return $this->name;', $code);
    }

    #[Test]
    public function itGeneratesSerializationMethods(): void
    {
        $mappedType = new MappedType('string');
        $property = new PropertyDefinition('name', $mappedType, true);

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addProperty($property)
            ->setGenerateSerialization(true)
            ->build();

        self::assertStringContainsString('public static function fromArray(array $data): self', $code);
        self::assertStringContainsString('public function toArray(): array', $code);
    }

    #[Test]
    public function itGeneratesJsonSerializable(): void
    {
        $mappedType = new MappedType('string');
        $property = new PropertyDefinition('name', $mappedType, true);

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addProperty($property)
            ->setGenerateJsonSerializable(true)
            ->build();

        self::assertStringContainsString('implements JsonSerializable', $code);
        self::assertStringContainsString('public function jsonSerialize(): array', $code);
    }

    #[Test]
    public function itGeneratesValidation(): void
    {
        $mappedType = new MappedType('string');
        $property = new PropertyDefinition('email', $mappedType, true);

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addProperty($property)
            ->setGenerateValidation(true)
            ->build();

        // Validation generates NotBlank attribute for required properties
        self::assertStringContainsString('NotBlank', $code);
    }

    #[Test]
    public function itResetsState(): void
    {
        $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('FirstDto')
            ->addUseStatement('SomeClass');

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Other')
            ->setClassName('SecondDto')
            ->build();

        self::assertStringContainsString('namespace App\\Other;', $code);
        self::assertStringContainsString('class SecondDto', $code);
        self::assertStringNotContainsString('FirstDto', $code);
        self::assertStringNotContainsString('SomeClass', $code);
    }

    #[Test]
    public function itHandlesImportsFromMappedType(): void
    {
        $mappedType = new MappedType('\DateTimeImmutable', null, ['\DateTimeImmutable']);
        $property = new PropertyDefinition('createdAt', $mappedType, true);

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addProperty($property)
            ->build();

        self::assertStringContainsString('use DateTimeImmutable;', $code);
    }

    #[Test]
    public function itGeneratesSerializerAttributes(): void
    {
        $mappedType = new MappedType('string');
        $property = new PropertyDefinition('userName', $mappedType, true, PropertyDefinition::NO_DEFAULT, null, null, [], 'user_name');

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addProperty($property)
            ->setGenerateSerializerAttributes(true)
            ->build();

        self::assertStringContainsString('SerializedName', $code);
    }
}
