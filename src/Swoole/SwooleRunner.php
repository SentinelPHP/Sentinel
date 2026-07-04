<?php

declare(strict_types=1);

namespace App\Swoole;

use App\Service\RequestContext;
use App\Service\StatusServiceInterface;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;

final class SwooleRunner implements RunnerInterface
{
    private const DEFAULT_PORT = 8080;
    private const DEFAULT_HOST = '0.0.0.0';
    private const GRACEFUL_SHUTDOWN_TIMEOUT = 30;

    /** @var array<string, mixed> */
    private readonly array $options;

    private bool $isShuttingDown = false;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly KernelInterface $kernel,
        array $options = [],
    ) {
        $this->options = $options;
    }

    public function run(): int
    {
        $host = $this->getHost();
        $port = $this->getPort();

        $server = new Server($host, $port, SWOOLE_PROCESS);
        $server->set($this->getServerSettings());

        $this->registerEventHandlers($server, $port);

        $server->start();

        return 0;
    }

    private function getHost(): string
    {
        /** @var string $host */
        $host = $this->options['host']
            ?? $_ENV['SWOOLE_HOST']
            ?? $_SERVER['SWOOLE_HOST']
            ?? self::DEFAULT_HOST;

        return $host;
    }

    private function getPort(): int
    {
        /** @var int|string $port */
        $port = $this->options['port']
            ?? $_ENV['PROXY_LISTEN_PORT']
            ?? $_SERVER['PROXY_LISTEN_PORT']
            ?? self::DEFAULT_PORT;

        return (int) $port;
    }

    /**
     * @return array<string, mixed>
     */
    private function getServerSettings(): array
    {
        /** @var int|string $workerNumValue */
        $workerNumValue = $_ENV['SWOOLE_WORKER_NUM'] ?? $_SERVER['SWOOLE_WORKER_NUM'] ?? swoole_cpu_num() * 2;
        $workerNum = (int) $workerNumValue;

        /** @var int|string $maxRequestValue */
        $maxRequestValue = $_ENV['SWOOLE_MAX_REQUEST'] ?? $_SERVER['SWOOLE_MAX_REQUEST'] ?? 10000;

        return [
            'worker_num' => $workerNum,
            'enable_coroutine' => true,
            'hook_flags' => $this->getHookFlags(),
            'max_request' => (int) $maxRequestValue,
            'dispatch_mode' => 2,
            'max_wait_time' => $this->getGracefulShutdownTimeout(),
            'reload_async' => true,
            'enable_static_handler' => true,
            'document_root' => $this->kernel->getProjectDir() . '/public',
            'static_handler_locations' => ['/build', '/assets', '/favicon.ico'],
        ];
    }

    /**
     * Coroutine hook flags for the worker runtime.
     *
     * Deliberately excludes SWOOLE_HOOK_PDO_PGSQL where available. Doctrine's
     * EntityManager/DBAL Connection is a single object shared by all coroutines
     * within a worker (it is resolved once from the container at worker boot and
     * reused by every request). If PDO_PGSQL were coroutine-hooked, a query could
     * yield control mid-flight and let a *different* coroutine send another query
     * on the same underlying socket, corrupting/interleaving results across
     * concurrent requests. Keeping PDO_PGSQL un-hooked makes DB calls fully
     * blocking, so a query always completes before any other coroutine in the
     * worker can run, which serializes DB access safely at the cost of coroutine
     * concurrency for DB-bound work. Outbound proxy requests are unaffected:
     * SwooleHttpClient uses Swoole\Coroutine\Http\Client, which is coroutine-native
     * regardless of hook_flags. See .windsurf/rules/swoole.md for details.
     *
     * SWOOLE_HOOK_PDO_PGSQL is only defined when Swoole is compiled with
     * --enable-swoole-pgsql, so its exclusion is guarded to remain portable
     * across Swoole builds.
     */
    private function getHookFlags(): int
    {
        $flags = SWOOLE_HOOK_ALL;

        if (defined('SWOOLE_HOOK_PDO_PGSQL')) {
            /** @var int $pdoPgsqlHook */
            $pdoPgsqlHook = constant('SWOOLE_HOOK_PDO_PGSQL');
            $flags &= ~$pdoPgsqlHook;
        }

        return $flags;
    }

    private function registerEventHandlers(Server $server, int $port): void
    {
        /** @var SwooleRequestHandler|null $requestHandler */
        $requestHandler = null;
        /** @var StatusServiceInterface|null $statusService */
        $statusService = null;

        $server->on('start', function (Server $server) use ($port): void {
            echo "SentinelPHP Swoole server started on http://0.0.0.0:{$port}\n";
            echo "Press Ctrl+C to stop.\n";
        });

        $server->on('workerStart', function (Server $server, int $workerId) use (&$requestHandler, &$statusService): void {
            $this->kernel->boot();

            $container = $this->kernel->getContainer();

            /** @var RequestContext $requestContext */
            $requestContext = $container->get(RequestContext::class);
            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);

            $requestHandler = new SwooleRequestHandler($this->kernel, $requestContext, $logger);
            /** @var StatusServiceInterface $service */
            $service = $container->get(StatusServiceInterface::class);
            $statusService = $service;

            if ($workerId === 0) {
                $statusService->setServerStartTime(time());
            }

            if ($workerId === 0) {
                echo "Worker {$workerId} started (master worker)\n";
            }
        });

        $server->on('request', function (Request $swooleRequest, Response $swooleResponse) use ($server, &$requestHandler, &$statusService): void {
            if ($statusService instanceof StatusServiceInterface) {
                /** @var array{connection_num?: int} $stats */
                $stats = $server->stats();
                $statusService->updateActiveConnections($stats['connection_num'] ?? 0);
            }

            if ($requestHandler instanceof SwooleRequestHandler) {
                $requestHandler->handle($swooleRequest, $swooleResponse);
            }
        });

        $server->on('shutdown', function (): void {
            $this->isShuttingDown = true;
            echo "SentinelPHP Swoole server stopped.\n";
        });
    }

    private function getGracefulShutdownTimeout(): int
    {
        /** @var int|string $timeout */
        $timeout = $_ENV['SWOOLE_GRACEFUL_SHUTDOWN_TIMEOUT']
            ?? $_SERVER['SWOOLE_GRACEFUL_SHUTDOWN_TIMEOUT']
            ?? self::GRACEFUL_SHUTDOWN_TIMEOUT;

        return (int) $timeout;
    }

    public function isShuttingDown(): bool
    {
        return $this->isShuttingDown;
    }
}
