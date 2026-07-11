# DTO Generation

This chapter documents DTO generation from learned master schemas.

## Workflow

1. Learn and promote schema (see [04-schema-learning.md](04-schema-learning.md)).
2. Generate DTO records in Sentinel.
3. Export DTOs to filesystem.
4. Use generated classes in consuming code.

## Generate DTOs

```bash
# Specific schema
ddev exec php bin/console sentinel:dto:generate --schema-id=<uuid>

# By token
ddev exec php bin/console sentinel:dto:generate --token="My API"

# All master schemas
ddev exec php bin/console sentinel:dto:generate --all

# Preview
ddev exec php bin/console sentinel:dto:generate --schema-id=<uuid> --dry-run
```

## Export DTOs

```bash
# Single DTO
ddev exec php bin/console sentinel:dto:export --schema-id=<uuid>

# Token bundle
ddev exec php bin/console sentinel:dto:export --token="My API" --format=bundled

# All DTOs to custom directory
ddev exec php bin/console sentinel:dto:export --all --output-dir=src/Dto/Generated

# Force overwrite
ddev exec php bin/console sentinel:dto:export --all --force
```

## Inspect DTOs

```bash
ddev exec php bin/console sentinel:dto:list --limit=100
ddev exec php bin/console sentinel:dto:show <dto-id>
ddev exec php bin/console sentinel:dto:diff <dto-id> --from=1 --to=2
```

## Dashboard Routes

- `/dashboard/dtos`
- `/dashboard/dtos/{id}`
- `/dashboard/dtos/{id}/download`
- `/dashboard/dtos/{id}/diff`
- `/dashboard/dtos/export-bulk`

## API Routes

- `GET /api/dtos`
- `GET /api/dtos/{id}`
- `GET /api/dtos/{id}/download`
- `POST /api/dtos/generate`

See [13-api-reference.md](13-api-reference.md) and [docs/api/dto-endpoints.yaml](api/dto-endpoints.yaml).

## Configuration

- `SENTINEL_DTO_NAMESPACE`
- `SENTINEL_DTO_OUTPUT_DIR`
- `SENTINEL_DTO_AUTO_GENERATE`

See [10-configuration.md](10-configuration.md).
