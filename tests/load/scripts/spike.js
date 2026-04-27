/**
 * Spike Test
 * 
 * Tests the system's ability to handle sudden traffic bursts.
 * Simulates scenarios like flash sales, viral content, or DDoS-like patterns.
 * 
 * Usage:
 *   k6 run tests/load/scripts/spike.js
 *   k6 run --env BASE_URL=http://localhost:8080 --env API_TOKEN=your-token tests/load/scripts/spike.js
 */

import { sleep, group } from 'k6';
import { Counter, Trend, Rate } from 'k6/metrics';
import { 
    healthCheck, 
    proxyRequest, 
    checkResponse, 
    generatePayload,
    BASE_URL 
} from '../lib/helpers.js';

// Custom metrics
const proxyRequests = new Counter('proxy_requests');
const proxyLatency = new Trend('proxy_latency');
const errorRate = new Rate('error_rate');
const spikeRecovery = new Trend('spike_recovery_time');

export const options = {
    stages: [
        { duration: '1m', target: 10 },    // Baseline
        { duration: '10s', target: 300 },  // Spike 1: Sudden surge
        { duration: '1m', target: 300 },   // Hold spike
        { duration: '10s', target: 10 },   // Drop back
        { duration: '1m', target: 10 },    // Recovery period
        { duration: '10s', target: 500 },  // Spike 2: Larger surge
        { duration: '1m', target: 500 },   // Hold spike
        { duration: '10s', target: 10 },   // Drop back
        { duration: '1m', target: 10 },    // Recovery period
        { duration: '30s', target: 0 },    // Ramp down
    ],
    thresholds: {
        http_req_duration: ['p(95)<3000'],  // Allow up to 3s during spikes
        http_req_failed: ['rate<0.15'],     // Allow up to 15% errors during spikes
        error_rate: ['rate<0.20'],
    },
};

export default function () {
    const startTime = Date.now();
    
    group('Health Check', function () {
        const res = healthCheck();
        const passed = checkResponse(res, 200);
        errorRate.add(!passed);
    });

    sleep(0.1);

    group('Proxy Request Burst', function () {
        const res = proxyRequest('GET', 'https://httpbin.org/get');
        proxyRequests.add(1);
        proxyLatency.add(res.timings.duration);
        const passed = checkResponse(res);
        errorRate.add(!passed);
        
        // Track recovery time (time from request start to successful response)
        if (passed) {
            spikeRecovery.add(Date.now() - startTime);
        }
    });

    sleep(0.1);

    group('Proxy POST During Spike', function () {
        const payload = generatePayload('small');
        const res = proxyRequest('POST', 'https://httpbin.org/post', payload);
        proxyRequests.add(1);
        proxyLatency.add(res.timings.duration);
        const passed = checkResponse(res);
        errorRate.add(!passed);
    });

    sleep(0.1);
}

export function handleSummary(data) {
    const errorPct = (data.metrics.http_req_failed.values.rate * 100).toFixed(2);
    const status = errorPct < 10 ? '✅ PASSED' : errorPct < 15 ? '⚠️  WARNING' : '❌ FAILED';
    
    return {
        stdout: `
╔══════════════════════════════════════════════════════════════╗
║                     SPIKE TEST RESULTS                       ║
╠══════════════════════════════════════════════════════════════╣
║  Status: ${status.padEnd(49)}║
║  Base URL: ${BASE_URL.padEnd(47)}║
║  Test Pattern: Baseline → 300 VUs → 500 VUs → Recovery       ║
╠══════════════════════════════════════════════════════════════╣
║  Total Requests: ${String(data.metrics.http_reqs.values.count).padEnd(40)}║
║  Peak Request Rate: ${(data.metrics.http_reqs.values.rate).toFixed(2).padEnd(37)}/s║
║  Failed Requests: ${errorPct.padEnd(40)}%║
╠══════════════════════════════════════════════════════════════╣
║  Response Times During Spikes:                               ║
║    Avg: ${(data.metrics.http_req_duration.values.avg).toFixed(2).padEnd(50)}ms║
║    P50: ${(data.metrics.http_req_duration.values.med).toFixed(2).padEnd(50)}ms║
║    P95: ${(data.metrics.http_req_duration.values['p(95)']).toFixed(2).padEnd(50)}ms║
║    P99: ${(data.metrics.http_req_duration.values['p(99)']).toFixed(2).padEnd(50)}ms║
║    Max: ${(data.metrics.http_req_duration.values.max).toFixed(2).padEnd(50)}ms║
╠══════════════════════════════════════════════════════════════╣
║  Recovery Metrics:                                           ║
║    Avg Recovery Time: ${(data.metrics.spike_recovery_time?.values.avg || 0).toFixed(2).padEnd(35)}ms║
║    P95 Recovery Time: ${(data.metrics.spike_recovery_time?.values['p(95)'] || 0).toFixed(2).padEnd(35)}ms║
╚══════════════════════════════════════════════════════════════╝
`,
        'tests/load/results/spike-summary.json': JSON.stringify(data, null, 2),
    };
}
