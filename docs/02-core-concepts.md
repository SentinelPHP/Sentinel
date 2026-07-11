# Core Concepts

Sentinel is an async proxy and API observability layer.

## Request Flow

1. Client sends a request to Sentinel with a Bearer token.
2. Client sets `X-Sentinel-Target` to the upstream API host.
3. Sentinel authenticates token and applies token policy.
4. Sentinel forwards request and records telemetry/log data per log level.
5. Sentinel optionally learns or validates schema based on token mode.

## Token Modes

- `passive`: Forward traffic without schema learning or validation.
- `learning`: Build and merge schemas from observed traffic.
- `validating`: Validate responses against master schema and emit drift events.

## Log Levels

- `none`: no logging
- `metadata_only`: method/path/status/latency
- `drift_only`: payload persistence focused on drift context
- `headers`: metadata plus headers
- `full_audit`: request and response bodies and headers

## Primary Endpoints

- `GET /health`
- `GET /status` (IP restricted)
- `GET /metrics`
- `/{path}` catch-all proxy endpoint

## Related Chapters

- Complete endpoint catalog: [13-api-reference.md](13-api-reference.md)
- Schema lifecycle: [04-schema-learning.md](04-schema-learning.md)
- Drift lifecycle: [05-drift-and-alerts.md](05-drift-and-alerts.md)
- Configuration details: [10-configuration.md](10-configuration.md)
