<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

interface IpAccessCheckerInterface
{
    public function isAllowed(Request $request): bool;

    public function isAllowedIp(string $ip): bool;

    /**
     * @return string[]
     */
    public function getAllowedRanges(): array;
}
