# Sentinel Drift

[![Latest Version](https://img.shields.io/packagist/v/sentinelphp/drift.svg)](https://packagist.org/packages/sentinelphp/drift)
[![License](https://img.shields.io/packagist/l/sentinelphp/drift.svg)](https://github.com/SentinelPHP/drift/blob/main/LICENSE)

API schema drift detection and classification library.

## Installation

```bash
composer require sentinelphp/drift
```

## Features

- **Detect** differences between expected and actual API responses
- **Classify** drift severity (Info, Warning, Critical)
- **Compare** JSON structures with detailed diff output

## Usage

### Drift Detection

```php
use SentinelPHP\Drift\Detector;
use SentinelPHP\Schema\Validator;

$detector = new Detector(new Validator());

$schema = [
    'type' => 'object',
    'properties' => [
        'id' => ['type' => 'integer'],
        'name' => ['type' => 'string'],
    ],
    'required' => ['id', 'name'],
];

$actualResponse = [
    'id' => '123',  // Type changed: integer → string
    'name' => 'John',
    'extra' => true, // New field added
];

$result = $detector->detect($schema, $actualResponse);

foreach ($result->getDrifts() as $drift) {
    echo sprintf(
        "[%s] %s at %s\n",
        $drift->severity->value,
        $drift->type->value,
        $drift->path
    );
}
```

### Severity Classification

```php
use SentinelPHP\Drift\Classifier;
use SentinelPHP\Drift\Enum\DriftType;
use SentinelPHP\Drift\Enum\DriftSeverity;

$classifier = new Classifier();

// Field removed = Critical
$severity = $classifier->classify(DriftType::FieldRemoved, 'email', null);
// Returns: DriftSeverity::Critical

// Field added = Info
$severity = $classifier->classify(DriftType::FieldAdded, null, 'extra_field');
// Returns: DriftSeverity::Info

// Type changed (object → primitive) = Critical
$severity = $classifier->classify(DriftType::TypeChanged, 'object', 'string');
// Returns: DriftSeverity::Critical
```

### Alert Threshold

```php
$classifier = new Classifier(defaultThreshold: DriftSeverity::Warning);

// Check if drift should trigger an alert
if ($classifier->shouldAlert($drift->severity)) {
    // Send notification
}

// Override threshold per-check
$classifier->shouldAlert($drift->severity, DriftSeverity::Critical);
```

### JSON Diff

```php
use SentinelPHP\Drift\Diff\JsonDiff;

$diff = new JsonDiff();

$expected = ['id' => 1, 'name' => 'John', 'email' => 'john@example.com'];
$actual = ['id' => 1, 'name' => 'Jane', 'phone' => '555-1234'];

$result = $diff->generateDiff($expected, $actual);

echo "Added: " . count($result->added);     // phone
echo "Removed: " . count($result->removed); // email
echo "Changed: " . count($result->changed); // name
```

## Drift Types

| Type | Description | Default Severity |
|------|-------------|------------------|
| `FieldRemoved` | Required field missing | Critical |
| `FieldAdded` | New unexpected field | Info |
| `TypeChanged` | Data type mismatch | Warning/Critical |
| `StructureChanged` | Array/object structure changed | Warning |

## License

GPL v3 — see LICENSE for details
