<?php

declare(strict_types=1);

namespace App\Service\Alert;

use App\Entity\SchemaDrift;
use App\ValueObject\AlertDispatchResult;

interface AlertDispatcherServiceInterface
{
    /**
     * Dispatch alerts for a schema drift to all configured channels.
     * This method is synchronous and blocks until all channels have been notified.
     *
     * @return AlertDispatchResult Aggregated results from all channels
     */
    public function dispatch(SchemaDrift $drift): AlertDispatchResult;

    /**
     * Queue a drift for async alert dispatch.
     * This method returns immediately and processes alerts in the background.
     */
    public function dispatchAsync(SchemaDrift $drift): void;
}
