<?php

declare(strict_types=1);

namespace App\Service\Alert;

use App\Entity\SchemaDrift;
use App\Message\AlertDispatchMessage;
use App\ValueObject\AlertDispatchResult;
use App\ValueObject\AlertResult;
use Psr\Log\LoggerInterface;
use SentinelPHP\Drift\ClassifierInterface;
use SentinelPHP\Drift\Enum\DriftSeverity as LibrarySeverity;
use Symfony\Component\Messenger\MessageBusInterface;

final class AlertDispatcherService implements AlertDispatcherServiceInterface
{
    /**
     * @var array<int, AlertChannelInterface>
     */
    private readonly array $channels;

    /**
     * @param iterable<AlertChannelInterface> $channels
     */
    public function __construct(
        iterable $channels,
        private readonly ClassifierInterface $driftClassifier,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        $this->channels = $channels instanceof \Traversable
            ? iterator_to_array($channels, false)
            : array_values($channels);
    }

    public function dispatch(SchemaDrift $drift): AlertDispatchResult
    {
        $token = $drift->getToken();
        $threshold = $token->getAlertMinSeverity();

        $librarySeverity = LibrarySeverity::from($drift->getSeverity()->value);
        $libraryThreshold = $threshold !== null ? LibrarySeverity::from($threshold->value) : null;

        if (!$this->driftClassifier->shouldAlert($librarySeverity, $libraryThreshold)) {
            $this->logger->debug('Drift does not meet severity threshold for alerting', [
                'drift_id' => $drift->getId()->toRfc4122(),
                'severity' => $drift->getSeverity()->value,
                'token_id' => $token->getId()->toRfc4122(),
            ]);

            return AlertDispatchResult::skippedDueToSeverity();
        }

        $enabledChannels = $this->getEnabledChannels();

        if ($enabledChannels === []) {
            $this->logger->debug('No alert channels enabled', [
                'drift_id' => $drift->getId()->toRfc4122(),
            ]);

            return AlertDispatchResult::noChannelsConfigured();
        }

        $results = [];
        foreach ($enabledChannels as $channel) {
            $result = $this->sendToChannel($channel, $drift);
            $results[] = $result;
        }

        $dispatchResult = AlertDispatchResult::fromResults($results);

        $this->logger->info('Alert dispatch completed', [
            'drift_id' => $drift->getId()->toRfc4122(),
            'success_count' => $dispatchResult->getSuccessCount(),
            'failure_count' => $dispatchResult->getFailureCount(),
        ]);

        return $dispatchResult;
    }

    public function dispatchAsync(SchemaDrift $drift): void
    {
        $this->messageBus->dispatch(new AlertDispatchMessage(
            $drift->getId()->toRfc4122(),
        ));

        $this->logger->debug('Alert dispatch queued', [
            'drift_id' => $drift->getId()->toRfc4122(),
        ]);
    }

    /**
     * @return list<AlertChannelInterface>
     */
    private function getEnabledChannels(): array
    {
        return array_values(array_filter(
            $this->channels,
            static fn (AlertChannelInterface $channel) => $channel->isEnabled(),
        ));
    }

    private function sendToChannel(AlertChannelInterface $channel, SchemaDrift $drift): AlertResult
    {
        try {
            return $channel->send($drift);
        } catch (\Throwable $e) {
            $this->logger->error('Alert channel threw exception', [
                'channel' => $channel->getName(),
                'drift_id' => $drift->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            return AlertResult::failure($channel->getName(), $e->getMessage());
        }
    }
}
