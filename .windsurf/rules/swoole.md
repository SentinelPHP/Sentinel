---
description: Swoole-Specific Guidelines
---

- Swoole coroutines are disabled during kernel boot (see `bin/swoole-server`)
- Use `App\Http\SwooleHttpClient` for async HTTP in production
- Use `App\Http\GuzzleHttpClientAdapter` for testing (non-coroutine)
- Never use blocking I/O in request handlers
- Graceful shutdown handles SIGTERM/SIGINT
