# Drift Detection And Alerts

Sentinel detects schema drift when tokens run in validating mode.

## List Drift Events

```bash
ddev exec php bin/console sentinel:drift:list
ddev exec php bin/console sentinel:drift:list --severity=critical
ddev exec php bin/console sentinel:drift:list --token="My API" --drift-type=type_changed
ddev exec php bin/console sentinel:drift:list --from="2026-07-01" --to="2026-07-11"
```

Supported filters:

- `--token` / `-t`
- `--severity` / `-s` (`info|warning|critical`)
- `--drift-type` (`field_added|field_removed|type_changed|structure_changed`)
- `--from`
- `--to`
- `--limit` / `-l`

## Alert Channels

Alert configuration is managed from the dashboard at `/dashboard/alerts`.

Environment-level channel settings:

- `SLACK_ALERT_WEBHOOK_URL`
- `SLACK_ALERT_RATE_LIMIT`
- `WEBHOOK_ALERT_URL`
- `WEBHOOK_ALERT_MAX_RETRIES`
- `WEBHOOK_ALERT_BASE_DELAY_MS`

## Operations In Dashboard

Available routes include:

- `/dashboard/alerts`
- `/dashboard/alerts/new`
- `/dashboard/alerts/{id}/edit`
- `/dashboard/alerts/{id}/test`
- `/dashboard/alerts/history`

See [08-dashboard-guide.md](08-dashboard-guide.md) for full workflow.
