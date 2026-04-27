<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dto;

use App\Service\Dto\PhpClassBuilder;
use SentinelPHP\Dto\MappedType;
use SentinelPHP\Dto\PropertyDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpClassBuilder::class)]
final class PhpClassBuilderTest extends TestCase
{
    private PhpClassBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PhpClassBuilder();
    }

    #[Test]
    public function it_builds_empty_class(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto\\Generated')
            ->setClassName('EmptyDto')
            ->build();

        self::assertStringContainsString('namespace App\\Dto\\Generated;', $code);
        self::assertStringContainsString('final readonly class EmptyDto', $code);
        self::assertStringContainsString('declare(strict_types=1);', $code);
    }

    #[Test]
    public function it_builds_class_with_primitive_properties(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->addProperty(new PropertyDefinition(
                name: 'id',
                mappedType: new MappedType(nativeType: 'int'),
                isRequired: true,
            ))
            ->addProperty(new PropertyDefinition(
                name: 'name',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
            ))
            ->addProperty(new PropertyDefinition(
                name: 'active',
                mappedType: new MappedType(nativeType: 'bool'),
                isRequired: true,
            ))
            ->build();

        self::assertStringContainsString('public int $id,', $code);
        self::assertStringContainsString('public string $name,', $code);
        self::assertStringContainsString('public bool $active,', $code);
        self::assertStringContainsString('public function __construct(', $code);
    }

    #[Test]
    public function it_builds_class_with_nullable_properties(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('ProfileDto')
            ->addProperty(new PropertyDefinition(
                name: 'id',
                mappedType: new MappedType(nativeType: 'int'),
                isRequired: true,
            ))
            ->addProperty(new PropertyDefinition(
                name: 'bio',
                mappedType: new MappedType(nativeType: '?string'),
                isRequired: false,
            ))
            ->build();

        self::assertStringContainsString('public int $id,', $code);
        self::assertStringContainsString('public ?string $bio = null,', $code);
    }

    #[Test]
    public function it_sorts_properties_required_first(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('SortedDto')
            ->addProperty(new PropertyDefinition(
                name: 'optional',
                mappedType: new MappedType(nativeType: '?string'),
                isRequired: false,
            ))
            ->addProperty(new PropertyDefinition(
                name: 'required',
                mappedType: new MappedType(nativeType: 'int'),
                isRequired: true,
            ))
            ->build();

        $requiredPos = strpos($code, '$required');
        $optionalPos = strpos($code, '$optional');

        self::assertNotFalse($requiredPos);
        self::assertNotFalse($optionalPos);
        self::assertLessThan($optionalPos, $requiredPos, 'Required properties should come before optional');
    }

    #[Test]
    public function it_builds_class_with_default_values(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('DefaultsDto')
            ->addProperty(new PropertyDefinition(
                name: 'count',
                mappedType: new MappedType(nativeType: 'int'),
                isRequired: true,
                defaultValue: 0,
            ))
            ->addProperty(new PropertyDefinition(
                name: 'name',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
                defaultValue: 'default',
            ))
            ->addProperty(new PropertyDefinition(
                name: 'enabled',
                mappedType: new MappedType(nativeType: 'bool'),
                isRequired: true,
                defaultValue: true,
            ))
            ->addProperty(new PropertyDefinition(
                name: 'tags',
                mappedType: new MappedType(nativeType: 'array'),
                isRequired: true,
                defaultValue: [],
            ))
            ->build();

        self::assertStringContainsString("public int \$count = 0,", $code);
        self::assertStringContainsString("public string \$name = 'default',", $code);
        self::assertStringContainsString('public bool $enabled = true,', $code);
        self::assertStringContainsString('public array $tags = [],', $code);
    }

    #[Test]
    public function it_builds_class_with_docblock(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('DocumentedDto')
            ->setClassDocblock("This is a documented DTO.\n\n@generated Automatically generated")
            ->build();

        self::assertStringContainsString('/**', $code);
        self::assertStringContainsString(' * This is a documented DTO.', $code);
        self::assertStringContainsString(' * @generated Automatically generated', $code);
        self::assertStringContainsString(' */', $code);
    }

    #[Test]
    public function it_builds_class_with_property_docblocks(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('ArrayDto')
            ->addProperty(new PropertyDefinition(
                name: 'items',
                mappedType: new MappedType(
                    nativeType: 'array',
                    docblockType: 'array<string>',
                ),
                isRequired: true,
            ))
            ->build();

        self::assertStringContainsString('@var array<string>', $code);
    }

    #[Test]
    public function it_builds_class_with_property_description(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('DescribedDto')
            ->addProperty(new PropertyDefinition(
                name: 'email',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
                description: 'The user email address',
            ))
            ->build();

        self::assertStringContainsString('The user email address', $code);
    }

    #[Test]
    public function it_builds_class_with_use_statements(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('DateDto')
            ->addUseStatement('DateTimeImmutable')
            ->addProperty(new PropertyDefinition(
                name: 'createdAt',
                mappedType: new MappedType(
                    nativeType: '\DateTimeImmutable',
                    imports: ['\DateTimeImmutable'],
                ),
                isRequired: true,
            ))
            ->build();

        self::assertStringContainsString('use DateTimeImmutable;', $code);
    }

    #[Test]
    public function it_sorts_use_statements_alphabetically(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('MultiImportDto')
            ->addUseStatement('Zebra\\Class')
            ->addUseStatement('Alpha\\Class')
            ->addUseStatement('Middle\\Class')
            ->build();

        $alphaPos = strpos($code, 'use Alpha\\Class;');
        $middlePos = strpos($code, 'use Middle\\Class;');
        $zebraPos = strpos($code, 'use Zebra\\Class;');

        self::assertNotFalse($alphaPos);
        self::assertNotFalse($middlePos);
        self::assertNotFalse($zebraPos);
        self::assertLessThan($middlePos, $alphaPos);
        self::assertLessThan($zebraPos, $middlePos);
    }

    #[Test]
    public function it_deduplicates_use_statements(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('DupeDto')
            ->addUseStatement('DateTimeImmutable')
            ->addUseStatement('DateTimeImmutable')
            ->addUseStatement('\\DateTimeImmutable')
            ->build();

        self::assertSame(1, substr_count($code, 'use DateTimeImmutable;'));
    }

    #[Test]
    public function it_builds_class_with_attributes(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('AttributedDto')
            ->addProperty(new PropertyDefinition(
                name: 'status',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
                attributes: ["\\App\\Attribute\\Format('status')"],
            ))
            ->build();

        self::assertStringContainsString("#[\\App\\Attribute\\Format('status')]", $code);
    }

    #[Test]
    public function it_builds_class_with_class_attributes(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('ClassAttributeDto')
            ->addClassAttribute('Deprecated')
            ->build();

        self::assertStringContainsString('#[Deprecated]', $code);
    }

    #[Test]
    public function it_builds_non_readonly_class(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('MutableDto')
            ->setReadonly(false)
            ->build();

        self::assertStringNotContainsString('readonly class', $code);
        self::assertStringContainsString('final class MutableDto', $code);
    }

    #[Test]
    public function it_builds_non_final_class(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('ExtendableDto')
            ->setFinal(false)
            ->build();

        self::assertStringNotContainsString('final', $code);
        self::assertStringContainsString('readonly class ExtendableDto', $code);
    }

    #[Test]
    public function it_builds_class_with_getters(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('GetterDto')
            ->setGenerateGetters(true)
            ->addProperty(new PropertyDefinition(
                name: 'name',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
            ))
            ->build();

        self::assertStringContainsString('public function getName(): string', $code);
        self::assertStringContainsString('return $this->name;', $code);
    }

    #[Test]
    public function it_resets_builder_state(): void
    {
        $this->builder
            ->setNamespace('App\\First')
            ->setClassName('FirstDto')
            ->addProperty(new PropertyDefinition(
                name: 'first',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
            ));

        $code = $this->builder
            ->reset()
            ->setNamespace('App\\Second')
            ->setClassName('SecondDto')
            ->build();

        self::assertStringContainsString('namespace App\\Second;', $code);
        self::assertStringContainsString('class SecondDto', $code);
        self::assertStringNotContainsString('FirstDto', $code);
        self::assertStringNotContainsString('$first', $code);
    }

    #[Test]
    public function it_handles_complex_default_array_values(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('ComplexDefaultDto')
            ->addProperty(new PropertyDefinition(
                name: 'config',
                mappedType: new MappedType(nativeType: 'array'),
                isRequired: true,
                defaultValue: ['key' => 'value', 'nested' => ['a', 'b']],
            ))
            ->build();

        self::assertStringContainsString("'key' => 'value'", $code);
        self::assertStringContainsString("'nested' => ['a', 'b']", $code);
    }

    #[Test]
    public function it_escapes_string_default_values(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('EscapedDto')
            ->addProperty(new PropertyDefinition(
                name: 'message',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
                defaultValue: "It's a \"test\"",
            ))
            ->build();

        self::assertStringContainsString("'It\\'s a \\\"test\\\"'", $code);
    }

    #[Test]
    public function it_generates_psr12_compliant_code(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto\\Generated')
            ->setClassName('Psr12Dto')
            ->addProperty(new PropertyDefinition(
                name: 'id',
                mappedType: new MappedType(nativeType: 'int'),
                isRequired: true,
            ))
            ->build();

        // Check for 4-space indentation
        self::assertStringContainsString('    public function __construct(', $code);
        self::assertStringContainsString('        public int $id,', $code);

        // Check for proper line endings (no Windows CRLF)
        self::assertStringNotContainsString("\r\n", $code);

        // Check for blank line after namespace
        self::assertMatchesRegularExpression('/namespace [^;]+;\n\n/', $code);
    }

    #[Test]
    public function it_handles_union_types(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UnionDto')
            ->addProperty(new PropertyDefinition(
                name: 'value',
                mappedType: new MappedType(nativeType: 'string|int'),
                isRequired: true,
            ))
            ->build();

        self::assertStringContainsString('public string|int $value,', $code);
    }

    #[Test]
    public function it_handles_nullable_union_types(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('NullableUnionDto')
            ->addProperty(new PropertyDefinition(
                name: 'value',
                mappedType: new MappedType(nativeType: 'string|int|null'),
                isRequired: false,
            ))
            ->build();

        self::assertStringContainsString('public string|int|null $value = null,', $code);
    }
}
