/**
 * Smoke Test
 * 
 * Quick validation that the system is working correctly.
 * Run this before other load tests to ensure basic functionality.
 * 
 * Usage:
 *   k6 run tests/load/scripts/smoke.js
 *   k6 run --env BASE_URL=http://localhost:8080 tests/load/scripts/smoke.js
 */

import { sleep } from 'k6';
import { healthCheck, statusCheck, checkResponseStrict, BASE_URL } from '../lib/helpers.js';

export const options = {
    vus: 1,
    duration: '30s',
    thresholds: {
        http_req_duration: ['p(95)<200'],
        http_req_failed: ['rate<0.01'],
        checks: ['rate>0.99'],
    },
};

export default function () {
    // Test health endpoint
    const healthRes = healthCheck();
    checkResponseStrict(healthRes, 200);
    
    sleep(1);
    
    // Test status endpoint
    const statusRes = statusCheck();
    checkResponseStrict(statusRes, 200);
    
    sleep(1);
}

export function handleSummary(data) {
    const passed = data.metrics.checks.values.passes;
    const failed = data.metrics.checks.values.fails;
    const total = passed + failed;
    
    return {
        stdout: `
╔══════════════════════════════════════════════════════════════╗
║                    SMOKE TEST RESULTS                        ║
╠══════════════════════════════════════════════════════════════╣
║  Base URL: ${BASE_URL.padEnd(47)}║
║  Duration: ${options.duration.padEnd(47)}║
║  VUs: ${String(options.vus).padEnd(52)}║
╠══════════════════════════════════════════════════════════════╣
║  Checks Passed: ${String(passed).padEnd(42)}║
║  Checks Failed: ${String(failed).padEnd(42)}║
║  Success Rate: ${((passed / total) * 100).toFixed(2).padEnd(43)}%║
╠══════════════════════════════════════════════════════════════╣
║  Avg Response Time: ${(data.metrics.http_req_duration.values.avg).toFixed(2).padEnd(37)}ms║
║  P95 Response Time: ${(data.metrics.http_req_duration.values['p(95)']).toFixed(2).padEnd(37)}ms║
║  P99 Response Time: ${(data.metrics.http_req_duration.values['p(99)']).toFixed(2).padEnd(37)}ms║
╚══════════════════════════════════════════════════════════════╝
`,
        'tests/load/results/smoke-summary.json': JSON.stringify(data, null, 2),
    };
}
