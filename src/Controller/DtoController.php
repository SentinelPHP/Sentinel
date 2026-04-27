<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Message\GenerateDtoMessage;
use App\Repository\GeneratedDtoRepository;
use App\Service\AccessControl\AccessControlServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/dashboard/dtos')]
#[IsGranted('ROLE_USER')]
final class DtoController extends AbstractController
{
    private const int DEFAULT_PAGE_SIZE = 25;

    public function __construct(
        private readonly GeneratedDtoRepository $dtoRepository,
        private readonly AccessControlServiceInterface $accessControlService,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route('', name: 'dashboard_dtos', methods: ['GET'])]
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

        // If user has no accessible tokens, return empty results
        if ($tokenIds === []) {
            $dtos = [];
            $totalCount = 0;
        } else {
            $dtos = $this->dtoRepository->findWithFilters($filters, $limit, $offset);
            $totalCount = $this->dtoRepository->countWithFilters($filters);
        }

        $totalPages = (int) ceil($totalCount / $limit);

        return $this->render('dashboard/dtos/index.html.twig', [
            'dtos' => $dtos,
            'tokens' => $accessibleTokens,
            'filters' => [
                'tokenId' => $request->query->get('token_id'),
                'className' => $request->query->get('class_name'),
                'namespace' => $request->query->get('namespace'),
                'endpointPath' => $request->query->get('endpoint_path'),
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalCount' => $totalCount,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1,
            ],
        ]);
    }

    #[Route('/{id}', name: 'dashboard_dtos_show', methods: ['GET'], requirements: ['id' => '[0-9a-f-]+'])]
    public function show(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $dto = $this->dtoRepository->find(Uuid::fromString($id));

        if ($dto === null) {
            throw $this->createNotFoundException('DTO not found.');
        }

        if (!$this->accessControlService->canViewSchema($user, $dto->getSchema())) {
            throw $this->createAccessDeniedException('You do not have access to view this DTO.');
        }

        $allVersions = $this->dtoRepository->findAllVersions($dto->getSchema());

        $selectedVersion = $request->query->getInt('version', $dto->getVersion());
        $selectedDto = $dto;

        foreach ($allVersions as $version) {
            if ($version->getVersion() === $selectedVersion) {
                $selectedDto = $version;
                break;
            }
        }

        return $this->render('dashboard/dtos/show.html.twig', [
            'dto' => $selectedDto,
            'allVersions' => $allVersions,
            'canRegenerate' => $this->isGranted('ROLE_ADMIN'),
        ]);
    }

    #[Route('/{id}/download', name: 'dashboard_dtos_download', methods: ['GET'], requirements: ['id' => '[0-9a-f-]+'])]
    public function download(string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $dto = $this->dtoRepository->find(Uuid::fromString($id));

        if ($dto === null) {
            throw $this->createNotFoundException('DTO not found.');
        }

        if (!$this->accessControlService->canViewSchema($user, $dto->getSchema())) {
            throw $this->createAccessDeniedException('You do not have access to download this DTO.');
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

    #[Route('/{id}/diff', name: 'dashboard_dtos_diff', methods: ['GET'], requirements: ['id' => '[0-9a-f-]+'])]
    public function diff(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $dto = $this->dtoRepository->find(Uuid::fromString($id));

        if ($dto === null) {
            throw $this->createNotFoundException('DTO not found.');
        }

        if (!$this->accessControlService->canViewSchema($user, $dto->getSchema())) {
            throw $this->createAccessDeniedException('You do not have access to view this DTO.');
        }

        $allVersions = $this->dtoRepository->findAllVersions($dto->getSchema());

        $compareFrom = $request->query->getInt('compare_from', 0);
        $compareTo = $request->query->getInt('compare_to', 0);
        $fromDto = null;
        $toDto = null;

        if ($compareFrom > 0 && $compareTo > 0 && $compareFrom !== $compareTo) {
            foreach ($allVersions as $version) {
                if ($version->getVersion() === $compareFrom) {
                    $fromDto = $version;
                }
                if ($version->getVersion() === $compareTo) {
                    $toDto = $version;
                }
            }
        }

        return $this->render('dashboard/dtos/diff.html.twig', [
            'dto' => $dto,
            'allVersions' => $allVersions,
            'compareFrom' => $compareFrom,
            'compareTo' => $compareTo,
            'fromDto' => $fromDto,
            'toDto' => $toDto,
        ]);
    }

    #[Route('/{id}/regenerate', name: 'dashboard_dtos_regenerate', methods: ['POST'], requirements: ['id' => '[0-9a-f-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function regenerate(string $id, Request $request): Response
    {
        $dto = $this->dtoRepository->find(Uuid::fromString($id));

        if ($dto === null) {
            throw $this->createNotFoundException('DTO not found.');
        }

        if (!$this->isCsrfTokenValid('regenerate_dto_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('dashboard_dtos_show', ['id' => $id]);
        }

        $this->messageBus->dispatch(new GenerateDtoMessage($dto->getSchema()->getId()->toRfc4122()));

        $this->addFlash('success', sprintf('DTO regeneration for "%s" has been queued.', $dto->getClassName()));

        return $this->redirectToRoute('dashboard_dtos_show', ['id' => $id]);
    }

    #[Route('/export-bulk', name: 'dashboard_dtos_export_bulk', methods: ['POST'])]
    public function exportBulk(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('export_bulk_dtos', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('dashboard_dtos');
        }

        /** @var list<string> $dtoIds */
        $dtoIds = $request->request->all('dto_ids');

        if ($dtoIds === []) {
            $this->addFlash('warning', 'No DTOs selected for export.');
            return $this->redirectToRoute('dashboard_dtos');
        }

        $dtos = [];
        foreach ($dtoIds as $dtoId) {
            $dto = $this->dtoRepository->find(Uuid::fromString($dtoId));
            if ($dto !== null && $this->accessControlService->canViewSchema($user, $dto->getSchema())) {
                $dtos[] = $dto;
            }
        }

        if ($dtos === []) {
            $this->addFlash('error', 'No accessible DTOs found.');
            return $this->redirectToRoute('dashboard_dtos');
        }

        $response = new StreamedResponse(function () use ($dtos): void {
            $zipFile = tempnam(sys_get_temp_dir(), 'dto_export_');
            if ($zipFile === false) {
                throw new \RuntimeException('Failed to create temporary file.');
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to create ZIP archive.');
            }

            foreach ($dtos as $dto) {
                $relativePath = str_replace('\\', '/', $dto->getNamespace());
                $filePath = $relativePath . '/' . $dto->getClassName() . '.php';
                $zip->addFromString($filePath, $dto->getPhpCode());
            }

            $zip->close();

            readfile($zipFile);
            unlink($zipFile);
        });

        $response->headers->set('Content-Type', 'application/zip');
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'dtos-export-' . date('Y-m-d-His') . '.zip'
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    /**
     * @param list<Uuid> $accessibleTokenIds
     * @return array{tokenIds?: list<Uuid>, tokenId?: Uuid, className?: string, namespace?: string, endpointPath?: string}
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

        $className = $request->query->get('class_name');
        if ($className !== null && $className !== '') {
            $filters['className'] = $className;
        }

        $namespace = $request->query->get('namespace');
        if ($namespace !== null && $namespace !== '') {
            $filters['namespace'] = $namespace;
        }

        $endpointPath = $request->query->get('endpoint_path');
        if ($endpointPath !== null && $endpointPath !== '') {
            $filters['endpointPath'] = $endpointPath;
        }

        return $filters;
    }
}
