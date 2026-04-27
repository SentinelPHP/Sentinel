<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ApiToken;
use App\Message\GenerateDtoMessage;
use App\Repository\ApiSchemaRepository;
use App\Repository\GeneratedDtoRepository;
use App\Security\TokenAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * API endpoints for DTO retrieval and generation.
 *
 * All endpoints require Bearer token authentication.
 */
#[Route('/api/dtos')]
final class DtoController extends AbstractController
{
    private const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly TokenAuthenticatorInterface $tokenAuthenticator,
        private readonly GeneratedDtoRepository $dtoRepository,
        private readonly ApiSchemaRepository $schemaRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    /**
     * List DTOs for the authenticated token.
     *
     * Query parameters:
     * - limit: Number of results (default: 50, max: 100)
     * - offset: Pagination offset (default: 0)
     * - class_name: Filter by class name (partial match)
     * - namespace: Filter by namespace (partial match)
     * - endpoint_path: Filter by endpoint path (partial match)
     * - format: Response format (json, php, base64) - only affects individual DTO endpoints
     */
    #[Route('', name: 'api_dtos_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $authResult = $this->tokenAuthenticator->authenticate($request);

        if (!$authResult->isAuthenticated || $authResult->token === null) {
            return $this->createErrorResponse(
                Response::HTTP_UNAUTHORIZED,
                $authResult->error ?? 'Authentication required'
            );
        }

        $token = $authResult->token;
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', self::DEFAULT_LIMIT)));
        $offset = max(0, $request->query->getInt('offset', 0));

        $filters = $this->buildFilters($request, $token);
        $dtos = $this->dtoRepository->findWithFilters($filters, $limit, $offset);
        $totalCount = $this->dtoRepository->countWithFilters($filters);

        $data = [
            'data' => array_map([$this, 'serializeDto'], $dtos),
            'meta' => [
                'total' => $totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'hasMore' => ($offset + count($dtos)) < $totalCount,
            ],
        ];

        return new JsonResponse($data);
    }

    /**
     * Get a specific DTO by ID.
     *
     * Query parameters:
     * - format: Response format (json, php, base64)
     *   - json: Returns JSON metadata with phpCode field (default)
     *   - php: Returns raw PHP code with text/x-php content type
     *   - base64: Returns JSON with base64-encoded PHP code
     * - version: Specific version to retrieve (default: current)
     */
    #[Route('/{id}', name: 'api_dtos_show', methods: ['GET'], requirements: ['id' => '[0-9a-f-]+'])]
    public function show(string $id, Request $request): Response
    {
        $authResult = $this->tokenAuthenticator->authenticate($request);

        if (!$authResult->isAuthenticated || $authResult->token === null) {
            return $this->createErrorResponse(
                Response::HTTP_UNAUTHORIZED,
                $authResult->error ?? 'Authentication required'
            );
        }

        $dto = $this->dtoRepository->find(Uuid::fromString($id));

        if ($dto === null) {
            return $this->createErrorResponse(Response::HTTP_NOT_FOUND, 'DTO not found');
        }

        if (!$dto->getSchema()->getToken()->getId()->equals($authResult->token->getId())) {
            return $this->createErrorResponse(
                Response::HTTP_FORBIDDEN,
                'You do not have access to this DTO'
            );
        }

        $version = $request->query->getInt('version', 0);
        if ($version > 0 && $version !== $dto->getVersion()) {
            $versionedDto = $this->dtoRepository->findBySchemaAndVersion($dto->getSchema(), $version);
            if ($versionedDto !== null) {
                $dto = $versionedDto;
            }
        }

        $format = $request->query->getString('format', 'json');

        return match ($format) {
            'php' => $this->createPhpResponse($dto->getPhpCode(), $dto->getClassName()),
            'base64' => new JsonResponse($this->serializeDtoBase64($dto)),
            default => new JsonResponse($this->serializeDtoFull($dto)),
        };
    }

    /**
     * Download a DTO as a PHP file.
     *
     * Query parameters:
     * - version: Specific version to download (default: current)
     */
    #[Route('/{id}/download', name: 'api_dtos_download', methods: ['GET'], requirements: ['id' => '[0-9a-f-]+'])]
    public function download(string $id, Request $request): Response
    {
        $authResult = $this->tokenAuthenticator->authenticate($request);

        if (!$authResult->isAuthenticated || $authResult->token === null) {
            return $this->createErrorResponse(
                Response::HTTP_UNAUTHORIZED,
                $authResult->error ?? 'Authentication required'
            );
        }

        $dto = $this->dtoRepository->find(Uuid::fromString($id));

        if ($dto === null) {
            return $this->createErrorResponse(Response::HTTP_NOT_FOUND, 'DTO not found');
        }

        if (!$dto->getSchema()->getToken()->getId()->equals($authResult->token->getId())) {
            return $this->createErrorResponse(
                Response::HTTP_FORBIDDEN,
                'You do not have access to this DTO'
            );
        }

        $version = $request->query->getInt('version', 0);
        if ($version > 0 && $version !== $dto->getVersion()) {
            $versionedDto = $this->dtoRepository->findBySchemaAndVersion($dto->getSchema(), $version);
            if ($versionedDto !== null) {
                $dto = $versionedDto;
            }
        }

        $filename = $dto->getClassName() . '.php';

        $response = new Response($dto->getPhpCode());
        $response->headers->set('Content-Type', 'text/x-php');
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    /**
     * Trigger DTO generation for a schema.
     *
     * Request body (JSON):
     * - schema_id: UUID of the schema to generate a DTO for (required)
     *
     * Returns 202 Accepted on success with the generation job details.
     */
    #[Route('/generate', name: 'api_dtos_generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $authResult = $this->tokenAuthenticator->authenticate($request);

        if (!$authResult->isAuthenticated || $authResult->token === null) {
            return $this->createErrorResponse(
                Response::HTTP_UNAUTHORIZED,
                $authResult->error ?? 'Authentication required'
            );
        }

        $content = $request->getContent();
        if ($content === '') {
            return $this->createErrorResponse(Response::HTTP_BAD_REQUEST, 'Request body is required');
        }

        try {
            /** @var array{schema_id?: string} $data */
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->createErrorResponse(Response::HTTP_BAD_REQUEST, 'Invalid JSON in request body');
        }

        if (!isset($data['schema_id']) || $data['schema_id'] === '') {
            return $this->createErrorResponse(Response::HTTP_BAD_REQUEST, 'schema_id is required');
        }

        $schemaId = $data['schema_id'];

        try {
            $schemaUuid = Uuid::fromString($schemaId);
        } catch (\InvalidArgumentException) {
            return $this->createErrorResponse(Response::HTTP_BAD_REQUEST, 'Invalid schema_id format');
        }

        $schema = $this->schemaRepository->find($schemaUuid);

        if ($schema === null) {
            return $this->createErrorResponse(Response::HTTP_NOT_FOUND, 'Schema not found');
        }

        if (!$schema->getToken()->getId()->equals($authResult->token->getId())) {
            return $this->createErrorResponse(
                Response::HTTP_FORBIDDEN,
                'You do not have access to this schema'
            );
        }

        $this->messageBus->dispatch(new GenerateDtoMessage($schemaId));

        return new JsonResponse([
            'message' => 'DTO generation has been queued',
            'schema_id' => $schemaId,
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * @return array{tokenId: Uuid, className?: string, namespace?: string, endpointPath?: string}
     */
    private function buildFilters(Request $request, ApiToken $token): array
    {
        $filters = [
            'tokenId' => $token->getId(),
        ];

        $className = $request->query->getString('class_name');
        if ($className !== '') {
            $filters['className'] = $className;
        }

        $namespace = $request->query->getString('namespace');
        if ($namespace !== '') {
            $filters['namespace'] = $namespace;
        }

        $endpointPath = $request->query->getString('endpoint_path');
        if ($endpointPath !== '') {
            $filters['endpointPath'] = $endpointPath;
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDto(\App\Entity\GeneratedDto $dto): array
    {
        $schema = $dto->getSchema();

        return [
            'id' => $dto->getId()->toRfc4122(),
            'className' => $dto->getClassName(),
            'namespace' => $dto->getNamespace(),
            'fullyQualifiedClassName' => $dto->getFullyQualifiedClassName(),
            'version' => $dto->getVersion(),
            'isCurrent' => $dto->isCurrent(),
            'checksum' => $dto->getChecksum(),
            'status' => $dto->getStatus()->value,
            'createdAt' => $dto->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'schema' => [
                'id' => $schema->getId()->toRfc4122(),
                'endpointPath' => $schema->getEndpointPath(),
                'httpMethod' => $schema->getHttpMethod(),
                'targetHost' => $schema->getTargetHost(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDtoFull(\App\Entity\GeneratedDto $dto): array
    {
        $data = $this->serializeDto($dto);
        $data['phpCode'] = $dto->getPhpCode();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDtoBase64(\App\Entity\GeneratedDto $dto): array
    {
        $data = $this->serializeDto($dto);
        $data['phpCodeBase64'] = base64_encode($dto->getPhpCode());

        return $data;
    }

    private function createPhpResponse(string $phpCode, string $className): Response
    {
        $response = new Response($phpCode);
        $response->headers->set('Content-Type', 'text/x-php');
        $response->headers->set('X-DTO-Class-Name', $className);

        return $response;
    }

    private function createErrorResponse(int $statusCode, string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => true,
            'message' => $message,
        ], $statusCode);
    }
}
