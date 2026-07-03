<?php

declare(strict_types=1);

namespace App\Swoole;

use App\Redis\RedisClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Factory for creating Swoole-compatible session storage.
 */
final class SwooleSessionStorageFactory implements SessionStorageFactoryInterface
{
    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly int $ttl = 86400,
        private readonly string $prefix = 'sf_sess_',
    ) {
    }

    public function createStorage(?Request $request): SessionStorageInterface
    {
        $storage = new SwooleSessionStorage($this->redis, $this->ttl, $this->prefix);

        if ($request && $request->cookies->has($storage->getName())) {
            $sessionId = $request->cookies->get($storage->getName());
            if (is_string($sessionId) && $sessionId !== '') {
                $storage->setId($sessionId);
            }
        }

        return $storage;
    }
}
