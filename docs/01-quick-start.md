# Quick Start

This chapter gets Sentinel running quickly in development and shows one end-to-end proxy request.

## Prerequisites

- DDEV
- Docker (for DDEV and production images)

## 5-Minute Local Setup (DDEV)

```bash
git clone https://github.com/SentinelPHP/Sentinel.git
cd Sentinel

ddev start
ddev exec php bin/console doctrine:migrations:migrate --no-interaction
ddev exec php bin/console sentinel:token:create "My First Token"
ddev swoole
```

The proxy is available on port 8080 in the DDEV environment.

## Send a Proxied Request

```bash
curl -X GET https://sentinelphp.ddev.site:8080/users/123 \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "X-Sentinel-Target: https://api.example.com"
```

## Verify Service Health

```bash
curl https://sentinelphp.ddev.site:8080/health
curl https://sentinelphp.ddev.site:8080/status
```

## Next Chapters

- Core model and token modes: [02-core-concepts.md](02-core-concepts.md)
- Token operations: [03-token-management.md](03-token-management.md)
- Schema lifecycle: [04-schema-learning.md](04-schema-learning.md)
- Dashboard operations: [08-dashboard-guide.md](08-dashboard-guide.md)
