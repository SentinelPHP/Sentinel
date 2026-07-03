<?php

declare(strict_types=1);

namespace App\Service\Alert;

use App\Entity\SchemaDrift;
use App\ValueObject\AlertResult;

interface AlertChannelInterface
{
    /**
     * Send an alert for the given schema drift.
     */
    public function send(SchemaDrift $drift): AlertResult;

    /**
     * Check if this channel supports the given channel type.
     */
    public function supports(string $channelType): bool;

    /**
     * Get the unique name/identifier for this channel.
     */
    public function getName(): string;

    /**
     * Check if this channel is enabled and configured.
     */
    public function isEnabled(): bool;
}
