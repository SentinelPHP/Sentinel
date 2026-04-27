# SentinelPHP

**An Open-Source, Async Middleware for API Observability and Schema Insurance.**

SentinelPHP is a high-performance, standalone proxy service built with **Symfony** and **Swoole**. It acts as a "Contract Guard" between your application and third-party APIs, providing token-based authentication, request logging, and health monitoring.

## Features

### Core Proxy
- **Async HTTP Proxy** — Built on Swoole for sub-millisecond overhead
- **Token-Based Authentication** — Secure Bearer token validation with Redis caching
- **Target Restrictions** — Limit tokens to specific destination hosts
- **Request Logging** — Metadata logging to PostgreSQL
- **Health Monitoring** — `/health` and `/status` endpoints

### Schema Insurance
- **Learning Mode** — Automatically generates JSON Schemas from API responses
- **Validation Mode** — Detects schema drift with detailed diffs
- **Drift Classification** — Severity levels (info/warning/critical)
- **Alerting** — Slack and webhook notifications on drift detection

### Data Protection
- **PII Redaction** — Configurable patterns for credit cards, emails, SSNs, API keys
- **Encryption at Rest** — Sodium-based encryption (XSalsa20-Poly1305)
- **Composable Strategies** — Redact only, encrypt only, or both

### Web Dashboard
- **Real-Time Monitoring** — Live dashboard with Mercure-powered updates
- **Service Health Grid** — Traffic light view of all monitored APIs
- **Drift Inspector** — Side-by-side JSON diff viewer with syntax highlighting
- **Role-Based Access Control** — Admin and user roles with token-level permissions
- **Responsive Design** — Mobile-friendly with dark mode support

### DTO Generation
- **Automatic PHP DTOs** — Generate type-safe PHP classes from learned JSON Schemas
- **Modern PHP 8.2+** — Readonly properties, constructor promotion, backed enums
- **Nested Object Support** — Separate classes for nested objects with proper references
- **Serialization Methods** — Built-in `fromArray()`, `toArray()`, and `JsonSerializable`
- **Version History** — Track DTO changes across schema updates with diff viewing
- **CLI & Dashboard** — Generate, export, list, and compare DTOs via CLI or web UI

## Quick Start

### Prerequisites

- [DDEV](https://ddev.readthedocs.io/en/stable/) (for development)
- Docker & Docker Compose (for production)

### Development Setup (DDEV)

```bash
# Clone the repository
git clone https://github.com/your-org/SentinelPHP.git
cd SentinelPHP

# Copy environment file
cp .env.example .env

# Start DDEV
ddev start

# Run database migrations
ddev exec php bin/console doctrine:migrations:migrate --no-interaction

# Create an API token
ddev exec php bin/console sentinel:token:create "My First Token"

# Start the Swoole server
ddev swoole
```

The proxy is now available at `https://sentinelphp.ddev.site:8080`.

### Production Setup (Docker)

```bash
# Copy and configure environment
cp .env.example .env
# Edit .env with production values (APP_SECRET, DATABASE_URL, etc.)

# Build and start services
make prod-deploy

# Run migrations
docker compose -f .docker/docker-compose.prod.yml exec app php bin/console doctrine:migrations:migrate --no-interaction

# Create an API token
docker compose -f .docker/docker-compose.prod.yml exec app php bin/console sentinel:token:create "Production Token"
```

The proxy listens on port `8080` by default.

## Usage

### Making Proxy Requests

Send requests to SentinelPHP with:
1. **Authorization header** — Your Bearer token
2. **X-Sentinel-Target header** — The destination API URL

```bash
curl -X GET https://sentinelphp.ddev.site:8080/users/123 \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "X-Sentinel-Target: https://api.example.com"
```

SentinelPHP will forward the request to `https://api.example.com/users/123` and return the response.

### Health Check

```bash
curl https://sentinelphp.ddev.site:8080/health
```

Returns:
```json
{
  "status": "ok",
  "timestamp": "2024-01-15T10:30:00+00:00",
  "checks": {
    "database": "ok",
    "redis": "ok",
    "outbound_http": "ok"
  }
}
```

### Status Endpoint

The `/status` endpoint provides runtime metrics (restricted to private IPs by default):

```bash
curl https://sentinelphp.ddev.site:8080/status
```

Returns:
```json
{
  "uptime_seconds": 3600,
  "total_requests": 1500,
  "active_connections": 5
}
```

### Ingest Endpoint (External Client Integration)

The `/api/ingest` endpoint allows external applications to send API call records directly to SentinelPHP without using the proxy. This is useful when:

- Your application makes HTTP calls directly (not through the proxy)
- You want to monitor API calls from multiple services
- You're using the `sentinelphp/core` package in your own application

**Request:**

```bash
curl -X POST https://sentinelphp.ddev.site/api/ingest \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "method": "GET",
    "url": "https://api.example.com/users/123",
    "statusCode": 200,
    "latencyMs": 45.5,
    "timestamp": "2024-01-15T10:30:00+00:00",
    "requestHeaders": {"Accept": "application/json"},
    "responseHeaders": {"Content-Type": "application/json"},
    "responseBody": "{\"id\":123,\"name\":\"John Doe\"}"
  }'
```

**Response:**

```json
{
  "success": true,
  "message": "Ingested successfully"
}
```

**Using the Core Package:**

Install `sentinelphp/core` in your application to automatically send API calls to SentinelPHP:

```bash
composer require sentinelphp/core
```

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use SentinelPHP\Core\Client\SentinelClient;
use SentinelPHP\Core\Config\InterceptorConfig;
use SentinelPHP\Core\SentinelInterceptor;
use SentinelPHP\Core\Storage\SentinelHttpStorage;

// Create storage pointing to your SentinelPHP server
$httpFactory = new HttpFactory();
$storage = new SentinelHttpStorage(
    httpClient: new Client(['timeout' => 5]),
    requestFactory: $httpFactory,
    streamFactory: $httpFactory,
    baseUrl: 'https://sentinel.example.com',
    apiToken: 'your-api-token',
    throwOnError: false,  // Don't fail if SentinelPHP is unavailable
);

// Wrap your HTTP client
$interceptor = new SentinelInterceptor($storage, InterceptorConfig::minimal());
$client = new SentinelClient(new Client(), $interceptor);

// All API calls are now monitored
$response = $client->get('https://api.example.com/users');
```

See the [Core Package README](packages/core/README.md) for more details.

## API Token Management

### Creating Tokens

```bash
# Basic token (all hosts allowed)
ddev exec php bin/console sentinel:token:create "My Token"

# Token restricted to specific hosts
ddev exec php bin/console sentinel:token:create "Stripe Token" \
  --targets=api.stripe.com \
  --targets=files.stripe.com

# Token with custom log level
ddev exec php bin/console sentinel:token:create "Debug Token" \
  --log-level=full_audit
```

**Options:**
- `--targets` / `-t` — Allowed target hosts (supports wildcards like `*.stripe.com`)
- `--log-level` / `-l` — Override log level (`metadata_only`, `drift_only`, `full_audit`)

### Token Security

- Tokens are hashed before storage (cannot be retrieved)
- Save the plain token immediately after creation
- Tokens are cached in Redis for performance

## Configuration Reference

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `dev` | Environment (`dev`, `prod`, `test`) |
| `APP_SECRET` | — | Symfony secret (generate with `openssl rand -hex 32`) |
| `DATABASE_URL` | — | PostgreSQL connection string |
| `REDIS_URL` | — | Redis connection string |
| `PROXY_LISTEN_PORT` | `8080` | Port the proxy listens on |
| `PROXY_TIMEOUT` | `30.0` | Request timeout in seconds |
| `PROXY_CONNECT_TIMEOUT` | `10.0` | Connection timeout in seconds |
| `LOG_LEVEL` | `metadata_only` | Default logging level |
| `HEALTH_CHECK_URL` | `https://httpbin.org/status/200` | URL for outbound HTTP health check |
| `STATUS_ALLOWED_IPS` | `null` | JSON array of allowed IPs for `/status` (null = private ranges) |

### Swoole Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `SWOOLE_HOST` | `0.0.0.0` | Host to bind to |
| `SWOOLE_WORKER_NUM` | `4` | Number of worker processes |
| `SWOOLE_MAX_REQUEST` | `10000` | Max requests per worker before restart |
| `SWOOLE_GRACEFUL_SHUTDOWN_TIMEOUT` | `30` | Graceful shutdown timeout in seconds |

### Log Levels

| Level | Description |
|-------|-------------|
| `none` | No logging (skip entirely) |
| `metadata_only` | Logs request method, path, status code, latency (default) |
| `drift_only` | Logs metadata; stores request/response headers and bodies only when drift detected (compressed, stored in `DriftPayload`) |
| `headers` | Logs metadata + request/response headers (no bodies) |
| `full_audit` | Logs complete request/response headers and bodies (stored in `RequestLog`, optionally compressed) |

## Schema Learning Workflow

SentinelPHP can automatically learn API schemas from traffic and then validate future responses against them.

### 1. Create a Token in Learning Mode

```bash
ddev exec php bin/console sentinel:token:create "Stripe API" \
  --mode=learning \
  --targets=api.stripe.com \
  --learning-threshold=10 \
  --auto-switch
```

**Options:**
- `--mode=learning` — Enable schema learning
- `--learning-threshold=N` — Samples required before schema is stable (default: 10)
- `--auto-switch` — Automatically switch to validating mode when threshold reached

### 2. Send Traffic Through the Proxy

Make requests through SentinelPHP as normal. Each response is analyzed and merged into a learned schema:

```bash
curl -X GET https://sentinelphp.ddev.site:8080/v1/customers \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Sentinel-Target: https://api.stripe.com"
```

### 3. View Learned Schemas

```bash
# List all schemas
ddev exec php bin/console sentinel:schema:list

# Show a specific schema
ddev exec php bin/console sentinel:schema:show <schema-id>

# Export to file
ddev exec php bin/console sentinel:schema:export <schema-id> --output=schema.json
```

### 4. Promote to Master (Manual)

If not using `--auto-switch`, manually promote a learned schema:

```bash
ddev exec php bin/console sentinel:schema:promote <schema-id> --switch-mode
```

### 5. Validation Mode

Once in validating mode, every response is checked against the master schema. Drift is recorded and alerts are dispatched based on severity.

### Token Modes

| Mode | Behavior |
|------|----------|
| `passive` | No schema operations (default) |
| `learning` | Generate/merge schemas from responses |
| `validating` | Validate responses against master schema |

### Schema Merging Logic

During learning, schemas are intelligently merged:
- **Union of fields** — All observed fields are included
- **Type widening** — `integer` → `number` if both types seen
- **Array unification** — Item schemas are merged
- **Format detection** — Dates, UUIDs, emails, URLs auto-detected

### Example Generated Schema

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "properties": {
    "id": { "type": "string", "format": "uuid" },
    "email": { "type": "string", "format": "email" },
    "created_at": { "type": "string", "format": "date-time" },
    "amount": { "type": "integer" },
    "metadata": {
      "type": "object",
      "additionalProperties": true
    }
  },
  "required": ["id", "email", "created_at", "amount"]
}
```

## Drift Detection

### Drift Types

| Type | Description |
|------|-------------|
| `field_added` | New field appeared in response |
| `field_removed` | Expected field missing from response |
| `type_changed` | Field type differs from schema |
| `structure_changed` | Array/object structure changed |

### Drift Severity Classification

| Severity | Triggers |
|----------|----------|
| **Critical** | Field removed, type changed from object/array to primitive |
| **Warning** | Type changed between compatible types, new required field |
| **Info** | New optional field added, format changed |

### Viewing Drift History

```bash
# List recent drifts
ddev exec php bin/console sentinel:drift:list

# Filter by severity
ddev exec php bin/console sentinel:drift:list --severity=critical

# Filter by token
ddev exec php bin/console sentinel:drift:list --token=<token-id>
```

## Alert Channel Configuration

Alerts are dispatched when drift severity meets the configured threshold.

### Slack Alerts

Configure via environment variables:

```bash
# .env
SLACK_ALERT_WEBHOOK_URL=https://hooks.slack.com/services/T00/B00/XXX
SLACK_ALERT_RATE_LIMIT=10  # Max alerts per minute
```

Slack messages include:
- Endpoint path and HTTP method
- Drift type and severity
- Expected vs actual values
- JSON path to changed field
- Link to token/schema details

### Webhook Alerts

Generic HTTP POST to any URL:

```bash
# .env
WEBHOOK_ALERT_URL=https://your-service.com/api/alerts
WEBHOOK_ALERT_MAX_RETRIES=3
WEBHOOK_ALERT_BASE_DELAY_MS=1000  # Exponential backoff base
```

**Payload format:**

```json
{
  "drift_id": "01234567-89ab-cdef-0123-456789abcdef",
  "drift_type": "field_removed",
  "severity": "critical",
  "path": "$.data.user.email",
  "expected": {"type": "string", "format": "email"},
  "actual": null,
  "endpoint": "GET /v1/users/123",
  "target_host": "api.example.com",
  "token_name": "Production API",
  "detected_at": "2024-01-15T10:30:00+00:00"
}
```

### Per-Token Alert Configuration

Override minimum severity per token:

```bash
ddev exec php bin/console sentinel:token:update <token-id> \
  --alert-min-severity=critical
```

## DTO Generation

SentinelPHP can automatically generate type-safe PHP Data Transfer Objects (DTOs) from learned JSON Schemas, enabling strongly-typed API integrations.

### Generation Workflow

1. **Learn Schema** — Traffic flows through the proxy in learning mode
2. **Promote to Master** — Schema is promoted when stable
3. **Generate DTO** — PHP class is generated from the JSON Schema
4. **Export to Filesystem** — Write PHP files to your project

```bash
# Generate DTO for a specific schema
ddev exec php bin/console sentinel:dto:generate --schema-id=<uuid>

# Export to filesystem
ddev exec php bin/console sentinel:dto:export --schema-id=<uuid>
```

### Auto-Generation

Enable automatic DTO generation when schemas are promoted:

```bash
# .env
SENTINEL_DTO_AUTO_GENERATE=true
```

Or enable per-token via the dashboard's token settings page.

### Generated DTO Features

Generated DTOs include:

- **Readonly Properties** — Immutable by default (PHP 8.1+)
- **Constructor Promotion** — Clean, concise constructors
- **Type Declarations** — Full native PHP types
- **Nullable Handling** — Based on schema `required` array
- **Nested Objects** — Separate classes with proper references
- **Backed Enums** — PHP 8.1+ enums for schema `enum` constraints
- **Serialization** — `fromArray()`, `toArray()`, `JsonSerializable`

### Example Generated DTO

**Input Schema:**

```json
{
  "type": "object",
  "properties": {
    "id": { "type": "string", "format": "uuid" },
    "email": { "type": "string", "format": "email" },
    "status": { "type": "string", "enum": ["active", "inactive", "pending"] },
    "created_at": { "type": "string", "format": "date-time" },
    "profile": {
      "type": "object",
      "properties": {
        "name": { "type": "string" },
        "age": { "type": "integer" }
      }
    }
  },
  "required": ["id", "email", "status"]
}
```

**Generated PHP:**

```php
<?php

declare(strict_types=1);

namespace App\Dto\Generated;

use App\Attribute\Description;
use App\Attribute\Format;

final readonly class GetUsersResponse implements \JsonSerializable
{
    public function __construct(
        #[Format('uuid')]
        public string $id,
        #[Format('email')]
        public string $email,
        public GetUsersResponseStatus $status,
        #[Format('date-time')]
        public ?\DateTimeImmutable $createdAt = null,
        public ?GetUsersResponseProfile $profile = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            email: $data['email'],
            status: GetUsersResponseStatus::from($data['status']),
            createdAt: isset($data['created_at']) 
                ? new \DateTimeImmutable($data['created_at']) 
                : null,
            profile: isset($data['profile']) 
                ? GetUsersResponseProfile::fromArray($data['profile']) 
                : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'status' => $this->status->value,
            'created_at' => $this->createdAt?->format(\DateTimeInterface::RFC3339),
            'profile' => $this->profile?->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
```

### Using Generated DTOs

**With an API client:**

```php
use App\Dto\Generated\GetUsersResponse;

$response = $httpClient->request('GET', '/api/users/123');
$data = $response->toArray();

// Type-safe DTO
$user = GetUsersResponse::fromArray($data);

echo $user->email;           // string
echo $user->status->value;   // "active"
echo $user->profile?->name;  // ?string
```

**Autoloading configuration (composer.json):**

```json
{
    "autoload": {
        "psr-4": {
            "App\\Dto\\Generated\\": "src/Dto/Generated/"
        }
    }
}
```

### Dashboard DTO Management

The web dashboard provides a full DTO management interface at `/dashboard/dtos`:

- **List View** — Browse all generated DTOs with filters
- **Code Viewer** — Syntax-highlighted PHP code display
- **Version History** — View and compare previous versions
- **Diff Viewer** — Side-by-side comparison of changes
- **Download** — Individual PHP files or bulk ZIP export
- **Regenerate** — Trigger regeneration from current schema

### DTO API Endpoints

Programmatic access to generated DTOs:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/dtos` | List DTOs for authenticated token |
| `GET` | `/api/dtos/{id}` | Get specific DTO metadata |
| `GET` | `/api/dtos/{id}/download` | Download PHP file |
| `POST` | `/api/dtos/generate` | Trigger DTO generation |

**Example API request:**

```bash
curl -X GET https://sentinelphp.ddev.site/api/dtos \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Data Protection Configuration

SentinelPHP provides composable data protection strategies for request/response bodies stored in full audit mode.

### Protection Strategies

| Strategy | Description |
|----------|-------------|
| `none` | No protection (default) |
| `redact` | PII patterns replaced with masked values |
| `encrypt` | Sodium encryption at rest |
| `redact_encrypt` | Redact first, then encrypt (defense-in-depth) |

### Global Configuration

```bash
# .env
SENTINEL_DATA_PROTECTION_STRATEGY=redact_encrypt
SENTINEL_ENCRYPTION_KEY=<base64-encoded-32-byte-key>
```

### Per-Token Override

```bash
ddev exec php bin/console sentinel:token:update <token-id> \
  --data-protection=encrypt
```

### Encryption Key Management

**Generate a new key:**

```bash
# Interactive output
ddev exec php bin/console sentinel:encryption:generate-key

# Direct .env format
ddev exec php bin/console sentinel:encryption:generate-key --env-format >> .env
```

**Key requirements:**
- 32 bytes (256 bits), base64-encoded
- Store securely (secrets manager, encrypted vault)
- Never commit to version control
- If lost, encrypted data cannot be recovered

**Key rotation procedure:**

Key rotation is critical for security hygiene. Follow these steps carefully:

```bash
# Step 1: Generate a new encryption key
ddev exec php bin/console sentinel:encryption:generate-key --env-format
# Output: SENTINEL_ENCRYPTION_KEY=<new-base64-key>
# Save this new key securely - you'll need it in Step 4

# Step 2: Export encrypted data with the OLD key (backup)
# Ensure your current .env still has the OLD key
ddev exec php bin/console sentinel:data:export --output=/tmp/encrypted-backup.json

# Step 3: Decrypt and re-encrypt data (run migration script)
# This command decrypts with old key and re-encrypts with new key
ddev exec php bin/console sentinel:encryption:rotate-key \
  --old-key="<your-old-base64-key>" \
  --new-key="<your-new-base64-key>" \
  --batch-size=100

# Step 4: Update environment with new key
# Replace SENTINEL_ENCRYPTION_KEY in your .env or secrets manager
# For production, update via your deployment pipeline

# Step 5: Verify data integrity
ddev exec php bin/console sentinel:data:verify --sample-size=10

# Step 6: Securely delete the old key
# Remove from password managers, rotate any backups containing it
```

**Key rotation checklist:**
- [ ] Generate new key and store securely
- [ ] Backup current encrypted data
- [ ] Run rotation in staging/test first
- [ ] Verify decryption works with new key
- [ ] Deploy new key to production
- [ ] Monitor for decryption errors
- [ ] Securely destroy old key after grace period

### PII Redaction Patterns

**Default patterns (enabled by default):**

| Pattern | Example Input | Redacted Output |
|---------|---------------|------------------|
| Credit Card | `4111111111111111` | `****-****-****-1111` |
| API Key | `sk_live_abc123...` | `[REDACTED]` |
| Email | `john.doe@example.com` | `j***@example.com` |
| Phone | `+1-555-123-4567` | `+1-***-***-4567` |
| SSN | `123-45-6789` | `***-**-6789` |

**Default field paths (always redacted):**

```
$.password, $.secret, $.token, $.api_key, $.apiKey,
$.access_token, $.accessToken, $.refresh_token, $.refreshToken,
$.private_key, $.privateKey, $.credit_card, $.creditCard,
$.card_number, $.cardNumber, $.cvv, $.ssn,
$.social_security, $.socialSecurity
```

**Custom patterns:**

```bash
# .env - Add custom regex patterns
SENTINEL_REDACTION_PATTERNS='{"order_id":{"pattern":"/\\bORD-[A-Z0-9]{8}\\b/","replacement":"[ORDER_REDACTED]"}}'

# Additional field paths to redact
SENTINEL_REDACTION_FIELD_PATHS='["$.internal_id","$.debug_info"]'
```

**Per-token custom patterns:**

```bash
ddev exec php bin/console sentinel:token:update <token-id> \
  --redaction-patterns='{"custom":{"pattern":"/pattern/","replacement":"[MASKED]"}}'
```

### Disable Default Patterns

```bash
# .env
SENTINEL_REDACTION_ENABLE_DEFAULTS=false
```

## Security Considerations

### Encryption Key Security

- **Never commit keys to version control** — Use environment variables or secrets managers
- **Use strong keys** — Always generate with `sentinel:encryption:generate-key`
- **Backup keys securely** — Encrypted data is unrecoverable without the key
- **Rotate keys periodically** — Especially after personnel changes

### PCI DSS Compliance Notes

If handling payment card data:

1. **Use `redact` or `redact_encrypt` strategy** — Credit card numbers are masked by default
2. **Verify patterns match your data formats** — Test with sample data before production
3. **Enable full audit logging selectively** — Only for endpoints that require it
4. **Review retention policies** — Set `SENTINEL_RETENTION_DAYS` appropriately
5. **Encrypt at rest** — Use `encrypt` or `redact_encrypt` for stored bodies

### Network Security

- **Run behind a reverse proxy** — Terminate TLS at nginx/Traefik
- **Restrict `/status` endpoint** — Configure `STATUS_ALLOWED_IPS` for internal access only
- **Use private networks** — Deploy in VPC with restricted egress

### Token Security

- **Tokens are hashed** — Plain tokens cannot be retrieved from the database
- **Use target restrictions** — Limit tokens to specific API hosts
- **Rotate tokens regularly** — Create new tokens and revoke old ones
- **Monitor for anomalies** — Review drift alerts for unexpected API changes

### Data Retention

Configure automatic cleanup of old logs:

```bash
# .env
SENTINEL_RETENTION_DAYS=90  # Delete logs older than 90 days
SENTINEL_RETENTION_BATCH_SIZE=1000
```

Run the purge command (schedule via cron):

```bash
ddev exec php bin/console sentinel:retention:purge
```

## Docker Configuration

### Development (DDEV)

DDEV handles all services automatically:
- PHP 8.4 with Swoole
- PostgreSQL 16
- Redis 7

```bash
ddev start          # Start all services
ddev stop           # Stop all services
ddev ssh            # Shell access
ddev logs           # View logs
```

### Production

The production stack uses `.docker/docker-compose.prod.yml`:

```bash
make prod-build     # Build images
make prod-up        # Start services
make prod-down      # Stop services
make prod-logs      # View logs
make prod-clean     # Remove volumes
make prod-deploy    # Build + start
```

**Services:**
- `app` — PHP 8.4 + Swoole (non-root user, read-only filesystem)
- `worker` — Messenger consumer (5 replicas by default, configurable via `WORKER_REPLICAS`)
- `postgres` — PostgreSQL 16 Alpine
- `redis` — Redis 7 Alpine with persistence

## Project Structure

```
├── bin/
│   ├── console           # Symfony console
│   └── swoole-server     # Swoole HTTP server entry point
├── config/
│   ├── packages/         # Symfony bundle configuration
│   ├── services.yaml     # Service definitions
│   └── routes.yaml       # Route definitions
├── .docker/
│   ├── Dockerfile.prod   # Production Dockerfile
│   └── docker-compose.prod.yml
├── src/
│   ├── Command/          # CLI commands
│   ├── Controller/       # HTTP controllers
│   ├── Entity/           # Doctrine entities
│   ├── Enum/             # Enums (TokenMode, DriftSeverity, etc.)
│   ├── Service/          # Business logic
│   │   ├── Alert/        # Alert channels (Slack, Webhook)
│   │   └── DataProtection/  # PII redaction, encryption
│   └── Swoole/           # Swoole integration
├── packages/             # Extracted standalone libraries
│   ├── redact/           # sentinel/redact - PII redaction
│   ├── encrypt/          # sentinel/encrypt - Sodium encryption
│   ├── schema/           # sentinel/schema - JSON Schema tools
│   ├── drift/            # sentinel/drift - Drift detection
│   ├── dto/              # sentinel/dto - DTO generation
│   └── core/             # sentinel/core - Metapackage
└── tests/
    ├── Integration/      # Integration tests
    └── Unit/             # Unit tests
```

## Standalone Libraries

SentinelPHP's core functionality is extracted into standalone Composer packages that can be used independently:

| Package | Description |
|---------|-------------|
| [`sentinel/redact`](packages/redact) | PII redaction for JSON payloads and strings |
| [`sentinel/encrypt`](packages/encrypt) | Sodium-based encryption (XSalsa20-Poly1305) |
| [`sentinel/schema`](packages/schema) | JSON Schema generation, merging, and validation |
| [`sentinel/drift`](packages/drift) | API drift detection and severity classification |
| [`sentinel/dto`](packages/dto) | PHP DTO generation from JSON Schemas |
| [`sentinel/core`](packages/core) | Metapackage that includes all above |

### Using Libraries Independently

```bash
# Install just what you need
composer require sentinel/schema
composer require sentinel/redact

# Or install everything
composer require sentinel/core
```

```php
use SentinelPHP\Schema\Generator;
use SentinelPHP\Schema\Validator;
use SentinelPHP\Redact\PiiRedactor;

// Generate schema from sample data
$generator = new Generator();
$schema = $generator->generate($apiResponse);

// Validate data against schema
$validator = new Validator();
$result = $validator->validate($newResponse, $schema);

// Redact PII before logging
$redactor = new PiiRedactor();
$safeData = $redactor->redact($apiResponse);
```

## CLI Commands Reference

### Token Management

```bash
# Create token
sentinel:token:create <name> [--targets=...] [--mode=...] [--log-level=...]

# Update token
sentinel:token:update <token-id> [--mode=...] [--data-protection=...]
```

### Schema Management

```bash
# List schemas
sentinel:schema:list [--token=...] [--host=...]

# Show schema details
sentinel:schema:show <schema-id> [--diff=<version>]

# Import schema from file
sentinel:schema:import <file> --token=<id> --host=<host> --path=<path> --method=<method>

# Export schema to file
sentinel:schema:export <schema-id> [--output=<file>] [--format=json|openapi]

# Promote to master
sentinel:schema:promote <schema-id> [--switch-mode]
```

### Drift Management

```bash
# List drifts
sentinel:drift:list [--severity=...] [--token=...] [--limit=...]
```

### Data Protection

```bash
# Generate encryption key
sentinel:encryption:generate-key [--env-format]
```

### Maintenance

```bash
# Purge old logs
sentinel:retention:purge [--days=...] [--batch-size=...]
```

### DTO Management

```bash
# Generate DTOs from schemas
sentinel:dto:generate --schema-id=<uuid>           # Single schema
sentinel:dto:generate --token=<name-or-uuid>       # All schemas for token
sentinel:dto:generate --all                        # All master schemas
sentinel:dto:generate --schema-id=<uuid> --dry-run # Preview without saving

# Export DTOs to filesystem
sentinel:dto:export --schema-id=<uuid>             # Export single DTO
sentinel:dto:export --token=<name> --format=bundled # Bundled file per token
sentinel:dto:export --all --output-dir=/path       # Export all to custom dir
sentinel:dto:export --all --force                  # Overwrite existing files

# List generated DTOs
sentinel:dto:list                                  # List all DTOs
sentinel:dto:list --token=<name> --limit=100       # Filter by token
sentinel:dto:list --endpoint=/users --class=User  # Filter by endpoint/class

# Show DTO code
sentinel:dto:show <dto-uuid>                       # Display with metadata
sentinel:dto:show <dto-uuid> --dto-version=2       # Show specific version
sentinel:dto:show <dto-uuid> --raw                 # Raw PHP code only

# Compare DTO versions
sentinel:dto:diff <dto-uuid>                       # Current vs previous
sentinel:dto:diff <dto-uuid> --from=1 --to=3       # Compare specific versions
sentinel:dto:diff <dto-uuid> --raw                 # Raw diff output
```

## Feature Configuration

### Schema Learning

| Variable | Default | Description |
|----------|---------|-------------|
| `SCHEMA_LEARNING_MIN_SAMPLES` | `10` | Samples before schema is stable |
| `SCHEMA_CACHE_TTL` | `3600` | Master schema cache TTL (seconds) |

### Data Protection

| Variable | Default | Description |
|----------|---------|-------------|
| `SENTINEL_ENCRYPTION_KEY` | — | Base64-encoded 32-byte key |
| `SENTINEL_DATA_PROTECTION_STRATEGY` | `none` | Global strategy |
| `SENTINEL_REDACTION_PATTERNS` | — | Custom patterns (JSON) |
| `SENTINEL_REDACTION_FIELD_PATHS` | — | Additional field paths (JSON array) |
| `SENTINEL_REDACTION_ENABLE_DEFAULTS` | `true` | Enable built-in PII patterns |

### Alerting

| Variable | Default | Description |
|----------|---------|-------------|
| `SLACK_ALERT_WEBHOOK_URL` | — | Slack incoming webhook URL |
| `SLACK_ALERT_RATE_LIMIT` | `10` | Max alerts per minute |
| `WEBHOOK_ALERT_URL` | — | Generic webhook URL |
| `WEBHOOK_ALERT_MAX_RETRIES` | `3` | Retry attempts on failure |
| `WEBHOOK_ALERT_BASE_DELAY_MS` | `1000` | Exponential backoff base |

### Retention & Storage

| Variable | Default | Description |
|----------|---------|-------------|
| `SENTINEL_RETENTION_DAYS` | `90` | Auto-delete logs after N days |
| `SENTINEL_RETENTION_BATCH_SIZE` | `1000` | Records per purge batch |
| `SENTINEL_COMPRESS_AUDIT_LOGS` | `false` | Gzip compress bodies in full_audit mode |

### DTO Generation

| Variable | Default | Description |
|----------|---------|-------------|
| `SENTINEL_DTO_NAMESPACE` | `App\Dto\Generated` | Default namespace for generated DTOs |
| `SENTINEL_DTO_OUTPUT_DIR` | `src/Dto/Generated` | Output directory for exported files |
| `SENTINEL_DTO_AUTO_GENERATE` | `false` | Auto-generate DTOs on schema promotion |

**Additional configuration in `config/packages/sentinel_dto.yaml`:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `php_version` | `8.2` | Target PHP version (8.2, 8.3) |
| `readonly_properties` | `true` | Generate readonly properties |
| `generate_getters` | `false` | Generate getter methods |
| `generate_serialization` | `true` | Generate `fromArray()`/`toArray()` methods |
| `generate_json_serializable` | `true` | Implement `JsonSerializable` interface |
| `generate_serializer_attributes` | `false` | Add Symfony Serializer attributes |
| `generate_validation` | `false` | Add Symfony Validator attributes |
| `template.base_class` | `null` | Base class for generated DTOs |
| `template.interfaces` | `[]` | Interfaces to implement |
| `template.traits` | `[]` | Traits to use |
| `property_mappings` | `[]` | JSON key → PHP property name mappings |
| `excluded_properties` | `[]` | JSON paths to exclude from generation |

## Testing

```bash
# Run all tests
ddev exec php bin/phpunit

# Run with coverage
ddev exec php bin/phpunit --coverage-html var/coverage

# Static analysis
ddev exec vendor/bin/phpstan analyse
```

## Load Testing

SentinelPHP includes k6-based load tests for performance benchmarking.

### Quick Start

```bash
# Smoke test (quick validation)
make k6-smoke

# Full load test (~16 minutes)
make k6-load

# Stress test (find breaking points)
make k6-stress

# Spike test (traffic bursts)
make k6-spike
```

### Configuration

```bash
# Test against custom server
K6_BASE_URL=http://my-server:8080 K6_API_TOKEN=my-token make k6-load
```

### Available Tests

| Test | Duration | Max VUs | Purpose |
|------|----------|---------|---------|
| `k6-smoke` | 30s | 1 | Quick validation |
| `k6-load` | 16m | 100 | Normal load |
| `k6-stress` | 18m | 500 | Breaking points |
| `k6-spike` | 7m | 500 | Traffic bursts |
| `k6-scenario` | 5m | 50 | Realistic flows |

### Metrics Visualization

```bash
# Start Grafana + InfluxDB
make k6-monitoring-up

# Run test with metrics
make k6-load-with-metrics

# Access Grafana at http://localhost:3000
```

See [tests/load/README.md](tests/load/README.md) for detailed documentation.

## Observability

SentinelPHP includes comprehensive observability support with metrics, tracing, and structured logging.

### Quick Start

```bash
# Start the observability stack
make observability-up

# Access dashboards
# - Grafana:    http://localhost:3000 (admin/admin)
# - Prometheus: http://localhost:9090
# - Tempo:      http://localhost:3200
```

### Prometheus Metrics

Metrics are exposed at `/metrics` in Prometheus text format:

```bash
curl http://localhost:8080/metrics
```

**Available metrics:**

| Metric | Type | Description |
|--------|------|-------------|
| `sentinel_http_requests_total` | Counter | Total HTTP requests by method, route, status |
| `sentinel_http_request_duration_seconds` | Histogram | Request latency distribution |
| `sentinel_proxy_requests_total` | Counter | Proxy requests by target host |
| `sentinel_schema_operations_total` | Counter | Schema operations (learn, validate, merge) |
| `sentinel_drift_detected_total` | Counter | Drifts detected by severity and type |
| `sentinel_alerts_sent_total` | Counter | Alerts sent by channel and status |
| `sentinel_circuit_breaker_state` | Gauge | Circuit breaker state (0=closed, 1=open, 2=half-open) |

### Distributed Tracing

Enable OpenTelemetry tracing for request correlation:

```bash
# .env
OTEL_ENABLED=true
OTEL_SERVICE_NAME=sentinel-php
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
```

Traces include:
- Proxy request lifecycle
- Schema validation operations
- Alert dispatch chain
- Upstream API calls

### Structured Logging

Production logs are JSON-formatted with trace context:

```json
{
  "datetime": "2026-04-21T15:00:00.000Z",
  "level_name": "INFO",
  "message": "Proxy request completed",
  "trace_id": "abc123def456",
  "span_id": "789xyz",
  "context": {
    "method": "GET",
    "path": "/api/users",
    "duration_ms": 45
  }
}
```

### Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `METRICS_ENABLED` | `true` | Enable `/metrics` endpoint |
| `OTEL_ENABLED` | `false` | Enable distributed tracing |
| `OTEL_SERVICE_NAME` | `sentinel-php` | Service name in traces |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | OTLP collector endpoint |

### Makefile Commands

```bash
make observability-up       # Start Prometheus, Grafana, Tempo, Loki
make observability-down     # Stop observability stack
make observability-logs     # View stack logs
make observability-status   # Check service status
```

## Web Dashboard

SentinelPHP includes a full-featured web dashboard for monitoring and managing your API proxy.

### Accessing the Dashboard

The dashboard is available at `/dashboard` after authentication:

```bash
# Development
https://sentinelphp.ddev.site/dashboard

# Production
https://your-domain/dashboard
```

### Dashboard Users

Create dashboard users via CLI:

```bash
# Create a regular user
ddev exec php bin/console sentinel:user:create user@example.com

# Create an admin user
ddev exec php bin/console sentinel:user:create admin@example.com --admin

# Provide password inline (for scripts)
ddev exec php bin/console sentinel:user:create user@example.com --password=SecurePass123
```

**User Roles:**

| Role | Permissions |
|------|-------------|
| `ROLE_USER` | View-only access to assigned tokens and their schemas/drifts |
| `ROLE_ADMIN` | Full access to all tokens, schemas, users, and settings |

### Managing User Permissions

Admins can manage user permissions via the web UI:

1. Navigate to `/dashboard/users` (admin only)
2. Click on a user to view their details
3. Click "Manage Permissions" to assign token access
4. Select which API tokens the user can view

### Dashboard Pages

| Page | Route | Description |
|------|-------|-------------|
| Overview | `/dashboard` | System metrics, health status, recent activity |
| Services | `/dashboard/services` | Traffic light health grid for all monitored APIs |
| Drifts | `/dashboard/drifts` | Schema drift list with filters and JSON diff viewer |
| Tokens | `/dashboard/tokens` | API token management (create, edit, delete) |
| Schemas | `/dashboard/schemas` | Schema browser with version history |
| DTOs | `/dashboard/dtos` | Generated PHP DTOs with code viewer and export |
| Alerts | `/dashboard/alerts` | Alert channel configuration |
| Logs | `/dashboard/logs` | Request log explorer with search and export |
| Users | `/dashboard/users` | User management (admin only) |
| Settings | `/dashboard/settings` | User preferences (theme, timezone, notifications) |

### Real-Time Updates

The dashboard receives real-time updates via [Mercure](https://mercure.rocks/). Events include:

- **Drift Detected** — New schema drift with severity and path
- **Health Status Change** — Service status transitions (green/yellow/red)
- **Threshold Exceeded** — Latency or error rate threshold breaches

If Mercure is unavailable, the dashboard automatically falls back to polling.

**Connection Status Indicator:**

| Status | Meaning |
|--------|---------|
| 🟢 Connected | Real-time updates via Mercure |
| 🔵 Polling | Fallback polling mode (30s interval) |
| 🟡 Reconnecting | Attempting to restore Mercure connection |
| 🔴 Disconnected | No connection to server |

### Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Esc` | Close sidebar (mobile), close modals |
| `Tab` | Navigate focusable elements |
| `Shift+Tab` | Navigate backwards |

### Theme Support

The dashboard supports light and dark modes:

- **Light** — Default theme
- **Dark** — Reduced eye strain for low-light environments
- **System** — Follows OS preference

Toggle via the sun/moon icon in the header or Settings page.

### URL State Management

All filters, search terms, and pagination are stored in URL query parameters:

- Share exact view state via URL
- Browser back/forward navigation works as expected
- Bookmarkable filtered views

Example: `/dashboard/drifts?severity=critical&token=abc123&page=2`

## Mercure Configuration

Mercure enables real-time updates in the dashboard.

### Development (DDEV)

Mercure is pre-configured in DDEV. The hub runs automatically:

```bash
# Mercure hub URL (internal)
MERCURE_URL=http://mercure/.well-known/mercure

# Public URL for browser connections
MERCURE_PUBLIC_URL=https://sentinelphp.ddev.site/.well-known/mercure

# JWT secret (change in production!)
MERCURE_JWT_SECRET="!ChangeThisMercureHubJWTSecretKey!"
```

### Production (Optional)

Mercure is **not included** in the default production stack. The dashboard automatically falls back to polling when Mercure is unavailable.

To enable real-time updates in production, deploy a Mercure hub alongside your application:

```yaml
# docker-compose.prod.yml
services:
  mercure:
    image: dunglas/mercure:latest
    environment:
      SERVER_NAME: ':80'
      MERCURE_PUBLISHER_JWT_KEY: '${MERCURE_JWT_SECRET}'
      MERCURE_SUBSCRIBER_JWT_KEY: '${MERCURE_JWT_SECRET}'
      MERCURE_EXTRA_DIRECTIVES: |
        anonymous
        cors_origins https://your-domain.com
    ports:
      - "3000:80"
```

Update your `.env`:

```bash
MERCURE_URL=http://mercure/.well-known/mercure
MERCURE_PUBLIC_URL=https://your-domain.com/.well-known/mercure
MERCURE_JWT_SECRET="<generate-a-secure-secret>"
```

### Published Events

| Topic | Event Type | Payload |
|-------|------------|---------|
| `sentinel/drift` | `drift_detected` | Drift ID, severity, path, endpoint, token |
| `sentinel/health` | `health_status_change` | Host, old status, new status |
| `sentinel/threshold` | `threshold_exceeded` | Host, metric, value, threshold |

### Fallback Polling

If Mercure is unavailable or connection fails after 5 retries, the dashboard falls back to polling:

- Polls `/api/dashboard/events/recent` every 30 seconds
- Automatically retries Mercure connection periodically
- Manual retry available via connection status indicator

## License

MIT License — see [LICENSE](LICENSE) for details.
