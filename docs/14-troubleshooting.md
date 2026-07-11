# Troubleshooting

## Token Authentication Fails

Checklist:

1. Confirm `Authorization: Bearer <token>` header format.
2. Verify token is active.
3. Verify target host matches token restrictions.

Useful command:

```bash
ddev exec php bin/console sentinel:token:update <identifier> --active=true
```

## Schema Not Promoting

Checklist:

1. Confirm token mode is `learning`.
2. Confirm enough samples have been captured.
3. Promote manually if needed.

```bash
ddev exec php bin/console sentinel:schema:promote <schema-id> --switch-mode
```

## DTO Not Appearing

Checklist:

1. Confirm schema exists and is master.
2. Generate DTO and check command output.
3. Verify DTO filters in dashboard or API request.

```bash
ddev exec php bin/console sentinel:dto:generate --schema-id=<uuid>
ddev exec php bin/console sentinel:dto:list --limit=100
```

## Metrics Not Available

Checklist:

1. Confirm service is running.
2. Confirm `METRICS_ENABLED=true`.
3. Check port and reverse-proxy routing.

## Verification Commands

Run before concluding any documentation-driven change set:

```bash
ddev exec bin/phpunit
ddev exec vendor/bin/phpstan analyse
```
