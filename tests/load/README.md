# Load Testing with k6

This directory contains performance and load tests for SentinelPHP using [k6](https://k6.io/).

## Prerequisites

- Docker and Docker Compose
- Running SentinelPHP instance (development or production)
- Valid API token for proxy requests

## Quick Start

```bash
# Run smoke test (quick validation)
make k6-smoke

# Run full load test
make k6-load

# Run stress test (find breaking points)
make k6-stress
```

## Test Types

### Smoke Test (`scripts/smoke.js`)
Quick validation that the system is working correctly.
- **Duration:** 30 seconds
- **VUs:** 1
- **Purpose:** Verify basic functionality before running heavier tests

```bash
make k6-smoke
```

### Load Test (`scripts/load.js`)
Tests the system under normal expected load conditions.
- **Duration:** ~16 minutes
- **VUs:** Ramps from 20 → 50 → 100 → 0
- **Purpose:** Validate performance under typical production traffic

```bash
make k6-load
```

### Stress Test (`scripts/stress.js`)
Tests the system beyond normal capacity to find breaking points.
- **Duration:** ~18 minutes
- **VUs:** Ramps from 50 → 100 → 200 → 300 → 400 → 500 → 0
- **Purpose:** Identify maximum throughput and error thresholds

```bash
make k6-stress
```

### Spike Test (`scripts/spike.js`)
Tests the system's ability to handle sudden traffic bursts.
- **Duration:** ~7 minutes
- **Pattern:** Baseline → 300 VUs spike → Recovery → 500 VUs spike → Recovery
- **Purpose:** Validate behavior during traffic surges

```bash
make k6-spike
```

### Proxy Flow Scenario (`scenarios/proxy-flow.js`)
Realistic scenario testing complete proxy operations.
- **Scenarios:** Read-heavy, Write operations, CRUD mix
- **Purpose:** Simulate real-world API usage patterns

```bash
make k6-scenario
```

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `K6_BASE_URL` | `http://host.docker.internal:8080` | SentinelPHP server URL |
| `K6_API_TOKEN` | `test-token` | API token for authentication |

### Custom Configuration

```bash
# Test against a different server
K6_BASE_URL=http://my-server:8080 make k6-smoke

# Use a specific API token
K6_API_TOKEN=my-real-token make k6-load

# Both
K6_BASE_URL=http://prod-server:8080 K6_API_TOKEN=prod-token make k6-load
```

## Metrics Visualization

### Using Grafana and InfluxDB

Start the monitoring stack:

```bash
make k6-monitoring-up
```

Run tests with metrics output:

```bash
make k6-load-with-metrics
```

Access Grafana at http://localhost:3000

Stop monitoring:

```bash
make k6-monitoring-down
```

## Thresholds

### Default Thresholds

| Metric | Smoke | Load | Stress |
|--------|-------|------|--------|
| P95 Response Time | < 200ms | < 500ms | < 2000ms |
| P99 Response Time | - | < 1000ms | - |
| Error Rate | < 1% | < 1% | < 10% |
| Request Rate | - | > 10/s | - |

### Interpreting Results

- **✅ PASSED:** All thresholds met
- **⚠️ WARNING:** Some thresholds exceeded but within acceptable range
- **❌ FAILED:** Critical thresholds exceeded

## Directory Structure

```
tests/load/
├── scripts/           # Main test scripts
│   ├── smoke.js       # Quick validation
│   ├── load.js        # Normal load test
│   ├── stress.js      # Breaking point test
│   └── spike.js       # Traffic burst test
├── scenarios/         # Complex test scenarios
│   └── proxy-flow.js  # Realistic proxy operations
├── lib/               # Shared utilities
│   └── helpers.js     # Common functions and configs
├── results/           # Test output (gitignored)
├── grafana/           # Grafana provisioning
├── docker-compose.k6.yml
└── README.md
```

## Writing Custom Tests

### Basic Test Structure

```javascript
import { sleep } from 'k6';
import { healthCheck, proxyRequest, checkResponse } from '../lib/helpers.js';

export const options = {
    vus: 10,
    duration: '1m',
    thresholds: {
        http_req_duration: ['p(95)<500'],
        http_req_failed: ['rate<0.01'],
    },
};

export default function () {
    const res = proxyRequest('GET', 'https://api.example.com/endpoint');
    checkResponse(res, 200);
    sleep(1);
}
```

### Using Scenarios

```javascript
export const options = {
    scenarios: {
        my_scenario: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '1m', target: 50 },
                { duration: '2m', target: 50 },
                { duration: '1m', target: 0 },
            ],
        },
    },
};
```

## Troubleshooting

### Connection Refused

Ensure the SentinelPHP server is running and accessible:

```bash
# For DDEV development
ddev start
ddev swoole

# For production
make prod-up
```

### Authentication Errors

Create a test token before running load tests:

```bash
ddev exec php bin/console sentinel:token:create --name="Load Test Token"
```

Use the generated token:

```bash
K6_API_TOKEN=<generated-token> make k6-smoke
```

### Docker Network Issues

If k6 can't reach the server, try using the host network:

```bash
docker compose -f tests/load/docker-compose.k6.yml run --rm --network host k6 run scripts/smoke.js
```
