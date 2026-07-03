<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

final class IpAccessChecker implements IpAccessCheckerInterface
{
    private const array DEFAULT_PRIVATE_RANGES = [
        '127.0.0.0/8',      // IPv4 loopback
        '10.0.0.0/8',       // Private class A
        '172.16.0.0/12',    // Private class B
        '192.168.0.0/16',   // Private class C
        '169.254.0.0/16',   // Link-local
        '::1/128',          // IPv6 loopback
        'fc00::/7',         // IPv6 unique local
        'fe80::/10',        // IPv6 link-local
    ];

    /** @var string[] */
    private readonly array $allowedIps;

    /**
     * @param string[]|null $allowedIps List of allowed IPs/CIDR ranges. Null uses default private ranges.
     */
    public function __construct(?array $allowedIps = null)
    {
        $this->allowedIps = $allowedIps ?? self::DEFAULT_PRIVATE_RANGES;
    }

    public function isAllowed(Request $request): bool
    {
        $clientIp = $request->getClientIp();

        if ($clientIp === null) {
            return false;
        }

        return IpUtils::checkIp($clientIp, $this->allowedIps);
    }

    public function isAllowedIp(string $ip): bool
    {
        return IpUtils::checkIp($ip, $this->allowedIps);
    }

    /**
     * @return string[]
     */
    public function getAllowedRanges(): array
    {
        return $this->allowedIps;
    }

    /**
     * @return string[]
     */
    public static function getDefaultPrivateRanges(): array
    {
        return self::DEFAULT_PRIVATE_RANGES;
    }
}
