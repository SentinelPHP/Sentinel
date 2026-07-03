# Sentinel Redact

[![Latest Version](https://img.shields.io/packagist/v/sentinelphp/redact.svg)](https://packagist.org/packages/sentinelphp/redact)
[![License](https://img.shields.io/packagist/l/sentinelphp/redact.svg)](https://github.com/SentinelPHP/redact/blob/main/LICENSE)

PII redaction library for JSON payloads and strings.

## Installation

```bash
composer require sentinelphp/redact
```

## Usage

```php
use SentinelPHP\Redact\PiiRedactor;

$redactor = new PiiRedactor();

// Redact PII from a string
$text = "Contact me at john@example.com or 555-123-4567";
$redacted = $redactor->redactString($text);
// Result: "Contact me at [EMAIL REDACTED] or [PHONE REDACTED]"

// Redact PII from JSON data
$data = [
    'user' => [
        'email' => 'john@example.com',
        'phone' => '555-123-4567',
        'credit_card' => '4111-1111-1111-1111',
    ],
];
$redacted = $redactor->redact($data);
```

## Built-in Patterns

- **Credit Cards**: Visa, Mastercard, Amex, Discover
- **Email Addresses**: Standard email format
- **Phone Numbers**: US phone formats
- **SSN**: US Social Security Numbers
- **API Keys**: Common API key formats (Stripe, Bearer tokens)

## Custom Patterns

```php
$redactor = new PiiRedactor();

// Add a custom pattern
$redactor->addPattern('custom_id', '/ID-\d{6}/', '[ID REDACTED]');

// Remove a default pattern
$redactor->removePattern('phone');
```

## Field Path Redaction

Redact specific fields by their JSON path:

```php
$redactor = new PiiRedactor();
$redactor->addFieldPath('user.password');
$redactor->addFieldPath('*.secret');

$data = ['user' => ['password' => 'secret123']];
$redacted = $redactor->redact($data);
// Result: ['user' => ['password' => '[REDACTED]']]
```

## License

GPL v3 — see LICENSE for details
