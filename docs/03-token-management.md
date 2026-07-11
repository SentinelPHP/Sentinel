# Token Management

Tokens control authentication, target restrictions, logging, and schema behavior.

## Create Token

```bash
# Basic token
ddev exec php bin/console sentinel:token:create "My Token"

# Restricted targets + learning mode
ddev exec php bin/console sentinel:token:create "Stripe Token" \
  --targets=api.stripe.com \
  --targets=files.stripe.com \
  --mode=learning \
  --learning-threshold=10 \
  --auto-switch

# Custom log level
ddev exec php bin/console sentinel:token:create "Debug Token" --log-level=full_audit
```

Supported create options:

- `--targets` / `-t` (repeatable)
- `--log-level` / `-l`
- `--mode` / `-m`
- `--learning-threshold`
- `--auto-switch`

## Update Token

```bash
# Update by name or UUID
ddev exec php bin/console sentinel:token:update "My Token" --mode=validating --active=true

# Replace allowed targets
ddev exec php bin/console sentinel:token:update "My Token" \
  --targets=api.example.com \
  --targets=*.example.net
```

Supported update options:

- `--name`
- `--mode` / `-m`
- `--log-level` / `-l`
- `--learning-threshold`
- `--auto-switch=true|false`
- `--active=true|false`
- `--targets` / `-t` (replace list)

## Security Notes

- Tokens are hashed in storage.
- Plain tokens are shown once at creation time.
- Restrict targets whenever possible.

## Related Chapters

- Core behavior: [02-core-concepts.md](02-core-concepts.md)
- Schema lifecycle: [04-schema-learning.md](04-schema-learning.md)
- Data protection: [06-data-protection.md](06-data-protection.md)
