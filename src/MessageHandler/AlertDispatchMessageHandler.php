<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AlertDispatchMessage;
use App\Repository\SchemaDriftRepository;
use App\Service\Alert\AlertDispatcherServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class AlertDispatchMessageHandler
{
    public function __construct(
        private SchemaDriftRepository $driftRepository,
        private AlertDispatcherServiceInterface $alertDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AlertDispatchMessage $message): void
    {
        $drift = $this->driftRepository->find(Uuid::fromString($message->driftId));

        if ($drift === null) {
            $this->logger->warning('Drift not found for alert dispatch', [
                'drift_id' => $message->driftId,
            ]);

            return;
        }

        $this->alertDispatcher->dispatch($drift);
    }
}
