# Sentinel Schema

[![Latest Version](https://img.shields.io/packagist/v/sentinelphp/schema.svg)](https://packagist.org/packages/sentinelphp/schema)
[![License](https://img.shields.io/packagist/l/sentinelphp/schema.svg)](https://github.com/SentinelPHP/schema/blob/main/LICENSE)

JSON Schema generation, merging, and validation library.

## Installation

```bash
composer require sentinelphp/schema
```

## Features

- **Generate** JSON Schema from sample data
- **Merge** multiple schemas together
- **Validate** data against schemas

## Usage

### Schema Generation

```php
use SentinelPHP\Schema\Generator;
use SentinelPHP\Schema\Config\GeneratorConfig;

$generator = new Generator();

$data = [
    'id' => 123,
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'created_at' => '2024-01-15T10:30:00Z',
];

$schema = $generator->generate($data);
// Returns JSON Schema with inferred types and formats
```

### Schema Merging

```php
use SentinelPHP\Schema\Merger;

$merger = new Merger();

$schema1 = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
$schema2 = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

$merged = $merger->merge($schema1, $schema2);
// Combines properties, widens types, intersects required fields
```

### Schema Validation

```php
use SentinelPHP\Schema\Validator;

$validator = new Validator();

$schema = [
    'type' => 'object',
    'properties' => [
        'email' => ['type' => 'string', 'format' => 'email'],
    ],
    'required' => ['email'],
];

$result = $validator->validate(['email' => 'invalid'], $schema);

if (!$result->isValid()) {
    foreach ($result->getErrors() as $error) {
        echo $error->path . ': ' . $error->message;
    }
}
```

### Partial Validation

Validate only the fields present in the payload:

```php
$result = $validator->validatePartial($partialData, $schema);
```

## Configuration

```php
use SentinelPHP\Schema\Config\GeneratorConfig;

$config = new GeneratorConfig(
    strictMode: true,           // Require all observed fields
    nullableFields: false,      // Don't allow null by default
    additionalProperties: false // Disallow extra properties
);

$generator = new Generator();
$schema = $generator->generate($data, $config);
```

## License

GPL v3 — see LICENSE for details
