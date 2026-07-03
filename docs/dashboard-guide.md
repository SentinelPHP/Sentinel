# SentinelPHP Dashboard User Guide

This guide covers all features of the SentinelPHP web dashboard for monitoring and managing your API proxy.

## Table of Contents

- [Getting Started](#getting-started)
- [Dashboard Overview](#dashboard-overview)
- [Service Health](#service-health)
- [Drift Inspector](#drift-inspector)
- [Token Management](#token-management)
- [Schema Management](#schema-management)
- [DTO Management](#dto-management)
- [Alert Configuration](#alert-configuration)
- [Request Logs](#request-logs)
- [User Management](#user-management)
- [Settings](#settings)
- [Keyboard Shortcuts](#keyboard-shortcuts)
- [Accessibility](#accessibility)

---

## Getting Started

### Logging In

1. Navigate to `/login`
2. Enter your email and password
3. Optionally check "Remember me" to stay logged in for 1 week
4. Click "Sign In"

After successful login, you'll be redirected to the dashboard overview.

### First-Time Setup

If you're an administrator setting up SentinelPHP for the first time:

```bash
# Create an admin user
ddev exec php bin/console sentinel:user:create admin@example.com --admin

# Create API tokens for your services
ddev exec php bin/console sentinel:token:create "Production API" --targets=api.example.com
```

### Understanding Roles

| Role | Access Level |
|------|--------------|
| **User** | View-only access to assigned tokens and their associated schemas, drifts, and logs |
| **Admin** | Full access to all tokens, schemas, users, settings, and alert configuration |

---

## Dashboard Overview

**Route:** `/dashboard`

The overview page provides a high-level summary of your API monitoring status.

### Summary Cards

- **Active Tokens** — Number of active API tokens (click to view all)
- **Requests (24h)** — Total requests processed in the last 24 hours
- **Drifts (24h)** — Schema drifts detected, broken down by severity
- **System Health** — Overall health status indicator

### Latency Sparklines

Real-time sparkline charts show request latency trends. Hover over data points to see exact values.

### Quick Actions

- **Create Token** — Jump directly to token creation form
- **View Recent Drifts** — See the latest schema drift events
- **Service Health** — Quick link to the traffic light view

### Real-Time Updates

The dashboard receives live updates via Mercure:

- New drift events appear automatically
- Health status changes are reflected immediately
- Toast notifications alert you to important events

---

## Service Health

**Route:** `/dashboard/services`

The Service Health page displays a "traffic light" grid showing the health status of all monitored API endpoints.

### Health Status Colors

| Color | Status | Criteria |
|-------|--------|----------|
| 🟢 **Green** | Healthy | <1% error rate, <500ms avg latency, no critical drifts |
| 🟡 **Yellow** | Degraded | 1-5% error rate, 500-1000ms latency, or warning drifts |
| 🔴 **Red** | Unhealthy | >5% error rate, >1000ms latency, or critical drifts |

### Service Detail View

Click on any service card to view detailed information:

- **Current Metrics** — Error rate, latency percentiles (P50, P95, P99)
- **Health History** — 24-hour timeline of status changes
- **Recent Drifts** — Schema drifts associated with this service
- **Request Volume** — Requests per minute chart

### Latency Analysis

**Route:** `/dashboard/services/{host}/latency`

Compare current latency against historical baselines:

- **Baseline Options:** 24 hours, 7 days, 30 days
- **Percentile Breakdown:** P50, P95, P99 values
- **Trend Indicator:** Improving, stable, or degrading
- **Time Series Chart:** 6-hour latency visualization

---

## Drift Inspector

**Route:** `/dashboard/drifts`

The Drift Inspector helps you identify and manage schema changes in your API responses.

### Drift List

The main view shows all detected drifts with:

- **Severity Badge** — Critical (red), Warning (yellow), Info (blue)
- **Drift Type** — Field added, removed, type changed, structure changed
- **Endpoint** — HTTP method and path
- **Token** — Associated API token
- **Detected At** — Timestamp of detection

### Filtering Drifts

Use the filter panel to narrow results:

| Filter | Options |
|--------|---------|
| **Severity** | Critical, Warning, Info |
| **Drift Type** | Field Added, Field Removed, Type Changed, Structure Changed |
| **Token** | Select from your accessible tokens |
| **Date Range** | From/To date pickers |

All filters are reflected in the URL for easy sharing.

### Drift Detail View

**Route:** `/dashboard/drifts/{id}`

Click on any drift to see the full details:

#### Side-by-Side JSON Diff

The diff viewer shows expected vs actual values with syntax highlighting:

- **Green** — Added fields/values
- **Red** — Removed fields/values
- **Yellow** — Changed values

#### Schema Path Navigation

Click on any path in the diff to copy it to clipboard. Useful for debugging or creating custom redaction rules.

#### Drift Timeline

See the chronological history of drifts for the same endpoint.

### Accepting Drifts

If a drift represents an intentional API change, you can accept it to update the master schema:

1. Open the drift detail view
2. Review the changes carefully
3. Click "Accept Drift"
4. Confirm the action

**Note:** Accepting a drift updates the master schema to include the new structure. This action cannot be undone.

---

## Token Management

**Route:** `/dashboard/tokens`

Manage your API tokens for proxy authentication.

### Token List

View all tokens with:

- **Name** — Descriptive token name
- **Mode** — Passive, Learning, or Validating
- **Status** — Active or Inactive
- **Last Used** — Most recent request timestamp
- **Request Count** — Total requests processed

### Creating a Token

**Route:** `/dashboard/tokens/new`

1. Click "Create Token"
2. Fill in the form:
   - **Name** — Descriptive name (e.g., "Stripe Production")
   - **Allowed Targets** — Comma-separated list of allowed hosts
   - **Mode** — Select operation mode
   - **Data Protection** — Choose protection strategy
   - **Custom Redaction** — Optional JSON patterns
3. Click "Create"
4. **Important:** Copy the displayed token immediately. It cannot be retrieved later.

### Token Modes

| Mode | Behavior |
|------|----------|
| **Passive** | No schema operations (default) |
| **Learning** | Generate schemas from responses |
| **Validating** | Validate responses against master schema |

### Token Detail View

**Route:** `/dashboard/tokens/{id}`

View and edit token configuration:

- **Usage Statistics** — Requests, drifts, last activity
- **Activity Log** — Recent requests through this token
- **Configuration** — Edit name, targets, mode, protection settings

### Deleting a Token

1. Open the token detail view
2. Click "Delete Token"
3. Confirm the deletion

**Warning:** Deleting a token is permanent. All associated schemas and drifts will be orphaned.

---

## Schema Management

**Route:** `/dashboard/schemas`

Browse and manage learned API schemas.

### Schema List

View all schemas with:

- **Endpoint** — HTTP method and path
- **Target Host** — API host
- **Type** — Request or Response
- **Version** — Current version number
- **Master** — Whether this is the master schema
- **Last Updated** — Most recent modification

### Schema Detail View

**Route:** `/dashboard/schemas/{id}`

Explore a schema in detail:

#### JSON Schema Display

Syntax-highlighted JSON Schema with collapsible sections.

#### Version History

**Route:** `/dashboard/schemas/{id}/versions`

Compare different versions of the schema:

1. Select two versions from the dropdown
2. View side-by-side diff
3. See what changed between versions

#### Promote to Master

If a schema is not yet the master:

1. Click "Promote to Master"
2. Confirm the action
3. The token will use this schema for validation

### Schema Tree Explorer

Visual representation of the schema structure:

- Click nodes to expand/collapse
- View type information for each field
- Navigate complex nested structures easily

### Import/Export

#### Importing a Schema

1. Click "Import Schema"
2. Upload a JSON Schema file
3. Select the target token and endpoint
4. Click "Import"

#### Exporting a Schema

1. Open the schema detail view
2. Click "Export"
3. Choose format (JSON or OpenAPI)
4. Download the file

#### Generate DTO from Schema

1. Open the schema detail view
2. Click "Generate DTO" (Admin only)
3. The DTO will be generated and stored
4. Navigate to DTO Management to view the result

---

## DTO Management

**Route:** `/dashboard/dtos`

Manage generated PHP Data Transfer Objects (DTOs) from your learned schemas.

### DTO List

View all generated DTOs with:

- **Class Name** — Generated PHP class name
- **Namespace** — PHP namespace
- **Endpoint** — Associated HTTP method and path
- **Version** — Current version number
- **Created** — Generation timestamp

### Filtering DTOs

| Filter | Description |
|--------|-------------|
| **Token** | Filter by API token |
| **Class Name** | Search by class name (partial match) |
| **Namespace** | Filter by namespace |
| **Endpoint Path** | Filter by endpoint path |

### DTO Detail View

**Route:** `/dashboard/dtos/{id}`

View the generated PHP code with:

- **Syntax Highlighting** — Color-coded PHP code display
- **Metadata** — Namespace, version, checksum, creation date
- **Version Selector** — Switch between different versions
- **Download Button** — Download as `.php` file
- **Copy Button** — Copy code to clipboard

### Version Comparison

**Route:** `/dashboard/dtos/{id}/diff`

Compare different versions of a DTO:

1. Open the DTO detail view
2. Click "Compare Versions"
3. Select two versions to compare
4. View side-by-side diff with:
   - **Green** — Added lines
   - **Red** — Removed lines

### Regenerating a DTO

If the schema has changed and you want to regenerate the DTO:

1. Open the DTO detail view
2. Click "Regenerate" (Admin only)
3. The regeneration job will be queued
4. Refresh to see the new version

### Bulk Export

Download multiple DTOs as a ZIP archive:

1. Check the DTOs you want to export in the list view
2. Click "Export Selected"
3. Download the ZIP file containing all selected DTOs

The ZIP preserves the namespace directory structure for easy integration.

---

## Alert Configuration

**Route:** `/dashboard/alerts`

Configure notifications for drift events.

### Alert List

View all configured alerts with:

- **Name** — Alert configuration name
- **Channel** — Slack, Webhook, or Email
- **Severity Threshold** — Minimum severity to trigger
- **Scope** — Global or specific token
- **Status** — Enabled, Disabled, or Muted

### Creating an Alert

**Route:** `/dashboard/alerts/new` (Admin only)

1. Click "Create Alert"
2. Configure the alert:
   - **Name** — Descriptive name
   - **Channel Type** — Slack, Webhook, or Email
   - **Channel Config** — URL, channel name, or email address
   - **Minimum Severity** — Info, Warning, or Critical
   - **Token Scope** — Global (all tokens) or specific token
3. Click "Create"

### Channel Configuration

#### Slack

```
Webhook URL: https://hooks.slack.com/services/T00/B00/XXX
Channel: #api-alerts (optional override)
```

#### Webhook

```
URL: https://your-service.com/api/alerts
Headers: {"Authorization": "Bearer xxx"} (optional)
```

#### Email

```
Recipients: alerts@example.com, team@example.com
```

### Testing Alerts

1. Open the alert configuration
2. Click "Test Alert"
3. A test notification will be sent to the configured channel
4. Verify receipt and formatting

### Muting Alerts

Temporarily disable an alert without deleting it:

1. Open the alert configuration
2. Click "Mute"
3. Select duration (1 hour, 4 hours, 24 hours, or custom)
4. The alert will automatically re-enable after the period

### Alert History

**Route:** `/dashboard/alerts/history`

View the log of all sent alerts:

- **Timestamp** — When the alert was sent
- **Channel** — Which alert configuration
- **Drift** — Associated drift event
- **Status** — Success, Failed, or Rate Limited

---

## Request Logs

**Route:** `/dashboard/logs`

Explore the history of all proxied requests.

### Log List

View requests with:

- **Timestamp** — Request time
- **Token** — API token used
- **Target** — Destination host
- **Method** — HTTP method
- **Path** — Request path
- **Status** — Response status code
- **Latency** — Response time in milliseconds
- **Drift** — Indicator if drift was detected

### Filtering Logs

| Filter | Description |
|--------|-------------|
| **Date Range** | From/To date pickers |
| **Token** | Filter by API token |
| **Target** | Filter by destination host |
| **Status Code** | Filter by response status (2xx, 4xx, 5xx) |
| **Latency** | Min/Max latency range |
| **Has Drift** | Show only requests with detected drift |

### Log Detail View

**Route:** `/dashboard/logs/{id}`

View complete request details:

- **Request Metadata** — Method, path, headers
- **Response Metadata** — Status, headers, latency
- **Request Body** — If stored (depends on log level)
- **Response Body** — If stored (depends on log level)
- **Associated Drift** — Link to drift if detected
- **Drift Payload** — Request/response data captured when drift detected (drift_only mode)

### Log Levels

The amount of data stored for each request depends on the token's log level:

| Log Level | Metadata | Headers | Bodies | Notes |
|-----------|----------|---------|--------|-------|
| **none** | ❌ | ❌ | ❌ | No logging |
| **metadata_only** | ✅ | ❌ | ❌ | Method, path, status, latency only |
| **drift_only** | ✅ | On drift | On drift | Bodies stored only when drift detected |
| **headers** | ✅ | ✅ | ❌ | Headers but no bodies |
| **full_audit** | ✅ | ✅ | ✅ | Complete request/response capture |

**Drift Payload:** When using `drift_only` log level, request/response data is captured in a separate "Drift Payload" only when a schema drift is detected. This optimizes storage while preserving debugging information for anomalies.

### Encrypted Logs

If a token uses the `encrypt` or `redact_encrypt` data protection strategy, log bodies are stored encrypted:

1. Encrypted logs show a yellow "Encrypted Content" card
2. Click the **Decrypt** button to reveal the content
3. Decryption happens server-side using the configured encryption key
4. If the encryption key is not configured, decryption will fail

### Exporting Logs

1. Apply desired filters
2. Click "Export"
3. Choose format (CSV or JSON)
4. Download the file

**Note:** Exports are limited to 10,000 records. Use date filters for larger datasets.

### Retention Indicator

Each log entry shows days until automatic deletion based on your retention policy.

---

## User Management

**Route:** `/dashboard/users` (Admin only)

Manage dashboard users and their permissions.

### User List

View all users with:

- **Email** — User email address
- **Roles** — User or Admin
- **Created** — Account creation date
- **Last Login** — Most recent login

### User Detail View

**Route:** `/dashboard/users/{id}`

View user information and their token access.

### Managing Permissions

**Route:** `/dashboard/users/{id}/permissions`

Assign token access to non-admin users:

1. Open the user detail view
2. Click "Manage Permissions"
3. Check/uncheck tokens the user should access
4. Click "Save"

**Note:** Admin users automatically have access to all tokens.

---

## Settings

**Route:** `/dashboard/settings`

Configure your personal dashboard preferences.

### Available Settings

| Setting | Options | Description |
|---------|---------|-------------|
| **Theme** | Light, Dark, System | Dashboard color scheme |
| **Timezone** | List of timezones | Timestamp display timezone |
| **Default Date Range** | 24h, 7d, 30d | Default filter range for lists |
| **Refresh Interval** | 30s, 1m, 5m, Off | Auto-refresh frequency |
| **Notifications** | Checkboxes | Which real-time events to show |

### Theme Toggle

Quick toggle between light and dark mode:

- Click the sun/moon icon in the header
- Or use the Settings page for "System" option

---

## Keyboard Shortcuts

### Global Shortcuts

| Shortcut | Action |
|----------|--------|
| `Esc` | Close sidebar (mobile), close modals |
| `Tab` | Navigate to next focusable element |
| `Shift+Tab` | Navigate to previous focusable element |

### Mobile Navigation

- **Swipe right from left edge** — Open sidebar
- **Swipe left** — Close sidebar

---

## Accessibility

SentinelPHP dashboard is designed with accessibility in mind:

### Screen Reader Support

- All interactive elements have ARIA labels
- Status changes are announced via live regions
- Focus is managed properly in modals and sidebars

### Keyboard Navigation

- All features are accessible via keyboard
- Focus trap in mobile sidebar prevents focus from escaping
- Skip links available for main content

### Visual Accessibility

- Color contrast meets WCAG 2.1 AA standards
- Status indicators use both color and icons
- Dark mode reduces eye strain in low-light environments

### Loading States

- Skeleton screens show content structure while loading
- Loading spinners have appropriate ARIA attributes
- Error states are clearly communicated

---

## Troubleshooting

### Connection Issues

If you see "Disconnected" in the connection status:

1. Check your internet connection
2. Verify the server is running
3. Check browser console for errors
4. Try refreshing the page

### Real-Time Updates Not Working

If updates aren't appearing in real-time:

1. Check the connection status indicator
2. If showing "Polling", Mercure may be unavailable
3. Verify Mercure configuration in `.env`
4. Check Mercure hub logs for errors

### Permission Denied Errors

If you receive 403 Forbidden:

1. Verify you have access to the requested token
2. Contact an admin to grant permissions
3. Admin-only pages require `ROLE_ADMIN`

### Missing Data

If data appears missing:

1. Check your filter settings
2. Verify date range includes expected data
3. Ensure you have access to the relevant tokens
4. Check if data retention has purged old records

---

## See Also

- [README.md](../README.md) — Main documentation
- [DTO Generation Guide](dto-generation.md) — Detailed DTO generation documentation
