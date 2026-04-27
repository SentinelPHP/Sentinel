<?php

declare(strict_types=1);

namespace SentinelPHP\Redact;

final class PiiRedactor implements PiiRedactorInterface
{
    private const CREDIT_CARD_PATTERN = '/\b(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|6(?:011|5[0-9]{2})[0-9]{12}|(?:2131|1800|35\d{3})\d{11})\b/';
    private const CREDIT_CARD_SPACED_PATTERN = '/\b(?:4[0-9]{3}|5[1-5][0-9]{2}|3[47][0-9]{2})[-\s]?[0-9]{4}[-\s]?[0-9]{4}[-\s]?([0-9]{4})\b/';
    private const API_KEY_PATTERN = '/\b(?:Bearer\s+[a-zA-Z0-9._~+\/-]+=*|sk_(?:live|test)_[a-zA-Z0-9]{24,}|pk_(?:live|test)_[a-zA-Z0-9]{24,}|api[_-]?key[_-]?[a-zA-Z0-9]{16,})\b/i';
    private const EMAIL_PATTERN = '/\b([a-zA-Z0-9._%+-])([a-zA-Z0-9._%+-]*)@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})\b/';
    private const PHONE_PATTERN = '/(?:\+?1[-.\s]?)?\(?([0-9]{3})\)?[-.\s]?([0-9]{3})[-.\s]?([0-9]{4})\b/';
    private const SSN_PATTERN = '/\b[0-9]{3}[-\s]?[0-9]{2}[-\s]?([0-9]{4})\b/';

    private const DEFAULT_FIELD_PATHS = [
        '$.password',
        '$.secret',
        '$.token',
        '$.api_key',
        '$.apiKey',
        '$.access_token',
        '$.accessToken',
        '$.refresh_token',
        '$.refreshToken',
        '$.private_key',
        '$.privateKey',
        '$.credit_card',
        '$.creditCard',
        '$.card_number',
        '$.cardNumber',
        '$.cvv',
        '$.ssn',
        '$.social_security',
        '$.socialSecurity',
    ];

    /** @var array<string, array{pattern: string, replacement: string}> */
    private array $patterns = [];

    /** @var array<string, callable> */
    private array $callbackPatterns = [];

    /** @var list<string> */
    private array $defaultFieldPaths;

    /** @var bool Flag to track if patterns have changed since last compilation */
    private bool $patternsModified = true;

    public function __construct(
        ?string $additionalPatternsJson = null,
        ?string $additionalFieldPathsJson = null,
        bool $enableDefaultPatterns = true,
    ) {
        $this->defaultFieldPaths = self::DEFAULT_FIELD_PATHS;

        if ($enableDefaultPatterns) {
            $this->registerDefaultPatterns();
        }

        if ($additionalPatternsJson !== null && $additionalPatternsJson !== '') {
            $this->loadPatternsFromJson($additionalPatternsJson);
        }

        if ($additionalFieldPathsJson !== null && $additionalFieldPathsJson !== '') {
            $this->loadFieldPathsFromJson($additionalFieldPathsJson);
        }

        // Pre-compile patterns after initialization
        $this->compilePatterns();
    }

    public function redactString(string $value, ?array $customPatterns = null): string
    {
        // Ensure patterns are compiled
        if ($this->patternsModified) {
            $this->compilePatterns();
        }

        $result = $value;

        // Apply callback patterns (cannot be pre-compiled into a single regex)
        foreach ($this->callbackPatterns as $name => $callback) {
            $result = (string) preg_replace_callback(
                $this->patterns[$name]['pattern'],
                $callback,
                $result
            );
        }

        // Apply simple patterns using individual pre-compiled regexes
        // (combining into one regex is complex due to replacement differences)
        foreach ($this->patterns as $name => $patternConfig) {
            if (isset($this->callbackPatterns[$name])) {
                continue;
            }
            $result = (string) preg_replace(
                $patternConfig['pattern'],
                $patternConfig['replacement'],
                $result
            );
        }

        // Apply custom patterns (not pre-compiled as they vary per call)
        if ($customPatterns !== null) {
            foreach ($customPatterns as $pattern => $replacement) {
                $result = (string) preg_replace($pattern, $replacement, $result);
            }
        }

        return $result;
    }

    public function redact(
        string|array|object $data,
        ?array $fieldPaths = null,
        ?array $customPatterns = null,
    ): string|array|object {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->redactString($data, $customPatterns);
            }
            /** @var array<mixed> $arrayData */
            $arrayData = $decoded;
            $allFieldPaths = array_merge($this->defaultFieldPaths, $fieldPaths ?? []);
            $arrayData = $this->redactArray($arrayData, $allFieldPaths, $customPatterns, '$');

            return json_encode($arrayData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        if (is_object($data)) {
            /** @var array<mixed> $arrayData */
            $arrayData = json_decode((string) json_encode($data), true);
            $allFieldPaths = array_merge($this->defaultFieldPaths, $fieldPaths ?? []);
            $arrayData = $this->redactArray($arrayData, $allFieldPaths, $customPatterns, '$');

            return (object) $arrayData;
        }

        $allFieldPaths = array_merge($this->defaultFieldPaths, $fieldPaths ?? []);

        return $this->redactArray($data, $allFieldPaths, $customPatterns, '$');
    }

    public function addPattern(string $name, string $pattern, string $replacement): void
    {
        // Validate regex pattern at registration time
        if (@preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException(sprintf('Invalid regex pattern for "%s": %s', $name, preg_last_error_msg()));
        }

        $this->patterns[$name] = [
            'pattern' => $pattern,
            'replacement' => $replacement,
        ];
        unset($this->callbackPatterns[$name]);
        $this->patternsModified = true;
    }

    /**
     * Add a pattern with a callback replacement function.
     *
     * @param string $name Unique name for the pattern
     * @param string $pattern Regex pattern to match
     * @param callable(array<int, string>): string $callback Callback that receives matches and returns replacement
     */
    private function addCallbackPattern(string $name, string $pattern, callable $callback): void
    {
        // Validate regex pattern at registration time
        if (@preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException(sprintf('Invalid regex pattern for "%s": %s', $name, preg_last_error_msg()));
        }

        $this->patterns[$name] = [
            'pattern' => $pattern,
            'replacement' => '',
        ];
        $this->callbackPatterns[$name] = $callback;
        $this->patternsModified = true;
    }

    public function removePattern(string $name): void
    {
        unset($this->patterns[$name]);
        unset($this->callbackPatterns[$name]);
        $this->patternsModified = true;
    }

    /**
     * Pre-compile and validate all registered patterns.
     * This ensures patterns are valid and ready for use.
     */
    private function compilePatterns(): void
    {
        // Validate all patterns are compilable
        foreach ($this->patterns as $name => $config) {
            // Test that the pattern compiles successfully
            if (@preg_match($config['pattern'], '') === false) {
                throw new \RuntimeException(sprintf(
                    'Failed to compile PII redaction pattern "%s": %s',
                    $name,
                    preg_last_error_msg()
                ));
            }
        }

        $this->patternsModified = false;
    }

    public function getPatternNames(): array
    {
        return array_keys($this->patterns);
    }

    /**
     * @return list<string>
     */
    public function getDefaultFieldPaths(): array
    {
        return $this->defaultFieldPaths;
    }

    /**
     * Add a field path to always redact.
     */
    public function addFieldPath(string $path): void
    {
        if (!in_array($path, $this->defaultFieldPaths, true)) {
            $this->defaultFieldPaths[] = $path;
        }
    }

    private function registerDefaultPatterns(): void
    {
        $this->addCallbackPattern(
            'credit_card',
            self::CREDIT_CARD_PATTERN,
            static fn (array $matches): string => self::redactCreditCard((string) $matches[0])
        );

        $this->addPattern(
            'credit_card_spaced',
            self::CREDIT_CARD_SPACED_PATTERN,
            '****-****-****-$1'
        );

        $this->addPattern(
            'api_key',
            self::API_KEY_PATTERN,
            '[REDACTED]'
        );

        $this->addPattern(
            'email',
            self::EMAIL_PATTERN,
            '$1***@$3'
        );

        $this->addPattern(
            'phone',
            self::PHONE_PATTERN,
            '+1-***-***-$3'
        );

        $this->addPattern(
            'ssn',
            self::SSN_PATTERN,
            '***-**-$1'
        );
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $fieldPaths
     * @param array<string, string>|null $customPatterns
     * @return array<mixed>
     */
    private function redactArray(
        array $data,
        array $fieldPaths,
        ?array $customPatterns,
        string $currentPath,
    ): array {
        foreach ($data as $key => $value) {
            $path = $currentPath . '.' . $key;

            if ($this->shouldRedactPath($path, $fieldPaths)) {
                $data[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                if ($this->isAssociativeArray($value)) {
                    $data[$key] = $this->redactArray($value, $fieldPaths, $customPatterns, $path);
                } else {
                    /** @var array<int, mixed> $value */
                    $data[$key] = $this->redactIndexedArray($value, $fieldPaths, $customPatterns, $path);
                }
            } elseif (is_string($value)) {
                $data[$key] = $this->redactString($value, $customPatterns);
            }
        }

        return $data;
    }

    /**
     * @param array<int, mixed> $data
     * @param list<string> $fieldPaths
     * @param array<string, string>|null $customPatterns
     * @return array<int, mixed>
     */
    private function redactIndexedArray(
        array $data,
        array $fieldPaths,
        ?array $customPatterns,
        string $currentPath,
    ): array {
        foreach ($data as $index => $value) {
            $path = $currentPath . '[' . $index . ']';

            if (is_array($value)) {
                if ($this->isAssociativeArray($value)) {
                    $data[$index] = $this->redactArray($value, $fieldPaths, $customPatterns, $path);
                } else {
                    /** @var array<int, mixed> $value */
                    $data[$index] = $this->redactIndexedArray($value, $fieldPaths, $customPatterns, $path);
                }
            } elseif (is_string($value)) {
                $data[$index] = $this->redactString($value, $customPatterns);
            }
        }

        return $data;
    }

    /**
     * @param list<string> $fieldPaths
     */
    private function shouldRedactPath(string $currentPath, array $fieldPaths): bool
    {
        foreach ($fieldPaths as $fieldPath) {
            if ($this->pathMatches($currentPath, $fieldPath)) {
                return true;
            }
        }

        return false;
    }

    private function pathMatches(string $currentPath, string $pattern): bool
    {
        $normalizedCurrent = strtolower($currentPath);
        $normalizedPattern = strtolower($pattern);

        if ($normalizedCurrent === $normalizedPattern) {
            return true;
        }

        $patternParts = explode('.', ltrim($normalizedPattern, '$'));
        $currentParts = explode('.', ltrim($normalizedCurrent, '$'));

        if (count($patternParts) > count($currentParts)) {
            return false;
        }

        $lastPatternPart = end($patternParts);
        $lastCurrentPart = end($currentParts);

        $lastCurrentPart = preg_replace('/\[\d+\]$/', '', $lastCurrentPart);

        return $lastPatternPart === $lastCurrentPart;
    }

    /**
     * @param array<mixed> $array
     */
    private function isAssociativeArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function loadPatternsFromJson(string $json): void
    {
        $patterns = json_decode($json, true);
        if (!is_array($patterns)) {
            return;
        }

        foreach ($patterns as $name => $config) {
            if (is_array($config) && isset($config['pattern'], $config['replacement'])
                && is_string($config['pattern']) && is_string($config['replacement'])) {
                $this->addPattern((string) $name, $config['pattern'], $config['replacement']);
            }
        }
    }

    private function loadFieldPathsFromJson(string $json): void
    {
        $paths = json_decode($json, true);
        if (!is_array($paths)) {
            return;
        }

        foreach ($paths as $path) {
            if (is_string($path) && !in_array($path, $this->defaultFieldPaths, true)) {
                $this->defaultFieldPaths[] = $path;
            }
        }
    }

    /**
     * Redact credit card number, keeping last 4 digits.
     */
    public static function redactCreditCard(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);
        if ($digits === null || strlen($digits) < 4) {
            return '[REDACTED]';
        }

        $last4 = substr($digits, -4);

        return '****-****-****-' . $last4;
    }

    /**
     * Redact email, keeping first char and domain.
     */
    public static function redactEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '[REDACTED]';
        }

        [$local, $domain] = explode('@', $email, 2);
        $firstChar = mb_substr($local, 0, 1);

        return $firstChar . '***@' . $domain;
    }

    /**
     * Redact phone number, keeping last 4 digits.
     */
    public static function redactPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === null || strlen($digits) < 4) {
            return '[REDACTED]';
        }

        $last4 = substr($digits, -4);

        return '+1-***-***-' . $last4;
    }

    /**
     * Redact SSN, keeping last 4 digits.
     */
    public static function redactSsn(string $ssn): string
    {
        $digits = preg_replace('/\D/', '', $ssn);
        if ($digits === null || strlen($digits) < 4) {
            return '[REDACTED]';
        }

        $last4 = substr($digits, -4);

        return '***-**-' . $last4;
    }
}
