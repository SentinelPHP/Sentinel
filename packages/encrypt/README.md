# Sentinel Encrypt

[![Latest Version](https://img.shields.io/packagist/v/sentinelphp/encrypt.svg)](https://packagist.org/packages/sentinelphp/encrypt)
[![License](https://img.shields.io/packagist/l/sentinelphp/encrypt.svg)](https://github.com/SentinelPHP/encrypt/blob/main/LICENSE)

Sodium-based encryption library for sensitive data protection.

## Installation

```bash
composer require sentinelphp/encrypt
```

**Requirements:** PHP 8.2+ with the `sodium` extension.

## Usage

```php
use SentinelPHP\Encrypt\Encryptor;

// Generate a new encryption key
$key = Encryptor::generateKey();

// Create encryptor with the key
$encryptor = new Encryptor($key);

// Encrypt data
$plaintext = 'sensitive data';
$ciphertext = $encryptor->encrypt($plaintext);

// Decrypt data
$decrypted = $encryptor->decrypt($ciphertext);
```

## Key Management

```php
use SentinelPHP\Encrypt\Encryptor;

// Generate a new key (base64-encoded)
$key = Encryptor::generateKey();
// Store this securely (e.g., environment variable, secrets manager)

// Check if encryption is enabled
$encryptor = new Encryptor($key);
if ($encryptor->isEnabled()) {
    $encrypted = $encryptor->encrypt($data);
}
```

## Security

This library uses:
- **XSalsa20-Poly1305** authenticated encryption
- **Random nonces** for each encryption operation
- **Constant-time** comparison for authentication

## Error Handling

```php
use SentinelPHP\Encrypt\Encryptor;
use SentinelPHP\Encrypt\Exception\EncryptionException;
use SentinelPHP\Encrypt\Exception\InvalidKeyException;

try {
    $encryptor = new Encryptor($key);
    $decrypted = $encryptor->decrypt($ciphertext);
} catch (InvalidKeyException $e) {
    // Invalid or missing encryption key
} catch (EncryptionException $e) {
    // Encryption/decryption failed (tampered data, wrong key, etc.)
}
```

## License

MIT
