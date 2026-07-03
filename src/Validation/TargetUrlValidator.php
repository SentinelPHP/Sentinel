<?php

declare(strict_types=1);

namespace App\Validation;

final class TargetUrlValidator implements TargetUrlValidatorInterface
{
    private const BLOCKED_HOSTS = [
        'localhost',
        '127.0.0.1',
        '::1',
        '0.0.0.0',
    ];

    /**
     * @param list<string> $allowedHosts Empty array means all non-private hosts are allowed
     */
    public function __construct(
        private readonly array $allowedHosts = [],
    ) {
    }

    public function validate(string $url): TargetUrlValidationResult
    {
        return $this->doValidate($url, false);
    }

    public function validateWithResolvedIp(string $url): TargetUrlValidationResultWithIp
    {
        $result = $this->doValidate($url, true);

        if (!$result->isValid) {
            return TargetUrlValidationResultWithIp::invalid($result->error ?? 'Unknown error');
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $resolvedIp = $this->resolveHostToIp(strtolower(trim($host, '[]')));

        return TargetUrlValidationResultWithIp::valid($resolvedIp);
    }

    private function doValidate(string $url, bool $forIpResolution): TargetUrlValidationResult
    {
        $parsed = parse_url($url);

        if ($parsed === false) {
            return TargetUrlValidationResult::invalid('Invalid URL format');
        }

        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
            return TargetUrlValidationResult::invalid('Only HTTP and HTTPS schemes are allowed');
        }

        if (!isset($parsed['host']) || $parsed['host'] === '') {
            return TargetUrlValidationResult::invalid('URL must contain a host');
        }

        $host = strtolower($parsed['host']);

        // Remove IPv6 brackets for comparison
        $normalizedHost = trim($host, '[]');

        if (in_array($normalizedHost, self::BLOCKED_HOSTS, true)) {
            return TargetUrlValidationResult::invalid('Target host is blocked');
        }

        $ip = $this->resolveHostToIp($normalizedHost);

        if ($ip !== null && $this->isPrivateIp($ip)) {
            return TargetUrlValidationResult::invalid('Target resolves to a private IP address');
        }

        if ($this->allowedHosts !== [] && !$this->isHostAllowed($host)) {
            return TargetUrlValidationResult::invalid('Target host is not in the allowed list');
        }

        return TargetUrlValidationResult::valid();
    }

    private function resolveHostToIp(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $ip = gethostbyname($host);

        if ($ip === $host) {
            return null;
        }

        return $ip;
    }

    private function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->isPrivateIpv4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $this->isPrivateIpv6($ip);
        }

        return false;
    }

    private function isPrivateIpv4(string $ip): bool
    {
        $ipLong = ip2long($ip);

        if ($ipLong === false) {
            return false;
        }

        $privateRanges = [
            ['start' => ip2long('10.0.0.0'), 'end' => ip2long('10.255.255.255')],
            ['start' => ip2long('172.16.0.0'), 'end' => ip2long('172.31.255.255')],
            ['start' => ip2long('192.168.0.0'), 'end' => ip2long('192.168.255.255')],
            ['start' => ip2long('169.254.0.0'), 'end' => ip2long('169.254.255.255')],
            ['start' => ip2long('127.0.0.0'), 'end' => ip2long('127.255.255.255')],
        ];

        foreach ($privateRanges as $range) {
            if ($ipLong >= $range['start'] && $ipLong <= $range['end']) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateIpv6(string $ip): bool
    {
        $ip = strtolower($ip);

        if (str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd')) {
            return true;
        }

        if (str_starts_with($ip, 'fe80')) {
            return true;
        }

        if ($ip === '::1') {
            return true;
        }

        return false;
    }

    private function isHostAllowed(string $host): bool
    {
        return HostMatcher::matches($host, $this->allowedHosts);
    }
}
