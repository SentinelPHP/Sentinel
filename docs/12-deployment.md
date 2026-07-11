# Deployment

This chapter documents production deployment commands and minimum steps.

## Production Commands

```bash
make prod-build
make prod-up
make prod-migrate
make prod-logs
make prod-down
make prod-clean
make prod-deploy
```

These targets use `.docker/docker-compose.prod.yml` with `.env` and optional `.env.local`.

## Recommended Deployment Order

1. Prepare `.env` with production values.
2. Build and start services.
3. Run database migrations.
4. Create at least one API token.
5. Validate `/health`, `/status`, and `/metrics`.

```bash
make prod-deploy
docker compose -f .docker/docker-compose.prod.yml exec app php bin/console sentinel:token:create "Production Token"
```

## Security Baseline

- Store `APP_SECRET` and `SENTINEL_ENCRYPTION_KEY` in a secrets manager.
- Restrict dashboard access by network and role.
- Use TLS termination in front of Sentinel.
- Restrict `STATUS_ALLOWED_IPS`.
