<?php

declare(strict_types=1);

namespace App\Storage;

use App\Entity\ApiToken;
use App\Enum\LogLevel;
use SentinelPHP\Core\Storage\StorageInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Factory for creating MessengerStorage instances with token context.
 */
final class MessengerStorageFactory
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LogLevel $defaultLogLevel = LogLevel::MetadataOnly,
    ) {
    }

    public function createForToken(ApiToken $token): StorageInterface
    {
        return new MessengerStorage(
            messageBus: $this->messageBus,
            token: $token,
            defaultLogLevel: $this->defaultLogLevel,
        );
    }
}
