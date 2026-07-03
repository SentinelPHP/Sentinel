<?php

declare(strict_types=1);

namespace App\Http;

final class HttpClientFactory
{
    public function __construct(
        private readonly SwooleHttpClient $swooleClient,
        private readonly GuzzleHttpClientAdapter $guzzleClient,
    ) {
    }

    public function create(): HttpClientInterface
    {
        if ($this->isSwooleCliRuntime()) {
            return $this->swooleClient;
        }

        return $this->guzzleClient;
    }

    private function isSwooleCliRuntime(): bool
    {
        return PHP_SAPI === 'cli'
            && extension_loaded('swoole')
            && \Swoole\Coroutine::getCid() >= 0;
    }
}
