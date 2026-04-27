# Sentinel DTO

[![Latest Version](https://img.shields.io/packagist/v/sentinelphp/dto.svg)](https://packagist.org/packages/sentinelphp/dto)
[![License](https://img.shields.io/packagist/l/sentinelphp/dto.svg)](https://github.com/SentinelPHP/dto/blob/main/LICENSE)

PHP DTO (Data Transfer Object) generation from JSON Schemas.

## Installation

```bash
composer require sentinelphp/dto
```

## Features

- **Generate** PHP classes from JSON Schema
- **Type-safe** properties with proper PHP types
- **Nested objects** and arrays support
- **Enum generation** from schema enums
- **Serialization** methods (fromArray/toArray)

## Usage

### Basic Generation

```php
use SentinelPHP\Dto\Generator;
use SentinelPHP\Dto\Config\GeneratorConfig;

$generator = new Generator();

$schema = [
    'type' => 'object',
    'properties' => [
        'id' => ['type' => 'integer'],
        'name' => ['type' => 'string'],
        'email' => ['type' => 'string', 'format' => 'email'],
        'created_at' => ['type' => 'string', 'format' => 'date-time'],
    ],
    'required' => ['id', 'name'],
];

$metadata = new SchemaMetadata(
    httpMethod: 'GET',
    endpointPath: '/users/{id}',
    schemaType: 'response'
);

$dto = $generator->generate($schema, $metadata);

// Write to file
file_put_contents($dto->filePath, $dto->code);
```

### Generated Output

```php
<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class GetUsersIdResponse
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $email = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            email: $data['email'] ?? null,
            createdAt: isset($data['created_at']) 
                ? new \DateTimeImmutable($data['created_at']) 
                : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->createdAt?->format(\DateTimeInterface::RFC3339),
        ];
    }
}
```

### Configuration

```php
use SentinelPHP\Dto\Config\GeneratorConfig;

$config = new GeneratorConfig(
    defaultNamespace: 'App\\Dto',
    generateGetters: false,
    generateSerialization: true,
    generateValidation: true,
    useReadonlyClasses: true,
    useFinalClasses: true,
);

$generator = new Generator(config: $config);
```

### Custom Naming Strategy

```php
use SentinelPHP\Dto\Naming\NamingStrategyInterface;

class CustomNamingStrategy implements NamingStrategyInterface
{
    public function generateClassName(SchemaMetadata $metadata): string
    {
        return 'Custom' . ucfirst($metadata->httpMethod) . 'Dto';
    }
    
    // ... other methods
}

$generator = new Generator(namingStrategy: new CustomNamingStrategy());
```

## Type Mapping

| JSON Schema Type | PHP Type |
|------------------|----------|
| `string` | `string` |
| `integer` | `int` |
| `number` | `float` |
| `boolean` | `bool` |
| `array` | `array` or typed array |
| `object` | Nested DTO class |
| `null` | `null` |

### Format Handling

| Format | PHP Type |
|--------|----------|
| `date-time` | `\DateTimeImmutable` |
| `date` | `\DateTimeImmutable` |
| `uuid` | `string` (with validation) |
| `email` | `string` (with validation) |
| `uri` | `string` |

## License

MIT
