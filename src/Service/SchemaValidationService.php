<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\RequestLog;
use App\Entity\SchemaDrift;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Enum\TokenMode;
use App\Event\DriftDetectedEvent;
use App\Message\DriftPayloadMessage;
use App\Repository\ApiSchemaRepositoryInterface;
use App\Service\Alert\AlertDispatcherServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SentinelPHP\Drift\Classifier;
use SentinelPHP\Drift\ClassifierInterface;
use SentinelPHP\Drift\Enum\DriftType as LibraryDriftType;
use SentinelPHP\Schema\Validation\ValidationError;
use SentinelPHP\Schema\ValidatorInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class SchemaValidationService implements SchemaValidationServiceInterface
{
    public function __construct(
        private readonly ValidatorInterface $schemaValidator,
        private readonly ApiSchemaRepositoryInterface $schemaRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClassifierInterface $driftClassifier,
        private readonly MessageBusInterface $messageBus,
        private readonly AlertDispatcherServiceInterface $alertDispatcher,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function validate(
        ApiToken $token,
        string $targetHost,
        string $path,
        string $method,
        string $responseBody,
        ?string $requestBody = null,
        ?string $requestLogId = null,
        ?string $requestHeaders = null,
        ?string $responseHeaders = null,
    ): void {
        if ($token->getMode() !== TokenMode::Validating) {
            return;
        }

        $requestLog = $this->findRequestLog($requestLogId);
        $firstDrift = null;
        $wasValidated = false;

        $result = $this->validateBody(
            $token,
            $targetHost,
            $path,
            $method,
            $responseBody,
            SchemaType::Response,
            $requestLog,
        );
        if ($result !== null) {
            $wasValidated = true;
            if ($result !== []) {
                $firstDrift = $result[0];
            }
        }
        $drifts = $result ?? [];

        if ($token->isValidateRequestBody() && $requestBody !== null) {
            $requestResult = $this->validateBody(
                $token,
                $targetHost,
                $path,
                $method,
                $requestBody,
                SchemaType::Request,
                $requestLog,
            );
            if ($requestResult !== null) {
                $wasValidated = true;
                if ($requestResult !== [] && $firstDrift === null) {
                    $firstDrift = $requestResult[0];
                }
                $drifts = array_merge($drifts, $requestResult);
            }
        }

        if ($wasValidated) {
            $this->updateRequestLogValidationStatus($requestLog, $drifts !== [], $firstDrift);
        }

        if ($drifts !== [] || ($requestLog !== null && $wasValidated)) {
            $this->entityManager->flush();
        }

        if ($drifts !== [] && $requestLogId !== null) {
            $this->dispatchDriftPayloadIfNeeded($token, $requestLogId, $requestBody, $responseBody, $requestHeaders, $responseHeaders);
        }

        $this->dispatchAlertsForDrifts($drifts);
    }

    /**
     * @param list<SchemaDrift> $drifts
     */
    private function dispatchAlertsForDrifts(array $drifts): void
    {
        foreach ($drifts as $drift) {
            $this->alertDispatcher->dispatchAsync($drift);
            $this->eventDispatcher->dispatch(new DriftDetectedEvent($drift));
        }
    }

    private function dispatchDriftPayloadIfNeeded(
        ApiToken $token,
        string $requestLogId,
        ?string $requestBody,
        ?string $responseBody,
        ?string $requestHeaders,
        ?string $responseHeaders,
    ): void {
        $logLevel = $token->getLogLevel();
        if ($logLevel === null || !$logLevel->shouldLogBodiesOnDrift()) {
            return;
        }

        $this->messageBus->dispatch(new DriftPayloadMessage(
            $requestLogId,
            $requestBody,
            $responseBody,
            $logLevel->shouldLogHeadersOnDrift() ? $requestHeaders : null,
            $logLevel->shouldLogHeadersOnDrift() ? $responseHeaders : null,
        ));
    }

    private function findRequestLog(?string $requestLogId): ?RequestLog
    {
        if ($requestLogId === null) {
            return null;
        }

        return $this->entityManager->find(RequestLog::class, Uuid::fromString($requestLogId));
    }

    private function updateRequestLogValidationStatus(
        ?RequestLog $requestLog,
        bool $hasDrifts,
        ?SchemaDrift $firstDrift,
    ): void {
        if ($requestLog === null) {
            return;
        }

        $requestLog->setSchemaValidated(true);
        $requestLog->setDriftDetected($hasDrifts);
        if ($firstDrift !== null) {
            $requestLog->setDrift($firstDrift);
        }
    }

    /**
     * @return list<SchemaDrift>|null Returns null if no schema exists, empty array if valid, or drifts if invalid
     */
    private function validateBody(
        ApiToken $token,
        string $targetHost,
        string $path,
        string $method,
        string $body,
        SchemaType $schemaType,
        ?RequestLog $requestLog,
    ): ?array {
        $masterSchema = $this->schemaRepository->findMasterSchema(
            $token->getId(),
            $targetHost,
            $path,
            strtoupper($method),
            $schemaType
        );

        if ($masterSchema === null) {
            return null;
        }

        $payload = $this->decodeJson($body);
        if ($payload === null) {
            return null;
        }

        $result = $this->schemaValidator->validate($payload, $masterSchema->getJsonSchema());

        if ($result->isValid()) {
            return [];
        }

        return $this->recordDrifts($masterSchema, $token, $result->getErrors(), $requestLog);
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function decodeJson(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return null;
            }
            /** @var array<string, mixed>|list<mixed> $decoded */
            return $decoded;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param list<ValidationError> $errors
     * @return list<SchemaDrift>
     */
    private function recordDrifts(
        ApiSchema $schema,
        ApiToken $token,
        array $errors,
        ?RequestLog $requestLog,
    ): array {
        $drifts = [];

        if (!$this->entityManager->contains($schema)) {
            $managedSchema = $this->entityManager->find(ApiSchema::class, $schema->getId());
            if ($managedSchema === null) {
                return [];
            }
            $schema = $managedSchema;
        }

        foreach ($errors as $error) {
            $driftType = $this->mapKeywordToDriftType($error->keyword);
            $libraryDriftType = LibraryDriftType::from($driftType->value);
            $librarySeverity = $this->driftClassifier->classify($libraryDriftType, $error->expected, $error->actual);
            $severity = DriftSeverity::from($librarySeverity->value);

            $drift = new SchemaDrift();
            $drift->setSchema($schema)
                ->setToken($token)
                ->setRequestLog($requestLog)
                ->setDriftType($driftType)
                ->setPath($error->path)
                ->setExpectedValue($this->wrapValue($error->expected))
                ->setActualValue($this->wrapValue($error->actual))
                ->setSeverity($severity);

            $this->entityManager->persist($drift);
            $drifts[] = $drift;
        }

        return $drifts;
    }

    private function mapKeywordToDriftType(string $keyword): DriftType
    {
        return match ($keyword) {
            'type' => DriftType::TypeChanged,
            'required' => DriftType::FieldRemoved,
            'additionalProperties' => DriftType::FieldAdded,
            default => DriftType::StructureChanged,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function wrapValue(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return ['value' => $value];
    }
}
