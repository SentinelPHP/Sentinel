# Dashboard Guide

This chapter covers the web dashboard routes and common operations.

## Authentication

- Login route: `/login`
- Logout route: `/logout`
- Access requires dashboard user account.

Create users from CLI:

```bash
ddev exec php bin/console sentinel:user:create user@example.com
ddev exec php bin/console sentinel:user:create admin@example.com --admin
```

## Primary Pages

- `/dashboard`
- `/dashboard/services`
- `/dashboard/drifts`
- `/dashboard/tokens`
- `/dashboard/schemas`
- `/dashboard/dtos`
- `/dashboard/alerts`
- `/dashboard/logs`
- `/dashboard/users`
- `/dashboard/settings`

## Service Health

Routes:

- `/dashboard/services`
- `/dashboard/services/{host}`
- `/dashboard/services/{host}/latency`

## Drift Inspector

Routes:

- `/dashboard/drifts`
- `/dashboard/drifts/{id}`
- `POST /dashboard/drifts/{id}/accept`

## Token Management

Routes:

- `/dashboard/tokens`
- `/dashboard/tokens/new`
- `/dashboard/tokens/{id}`
- `POST /dashboard/tokens/{id}/toggle`
- `POST /dashboard/tokens/{id}/delete`
- `/dashboard/tokens/{id}/activity`

## Schema Management

Routes:

- `/dashboard/schemas`
- `/dashboard/schemas/{id}`
- `/dashboard/schemas/{id}/versions`
- `POST /dashboard/schemas/{id}/promote`
- `/dashboard/schemas/{id}/export`
- `POST /dashboard/schemas/{id}/generate-dto`
- `/dashboard/schemas/{id}/preview-dto`
- `/dashboard/schemas/import`

## DTO Management

Routes:

- `/dashboard/dtos`
- `/dashboard/dtos/{id}`
- `/dashboard/dtos/{id}/download`
- `/dashboard/dtos/{id}/diff`
- `POST /dashboard/dtos/{id}/regenerate`
- `POST /dashboard/dtos/export-bulk`

## Alerts

Routes:

- `/dashboard/alerts`
- `/dashboard/alerts/new`
- `/dashboard/alerts/{id}/edit`
- `POST /dashboard/alerts/{id}/toggle`
- `POST /dashboard/alerts/{id}/delete`
- `POST /dashboard/alerts/{id}/mute`
- `POST /dashboard/alerts/{id}/unmute`
- `POST /dashboard/alerts/{id}/test`
- `/dashboard/alerts/history`

## Request Logs

Routes:

- `/dashboard/logs`
- `/dashboard/logs/{id}`
- `POST /dashboard/logs/{id}/decrypt`
- `/dashboard/logs/export.csv`
- `/dashboard/logs/export.json`

## User Management

Routes:

- `/dashboard/users`
- `/dashboard/users/{id}`
- `/dashboard/users/{id}/permissions`

## Settings

Route:

- `/dashboard/settings`
