/**
 * Stress Test
 * 
 * Tests the system beyond normal capacity to find breaking points.
 * Identifies the maximum throughput and when errors start occurring.
 * 
 * Usage:
 *   k6 run tests/load/scripts/stress.js
 *   k6 run --env BASE_URL=http://localhost:8080 --env API_TOKEN=your-token tests/load/scripts/stress.js
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

export const options = {
    stages: [
        { duration: '2m', target: 50 },    // Warm up
        { duration: '3m', target: 100 },   // Normal load
        { duration: '3m', target: 200 },   // Beyond normal
        { duration: '3m', target: 300 },   // Stress level
        { duration: '3m', target: 400 },   // Breaking point search
        { duration: '2m', target: 500 },   // Maximum stress
        { duration: '2m', target: 0 },     // Recovery
    ],
    thresholds: {
        // More lenient thresholds for stress testing
        http_req_duration: ['p(95)<2000'],  // Allow up to 2s at p95
        http_req_failed: ['rate<0.10'],     // Allow up to 10% errors
        error_rate: ['rate<0.15'],          // Track custom error rate
    },
};

export default function () {
    group('Health Check', function () {
        const res = healthCheck();
        const passed = checkResponse(res, 200);
        errorRate.add(!passed);
    });

    sleep(0.2);

    group('Proxy GET Request', function () {
        const res = proxyRequest('GET', 'https://httpbin.org/get');
        proxyRequests.add(1);
        proxyLatency.add(res.timings.duration);
        const passed = checkResponse(res);
        errorRate.add(!passed);
    });

    sleep(0.2);

    group('Proxy POST Request', function () {
        const payload = generatePayload('medium');
        const res = proxyRequest('POST', 'https://httpbin.org/post', payload);
        proxyRequests.add(1);
        proxyLatency.add(res.timings.duration);
        const passed = checkResponse(res);
        errorRate.add(!passed);
    });

    sleep(0.3);
}

export function handleSummary(data) {
    const duration = options.stages.reduce((acc, s) => {
        const match = s.duration.match(/(\d+)m/);
        return acc + (match ? parseInt(match[1]) : 0);
    }, 0);
    
    const maxVUs = Math.max(...options.stages.map(s => s.target));
    const errorPct = (data.metrics.http_req_failed.values.rate * 100).toFixed(2);
    const status = errorPct < 5 ? '✅ PASSED' : errorPct < 10 ? '⚠️  WARNING' : '❌ FAILED';
    
    return {
        stdout: `
╔══════════════════════════════════════════════════════════════╗
║                    STRESS TEST RESULTS                       ║
╠══════════════════════════════════════════════════════════════╣
║  Status: ${status.padEnd(49)}║
║  Base URL: ${BASE_URL.padEnd(47)}║
║  Duration: ${(duration + ' minutes').padEnd(47)}║
║  Max VUs: ${String(maxVUs).padEnd(48)}║
╠══════════════════════════════════════════════════════════════╣
║  Total Requests: ${String(data.metrics.http_reqs.values.count).padEnd(40)}║
║  Request Rate: ${(data.metrics.http_reqs.values.rate).toFixed(2).padEnd(42)}/s║
║  Failed Requests: ${errorPct.padEnd(40)}%║
╠══════════════════════════════════════════════════════════════╣
║  Response Times:                                             ║
║    Min: ${(data.metrics.http_req_duration.values.min).toFixed(2).padEnd(50)}ms║
║    Avg: ${(data.metrics.http_req_duration.values.avg).toFixed(2).padEnd(50)}ms║
║    P50: ${(data.metrics.http_req_duration.values.med).toFixed(2).padEnd(50)}ms║
║    P90: ${(data.metrics.http_req_duration.values['p(90)']).toFixed(2).padEnd(50)}ms║
║    P95: ${(data.metrics.http_req_duration.values['p(95)']).toFixed(2).padEnd(50)}ms║
║    P99: ${(data.metrics.http_req_duration.values['p(99)']).toFixed(2).padEnd(50)}ms║
║    Max: ${(data.metrics.http_req_duration.values.max).toFixed(2).padEnd(50)}ms║
╠══════════════════════════════════════════════════════════════╣
║  Proxy Metrics:                                              ║
║    Total: ${String(data.metrics.proxy_requests?.values.count || 0).padEnd(48)}║
║    P95 Latency: ${(data.metrics.proxy_latency?.values['p(95)'] || 0).toFixed(2).padEnd(41)}ms║
╚══════════════════════════════════════════════════════════════╝
`,
        'tests/load/results/stress-summary.json': JSON.stringify(data, null, 2),
    };
}
