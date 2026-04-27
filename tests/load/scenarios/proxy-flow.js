/**
 * Proxy Flow Scenario
 * 
 * Realistic scenario testing the complete proxy flow including:
 * - Authentication
 * - Request proxying
 * - Schema validation (in validating mode)
 * - Various HTTP methods and payload sizes
 * 
 * Usage:
 *   k6 run tests/load/scenarios/proxy-flow.js
 */

import { sleep, group, check } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import http from 'k6/http';
import { 
    BASE_URL, 
    API_TOKEN,
    getProxyHeaders,
    generatePayload,
    checkResponse 
} from '../lib/helpers.js';

// Custom metrics per operation
const getRequests = new Counter('proxy_get_requests');
const postRequests = new Counter('proxy_post_requests');
const putRequests = new Counter('proxy_put_requests');
const deleteRequests = new Counter('proxy_delete_requests');

const getLatency = new Trend('proxy_get_latency');
const postLatency = new Trend('proxy_post_latency');
const putLatency = new Trend('proxy_put_latency');
const deleteLatency = new Trend('proxy_delete_latency');

export const options = {
    scenarios: {
        // Simulate read-heavy workload (typical API pattern)
        read_heavy: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '1m', target: 30 },
                { duration: '3m', target: 30 },
                { duration: '1m', target: 0 },
            ],
            exec: 'readHeavyScenario',
        },
        // Simulate write workload
        write_operations: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '1m', target: 10 },
                { duration: '3m', target: 10 },
                { duration: '1m', target: 0 },
            ],
            exec: 'writeScenario',
            startTime: '30s',
        },
        // Simulate mixed CRUD operations
        crud_mix: {
            executor: 'constant-vus',
            vus: 20,
            duration: '4m',
            exec: 'crudScenario',
            startTime: '1m',
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<500', 'p(99)<1000'],
        http_req_failed: ['rate<0.05'],
        proxy_get_latency: ['p(95)<300'],
        proxy_post_latency: ['p(95)<500'],
    },
};

// Read-heavy scenario (80% GET, 20% POST)
export function readHeavyScenario() {
    const rand = Math.random();
    
    if (rand < 0.8) {
        // GET request
        group('Read Operation', function () {
            const res = http.get(`${BASE_URL}/proxy`, {
                headers: getProxyHeaders('https://httpbin.org/get'),
            });
            getRequests.add(1);
            getLatency.add(res.timings.duration);
            checkResponse(res);
        });
    } else {
        // POST request
        group('Write Operation', function () {
            const payload = generatePayload('small');
            const res = http.post(`${BASE_URL}/proxy`, JSON.stringify(payload), {
                headers: getProxyHeaders('https://httpbin.org/post'),
            });
            postRequests.add(1);
            postLatency.add(res.timings.duration);
            checkResponse(res);
        });
    }
    
    sleep(0.5);
}

// Write-heavy scenario
export function writeScenario() {
    group('Create Resource', function () {
        const payload = generatePayload('medium');
        const res = http.post(`${BASE_URL}/proxy`, JSON.stringify(payload), {
            headers: getProxyHeaders('https://httpbin.org/post'),
        });
        postRequests.add(1);
        postLatency.add(res.timings.duration);
        checkResponse(res);
    });
    
    sleep(0.3);
    
    group('Update Resource', function () {
        const payload = generatePayload('small');
        const res = http.put(`${BASE_URL}/proxy`, JSON.stringify(payload), {
            headers: getProxyHeaders('https://httpbin.org/put'),
        });
        putRequests.add(1);
        putLatency.add(res.timings.duration);
        checkResponse(res);
    });
    
    sleep(0.5);
}

// Full CRUD scenario
export function crudScenario() {
    const resourceId = Math.floor(Math.random() * 1000);
    
    // Create
    group('CRUD: Create', function () {
        const payload = { id: resourceId, name: `Resource ${resourceId}`, created: Date.now() };
        const res = http.post(`${BASE_URL}/proxy`, JSON.stringify(payload), {
            headers: getProxyHeaders('https://httpbin.org/post'),
        });
        postRequests.add(1);
        postLatency.add(res.timings.duration);
        check(res, { 'create successful': (r) => r.status >= 200 && r.status < 300 });
    });
    
    sleep(0.2);
    
    // Read
    group('CRUD: Read', function () {
        const res = http.get(`${BASE_URL}/proxy`, {
            headers: getProxyHeaders(`https://httpbin.org/get?id=${resourceId}`),
        });
        getRequests.add(1);
        getLatency.add(res.timings.duration);
        check(res, { 'read successful': (r) => r.status >= 200 && r.status < 300 });
    });
    
    sleep(0.2);
    
    // Update
    group('CRUD: Update', function () {
        const payload = { id: resourceId, name: `Updated ${resourceId}`, updated: Date.now() };
        const res = http.put(`${BASE_URL}/proxy`, JSON.stringify(payload), {
            headers: getProxyHeaders('https://httpbin.org/put'),
        });
        putRequests.add(1);
        putLatency.add(res.timings.duration);
        check(res, { 'update successful': (r) => r.status >= 200 && r.status < 300 });
    });
    
    sleep(0.2);
    
    // Delete
    group('CRUD: Delete', function () {
        const res = http.del(`${BASE_URL}/proxy`, null, {
            headers: getProxyHeaders(`https://httpbin.org/delete?id=${resourceId}`),
        });
        deleteRequests.add(1);
        deleteLatency.add(res.timings.duration);
        check(res, { 'delete successful': (r) => r.status >= 200 && r.status < 300 });
    });
    
    sleep(0.5);
}

export function handleSummary(data) {
    return {
        stdout: `
╔══════════════════════════════════════════════════════════════╗
║                  PROXY FLOW SCENARIO RESULTS                 ║
╠══════════════════════════════════════════════════════════════╣
║  Scenarios: read_heavy, write_operations, crud_mix           ║
╠══════════════════════════════════════════════════════════════╣
║  Request Counts:                                             ║
║    GET:    ${String(data.metrics.proxy_get_requests?.values.count || 0).padEnd(48)}║
║    POST:   ${String(data.metrics.proxy_post_requests?.values.count || 0).padEnd(48)}║
║    PUT:    ${String(data.metrics.proxy_put_requests?.values.count || 0).padEnd(48)}║
║    DELETE: ${String(data.metrics.proxy_delete_requests?.values.count || 0).padEnd(48)}║
╠══════════════════════════════════════════════════════════════╣
║  P95 Latencies:                                              ║
║    GET:    ${(data.metrics.proxy_get_latency?.values['p(95)'] || 0).toFixed(2).padEnd(46)}ms║
║    POST:   ${(data.metrics.proxy_post_latency?.values['p(95)'] || 0).toFixed(2).padEnd(46)}ms║
║    PUT:    ${(data.metrics.proxy_put_latency?.values['p(95)'] || 0).toFixed(2).padEnd(46)}ms║
║    DELETE: ${(data.metrics.proxy_delete_latency?.values['p(95)'] || 0).toFixed(2).padEnd(46)}ms║
╠══════════════════════════════════════════════════════════════╣
║  Overall:                                                    ║
║    Total Requests: ${String(data.metrics.http_reqs.values.count).padEnd(38)}║
║    Failed Rate: ${((data.metrics.http_req_failed.values.rate) * 100).toFixed(2).padEnd(41)}%║
║    Avg Response: ${(data.metrics.http_req_duration.values.avg).toFixed(2).padEnd(40)}ms║
╚══════════════════════════════════════════════════════════════╝
`,
        'tests/load/results/proxy-flow-summary.json': JSON.stringify(data, null, 2),
    };
}
