<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiSchema;
use App\Entity\User;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Event\SchemaPromotedEvent;
use App\Message\GenerateDtoMessage;
use App\Repository\ApiSchemaRepository;
use App\Repository\ApiTokenRepository;
use App\Repository\GeneratedDtoRepository;
use App\Service\AccessControl\AccessControlServiceInterface;
use SentinelPHP\Drift\Diff\JsonDiffInterface;
use App\Service\Dto\DtoGeneratorServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route('/dashboard/schemas')]
#[IsGranted('ROLE_USER')]
final class SchemaController extends AbstractController
{
    private const int DEFAULT_PAGE_SIZE = 25;

    public function __construct(
        private readonly ApiSchemaRepository $schemaRepository,
        private readonly ApiTokenRepository $tokenRepository,
        private readonly AccessControlServiceInterface $accessControlService,
        private readonly JsonDiffInterface $jsonDiffService,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly GeneratedDtoRepository $dtoRepository,
        private readonly DtoGeneratorServiceInterface $dtoGenerator,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route('', name: 'dashboard_schemas', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $accessibleTokens = $this->accessControlService->getAccessibleTokens($user);
        $tokenIds = array_map(static fn ($token) => $token->getId(), $accessibleTokens);

        $filters = $this->buildFilters($request, $tokenIds);
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(10, $request->query->getInt('limit', self::DEFAULT_PAGE_SIZE)));
        $offset = ($page - 1) * $limit;

        $schemas = $this->schemaRepository->findByAccessibleTokens($tokenIds, $filters, $limit, $offset);
        $totalCount = $this->schemaRepository->countByAccessibleTokens($tokenIds, $filters);

        $totalPages = (int) ceil($totalCount / $limit);

        return $this->render('dashboard/schemas/index.html.twig', [
            'schemas' => $schemas,
            'tokens' => $accessibleTokens,
            'filters' => [
                'tokenId' => $request->query->get('token_id'),
                'targetHost' => $request->query->get('target_host'),
                'endpointPath' => $request->query->get('endpoint_path'),
                'httpMethod' => $request->query->get('http_method'),
                'schemaType' => $request->query->get('schema_type'),
                'masterOnly' => $request->query->getBoolean('master_only'),
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalCount' => $totalCount,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1,
            ],
            'schemaTypes' => SchemaType::cases(),
            'httpMethods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        ]);
    }

    #[Route('/{id}', name: 'dashboard_schemas_show', methods: ['GET'], requirements: ['id' => '[0-9a-f-]+'])]
    public function show(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $schema = $this->schemaRepository->find(Uuid::fromString($id));

        if ($schema === null) {
            throw $this->createNotFoundException('Schema not found.');
        }

        if (!$this->accessControlService->canViewSchema($user, $schema)) {
            throw $this->createAccessDeniedException('You do not have access to view this schema.');
        }

        $allVersions = $this->schemaRepository->findAllVersions(
            $schema->getToken()->getId(),
            $schema->getTargetHost(),
            $schema->getEndpointPath(),
            $schema->getHttpMethod(),
            $schema->getSchemaType()
        );

        $selectedVersion = $request->query->getInt('version', $schema->getVersion());
        $selectedSchema = $schema;

        foreach ($allVersions as $version) {
            if ($version->getVersion() === $selectedVersion) {
                $selectedSchema = $version;
                break;
            }
        }

        $currentDto = $this->dtoRepository->findCurrentBySchema($selectedSchema);
        $dtoVersions = $this->dtoRepository->findAllVersions($selectedSchema);

        return $this->render('dashboard/schemas/show.html.twig', [
            'schema' => $selectedSchema,
            'allVersions' => $allVersions,
            'canEdit' => $this->isGranted('ROLE_ADMIN'),
            'currentDto' => $currentDto,
            'dtoVersions' => $dtoVersions,
        ]);
    }

    #[Route('/{id}/versions', name: 'dashboard_schemas_versions', methods: ['GET'], requirements: ['id' => '[0-9a-f-]+'])]
    public function versions(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $schema = $this->schemaRepository->find(Uuid::fromString($id));

        if ($schema === null) {
            throw $this->createNotFoundException('Schema not found.');
        }

        if (!$this->accessControlService->canViewSchema($user, $schema)) {
            throw $this->createAccessDeniedException('You do not have access to view this schema.');
        }

        $allVersions = $this->schemaRepository->findAllVersions(
            $schema->getToken()->getId(),
            $schema->getTargetHost(),
            $schema->getEndpointPath(),
            $schema->getHttpMethod(),
            $schema->getSchemaType()
        );

        $compareFrom = $request->query->getInt('compare_from', 0);
        $compareTo = $request->query->getInt('compare_to', 0);
        $diffResult = null;
        $fromSchema = null;
        $toSchema = null;

        if ($compareFrom > 0 && $compareTo > 0 && $compareFrom !== $compareTo) {
            foreach ($allVersions as $version) {
                if ($version->getVersion() === $compareFrom) {
                    $fromSchema = $version;
                }
                if ($version->getVersion() === $compareTo) {
                    $toSchema = $version;
                }
            }

            if ($fromSchema !== null && $toSchema !== null) {
                $diffResult = $this->jsonDiffService->generateDiff(
                    $fromSchema->getJsonSchema(),
                    $toSchema->getJsonSchema()
                );
            }
        }

        return $this->render('dashboard/schemas/versions.html.twig', [
            'schema' => $schema,
            'allVersions' => $allVersions,
            'compareFrom' => $compareFrom,
            'compareTo' => $compareTo,
            'diffResult' => $diffResult,
            'fromSchema' => $fromSchema,
            'toSchema' => $toSchema,
        ]);
    }

    #[Route('/{id}/promote', name: 'dashboard_schemas_promote', methods: ['POST'], requirements: ['id' => '[0-9a-f-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function promote(string $id, Request $request): Response
    {
        $schema = $this->schemaRepository->find(Uuid::fromString($id));

        if ($schema === null) {
            throw $this->createNotFoundException('Schema not found.');
        }

        if (!$this->isCsrfTokenValid('promote_schema_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('dashboard_schemas_show', ['id' => $id]);
        }

        if ($schema->isMaster()) {
            $this->addFlash('warning', 'This schema is already the master.');
            return $this->redirectToRoute('dashboard_schemas_show', ['id' => $id]);
        }

        // Demote ALL existing masters (handles data integrity issues)
        $currentMasters = $this->schemaRepository->findMasterSchemas(
            $schema->getToken()->getId(),
            $schema->getTargetHost(),
            $schema->getEndpointPath(),
            $schema->getHttpMethod(),
            $schema->getSchemaType()
        );

        $previousMaster = $currentMasters[0] ?? null;

        foreach ($currentMasters as $currentMaster) {
            $currentMaster->setIsMaster(false);
        }

        $schema->setIsMaster(true);
        $this->entityManager->flush();

        $this->schemaRepository->invalidateMasterSchema(
            $schema->getToken()->getId(),
            $schema->getTargetHost(),
            $schema->getEndpointPath(),
            $schema->getHttpMethod(),
            $schema->getSchemaType()
        );

        // Dispatch event after successful promotion
        $this->eventDispatcher->dispatch(new SchemaPromotedEvent($schema, $previousMaster));

        $this->addFlash('success', sprintf('Schema version %d has been promoted to master.', $schema->getVersion()));

        return $this->redirectToRoute('dashboard_schemas_show', ['id' => $id]);
    }

    #[Route('/{id}/export', name: 'dashboard_schemas_export', methods: ['GET'], requirements: ['id' => '[0-9a-f-]+'])]
    public function export(string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $schema = $this->schemaRepository->find(Uuid::fromString($id));

        if ($schema === null) {
            throw $this->createNotFoundException('Schema not found.');
        }

        if (!$this->accessControlService->canViewSchema($user, $schema)) {
            throw $this->createAccessDeniedException('You do not have access to export this schema.');
        }

        $jsonContent = json_encode($schema->getJsonSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($jsonContent === false) {
            throw new \RuntimeException('Failed to encode schema as JSON.');
        }

        $endpoint = preg_replace('/[^a-zA-Z0-9_-]/', '_', $schema->getEndpointPath()) ?? '';
        $filename = sprintf(
            '%s-%s-%s-v%d.json',
            $schema->getHttpMethod(),
            trim($endpoint, '_'),
            $schema->getSchemaType()->value,
            $schema->getVersion()
        );

        $response = new Response($jsonContent);
        $response->headers->set('Content-Type', 'application/json');
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/{id}/generate-dto', name: 'dashboard_schemas_generate_dto', methods: ['POST'], requirements: ['id' => '[0-9a-f-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generateDto(string $id, Request $request): Response
    {
        $schema = $this->schemaRepository->find(Uuid::fromString($id));

        if ($schema === null) {
            throw $this->createNotFoundException('Schema not found.');
        }

        if (!$this->isCsrfTokenValid('generate_dto_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('dashboard_schemas_show', ['id' => $id]);
        }

        $this->messageBus->dispatch(new GenerateDtoMessage($schema->getId()->toRfc4122()));

        $this->addFlash('success', 'DTO generation has been queued. It will appear shortly.');

        return $this->redirectToRoute('dashboard_schemas_show', ['id' => $id]);
    }

    #[Route('/{id}/preview-dto', name: 'dashboard_schemas_preview_dto', methods: ['GET'], requirements: ['id' => '[0-9a-f-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function previewDto(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $schema = $this->schemaRepository->find(Uuid::fromString($id));

        if ($schema === null) {
            return new JsonResponse(['error' => 'Schema not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessControlService->canViewSchema($user, $schema)) {
            return new JsonResponse(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $customClassName = $request->query->getString('class_name');
            $customNamespace = $request->query->getString('namespace');

            $generatedDto = $this->dtoGenerator->generateFromSchema($schema);

            $phpCode = $generatedDto->phpCode;

            if ($customClassName !== '') {
                $phpCode = preg_replace(
                    '/class\s+' . preg_quote($generatedDto->className, '/') . '/',
                    'class ' . $customClassName,
                    $phpCode
                ) ?? $phpCode;
            }

            if ($customNamespace !== '') {
                $phpCode = preg_replace(
                    '/namespace\s+' . preg_quote($generatedDto->namespace, '/') . ';/',
                    'namespace ' . $customNamespace . ';',
                    $phpCode
                ) ?? $phpCode;
            }

            return new JsonResponse([
                'className' => $customClassName !== '' ? $customClassName : $generatedDto->className,
                'namespace' => $customNamespace !== '' ? $customNamespace : $generatedDto->namespace,
                'phpCode' => $phpCode,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Failed to generate DTO: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/import', name: 'dashboard_schemas_import', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function import(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $accessibleTokens = $this->accessControlService->getAccessibleTokens($user);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('import_schema', $request->request->getString('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('dashboard_schemas_import');
            }

            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $uploadedFile */
            $uploadedFile = $request->files->get('schema_file');
            $tokenId = $request->request->getString('token_id');
            $targetHost = $request->request->getString('target_host');
            $endpointPath = $request->request->getString('endpoint_path');
            $httpMethod = strtoupper($request->request->getString('http_method'));
            $schemaType = $request->request->getString('schema_type');
            $setAsMaster = $request->request->getBoolean('set_as_master');

            if ($uploadedFile === null || !$uploadedFile->isValid()) {
                $this->addFlash('error', 'Please upload a valid JSON file.');
                return $this->redirectToRoute('dashboard_schemas_import');
            }

            try {
                $jsonContent = file_get_contents($uploadedFile->getPathname());
                if ($jsonContent === false) {
                    throw new \RuntimeException('Failed to read uploaded file.');
                }
                /** @var array<string, mixed> $jsonSchema */
                $jsonSchema = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->addFlash('error', 'Invalid JSON file: ' . $e->getMessage());
                return $this->redirectToRoute('dashboard_schemas_import');
            }

            $token = $this->tokenRepository->find(Uuid::fromString($tokenId));
            if ($token === null) {
                $this->addFlash('error', 'Invalid token selected.');
                return $this->redirectToRoute('dashboard_schemas_import');
            }

            $existingVersions = $this->schemaRepository->findAllVersions(
                $token->getId(),
                $targetHost,
                $endpointPath,
                $httpMethod,
                SchemaType::from($schemaType)
            );

            $nextVersion = 1;
            if ($existingVersions !== []) {
                $nextVersion = $existingVersions[0]->getVersion() + 1;
            }

            if ($setAsMaster) {
                // Demote ALL existing masters (handles data integrity issues)
                $currentMasters = $this->schemaRepository->findMasterSchemas(
                    $token->getId(),
                    $targetHost,
                    $endpointPath,
                    $httpMethod,
                    SchemaType::from($schemaType)
                );
                foreach ($currentMasters as $currentMaster) {
                    $currentMaster->setIsMaster(false);
                }
            }

            $schema = new ApiSchema();
            $schema->setToken($token);
            $schema->setTargetHost($targetHost);
            $schema->setEndpointPath($endpointPath);
            $schema->setHttpMethod($httpMethod);
            $schema->setSchemaType(SchemaType::from($schemaType));
            $schema->setJsonSchema($jsonSchema);
            $schema->setVersion($nextVersion);
            $schema->setIsMaster($setAsMaster);
            $schema->setSampleCount(0);

            $this->entityManager->persist($schema);
            $this->entityManager->flush();

            if ($setAsMaster) {
                $this->schemaRepository->invalidateMasterSchema(
                    $token->getId(),
                    $targetHost,
                    $endpointPath,
                    $httpMethod,
                    SchemaType::from($schemaType)
                );
            }

            $this->addFlash('success', sprintf('Schema imported successfully as version %d.', $nextVersion));

            return $this->redirectToRoute('dashboard_schemas_show', ['id' => $schema->getId()]);
        }

        return $this->render('dashboard/schemas/import.html.twig', [
            'tokens' => $accessibleTokens,
            'schemaTypes' => SchemaType::cases(),
            'httpMethods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        ]);
    }

    /**
     * @param list<Uuid> $accessibleTokenIds
     * @return array{tokenIds?: list<Uuid>, tokenId?: Uuid, targetHost?: string, endpointPath?: string, httpMethod?: string, schemaType?: SchemaType, masterOnly?: bool}
     */
    private function buildFilters(Request $request, array $accessibleTokenIds): array
    {
        $filters = [];

        if ($accessibleTokenIds !== []) {
            $filters['tokenIds'] = $accessibleTokenIds;
        }

        $tokenId = $request->query->get('token_id');
        if ($tokenId !== null && $tokenId !== '') {
            $tokenUuid = Uuid::fromString($tokenId);
            if (in_array($tokenUuid, $accessibleTokenIds, false)) {
                $filters['tokenId'] = $tokenUuid;
            }
        }

        $targetHost = $request->query->get('target_host');
        if ($targetHost !== null && $targetHost !== '') {
            $filters['targetHost'] = $targetHost;
        }

        $endpointPath = $request->query->get('endpoint_path');
        if ($endpointPath !== null && $endpointPath !== '') {
            $filters['endpointPath'] = $endpointPath;
        }

        $httpMethod = $request->query->get('http_method');
        if ($httpMethod !== null && $httpMethod !== '') {
            $filters['httpMethod'] = strtoupper($httpMethod);
        }

        $schemaType = $request->query->get('schema_type');
        if ($schemaType !== null && $schemaType !== '') {
            $filters['schemaType'] = SchemaType::from($schemaType);
        }

        if ($request->query->getBoolean('master_only')) {
            $filters['masterOnly'] = true;
        }

        return $filters;
    }
}
