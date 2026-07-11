# SentinelPHP

SentinelPHP is an async API proxy and observability layer built on Symfony and Swoole.

This README is intentionally short. Full documentation is organized into chapter files under docs.

## Quick Start

```bash
git clone https://github.com/SentinelPHP/Sentinel.git
cd Sentinel

ddev start
ddev exec php bin/console doctrine:migrations:migrate --no-interaction
ddev exec php bin/console sentinel:token:create "My First Token"
ddev swoole
```

Send traffic through Sentinel:

```bash
curl -X GET https://sentinelphp.ddev.site:8080/users/123 \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "X-Sentinel-Target: https://api.example.com"
```

## Documentation Index

- [01 Quick Start](docs/01-quick-start.md)
- [02 Core Concepts](docs/02-core-concepts.md)
- [03 Token Management](docs/03-token-management.md)
- [04 Schema Learning](docs/04-schema-learning.md)
- [05 Drift And Alerts](docs/05-drift-and-alerts.md)
- [06 Data Protection](docs/06-data-protection.md)
- [07 DTO Generation](docs/07-dto-generation.md)
- [08 Dashboard Guide](docs/08-dashboard-guide.md)
- [09 CLI Reference](docs/09-cli-reference.md)
- [10 Configuration](docs/10-configuration.md)
- [11 Observability](docs/11-observability.md)
- [12 Deployment](docs/12-deployment.md)
- [13 API Reference](docs/13-api-reference.md)
- [14 Troubleshooting](docs/14-troubleshooting.md)

API specs:

- [DTO Endpoints OpenAPI](docs/api/dto-endpoints.yaml)
- [Ingest Endpoint OpenAPI](docs/api/ingest-endpoint.yaml)

Package-level docs:

- [Core package](packages/core/README.md)
- [DTO package](packages/dto/README.md)
- [Schema package](packages/schema/README.md)
- [Drift package](packages/drift/README.md)
- [Encrypt package](packages/encrypt/README.md)
- [Redact package](packages/redact/README.md)

## Verification

```bash
ddev exec bin/phpunit
ddev exec vendor/bin/phpstan analyse
```
