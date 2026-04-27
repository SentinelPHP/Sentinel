/**
 * Load Test
 * 
 * Tests the system under normal expected load conditions.
 * Simulates typical production traffic patterns.
 * 
 * Usage:
 *   k6 run tests/load/scripts/load.js
 *   k6 run --env BASE_URL=http://localhost:8080 --env API_TOKEN=your-token tests/load/scripts/load.js
 */

import { sleep, group } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { 
    healthCheck, 
    proxyRequest, 
    checkResponse, 
    generatePayload,
    BASE_URL,
    commonThresholds 
} from '../lib/helpers.js';

// Custom metrics
const proxyRequests = new Counter('proxy_requests');
const proxyLatency = new Trend('proxy_latency');

export const options = {
    stages: [
        { duration: '1m', target: 20 },   // Ramp up to 20 users
        { duration: '3m', target: 50 },   // Ramp up to 50 users
        { duration: '5m', target: 50 },   // Stay at 50 users
        { duration: '2m', target: 100 },  // Ramp up to 100 users
        { duration: '3m', target: 100 },  // Stay at 100 users
        { duration: '2m', target: 0 },    // Ramp down to 0
    ],
    thresholds: {
        ...commonThresholds,
        proxy_latency: ['p(95)<300', 'p(99)<500'],
        checks: ['rate>0.95'],
    },
};

export default function () {
    group('Health Check', function () {
        const res = healthCheck();
        checkResponse(res, 200);
    });

    sleep(0.5);

    group('Proxy GET Request', function () {
        const res = proxyRequest('GET', 'https://httpbin.org/get');
        proxyRequests.add(1);
        proxyLatency.add(res.timings.duration);
        checkResponse(res);
    });

    sleep(0.5);

    group('Proxy POST Request (Small Payload)', function () {
        const payload = generatePayload('small');
        const res = proxyRequest('POST', 'https://httpbin.org/post', payload);
        proxyRequests.add(1);
        proxyLatency.add(res.timings.duration);
        checkResponse(res);
    });

    sleep(0.5);

    group('Proxy POST Request (Medium Payload)', function () {
        const payload = generatePayload('medium');
        const res = proxyRequest('POST', 'https://httpbin.org/post', payload);
        proxyRequests.add(1);
        proxyLatency.add(res.timings.duration);
        checkResponse(res);
    });

    sleep(1);
}

export function handleSummary(data) {
    const duration = options.stages.reduce((acc, s) => {
        const match = s.duration.match(/(\d+)m/);
        return acc + (match ? parseInt(match[1]) : 0);
    }, 0);
    
    const maxVUs = Math.max(...options.stages.map(s => s.target));
    
    return {
        stdout: `
╔══════════════════════════════════════════════════════════════╗
║                     LOAD TEST RESULTS                        ║
╠══════════════════════════════════════════════════════════════╣
║  Base URL: ${BASE_URL.padEnd(47)}║
║  Duration: ${(duration + ' minutes').padEnd(47)}║
║  Max VUs: ${String(maxVUs).padEnd(48)}║
╠══════════════════════════════════════════════════════════════╣
║  Total Requests: ${String(data.metrics.http_reqs.values.count).padEnd(40)}║
║  Request Rate: ${(data.metrics.http_reqs.values.rate).toFixed(2).padEnd(42)}/s║
║  Failed Requests: ${((data.metrics.http_req_failed.values.rate) * 100).toFixed(2).padEnd(40)}%║
╠══════════════════════════════════════════════════════════════╣
║  Avg Response Time: ${(data.metrics.http_req_duration.values.avg).toFixed(2).padEnd(37)}ms║
║  P50 Response Time: ${(data.metrics.http_req_duration.values.med).toFixed(2).padEnd(37)}ms║
║  P95 Response Time: ${(data.metrics.http_req_duration.values['p(95)']).toFixed(2).padEnd(37)}ms║
║  P99 Response Time: ${(data.metrics.http_req_duration.values['p(99)']).toFixed(2).padEnd(37)}ms║
║  Max Response Time: ${(data.metrics.http_req_duration.values.max).toFixed(2).padEnd(37)}ms║
╠══════════════════════════════════════════════════════════════╣
║  Proxy Requests: ${String(data.metrics.proxy_requests?.values.count || 0).padEnd(40)}║
║  Proxy P95 Latency: ${(data.metrics.proxy_latency?.values['p(95)'] || 0).toFixed(2).padEnd(37)}ms║
╚══════════════════════════════════════════════════════════════╝
`,
        'tests/load/results/load-summary.json': JSON.stringify(data, null, 2),
    };
}
