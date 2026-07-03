# Sentinel Core

[![Latest Version](https://img.shields.io/packagist/v/sentinelphp/core.svg)](https://packagist.org/packages/sentinelphp/core)
[![License](https://img.shields.io/packagist/l/sentinelphp/core.svg)](https://github.com/SentinelPHP/core/blob/main/LICENSE)

HTTP client wrapper for intercepting and storing API calls with automatic PII redaction and schema generation.

## Installation

```bash
composer require sentinelphp/core
```

## Features

- **Intercept HTTP calls** via PSR-18 client wrapper or Guzzle middleware
- **Centralized setup** - configure once, intercept all HTTP requests automatically
- **Store API calls** to any backend (logger, database, queue, etc.)
- **Automatic PII redaction** before storage
- **Schema generation** from API responses
- **Pluggable storage** with built-in PSR-3 logger and callback adapters

## Quick Start

### Using PSR-18 Client Wrapper

```php
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use SentinelPHP\Core\Client\SentinelClient;
use SentinelPHP\Core\Config\InterceptorConfig;
use SentinelPHP\Core\SentinelInterceptor;
use SentinelPHP\Core\Storage\Psr3LoggerStorage;
use SentinelPHP\Redact\PiiRedactor;

// 1. Create storage (logs to PSR-3 logger)
$storage = new Psr3LoggerStorage($logger);

// 2. Create interceptor with PII redaction
$interceptor = new SentinelInterceptor(
    storage: $storage,
    config: InterceptorConfig::default(),
    redactor: new PiiRedactor(),
);

// 3. Wrap your HTTP client
$client = new SentinelClient(
    inner: new Client(),
    interceptor: $interceptor,
);

// 4. Use normally - all calls are intercepted and stored
$response = $client->sendRequest($request);
```

### Using Guzzle Middleware

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use SentinelPHP\Core\Middleware\GuzzleMiddleware;
use SentinelPHP\Core\SentinelInterceptor;
use SentinelPHP\Core\Storage\CallbackStorage;

// Store to your database
$storage = new CallbackStorage(function (ApiCallRecord $record) use ($db) {
    $db->insert('api_logs', $record->toArray());
});

$interceptor = new SentinelInterceptor($storage);

$stack = HandlerStack::create();
$stack->push(GuzzleMiddleware::create($interceptor));

$client = new Client(['handler' => $stack]);
$client->get('https://api.example.com/users');
```

## Storage Options

### PSR-3 Logger

```php
use SentinelPHP\Core\Storage\Psr3LoggerStorage;
use Psr\Log\LogLevel;

$storage = new Psr3LoggerStorage(
    logger: $monolog,
    logLevel: LogLevel::INFO,
    includeBody: true,
);
```

### Custom Callback

```php
use SentinelPHP\Core\Storage\CallbackStorage;

$storage = new CallbackStorage(function (ApiCallRecord $record) {
    // Store to database, queue, file, etc.
    $this->entityManager->persist(ApiLog::fromRecord($record));
});
```

### Chain Multiple Storages

```php
use SentinelPHP\Core\Storage\ChainStorage;

$storage = new ChainStorage(
    new Psr3LoggerStorage($logger),
    new CallbackStorage($databaseCallback),
);
```

### SentinelPHP Server

Send intercepted calls to a SentinelPHP server for centralized monitoring, schema learning, and drift detection:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use SentinelPHP\Core\Storage\SentinelHttpStorage;

$httpFactory = new HttpFactory();

$storage = new SentinelHttpStorage(
    httpClient: new Client(),
    requestFactory: $httpFactory,
    streamFactory: $httpFactory,
    baseUrl: 'https://sentinel.example.com',  // Your SentinelPHP server URL
    apiToken: 'your-api-token',                // API token from SentinelPHP dashboard
);
```

**Options:**
- `throwOnError: false` - Silently ignore HTTP errors (fire-and-forget mode)

**How it works:**
1. Your application makes HTTP calls through `SentinelClient` or Guzzle middleware
2. Each call is intercepted and an `ApiCallRecord` is created
3. The record is sent to your SentinelPHP server's `/api/ingest` endpoint
4. SentinelPHP processes the data for logging, schema learning, and drift detection

**Complete Example:**

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use SentinelPHP\Core\Client\SentinelClient;
use SentinelPHP\Core\Config\InterceptorConfig;
use SentinelPHP\Core\SentinelInterceptor;
use SentinelPHP\Core\Storage\SentinelHttpStorage;

// Create HTTP storage pointing to your SentinelPHP server
$httpFactory = new HttpFactory();
$storage = new SentinelHttpStorage(
    httpClient: new Client(['timeout' => 5]),
    requestFactory: $httpFactory,
    streamFactory: $httpFactory,
    baseUrl: $_ENV['SENTINEL_SERVER_URL'],
    apiToken: $_ENV['SENTINEL_API_TOKEN'],
    throwOnError: false,  // Don't fail if SentinelPHP is unavailable
);

// Create interceptor with minimal config (SentinelPHP handles redaction)
$interceptor = new SentinelInterceptor(
    storage: $storage,
    config: InterceptorConfig::minimal(),
);

// Wrap your HTTP client
$client = new SentinelClient(
    inner: new Client(['base_uri' => 'https://api.example.com']),
    interceptor: $interceptor,
);

// All API calls are now monitored by SentinelPHP
$response = $client->get('/users');
```

## Configuration

```php
use SentinelPHP\Core\Config\InterceptorConfig;

// Default: redact PII, capture bodies and headers
$config = InterceptorConfig::default();

// Minimal: no redaction, no body/header capture
$config = InterceptorConfig::minimal();

// Full: redact PII + generate schemas
$config = InterceptorConfig::full();

// Custom
$config = new InterceptorConfig(
    redactPii: true,
    generateSchemas: true,
    captureRequestBody: true,
    captureResponseBody: true,
    captureHeaders: true,
    redactFieldPaths: ['password', 'secret', 'api_key'],
);
```

## API Call Record

Each intercepted call creates an `ApiCallRecord` with:

| Field | Type | Description |
|-------|------|-------------|
| `method` | string | HTTP method (GET, POST, etc.) |
| `url` | string | Full request URL |
| `statusCode` | int | Response status code |
| `latencyMs` | float | Request duration in milliseconds |
| `timestamp` | DateTimeImmutable | When the call was made |
| `requestHeaders` | array | Request headers |
| `requestBody` | ?string | Request body (if captured) |
| `responseHeaders` | array | Response headers |
| `responseBody` | ?string | Response body (if captured) |
| `generatedSchema` | ?array | JSON Schema (if enabled) |
| `id` | ?string | Unique identifier |

## Centralized Setup (Intercept All HTTP Requests)

You can configure SentinelPHP once in your application's service container to automatically intercept **all** HTTP requests without modifying existing code.

### Symfony

Register the `SentinelClient` as your application's HTTP client in `config/services.yaml`:

```yaml
services:
    # Inner HTTP client (not used directly)
    app.http_client.inner:
        class: GuzzleHttp\Client
        arguments:
            - { timeout: 30 }

    # SentinelPHP storage pointing to your server
    SentinelPHP\Core\Storage\SentinelHttpStorage:
        arguments:
            $httpClient: '@app.http_client.inner'
            $requestFactory: '@GuzzleHttp\Psr7\HttpFactory'
            $streamFactory: '@GuzzleHttp\Psr7\HttpFactory'
            $baseUrl: '%env(SENTINEL_SERVER_URL)%'
            $apiToken: '%env(SENTINEL_API_TOKEN)%'
            $throwOnError: false

    # SentinelPHP interceptor
    SentinelPHP\Core\SentinelInterceptor:
        arguments:
            $storage: '@SentinelPHP\Core\Storage\SentinelHttpStorage'
            $config: !service
                class: SentinelPHP\Core\Config\InterceptorConfig
                factory: ['SentinelPHP\Core\Config\InterceptorConfig', 'minimal']

    # Wrapped client - use this as your main HTTP client
    SentinelPHP\Core\Client\SentinelClient:
        arguments:
            $inner: '@app.http_client.inner'
            $interceptor: '@SentinelPHP\Core\SentinelInterceptor'

    # Alias so any service requesting ClientInterface gets the wrapped client
    Psr\Http\Client\ClientInterface: '@SentinelPHP\Core\Client\SentinelClient'
```

Now any service that type-hints `Psr\Http\Client\ClientInterface` will automatically use the SentinelPHP-wrapped client:

```php
class PaymentGateway
{
    public function __construct(
        private ClientInterface $httpClient, // Automatically intercepted!
    ) {}

    public function charge(Payment $payment): Response
    {
        // This request is automatically sent to SentinelPHP
        return $this->httpClient->sendRequest($request);
    }
}
```

### Laravel

Register in a service provider (`app/Providers/AppServiceProvider.php`):

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use SentinelPHP\Core\Client\SentinelClient;
use SentinelPHP\Core\Config\InterceptorConfig;
use SentinelPHP\Core\SentinelInterceptor;
use SentinelPHP\Core\Storage\SentinelHttpStorage;

public function register(): void
{
    $this->app->singleton(ClientInterface::class, function ($app) {
        $httpFactory = new HttpFactory();
        
        $storage = new SentinelHttpStorage(
            httpClient: new Client(['timeout' => 5]),
            requestFactory: $httpFactory,
            streamFactory: $httpFactory,
            baseUrl: config('services.sentinel.url'),
            apiToken: config('services.sentinel.token'),
            throwOnError: false,
        );

        $interceptor = new SentinelInterceptor(
            storage: $storage,
            config: InterceptorConfig::minimal(),
        );

        return new SentinelClient(
            inner: new Client(['timeout' => 30]),
            interceptor: $interceptor,
        );
    });
}
```

Then inject `ClientInterface` anywhere in your application:

```php
class ExternalApiService
{
    public function __construct(
        private ClientInterface $client,
    ) {}
}
```

### Guzzle-Only Applications

If your application uses Guzzle directly, create a factory function that returns a pre-configured client:

```php
// src/Http/HttpClientFactory.php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use SentinelPHP\Core\Config\InterceptorConfig;
use SentinelPHP\Core\Middleware\GuzzleMiddleware;
use SentinelPHP\Core\SentinelInterceptor;
use SentinelPHP\Core\Storage\SentinelHttpStorage;

class HttpClientFactory
{
    private static ?Client $instance = null;

    public static function create(): Client
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $httpFactory = new HttpFactory();
        
        $storage = new SentinelHttpStorage(
            httpClient: new Client(['timeout' => 5]),
            requestFactory: $httpFactory,
            streamFactory: $httpFactory,
            baseUrl: $_ENV['SENTINEL_SERVER_URL'],
            apiToken: $_ENV['SENTINEL_API_TOKEN'],
            throwOnError: false,
        );

        $interceptor = new SentinelInterceptor(
            storage: $storage,
            config: InterceptorConfig::minimal(),
        );

        $stack = HandlerStack::create();
        $stack->push(GuzzleMiddleware::create($interceptor));

        self::$instance = new Client([
            'handler' => $stack,
            'timeout' => 30,
        ]);

        return self::$instance;
    }
}
```

Replace your existing `new Client()` calls with `HttpClientFactory::create()`:

```php
// Before
$client = new Client();

// After
$client = HttpClientFactory::create();
```

### Environment Variables

Add these to your `.env` file:

```env
SENTINEL_SERVER_URL=https://sentinel.example.com
SENTINEL_API_TOKEN=your-api-token
```

## Related Packages

- **[sentinelphp/redact](../redact)** - PII redaction
- **[sentinelphp/schema](../schema)** - JSON Schema generation
- **[sentinelphp/drift](../drift)** - API drift detection
- **[sentinelphp/encrypt](../encrypt)** - Data encryption
- **[sentinelphp/dto](../dto)** - DTO code generation

## License

GPL v3 — see LICENSE for details
