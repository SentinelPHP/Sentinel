<?php

declare(strict_types=1);

namespace App\Swoole;

use App\Redis\RedisClientInterface;
use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Swoole-compatible session storage using Redis.
 *
 * This storage doesn't use PHP's native session functions (session_start, etc.)
 * which are incompatible with Swoole's HTTP handling.
 */
final class SwooleSessionStorage implements SessionStorageInterface
{
    private string $id = '';
    private string $name = 'PHPSESSID';
    private bool $started = false;
    private bool $closed = false;

    /** @var array<string, SessionBagInterface> */
    private array $bags = [];

    private MetadataBag $metadataBag;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly int $ttl = 86400,
        private readonly string $prefix = 'sf_sess_',
    ) {
        $this->metadataBag = new MetadataBag();
    }

    public function start(): bool
    {
        if ($this->started) {
            return true;
        }

        if ('' === $this->id) {
            $this->id = $this->generateId();
        }

        $this->loadSession();
        $this->started = true;
        $this->closed = false;

        return true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        if ($this->started) {
            throw new \LogicException('Cannot change session ID when session is active.');
        }
        $this->id = $id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function regenerate(bool $destroy = false, ?int $lifetime = null): bool
    {
        if (!$this->started) {
            $this->start();
        }

        if ($destroy) {
            $this->redis->del($this->prefix . $this->id);
        }

        $this->id = $this->generateId();

        return true;
    }

    public function save(): void
    {
        if (!$this->started || $this->closed) {
            return;
        }

        $this->redis->setex(
            $this->prefix . $this->id,
            $this->ttl,
            serialize($this->data)
        );

        $this->closed = true;
        $this->started = false;
    }

    public function clear(): void
    {
        foreach ($this->bags as $bag) {
            $bag->clear();
        }

        $this->redis->del($this->prefix . $this->id);
        $this->data = [];
    }

    public function getBag(string $name): SessionBagInterface
    {
        if (!isset($this->bags[$name])) {
            throw new \InvalidArgumentException(sprintf('The SessionBagInterface "%s" is not registered.', $name));
        }

        if (!$this->started) {
            $this->start();
        }

        return $this->bags[$name];
    }

    public function registerBag(SessionBagInterface $bag): void
    {
        $this->bags[$bag->getName()] = $bag;
    }

    public function getMetadataBag(): MetadataBag
    {
        return $this->metadataBag;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function loadSession(): void
    {
        $data = $this->redis->get($this->prefix . $this->id);

        if ($data) {
            $unserialized = unserialize($data, ['allowed_classes' => true]);
            /** @var array<string, mixed> $sessionData */
            $sessionData = is_array($unserialized) ? $unserialized : [];
            $this->data = $sessionData;
        } else {
            $this->data = [];
        }

        foreach ($this->bags as $bag) {
            $key = $bag->getStorageKey();
            if (!isset($this->data[$key])) {
                $this->data[$key] = [];
            }
            /** @var array<string, mixed> $bagData */
            $bagData = $this->data[$key];
            $bag->initialize($bagData);
        }

        if (!isset($this->data['_sf2_meta'])) {
            $this->data['_sf2_meta'] = [];
        }
        /** @var array<string, mixed> $metaData */
        $metaData = $this->data['_sf2_meta'];
        $this->metadataBag->initialize($metaData);
    }
}
