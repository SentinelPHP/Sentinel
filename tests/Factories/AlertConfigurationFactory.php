<?php

declare(strict_types=1);

namespace App\Tests\Factories;

use App\Entity\AlertConfiguration;
use App\Enum\AlertChannelType;
use SentinelPHP\Drift\Enum\DriftSeverity;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<AlertConfiguration>
 */
final class AlertConfigurationFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return AlertConfiguration::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'channelType' => self::faker()->randomElement(AlertChannelType::cases()),
            'channelConfig' => ['webhook_url' => 'https://hooks.example.com/' . self::faker()->uuid()],
            'minSeverity' => self::faker()->randomElement(DriftSeverity::cases()),
            'isActive' => true,
        ];
    }

    public function slack(): static
    {
        return $this->with([
            'channelType' => AlertChannelType::Slack,
            'channelConfig' => ['webhook_url' => 'https://hooks.slack.com/services/' . self::faker()->uuid()],
        ]);
    }

    public function webhook(): static
    {
        return $this->with([
            'channelType' => AlertChannelType::Webhook,
            'channelConfig' => ['url' => 'https://api.example.com/webhook/' . self::faker()->uuid()],
        ]);
    }

    public function email(): static
    {
        return $this->with([
            'channelType' => AlertChannelType::Email,
            'channelConfig' => ['recipients' => [self::faker()->email()]],
        ]);
    }

    public function inactive(): static
    {
        return $this->with(['isActive' => false]);
    }

    public function muted(): static
    {
        return $this->afterInstantiate(function (AlertConfiguration $alert): void {
            $alert->mute(new \DateTimeImmutable('+1 hour'), 'Scheduled maintenance');
        });
    }

    public function global(): static
    {
        return $this->with(['token' => null]);
    }

    public function forToken(\App\Entity\ApiToken $token): static
    {
        return $this->with(['token' => $token]);
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this;
    }
}
