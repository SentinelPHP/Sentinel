<?php

declare(strict_types=1);

namespace SentinelPHP\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Schema\Validation\ValidationError;
use SentinelPHP\Schema\Validation\ValidationResult;
use SentinelPHP\Schema\Validator;

#[CoversClass(Validator::class)]
#[CoversClass(ValidationResult::class)]
#[CoversClass(ValidationError::class)]
final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    #[Test]
    public function itValidatesMatchingPayload(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
            'required' => ['name', 'age'],
            'additionalProperties' => false,
        ];

        $payload = ['name' => 'John', 'age' => 30];

        $result = $this->validator->validate($payload, $schema);

        self::assertTrue($result->isValid());
        self::assertEmpty($result->getErrors());
    }

    #[Test]
    public function itDetectsTypeMismatch(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'age' => ['type' => 'integer'],
            ],
            'required' => ['age'],
        ];

        $payload = ['age' => 'not a number'];

        $result = $this->validator->validate($payload, $schema);

        self::assertFalse($result->isValid());
        self::assertNotEmpty($result->getErrors());
        self::assertSame('type', $result->getErrors()[0]->keyword);
    }

    #[Test]
    public function itDetectsMissingRequiredField(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
            ],
            'required' => ['name', 'email'],
        ];

        $payload = ['name' => 'John'];

        $result = $this->validator->validate($payload, $schema);

        self::assertFalse($result->isValid());
        self::assertNotEmpty($result->getErrors());
        self::assertSame('required', $result->getErrors()[0]->keyword);
    }

    #[Test]
    public function itDetectsAdditionalProperties(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];

        $payload = ['name' => 'John', 'extra' => 'field'];

        $result = $this->validator->validate($payload, $schema);

        self::assertFalse($result->isValid());
        self::assertNotEmpty($result->getErrors());
    }

    #[Test]
    public function itAllowsAdditionalPropertiesWhenEnabled(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
            'additionalProperties' => true,
        ];

        $payload = ['name' => 'John', 'extra' => 'field'];

        $result = $this->validator->validate($payload, $schema);

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function itValidatesNestedObjects(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'name'],
                ],
            ],
            'required' => ['user'],
        ];

        $payload = ['user' => ['id' => 1, 'name' => 'Alice']];

        $result = $this->validator->validate($payload, $schema);

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function itDetectsNestedTypeMismatch(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                    ],
                    'required' => ['id'],
                ],
            ],
            'required' => ['user'],
        ];

        $payload = ['user' => ['id' => 'not-an-integer']];

        $result = $this->validator->validate($payload, $schema);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('user', $result->getErrors()[0]->path);
    }

    #[Test]
    public function itValidatesArrays(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['tags'],
        ];

        $payload = ['tags' => ['php', 'symfony', 'api']];

        $result = $this->validator->validate($payload, $schema);

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function itDetectsArrayItemTypeMismatch(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
            ],
            'required' => ['ids'],
        ];

        $payload = ['ids' => [1, 2, 'three']];

        $result = $this->validator->validate($payload, $schema);

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function itValidatesNullableFields(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'name' => ['type' => ['string', 'null']],
            ],
            'required' => ['name'],
        ];

        $payload = ['name' => null];

        $result = $this->validator->validate($payload, $schema);

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function itValidatesStringFormats(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string', 'format' => 'email'],
            ],
            'required' => ['email'],
        ];

        $payload = ['email' => 'user@example.com'];

        $result = $this->validator->validate($payload, $schema);

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function itValidatesPartialPayload(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
                'email' => ['type' => 'string', 'format' => 'email'],
            ],
            'required' => ['name', 'age', 'email'],
        ];

        $partialPayload = ['name' => 'John'];

        $result = $this->validator->validatePartial($partialPayload, $schema);

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function itDetectsTypeMismatchInPartialValidation(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
            'required' => ['name', 'age'],
        ];

        $partialPayload = ['age' => 'not a number'];

        $result = $this->validator->validatePartial($partialPayload, $schema);

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function itValidatesComplexSchema(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'user' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'roles' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['name', 'email'],
                ],
                'metadata' => [
                    'type' => ['object', 'null'],
                ],
            ],
            'required' => ['id', 'user'],
        ];

        $payload = [
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'user' => [
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'roles' => ['admin', 'user'],
            ],
            'metadata' => null,
        ];

        $result = $this->validator->validate($payload, $schema);

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function validationResultToArray(): void
    {
        $error = new ValidationError('/path', 'message', 'type', 'string', 123);
        $result = ValidationResult::invalid([$error]);

        $array = $result->toArray();

        self::assertFalse($array['valid']);
        self::assertCount(1, $array['errors']);
        self::assertSame('/path', $array['errors'][0]['path']);
        self::assertSame('message', $array['errors'][0]['message']);
        self::assertSame('type', $array['errors'][0]['keyword']);
        self::assertSame('string', $array['errors'][0]['expected']);
        self::assertSame(123, $array['errors'][0]['actual']);
    }

    #[Test]
    public function validationResultWithWarnings(): void
    {
        $warning = new ValidationError('/path', 'warning message', 'deprecated');
        $result = new ValidationResult(true, [], [$warning]);

        self::assertTrue($result->isValid());
        self::assertEmpty($result->getErrors());
        self::assertCount(1, $result->getWarnings());
        self::assertSame('warning message', $result->getWarnings()[0]->message);
    }

    #[Test]
    public function validationResultValidFactory(): void
    {
        $result = ValidationResult::valid();

        self::assertTrue($result->isValid());
        self::assertEmpty($result->getErrors());
        self::assertEmpty($result->getWarnings());
    }

    #[Test]
    public function validationErrorToArray(): void
    {
        $error = new ValidationError('/data/name', 'Type mismatch', 'type', 'string', 123);

        $array = $error->toArray();

        self::assertSame('/data/name', $array['path']);
        self::assertSame('Type mismatch', $array['message']);
        self::assertSame('type', $array['keyword']);
        self::assertSame('string', $array['expected']);
        self::assertSame(123, $array['actual']);
    }

    #[Test]
    public function validationErrorWithNullExpectedAndActual(): void
    {
        $error = new ValidationError('/path', 'message', 'required');

        self::assertNull($error->expected);
        self::assertNull($error->actual);
    }
}
