/**
 * Shared helpers for k6 load tests
 */

import { check } from 'k6';
import http from 'k6/http';

// Default base URL - override with __ENV.BASE_URL
export const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

// Test API token - should be created before running tests
export const API_TOKEN = __ENV.API_TOKEN || 'test-token-for-load-testing';

/**
 * Standard headers for proxy requests
 */
export function getProxyHeaders(targetUrl) {
    return {
        'Authorization': `Bearer ${API_TOKEN}`,
        'Content-Type': 'application/json',
        'X-Target-URL': targetUrl,
    };
}

/**
 * Standard headers for internal endpoints
 */
export function getInternalHeaders() {
    return {
        'Content-Type': 'application/json',
    };
}

/**
 * Make a proxy request through Sentinel
 */
export function proxyRequest(method, targetUrl, body = null) {
    const headers = getProxyHeaders(targetUrl);
    const options = { headers };
    
    let response;
    switch (method.toUpperCase()) {
        case 'GET':
            response = http.get(`${BASE_URL}/proxy`, options);
            break;
        case 'POST':
            response = http.post(`${BASE_URL}/proxy`, body ? JSON.stringify(body) : null, options);
            break;
        case 'PUT':
            response = http.put(`${BASE_URL}/proxy`, body ? JSON.stringify(body) : null, options);
            break;
        case 'DELETE':
            response = http.del(`${BASE_URL}/proxy`, null, options);
            break;
        default:
            response = http.request(method, `${BASE_URL}/proxy`, body ? JSON.stringify(body) : null, options);
    }
    
    return response;
}

/**
 * Check health endpoint
 */
export function healthCheck() {
    return http.get(`${BASE_URL}/health`, {
        headers: getInternalHeaders(),
    });
}

/**
 * Check status endpoint
 */
export function statusCheck() {
    return http.get(`${BASE_URL}/status`, {
        headers: getInternalHeaders(),
    });
}

/**
 * Standard response checks
 */
export function checkResponse(response, expectedStatus = 200) {
    return check(response, {
        [`status is ${expectedStatus}`]: (r) => r.status === expectedStatus,
        'response time < 500ms': (r) => r.timings.duration < 500,
        'response time < 1000ms': (r) => r.timings.duration < 1000,
    });
}

/**
 * Strict response checks for critical paths
 */
export function checkResponseStrict(response, expectedStatus = 200) {
    return check(response, {
        [`status is ${expectedStatus}`]: (r) => r.status === expectedStatus,
        'response time < 100ms': (r) => r.timings.duration < 100,
        'response time < 200ms': (r) => r.timings.duration < 200,
        'no server errors': (r) => r.status < 500,
    });
}

/**
 * Generate a random JSON payload for testing
 */
export function generatePayload(size = 'small') {
    const base = {
        id: Math.floor(Math.random() * 1000000),
        timestamp: new Date().toISOString(),
        action: 'test',
    };
    
    switch (size) {
        case 'small':
            return base;
        case 'medium':
            return {
                ...base,
                data: {
                    users: Array.from({ length: 10 }, (_, i) => ({
                        id: i,
                        name: `User ${i}`,
                        email: `user${i}@example.com`,
                    })),
                },
            };
        case 'large':
            return {
                ...base,
                data: {
                    users: Array.from({ length: 100 }, (_, i) => ({
                        id: i,
                        name: `User ${i}`,
                        email: `user${i}@example.com`,
                        metadata: {
                            created: new Date().toISOString(),
                            updated: new Date().toISOString(),
                            tags: ['tag1', 'tag2', 'tag3'],
                        },
                    })),
                },
            };
        default:
            return base;
    }
}

/**
 * Common thresholds for all tests
 */
export const commonThresholds = {
    http_req_duration: ['p(95)<500', 'p(99)<1000'],
    http_req_failed: ['rate<0.01'],
    http_reqs: ['rate>10'],
};

/**
 * Strict thresholds for performance-critical tests
 */
export const strictThresholds = {
    http_req_duration: ['p(50)<50', 'p(95)<100', 'p(99)<200'],
    http_req_failed: ['rate<0.001'],
    http_reqs: ['rate>100'],
};
