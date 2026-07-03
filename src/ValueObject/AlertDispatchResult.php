<?php

declare(strict_types=1);

namespace App\ValueObject;

final readonly class AlertDispatchResult
{
    /**
     * @param list<AlertResult> $results
     */
    private function __construct(
        public array $results,
        public bool $skippedDueToSeverity,
    ) {
    }

    /**
     * @param list<AlertResult> $results
     */
    public static function fromResults(array $results): self
    {
        return new self($results, skippedDueToSeverity: false);
    }

    public static function skippedDueToSeverity(): self
    {
        return new self([], skippedDueToSeverity: true);
    }

    public static function noChannelsConfigured(): self
    {
        return new self([], skippedDueToSeverity: false);
    }

    public function hasSuccesses(): bool
    {
        foreach ($this->results as $result) {
            if ($result->isSuccess()) {
                return true;
            }
        }

        return false;
    }

    public function hasFailures(): bool
    {
        foreach ($this->results as $result) {
            if ($result->isFailure()) {
                return true;
            }
        }

        return false;
    }

    public function getSuccessCount(): int
    {
        $count = 0;
        foreach ($this->results as $result) {
            if ($result->isSuccess()) {
                $count++;
            }
        }

        return $count;
    }

    public function getFailureCount(): int
    {
        $count = 0;
        foreach ($this->results as $result) {
            if ($result->isFailure()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<AlertResult>
     */
    public function getSuccessfulResults(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (AlertResult $r) => $r->isSuccess(),
        ));
    }

    /**
     * @return list<AlertResult>
     */
    public function getFailedResults(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (AlertResult $r) => $r->isFailure(),
        ));
    }
}
