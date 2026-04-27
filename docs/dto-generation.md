# DTO Generation Guide

This guide covers the automated PHP DTO (Data Transfer Object) generation feature in SentinelPHP. DTOs provide type-safe representations of API responses, enabling better IDE support, static analysis, and runtime type checking.

## Table of Contents

- [Overview](#overview)
- [Quick Start](#quick-start)
- [Generation Workflow](#generation-workflow)
- [CLI Commands](#cli-commands)
- [Configuration](#configuration)
- [Customization](#customization)
- [Type Mapping](#type-mapping)
- [Nested Objects & Enums](#nested-objects--enums)
- [Serialization Methods](#serialization-methods)
- [Integration Guide](#integration-guide)
- [Dashboard UI](#dashboard-ui)
- [API Endpoints](#api-endpoints)
- [Troubleshooting](#troubleshooting)

---

## Overview

SentinelPHP's DTO generation transforms learned JSON Schemas into PHP classes with:

- **Native PHP Types** — Full type declarations for properties and methods
- **Readonly Properties** — Immutable DTOs by default (PHP 8.1+)
- **Constructor Promotion** — Clean, concise class definitions
- **Backed Enums** — PHP 8.1+ enums for schema `enum` constraints
- **Nested Objects** — Separate classes for complex structures
- **Serialization** — Built-in `fromArray()`, `toArray()`, and `JsonSerializable`
- **Version History** — Track changes across schema updates

---

## Quick Start

### 1. Generate a DTO from an existing schema

```bash
# Find your schema ID
ddev exec php bin/console sentinel:schema:list

# Generate the DTO
ddev exec php bin/console sentinel:dto:generate --schema-id=<uuid>
```

### 2. Export to filesystem

```bash
ddev exec php bin/console sentinel:dto:export --schema-id=<uuid>
```

### 3. Use in your code

```php
use App\Dto\Generated\GetUsersResponse;

$response = $httpClient->request('GET', '/api/users/123');
$user = GetUsersResponse::fromArray($response->toArray());

echo $user->email;  // Type-safe access
```

---

## Generation Workflow

### End-to-End Flow

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  API Traffic    │────▶│  Learn Schema   │────▶│ Promote Master  │
│  (Learning)     │     │  (Auto-merge)   │     │  (Stable)       │
└─────────────────┘     └─────────────────┘     └────────┬────────┘
                                                         │
                                                         ▼
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  Use in Code    │◀────│  Export Files   │◀────│  Generate DTO   │
│  (Type-safe)    │     │  (Filesystem)   │     │  (PHP Class)    │
└─────────────────┘     └─────────────────┘     └─────────────────┘
```

### Step-by-Step

1. **Create Token in Learning Mode**
   ```bash
   ddev exec php bin/console sentinel:token:create "My API" \
     --mode=learning \
     --targets=api.example.com \
     --auto-switch
   ```

2. **Send Traffic Through Proxy**
   ```bash
   curl -X GET https://sentinelphp.ddev.site:8080/users/123 \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "X-Sentinel-Target: https://api.example.com"
   ```

3. **Schema is Learned and Promoted**
   - After reaching the learning threshold, schema is promoted to master
   - If `--auto-switch` was used, token switches to validating mode

4. **Generate DTO**
   ```bash
   # Manual generation
   ddev exec php bin/console sentinel:dto:generate --schema-id=<uuid>
   
   # Or enable auto-generation
   # .env: SENTINEL_DTO_AUTO_GENERATE=true
   ```

5. **Export to Filesystem**
   ```bash
   ddev exec php bin/console sentinel:dto:export --schema-id=<uuid>
   ```

6. **Update Autoloader**
   ```bash
   composer dump-autoload
   ```

---

## CLI Commands

### sentinel:dto:generate

Generate DTOs from API schemas.

```bash
# Generate for specific schema
ddev exec php bin/console sentinel:dto:generate --schema-id=<uuid>

# Generate for all schemas of a token
ddev exec php bin/console sentinel:dto:generate --token="My API"

# Generate for all master schemas
ddev exec php bin/console sentinel:dto:generate --all

# Preview without saving (dry run)
ddev exec php bin/console sentinel:dto:generate --schema-id=<uuid> --dry-run
```

**Options:**

| Option | Short | Description |
|--------|-------|-------------|
| `--schema-id` | `-s` | Generate for specific schema UUID |
| `--token` | `-t` | Filter by token name or UUID |
| `--endpoint` | `-p` | Filter by endpoint path |
| `--all` | `-a` | Generate for all master schemas |
| `--dry-run` | | Preview without saving to database |

### sentinel:dto:export

Export generated DTOs to the filesystem.

```bash
# Export single DTO
ddev exec php bin/console sentinel:dto:export --schema-id=<uuid>

# Export all DTOs for a token
ddev exec php bin/console sentinel:dto:export --token="My API"

# Export as bundled file (all classes in one file)
ddev exec php bin/console sentinel:dto:export --token="My API" --format=bundled

# Export to custom directory
ddev exec php bin/console sentinel:dto:export --all --output-dir=/path/to/dir

# Force overwrite existing files
ddev exec php bin/console sentinel:dto:export --all --force

# Preview without writing
ddev exec php bin/console sentinel:dto:export --all --dry-run
```

**Options:**

| Option | Short | Description |
|--------|-------|-------------|
| `--schema-id` | `-s` | Export DTO for specific schema |
| `--token` | `-t` | Export all DTOs for a token |
| `--all` | `-a` | Export all current DTOs |
| `--format` | `-f` | `single-file` (default) or `bundled` |
| `--force` | | Overwrite existing files |
| `--output-dir` | `-o` | Override default output directory |
| `--dry-run` | | Preview without writing files |

### sentinel:dto:list

List all generated DTOs with optional filters.

```bash
# List all DTOs
ddev exec php bin/console sentinel:dto:list

# Filter by token
ddev exec php bin/console sentinel:dto:list --token="My API"

# Filter by endpoint path (partial match)
ddev exec php bin/console sentinel:dto:list --endpoint=/users

# Filter by class name (partial match)
ddev exec php bin/console sentinel:dto:list --class=User

# Limit results
ddev exec php bin/console sentinel:dto:list --limit=100
```

**Output columns:**

| Column | Description |
|--------|-------------|
| ID | DTO UUID (truncated) |
| Class | Generated class name |
| Namespace | PHP namespace |
| Endpoint | HTTP method and path |
| Ver | Current version number |
| Versions | Total version count |
| Current | ✓ if this is the current version |
| Created | Generation timestamp |

### sentinel:dto:show

Display the generated PHP code for a DTO.

```bash
# Show with metadata
ddev exec php bin/console sentinel:dto:show <dto-uuid>

# Show specific version
ddev exec php bin/console sentinel:dto:show <dto-uuid> --dto-version=2

# Raw PHP code only (for piping)
ddev exec php bin/console sentinel:dto:show <dto-uuid> --raw

# Pipe to file
ddev exec php bin/console sentinel:dto:show <dto-uuid> --raw > MyDto.php
```

### sentinel:dto:diff

Compare DTO versions to see what changed.

```bash
# Compare current with previous version
ddev exec php bin/console sentinel:dto:diff <dto-uuid>

# Compare with specific version
ddev exec php bin/console sentinel:dto:diff <dto-uuid> --dto-version=2

# Compare two specific versions
ddev exec php bin/console sentinel:dto:diff <dto-uuid> --from=1 --to=3

# Raw diff output
ddev exec php bin/console sentinel:dto:diff <dto-uuid> --raw
```

---

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `SENTINEL_DTO_NAMESPACE` | `App\Dto\Generated` | Default namespace for DTOs |
| `SENTINEL_DTO_OUTPUT_DIR` | `src/Dto/Generated` | Output directory for exports |
| `SENTINEL_DTO_AUTO_GENERATE` | `false` | Auto-generate on schema promotion |

### Configuration File

Full configuration in `config/packages/sentinel_dto.yaml`:

```yaml
parameters:
    # Namespace and output
    sentinel_dto.default_namespace: '%env(SENTINEL_DTO_NAMESPACE)%'
    sentinel_dto.output_directory: '%env(SENTINEL_DTO_OUTPUT_DIR)%'
    
    # PHP version target
    sentinel_dto.php_version: '8.2'  # or '8.3'
    
    # Property generation
    sentinel_dto.readonly_properties: true
    sentinel_dto.generate_getters: false
    
    # Serialization
    sentinel_dto.generate_serialization: true      # fromArray/toArray
    sentinel_dto.generate_json_serializable: true  # JsonSerializable
    
    # Symfony integration (optional)
    sentinel_dto.generate_serializer_attributes: false  # #[Groups], #[SerializedName]
    sentinel_dto.generate_validation: false             # Validator attributes
    
    # Auto-generation
    sentinel_dto.auto_generate: '%env(SENTINEL_DTO_AUTO_GENERATE)%'
    
    # Template customization
    sentinel_dto.template.base_class: ~        # e.g., 'App\Dto\BaseDto'
    sentinel_dto.template.interfaces: []       # Additional interfaces
    sentinel_dto.template.traits: []           # Traits to use
    
    # Property transformations
    sentinel_dto.property_mappings: {}         # JSON key → PHP property
    sentinel_dto.excluded_properties: []       # JSON paths to exclude
```

---

## Customization

### Naming Strategy

The default naming strategy converts endpoint paths to PascalCase class names:

| Endpoint | HTTP Method | Generated Class |
|----------|-------------|-----------------|
| `/users` | GET | `GetUsersResponse` |
| `/users/{id}` | GET | `GetUsersIdResponse` |
| `/users` | POST | `PostUsersResponse` |
| `/orders/{id}/items` | GET | `GetOrdersIdItemsResponse` |

**Custom naming via per-token configuration:**

Configure in the dashboard under Token Settings → DTO Configuration.

### Property Transformations

**Global property mappings:**

```yaml
# config/packages/sentinel_dto.yaml
parameters:
    sentinel_dto.property_mappings:
        user_id: userId
        created_at: createdAt
        updated_at: updatedAt
```

**Exclude properties:**

```yaml
parameters:
    sentinel_dto.excluded_properties:
        - '$.internal_id'
        - '$.debug_info'
        - '$.metadata._internal'
```

### Template Customization

**Base class:**

```yaml
parameters:
    sentinel_dto.template.base_class: 'App\Dto\BaseDto'
```

Generated DTOs will extend this class:

```php
final readonly class GetUsersResponse extends BaseDto
{
    // ...
}
```

**Interfaces:**

```yaml
parameters:
    sentinel_dto.template.interfaces:
        - 'App\Contract\DtoInterface'
        - 'App\Contract\Auditable'
```

**Traits:**

```yaml
parameters:
    sentinel_dto.template.traits:
        - 'App\Dto\Traits\TimestampTrait'
```

### Per-Token Configuration

Override settings per token via the dashboard or API:

- Custom namespace (e.g., `App\Dto\Stripe`)
- Custom naming strategy
- Property mappings specific to that API
- Enable/disable auto-generation

---

## Type Mapping

### JSON Schema to PHP Types

| JSON Schema Type | Format | PHP Type |
|------------------|--------|----------|
| `string` | — | `string` |
| `string` | `date-time` | `\DateTimeImmutable` |
| `string` | `date` | `\DateTimeImmutable` |
| `string` | `uuid` | `string` |
| `string` | `email` | `string` |
| `string` | `uri` | `string` |
| `integer` | — | `int` |
| `number` | — | `float` |
| `boolean` | — | `bool` |
| `null` | — | `null` |
| `array` | — | `array` |
| `object` | — | Nested DTO class |

### Union Types

JSON Schema `oneOf`/`anyOf` generates PHP union types:

```json
{
  "oneOf": [
    { "type": "string" },
    { "type": "integer" }
  ]
}
```

```php
public string|int $value;
```

### Nullable Types

Properties not in the `required` array are nullable:

```json
{
  "properties": {
    "name": { "type": "string" },
    "nickname": { "type": "string" }
  },
  "required": ["name"]
}
```

```php
public string $name;
public ?string $nickname = null;
```

---

## Nested Objects & Enums

### Nested Objects

Nested objects generate separate DTO classes:

```json
{
  "properties": {
    "user": {
      "type": "object",
      "properties": {
        "id": { "type": "integer" },
        "profile": {
          "type": "object",
          "properties": {
            "bio": { "type": "string" }
          }
        }
      }
    }
  }
}
```

Generates:
- `GetUsersResponse` (main class)
- `GetUsersResponseUser` (nested)
- `GetUsersResponseUserProfile` (deeply nested)

### Backed Enums

Schema `enum` constraints generate PHP backed enums:

```json
{
  "properties": {
    "status": {
      "type": "string",
      "enum": ["pending", "active", "suspended"]
    }
  }
}
```

```php
enum GetUsersResponseStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
}
```

Integer enums are also supported:

```json
{
  "properties": {
    "priority": {
      "type": "integer",
      "enum": [1, 2, 3]
    }
  }
}
```

```php
enum GetUsersResponsePriority: int
{
    case Priority1 = 1;
    case Priority2 = 2;
    case Priority3 = 3;
}
```

---

## Serialization Methods

### fromArray()

Create a DTO instance from an associative array:

```php
$data = [
    'id' => '123e4567-e89b-12d3-a456-426614174000',
    'email' => 'user@example.com',
    'created_at' => '2024-01-15T10:30:00+00:00',
    'profile' => [
        'name' => 'John Doe',
        'age' => 30
    ]
];

$user = GetUsersResponse::fromArray($data);
```

Features:
- Automatic type coercion for primitives
- DateTime parsing for date/time formats
- Recursive instantiation of nested DTOs
- Enum resolution via `::from()`

### toArray()

Convert a DTO back to an associative array:

```php
$array = $user->toArray();
// [
//     'id' => '123e4567-e89b-12d3-a456-426614174000',
//     'email' => 'user@example.com',
//     'created_at' => '2024-01-15T10:30:00+00:00',
//     'profile' => ['name' => 'John Doe', 'age' => 30]
// ]
```

Features:
- DateTime formatting to RFC3339
- Recursive serialization of nested DTOs
- Enum values extracted via `->value`
- Null values included

### JsonSerializable

DTOs implement `JsonSerializable` for direct JSON encoding:

```php
$json = json_encode($user);
// {"id":"123e4567...","email":"user@example.com",...}
```

---

## Integration Guide

### Autoloading

Add the generated DTO directory to your `composer.json`:

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "src/",
            "App\\Dto\\Generated\\": "src/Dto/Generated/"
        }
    }
}
```

Then run:

```bash
composer dump-autoload
```

### Using with HTTP Clients

**Symfony HttpClient:**

```php
use App\Dto\Generated\GetUsersResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class UserApiClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {}

    public function getUser(string $id): GetUsersResponse
    {
        $response = $this->httpClient->request('GET', "/users/{$id}");
        return GetUsersResponse::fromArray($response->toArray());
    }
}
```

**Guzzle:**

```php
use App\Dto\Generated\GetUsersResponse;
use GuzzleHttp\Client;

$client = new Client(['base_uri' => 'https://api.example.com']);
$response = $client->get('/users/123');
$data = json_decode($response->getBody()->getContents(), true);

$user = GetUsersResponse::fromArray($data);
```

### Handling DTO Updates

When schemas change and DTOs are regenerated:

1. **Version History** — Previous versions are preserved in the database
2. **Checksum Detection** — Unchanged DTOs are not re-stored
3. **Backup on Export** — Existing files are backed up before overwrite

**Recommended workflow:**

```bash
# 1. Generate new version
ddev exec php bin/console sentinel:dto:generate --schema-id=<uuid>

# 2. Review changes
ddev exec php bin/console sentinel:dto:diff <dto-uuid>

# 3. Export with backup
ddev exec php bin/console sentinel:dto:export --schema-id=<uuid> --force

# 4. Update autoloader
composer dump-autoload

# 5. Run tests to catch breaking changes
./vendor/bin/phpunit
```

### IDE Support

Generated DTOs provide full IDE support:

- **Autocompletion** — Property and method suggestions
- **Type Checking** — Static analysis compatibility
- **Refactoring** — Safe renames and moves
- **Documentation** — PHPDoc comments from schema descriptions

---

## Dashboard UI

### DTO List View

Access at `/dashboard/dtos`:

- Browse all generated DTOs
- Filter by token, endpoint, class name
- Sort by creation date, version
- Quick actions: view, download, regenerate

### DTO Detail View

Click on a DTO to see:

- Full PHP code with syntax highlighting
- Metadata (namespace, version, checksum)
- Version history dropdown
- Download and copy buttons

### Version Diff Viewer

Compare versions side-by-side:

- Additions highlighted in green
- Removals highlighted in red
- Changes highlighted in yellow

### Bulk Export

Select multiple DTOs and download as ZIP:

1. Check DTOs in the list view
2. Click "Export Selected"
3. Download ZIP archive

---

## API Endpoints

### List DTOs

```http
GET /api/dtos
Authorization: Bearer <token>
```

**Response:**

```json
{
    "data": [
        {
            "id": "123e4567-e89b-12d3-a456-426614174000",
            "class_name": "GetUsersResponse",
            "namespace": "App\\Dto\\Generated",
            "version": 3,
            "is_current": true,
            "created_at": "2024-01-15T10:30:00+00:00",
            "schema_id": "987fcdeb-51a2-3bc4-d567-890123456789"
        }
    ],
    "meta": {
        "total": 15,
        "page": 1,
        "per_page": 20
    }
}
```

### Get DTO

```http
GET /api/dtos/{id}
Authorization: Bearer <token>
```

### Download DTO

```http
GET /api/dtos/{id}/download
Authorization: Bearer <token>
```

Returns raw PHP file with `Content-Type: text/x-php`.

### Trigger Generation

```http
POST /api/dtos/generate
Authorization: Bearer <token>
Content-Type: application/json

{
    "schema_id": "987fcdeb-51a2-3bc4-d567-890123456789"
}
```

---

## Troubleshooting

### DTO Not Generated

**Symptom:** `sentinel:dto:generate` reports "No master schemas found"

**Causes:**
- Schema not promoted to master
- Token still in learning mode

**Solution:**
```bash
# Check schema status
ddev exec php bin/console sentinel:schema:list --token="My API"

# Promote if needed
ddev exec php bin/console sentinel:schema:promote <schema-id>
```

### Export Permission Denied

**Symptom:** `sentinel:dto:export` fails with permission error

**Solution:**
```bash
# Ensure output directory exists and is writable
mkdir -p src/Dto/Generated
chmod 755 src/Dto/Generated
```

### Class Name Conflicts

**Symptom:** Multiple endpoints generate same class name

**Solution:**
- Use per-token namespace configuration
- Configure custom naming via dashboard

### Circular Reference Detected

**Symptom:** Warning about circular references in nested objects

**Explanation:** The generator detects and breaks circular references automatically. The affected property will use a nullable type with a note in the docblock.

### Autoloader Not Finding Classes

**Symptom:** `Class not found` errors after export

**Solution:**
```bash
# Regenerate autoloader
composer dump-autoload

# Verify PSR-4 mapping in composer.json
```

---

## See Also

- [README.md](../README.md) — Main documentation
- [Schema Learning Workflow](../README.md#schema-learning-workflow) — How schemas are learned
- [Dashboard Guide](dashboard-guide.md) — Web UI documentation
