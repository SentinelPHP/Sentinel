<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\HttpClientInterface;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;

final class HealthCheckService implements HealthCheckServiceInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CacheItemPoolInterface $cache,
        private readonly HttpClientInterface $httpClient,
        private readonly string $healthCheckUrl = 'https://httpbin.org/status/200',
    ) {
    }

    /**
     * @return array{status: string, timestamp: string, checks: array<string, array<string, mixed>>}
     */
    public function getHealthStatus(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'outbound' => $this->checkOutbound(),
        ];

        $allOk = true;
        foreach ($checks as $check) {
            if ($check['status'] !== 'ok') {
                $allOk = false;
                break;
            }
        }

        return [
            'status' => $allOk ? 'ok' : 'degraded',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{status: string, latency_ms?: int, message?: string}
     */
    public function checkDatabase(): array
    {
        $start = hrtime(true);

        try {
            $this->connection->executeQuery('SELECT 1');
            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

            return [
                'status' => 'ok',
                'latency_ms' => $latencyMs,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{status: string, latency_ms?: int, message?: string}
     */
    public function checkRedis(): array
    {
        $start = hrtime(true);

        try {
            $testKey = 'health_check_' . bin2hex(random_bytes(8));
            $item = $this->cache->getItem($testKey);
            $item->set('ok');
            $item->expiresAfter(10);
            $this->cache->save($item);

            $retrieved = $this->cache->getItem($testKey);
            if ($retrieved->get() !== 'ok') {
                return [
                    'status' => 'error',
                    'message' => 'Cache read/write verification failed',
                ];
            }

            $this->cache->deleteItem($testKey);
            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

            return [
                'status' => 'ok',
                'latency_ms' => $latencyMs,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{status: string, latency_ms?: int, url?: string, message?: string}
     */
    public function checkOutbound(): array
    {
        $start = hrtime(true);

        try {
            $response = $this->httpClient->request('HEAD', $this->healthCheckUrl, [], null);
            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

            if ($response->statusCode >= 200 && $response->statusCode < 400) {
                return [
                    'status' => 'ok',
                    'latency_ms' => $latencyMs,
                    'url' => $this->healthCheckUrl,
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Unexpected status code: ' . $response->statusCode,
                'url' => $this->healthCheckUrl,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'url' => $this->healthCheckUrl,
            ];
        }
    }
}
