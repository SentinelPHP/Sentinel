<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Client;

use Psr\Http\Client\ClientInterface;

/**
 * PSR-18 HTTP client that intercepts and stores API calls.
 */
interface SentinelClientInterface extends ClientInterface
{
}
