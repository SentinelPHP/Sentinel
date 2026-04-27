<?php

declare(strict_types=1);

namespace App\Service\Alert;

use App\Entity\AlertConfiguration;
use App\ValueObject\AlertTestResult;

interface AlertTestServiceInterface
{
    public function sendTestAlert(AlertConfiguration $config): AlertTestResult;
}
