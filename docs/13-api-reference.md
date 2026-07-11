# API Reference

Sentinel exposes proxy, system, and programmatic API endpoints.

## System Endpoints

- `GET /health`
- `GET /status`
- `GET /metrics`

## Proxy Endpoint

- `GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD /{path}`
- Requires `Authorization: Bearer <token>` and `X-Sentinel-Target` headers.

## Ingest API

Base route: `/api/ingest`

- `POST /api/ingest`

Response behavior from controller:

- `202` with `{ "success": true }` on accepted ingest
- `400` on malformed or missing payload
- `401` on authentication failure
- `429` when rate limiting is enabled and exceeded

OpenAPI spec: [docs/api/ingest-endpoint.yaml](api/ingest-endpoint.yaml)

## DTO API

Base route: `/api/dtos`

- `GET /api/dtos`
- `GET /api/dtos/{id}`
- `GET /api/dtos/{id}/download`
- `POST /api/dtos/generate`

Notes:

- `GET /api/dtos/{id}` supports `format=json|php|base64` and optional `version`.
- `POST /api/dtos/generate` returns `202` when job is queued.

OpenAPI spec: [docs/api/dto-endpoints.yaml](api/dto-endpoints.yaml)
