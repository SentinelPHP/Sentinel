<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dto;

use App\Service\Dto\PhpClassBuilder;
use SentinelPHP\Dto\GeneratedEnum;
use SentinelPHP\Dto\MappedType;
use SentinelPHP\Dto\PropertyDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpClassBuilder::class)]
final class SerializationGenerationTest extends TestCase
{
    private PhpClassBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PhpClassBuilder();
    }

    #[Test]
    public function it_generates_from_array_method(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateSerialization(true)
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
            ->build();

        self::assertStringContainsString('public static function fromArray(array $data): self', $code);
        self::assertStringContainsString('return new self(', $code);
        self::assertStringContainsString("id: (int) (\$data['id'])", $code);
        self::assertStringContainsString("name: (string) (\$data['name'])", $code);
    }

    #[Test]
    public function it_generates_to_array_method(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateSerialization(true)
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
            ->build();

        self::assertStringContainsString('public function toArray(): array', $code);
        self::assertStringContainsString("'id' => \$this->id", $code);
        self::assertStringContainsString("'name' => \$this->name", $code);
    }

    #[Test]
    public function it_generates_json_serializable_interface(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateJsonSerializable(true)
            ->addProperty(new PropertyDefinition(
                name: 'id',
                mappedType: new MappedType(nativeType: 'int'),
                isRequired: true,
            ))
            ->build();

        self::assertStringContainsString('use JsonSerializable;', $code);
        self::assertStringContainsString('implements JsonSerializable', $code);
        self::assertStringContainsString('public function jsonSerialize(): array', $code);
    }

    #[Test]
    public function it_json_serialize_delegates_to_to_array(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateSerialization(true)
            ->setGenerateJsonSerializable(true)
            ->addProperty(new PropertyDefinition(
                name: 'id',
                mappedType: new MappedType(nativeType: 'int'),
                isRequired: true,
            ))
            ->build();

        self::assertStringContainsString('public function jsonSerialize(): array', $code);
        self::assertStringContainsString('return $this->toArray();', $code);
    }

    #[Test]
    public function it_handles_nullable_properties_in_from_array(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('ProfileDto')
            ->setGenerateSerialization(true)
            ->addProperty(new PropertyDefinition(
                name: 'bio',
                mappedType: new MappedType(nativeType: '?string'),
                isRequired: false,
            ))
            ->build();

        self::assertStringContainsString("isset(\$data['bio']) ? (string) \$data['bio'] : null", $code);
    }

    #[Test]
    public function it_handles_default_values_in_from_array(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('ConfigDto')
            ->setGenerateSerialization(true)
            ->addProperty(new PropertyDefinition(
                name: 'enabled',
                mappedType: new MappedType(nativeType: 'bool'),
                isRequired: true,
                defaultValue: true,
            ))
            ->build();

        self::assertStringContainsString("\$data['enabled'] ?? true", $code);
    }

    #[Test]
    public function it_handles_datetime_in_serialization(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('EventDto')
            ->setGenerateSerialization(true)
            ->addProperty(new PropertyDefinition(
                name: 'createdAt',
                mappedType: new MappedType(
                    nativeType: '\DateTimeImmutable',
                    imports: ['\DateTimeImmutable'],
                ),
                isRequired: true,
            ))
            ->build();

        // fromArray should parse DateTime
        self::assertStringContainsString("new \\DateTimeImmutable(\$data['createdAt'])", $code);
        // toArray should format DateTime
        self::assertStringContainsString("\$this->createdAt->format(\\DateTimeInterface::ATOM)", $code);
    }

    #[Test]
    public function it_handles_nullable_datetime_in_serialization(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('EventDto')
            ->setGenerateSerialization(true)
            ->addProperty(new PropertyDefinition(
                name: 'deletedAt',
                mappedType: new MappedType(
                    nativeType: '?\DateTimeImmutable',
                    imports: ['\DateTimeImmutable'],
                ),
                isRequired: false,
            ))
            ->build();

        // fromArray should handle null
        self::assertStringContainsString("isset(\$data['deletedAt']) ? new \\DateTimeImmutable(\$data['deletedAt']) : null", $code);
        // toArray should use null-safe operator
        self::assertStringContainsString("\$this->deletedAt?->format(\\DateTimeInterface::ATOM)", $code);
    }

    #[Test]
    public function it_handles_nested_objects_in_serialization(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('OrderDto')
            ->setGenerateSerialization(true)
            ->addProperty(new PropertyDefinition(
                name: 'customer',
                mappedType: new MappedType(
                    nativeType: 'CustomerDto',
                    nestedClassName: 'CustomerDto',
                ),
                isRequired: true,
            ))
            ->build();

        // fromArray should call nested fromArray
        self::assertStringContainsString("CustomerDto::fromArray(\$data['customer'])", $code);
        // toArray should call nested toArray
        self::assertStringContainsString("\$this->customer->toArray()", $code);
    }

    #[Test]
    public function it_handles_array_of_nested_objects(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('OrderDto')
            ->setGenerateSerialization(true)
            ->addProperty(new PropertyDefinition(
                name: 'items',
                mappedType: new MappedType(
                    nativeType: 'array',
                    docblockType: 'array<OrderItemDto>',
                    nestedClassName: 'OrderItemDto',
                    isArrayOfNested: true,
                ),
                isRequired: true,
            ))
            ->build();

        // fromArray should map array
        self::assertStringContainsString('array_map(static fn(array $item): OrderItemDto => OrderItemDto::fromArray($item)', $code);
        // toArray should map array
        self::assertStringContainsString('array_map(static fn(object $item): array => $item->toArray()', $code);
    }

    #[Test]
    public function it_handles_enums_in_serialization(): void
    {
        $generatedEnum = new GeneratedEnum(
            enumName: 'StatusEnum',
            namespace: 'App\\Dto\\Enum',
            backingType: 'string',
            cases: ['active', 'inactive'],
            phpCode: '',
        );

        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateSerialization(true)
            ->addProperty(new PropertyDefinition(
                name: 'status',
                mappedType: new MappedType(
                    nativeType: 'StatusEnum',
                    generatedEnum: $generatedEnum,
                ),
                isRequired: true,
            ))
            ->build();

        // fromArray should use ::from()
        self::assertStringContainsString("StatusEnum::from(\$data['status'])", $code);
        // toArray should use ->value
        self::assertStringContainsString('$this->status->value', $code);
    }

    #[Test]
    public function it_uses_json_key_for_serialization(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateSerialization(true)
            ->addProperty(new PropertyDefinition(
                name: 'firstName',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
                jsonKey: 'first_name',
            ))
            ->build();

        // fromArray should use JSON key
        self::assertStringContainsString("\$data['first_name']", $code);
        // toArray should use JSON key
        self::assertStringContainsString("'first_name' =>", $code);
    }

    #[Test]
    public function it_generates_symfony_serializer_groups_attribute(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateSerializerAttributes(true)
            ->addProperty(new PropertyDefinition(
                name: 'id',
                mappedType: new MappedType(nativeType: 'int'),
                isRequired: true,
            ))
            ->build();

        self::assertStringContainsString('use Symfony\\Component\\Serializer\\Attribute\\Groups;', $code);
        self::assertStringContainsString("#[Groups(['read', 'write'])]", $code);
    }

    #[Test]
    public function it_generates_serialized_name_attribute_for_custom_json_key(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateSerializerAttributes(true)
            ->addProperty(new PropertyDefinition(
                name: 'firstName',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
                jsonKey: 'first_name',
            ))
            ->build();

        self::assertStringContainsString('use Symfony\\Component\\Serializer\\Attribute\\SerializedName;', $code);
        self::assertStringContainsString("#[SerializedName('first_name')]", $code);
    }

    #[Test]
    public function it_does_not_add_serialized_name_when_json_key_matches_property(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateSerializerAttributes(true)
            ->addProperty(new PropertyDefinition(
                name: 'id',
                mappedType: new MappedType(nativeType: 'int'),
                isRequired: true,
            ))
            ->build();

        self::assertStringNotContainsString('SerializedName', $code);
    }

    #[Test]
    public function it_generates_not_blank_validator_for_required_string(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateValidation(true)
            ->addProperty(new PropertyDefinition(
                name: 'name',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
            ))
            ->build();

        self::assertStringContainsString('use Symfony\\Component\\Validator\\Constraints\\NotBlank;', $code);
        self::assertStringContainsString('#[NotBlank]', $code);
    }

    #[Test]
    public function it_generates_not_null_validator_for_required_non_string(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateValidation(true)
            ->addProperty(new PropertyDefinition(
                name: 'age',
                mappedType: new MappedType(nativeType: 'int'),
                isRequired: true,
            ))
            ->build();

        self::assertStringContainsString('use Symfony\\Component\\Validator\\Constraints\\NotNull;', $code);
        self::assertStringContainsString('#[NotNull]', $code);
    }

    #[Test]
    public function it_generates_email_validator_for_email_format(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateValidation(true)
            ->addProperty(new PropertyDefinition(
                name: 'email',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
                format: 'email',
            ))
            ->build();

        self::assertStringContainsString('use Symfony\\Component\\Validator\\Constraints\\Email;', $code);
        self::assertStringContainsString('#[Email]', $code);
    }

    #[Test]
    public function it_generates_uuid_validator_for_uuid_format(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateValidation(true)
            ->addProperty(new PropertyDefinition(
                name: 'id',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
                format: 'uuid',
            ))
            ->build();

        self::assertStringContainsString('use Symfony\\Component\\Validator\\Constraints\\Uuid;', $code);
        self::assertStringContainsString('#[Uuid]', $code);
    }

    #[Test]
    public function it_generates_url_validator_for_uri_format(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateValidation(true)
            ->addProperty(new PropertyDefinition(
                name: 'website',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: false,
                format: 'uri',
            ))
            ->build();

        self::assertStringContainsString('use Symfony\\Component\\Validator\\Constraints\\Url;', $code);
        self::assertStringContainsString('#[Url]', $code);
    }

    #[Test]
    public function it_does_not_generate_serialization_when_disabled(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('UserDto')
            ->setGenerateSerialization(false)
            ->setGenerateJsonSerializable(false)
            ->addProperty(new PropertyDefinition(
                name: 'id',
                mappedType: new MappedType(nativeType: 'int'),
                isRequired: true,
            ))
            ->build();

        self::assertStringNotContainsString('fromArray', $code);
        self::assertStringNotContainsString('toArray', $code);
        self::assertStringNotContainsString('jsonSerialize', $code);
        self::assertStringNotContainsString('JsonSerializable', $code);
    }

    #[Test]
    public function it_combines_all_serialization_features(): void
    {
        $code = $this->builder
            ->setNamespace('App\\Dto')
            ->setClassName('CompleteDto')
            ->setGenerateSerialization(true)
            ->setGenerateJsonSerializable(true)
            ->setGenerateSerializerAttributes(true)
            ->setGenerateValidation(true)
            ->addProperty(new PropertyDefinition(
                name: 'email',
                mappedType: new MappedType(nativeType: 'string'),
                isRequired: true,
                format: 'email',
                jsonKey: 'user_email',
            ))
            ->build();

        // All features should be present
        self::assertStringContainsString('implements JsonSerializable', $code);
        self::assertStringContainsString('public static function fromArray(array $data): self', $code);
        self::assertStringContainsString('public function toArray(): array', $code);
        self::assertStringContainsString('public function jsonSerialize(): array', $code);
        self::assertStringContainsString("#[Groups(['read', 'write'])]", $code);
        self::assertStringContainsString("#[SerializedName('user_email')]", $code);
        self::assertStringContainsString('#[NotBlank]', $code);
        self::assertStringContainsString('#[Email]', $code);
    }
}
