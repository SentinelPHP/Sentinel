<?php

declare(strict_types=1);

namespace App\Tests\Factories;

use App\Entity\AlertLog;
use App\Enum\AlertChannelType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<AlertLog>
 */
final class AlertLogFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return AlertLog::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'channelType' => self::faker()->randomElement(AlertChannelType::cases()),
            'status' => AlertLog::STATUS_SUCCESS,
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this->instantiateWith(function (array $attributes): AlertLog {
            /** @var AlertChannelType $channelType */
            $channelType = $attributes['channelType'];
            /** @var string $status */
            $status = $attributes['status'];
            /** @var \App\Entity\AlertConfiguration|null $config */
            $config = $attributes['alertConfiguration'] ?? null;
            /** @var \App\Entity\SchemaDrift|null $drift */
            $drift = $attributes['drift'] ?? null;
            /** @var array<string, mixed>|null $payload */
            $payload = $attributes['payload'] ?? null;

            if ($status === AlertLog::STATUS_SUCCESS) {
                $log = AlertLog::success(
                    $channelType,
                    $config,
                    $drift,
                    $payload,
                );
            } else {
                /** @var string $errorMessage */
                $errorMessage = $attributes['errorMessage'] ?? 'Test error message';
                $log = AlertLog::failure(
                    $channelType,
                    $errorMessage,
                    $config,
                    $drift,
                    $payload,
                );
            }

            return $log;
        });
    }

    public function success(): static
    {
        return $this->with(['status' => AlertLog::STATUS_SUCCESS]);
    }

    public function failure(string $errorMessage = 'Test error'): static
    {
        return $this->with([
            'status' => AlertLog::STATUS_FAILURE,
            'errorMessage' => $errorMessage,
        ]);
    }

    public function slack(): static
    {
        return $this->with(['channelType' => AlertChannelType::Slack]);
    }

    public function webhook(): static
    {
        return $this->with(['channelType' => AlertChannelType::Webhook]);
    }

    public function email(): static
    {
        return $this->with(['channelType' => AlertChannelType::Email]);
    }
}
