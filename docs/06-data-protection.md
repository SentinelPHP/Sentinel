# Data Protection

Sentinel supports response/request body protection via redaction and encryption.

## Strategies

- `none`
- `redact`
- `encrypt`
- `redact_encrypt`

Set globally:

```bash
# .env
SENTINEL_DATA_PROTECTION_STRATEGY=redact_encrypt
SENTINEL_ENCRYPTION_KEY=<base64-encoded-key>
```

Token-level note:

```bash
ddev exec php bin/console sentinel:token:update <token-name-or-id> --log-level=full_audit
```

`--log-level` controls what is persisted for that token. Data protection strategy is configured globally via `SENTINEL_DATA_PROTECTION_STRATEGY`.

## Generate Encryption Key

```bash
ddev exec php bin/console sentinel:encryption:generate-key
ddev exec php bin/console sentinel:encryption:generate-key --env-format
```

## Redaction Configuration

```bash
# .env
SENTINEL_REDACTION_ENABLE_DEFAULTS=true
SENTINEL_REDACTION_PATTERNS='{"order_id":{"pattern":"/\\bORD-[A-Z0-9]{8}\\b/","replacement":"[ORDER_REDACTED]"}}'
SENTINEL_REDACTION_FIELD_PATHS='["$.password","$.api_key"]'
```

## Retention

Retention purge currently targets old drift payload records.

```bash
ddev exec php bin/console sentinel:retention:purge
ddev exec php bin/console sentinel:retention:purge --days=30 --batch-size=500
ddev exec php bin/console sentinel:retention:purge --dry-run
```
