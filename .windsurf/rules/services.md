---
description: Service Architecture
---

- Services use interface-based injection (see `config/services.yaml`)
- Redis is used for caching tokens and status counters
- PostgreSQL stores entities (ApiToken, RequestLog)
- Async logging via Symfony Messenger (`RequestLogMessage`)
- Environment variables for all configuration (timeouts, URLs, IPs)
