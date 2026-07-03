<?php

declare(strict_types=1);

namespace App\Service\Alert;

use App\Redis\RedisClientInterface;

/**
 * Circuit breaker implementation to prevent repeated calls to failing services.
 *
 * States:
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: Service is failing, requests are rejected immediately
 * - HALF_OPEN: Testing if service has recovered
 */
final class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private const KEY_PREFIX = 'sentinel:circuit_breaker:';
    private const FAILURE_COUNT_SUFFIX = ':failures';
    private const STATE_SUFFIX = ':state';
    private const LAST_FAILURE_SUFFIX = ':last_failure';

    public function __construct(
        private readonly ?RedisClientInterface $redisClient,
        private readonly int $failureThreshold = 5,
        private readonly int $recoveryTimeSeconds = 60,
        private readonly int $failureWindowSeconds = 120,
    ) {
    }

    /**
     * Check if the circuit allows a request to pass through.
     */
    public function isAvailable(string $serviceName): bool
    {
        if ($this->redisClient === null) {
            return true;
        }

        $state = $this->getState($serviceName);

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            if ($this->shouldAttemptRecovery($serviceName)) {
                $this->setState($serviceName, self::STATE_HALF_OPEN);
                return true;
            }
            return false;
        }

        // HALF_OPEN: allow one request through to test recovery
        return true;
    }

    /**
     * Record a successful request. Resets the circuit if in half-open state.
     */
    public function recordSuccess(string $serviceName): void
    {
        if ($this->redisClient === null) {
            return;
        }

        $state = $this->getState($serviceName);

        if ($state === self::STATE_HALF_OPEN) {
            $this->reset($serviceName);
        }
    }

    /**
     * Record a failed request. May trip the circuit if threshold is exceeded.
     */
    public function recordFailure(string $serviceName): void
    {
        if ($this->redisClient === null) {
            return;
        }

        $state = $this->getState($serviceName);

        if ($state === self::STATE_HALF_OPEN) {
            $this->tripCircuit($serviceName);
            return;
        }

        $failureKey = $this->getFailureCountKey($serviceName);
        $count = $this->redisClient->incr($failureKey);

        if ($count === 1) {
            $this->redisClient->setex($failureKey, $this->failureWindowSeconds, '1');
        }

        $this->redisClient->set(
            $this->getLastFailureKey($serviceName),
            (string) time()
        );

        if ($count >= $this->failureThreshold) {
            $this->tripCircuit($serviceName);
        }
    }

    /**
     * Get the current state of the circuit.
     */
    public function getState(string $serviceName): string
    {
        if ($this->redisClient === null) {
            return self::STATE_CLOSED;
        }

        return $this->redisClient->get($this->getStateKey($serviceName)) ?? self::STATE_CLOSED;
    }

    /**
     * Check if the circuit is currently open (rejecting requests).
     */
    public function isOpen(string $serviceName): bool
    {
        return $this->getState($serviceName) === self::STATE_OPEN;
    }

    /**
     * Manually reset the circuit to closed state.
     */
    public function reset(string $serviceName): void
    {
        if ($this->redisClient === null) {
            return;
        }

        $this->redisClient->del($this->getStateKey($serviceName));
        $this->redisClient->del($this->getFailureCountKey($serviceName));
        $this->redisClient->del($this->getLastFailureKey($serviceName));
    }

    private function tripCircuit(string $serviceName): void
    {
        $this->setState($serviceName, self::STATE_OPEN);
        $this->redisClient?->set(
            $this->getLastFailureKey($serviceName),
            (string) time()
        );
    }

    private function setState(string $serviceName, string $state): void
    {
        if ($this->redisClient === null) {
            return;
        }

        if ($state === self::STATE_CLOSED) {
            $this->redisClient->del($this->getStateKey($serviceName));
        } else {
            // State expires after recovery time + buffer
            $this->redisClient->setex(
                $this->getStateKey($serviceName),
                $this->recoveryTimeSeconds * 2,
                $state
            );
        }
    }

    private function shouldAttemptRecovery(string $serviceName): bool
    {
        $lastFailure = $this->redisClient?->get($this->getLastFailureKey($serviceName));

        if ($lastFailure === null) {
            return true;
        }

        return (time() - (int) $lastFailure) >= $this->recoveryTimeSeconds;
    }

    private function getStateKey(string $serviceName): string
    {
        return self::KEY_PREFIX . $serviceName . self::STATE_SUFFIX;
    }

    private function getFailureCountKey(string $serviceName): string
    {
        return self::KEY_PREFIX . $serviceName . self::FAILURE_COUNT_SUFFIX;
    }

    private function getLastFailureKey(string $serviceName): string
    {
        return self::KEY_PREFIX . $serviceName . self::LAST_FAILURE_SUFFIX;
    }
}
