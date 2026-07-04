<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swoole;

use App\Swoole\SwooleRunner;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(SwooleRunner::class)]
#[AllowMockObjectsWithoutExpectations]
#[RequiresPhpExtension('swoole')]
final class SwooleRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $envKeysToClean = [
        'SWOOLE_HOST',
        'PROXY_LISTEN_PORT',
        'SWOOLE_WORKER_NUM',
        'SWOOLE_MAX_REQUEST',
        'SWOOLE_GRACEFUL_SHUTDOWN_TIMEOUT',
    ];

    protected function setUp(): void
    {
        foreach ($this->envKeysToClean as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envKeysToClean as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    #[Test]
    public function isShuttingDownReturnsFalseInitially(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $runner = new SwooleRunner($kernel);

        $this->assertFalse($runner->isShuttingDown());
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $env
     */
    #[Test]
    #[DataProvider('hostConfigurationProvider')]
    public function hostIsResolvedFromOptionsEnvOrDefault(
        array $options,
        array $env,
        string $expectedHost
    ): void {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
        }

        $kernel = $this->createMock(KernelInterface::class);
        $runner = new SwooleRunner($kernel, $options);

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('getHost');
        $method->setAccessible(true);

        $this->assertSame($expectedHost, $method->invoke($runner));
    }

    /**
     * @return iterable<string, array{options: array<string, mixed>, env: array<string, string>, expectedHost: string}>
     */
    public static function hostConfigurationProvider(): iterable
    {
        yield 'default host when nothing set' => [
            'options' => [],
            'env' => [],
            'expectedHost' => '0.0.0.0',
        ];

        yield 'host from options takes priority' => [
            'options' => ['host' => '127.0.0.1'],
            'env' => ['SWOOLE_HOST' => '192.168.1.1'],
            'expectedHost' => '127.0.0.1',
        ];

        yield 'host from ENV when no options' => [
            'options' => [],
            'env' => ['SWOOLE_HOST' => '192.168.1.1'],
            'expectedHost' => '192.168.1.1',
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $env
     */
    #[Test]
    #[DataProvider('portConfigurationProvider')]
    public function portIsResolvedFromOptionsEnvOrDefault(
        array $options,
        array $env,
        int $expectedPort
    ): void {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
        }

        $kernel = $this->createMock(KernelInterface::class);
        $runner = new SwooleRunner($kernel, $options);

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('getPort');
        $method->setAccessible(true);

        $this->assertSame($expectedPort, $method->invoke($runner));
    }

    /**
     * @return iterable<string, array{options: array<string, mixed>, env: array<string, string>, expectedPort: int}>
     */
    public static function portConfigurationProvider(): iterable
    {
        yield 'default port when nothing set' => [
            'options' => [],
            'env' => [],
            'expectedPort' => 8080,
        ];

        yield 'port from options takes priority' => [
            'options' => ['port' => 9000],
            'env' => ['PROXY_LISTEN_PORT' => '9001'],
            'expectedPort' => 9000,
        ];

        yield 'port from ENV when no options' => [
            'options' => [],
            'env' => ['PROXY_LISTEN_PORT' => '3000'],
            'expectedPort' => 3000,
        ];

        yield 'port is cast to integer' => [
            'options' => [],
            'env' => ['PROXY_LISTEN_PORT' => '8888'],
            'expectedPort' => 8888,
        ];
    }

    #[Test]
    public function serverSettingsContainsExpectedKeys(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $runner = new SwooleRunner($kernel);

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('getServerSettings');
        $method->setAccessible(true);

        $settings = $method->invoke($runner);
        $this->assertIsArray($settings);

        /** @var array<string, mixed> $settings */
        $this->assertArrayHasKey('worker_num', $settings);
        $this->assertArrayHasKey('enable_coroutine', $settings);
        $this->assertArrayHasKey('hook_flags', $settings);
        $this->assertArrayHasKey('max_request', $settings);
        $this->assertArrayHasKey('dispatch_mode', $settings);
        $this->assertArrayHasKey('max_wait_time', $settings);
        $this->assertArrayHasKey('reload_async', $settings);
    }

    #[Test]
    public function serverSettingsHasCoroutinesEnabled(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $runner = new SwooleRunner($kernel);

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('getServerSettings');
        $method->setAccessible(true);

        $settings = $method->invoke($runner);
        $this->assertIsArray($settings);

        /** @var array<string, mixed> $settings */
        $this->assertTrue($settings['enable_coroutine']);
        $this->assertTrue($settings['reload_async']);
    }

    #[Test]
    public function hookFlagsExcludePdoPgsqlToKeepDoctrineConnectionSafeAcrossCoroutines(): void
    {
        if (!defined('SWOOLE_HOOK_PDO_PGSQL')) {
            self::markTestSkipped('Installed Swoole build does not expose SWOOLE_HOOK_PDO_PGSQL.');
        }

        $kernel = $this->createMock(KernelInterface::class);
        $runner = new SwooleRunner($kernel);

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('getServerSettings');
        $method->setAccessible(true);

        $settings = $method->invoke($runner);
        $this->assertIsArray($settings);

        /** @var array<string, mixed> $settings */
        $hookFlags = $settings['hook_flags'];
        $this->assertIsInt($hookFlags);

        /** @var int $pdoPgsqlHook */
        $pdoPgsqlHook = constant('SWOOLE_HOOK_PDO_PGSQL');

        $this->assertSame(
            0,
            $hookFlags & $pdoPgsqlHook,
            'PDO_PGSQL must not be coroutine-hooked; sharing the Doctrine connection across '
            . 'coroutines would allow interleaved queries from concurrent requests.'
        );

        // Other hooks (e.g. TCP sockets used by the proxy's outbound HTTP path) must remain enabled.
        $this->assertNotSame(0, $hookFlags & SWOOLE_HOOK_TCP);
    }

    #[Test]
    public function workerNumCanBeConfiguredViaEnv(): void
    {
        $_ENV['SWOOLE_WORKER_NUM'] = '16';

        $kernel = $this->createMock(KernelInterface::class);
        $runner = new SwooleRunner($kernel);

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('getServerSettings');
        $method->setAccessible(true);

        $settings = $method->invoke($runner);
        $this->assertIsArray($settings);

        /** @var array<string, mixed> $settings */
        $this->assertSame(16, $settings['worker_num']);
    }

    #[Test]
    public function maxRequestCanBeConfiguredViaEnv(): void
    {
        $_ENV['SWOOLE_MAX_REQUEST'] = '5000';

        $kernel = $this->createMock(KernelInterface::class);
        $runner = new SwooleRunner($kernel);

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('getServerSettings');
        $method->setAccessible(true);

        $settings = $method->invoke($runner);
        $this->assertIsArray($settings);

        /** @var array<string, mixed> $settings */
        $this->assertSame(5000, $settings['max_request']);
    }

    #[Test]
    public function gracefulShutdownTimeoutCanBeConfiguredViaEnv(): void
    {
        $_ENV['SWOOLE_GRACEFUL_SHUTDOWN_TIMEOUT'] = '60';

        $kernel = $this->createMock(KernelInterface::class);
        $runner = new SwooleRunner($kernel);

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('getGracefulShutdownTimeout');
        $method->setAccessible(true);

        $this->assertSame(60, $method->invoke($runner));
    }

    #[Test]
    public function gracefulShutdownTimeoutDefaultsTo30Seconds(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $runner = new SwooleRunner($kernel);

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('getGracefulShutdownTimeout');
        $method->setAccessible(true);

        $this->assertSame(30, $method->invoke($runner));
    }
}
