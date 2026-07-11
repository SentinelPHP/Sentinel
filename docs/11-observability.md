# Observability

Sentinel supports metrics and optional tracing.

## Metrics Endpoint

```bash
curl https://sentinelphp.ddev.site:8080/metrics
```

Route: `GET /metrics`

## Health Endpoints

- `GET /health`
- `GET /status` (IP restricted)

## Local Observability Stack

```bash
make observability-up
make observability-status
make observability-logs
make observability-down
```

Services:

- Grafana: `http://localhost:3000`
- Prometheus: `http://localhost:9090`
- Tempo: `http://localhost:3200`
- Loki: `http://localhost:3100`

## Tracing Configuration

```bash
# .env
OTEL_ENABLED=true
OTEL_SERVICE_NAME=sentinel-php
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
```
