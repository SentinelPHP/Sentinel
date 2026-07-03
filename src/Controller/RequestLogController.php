<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\RequestLogRepository;
use App\Service\AccessControl\AccessControlServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use SentinelPHP\Encrypt\EncryptorInterface;

#[Route('/dashboard/logs')]
#[IsGranted('ROLE_USER')]
class RequestLogController extends AbstractController
{
    private const int DEFAULT_PAGE_SIZE = 25;
    private const int MAX_EXPORT_RECORDS = 10000;
    private const int LOG_RETENTION_DAYS = 30;

    public function __construct(
        private readonly RequestLogRepository $requestLogRepository,
        private readonly AccessControlServiceInterface $accessControlService,
        private readonly EncryptorInterface $encryptor,
    ) {
    }

    #[Route('', name: 'dashboard_logs')]
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

        $logs = $this->requestLogRepository->findWithFilters($filters, $limit, $offset);
        $totalCount = $this->requestLogRepository->countWithFilters($filters);
        $totalPages = (int) ceil($totalCount / $limit);

        $targetHosts = $this->requestLogRepository->getDistinctTargetHosts($tokenIds);
        $methods = $this->requestLogRepository->getDistinctMethods($tokenIds);

        return $this->render('dashboard/logs/index.html.twig', [
            'logs' => $logs,
            'tokens' => $accessibleTokens,
            'targetHosts' => $targetHosts,
            'methods' => $methods,
            'filters' => [
                'tokenId' => $request->query->get('token_id'),
                'targetHost' => $request->query->get('target_host'),
                'method' => $request->query->get('method'),
                'pathSearch' => $request->query->get('path_search'),
                'statusCodeRange' => $request->query->get('status_code_range'),
                'latencyMin' => $request->query->get('latency_min'),
                'latencyMax' => $request->query->get('latency_max'),
                'hasDrift' => $request->query->get('has_drift'),
                'from' => $request->query->get('from'),
                'to' => $request->query->get('to'),
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalCount' => $totalCount,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1,
            ],
            'retentionDays' => self::LOG_RETENTION_DAYS,
        ]);
    }

    #[Route('/{id}', name: 'dashboard_logs_show', requirements: ['id' => '[0-9a-f-]+'])]
    public function show(string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $log = $this->requestLogRepository->find(Uuid::fromString($id));

        if ($log === null) {
            throw $this->createNotFoundException('Request log not found.');
        }

        $token = $log->getToken();
        if ($token !== null && !$this->accessControlService->canViewToken($user, $token)) {
            throw $this->createAccessDeniedException('You do not have access to view this request log.');
        }

        $retentionDate = (new \DateTimeImmutable())->modify('+' . self::LOG_RETENTION_DAYS . ' days');
        $deleteDate = $log->getCreatedAt()->modify('+' . self::LOG_RETENTION_DAYS . ' days');
        $daysUntilDeletion = max(0, (int) $deleteDate->diff(new \DateTimeImmutable())->days);
        if ($deleteDate < new \DateTimeImmutable()) {
            $daysUntilDeletion = 0;
        }

        return $this->render('dashboard/logs/show.html.twig', [
            'log' => $log,
            'daysUntilDeletion' => $daysUntilDeletion,
            'retentionDays' => self::LOG_RETENTION_DAYS,
        ]);
    }

    #[Route('/{id}/decrypt', name: 'dashboard_logs_decrypt', requirements: ['id' => '[0-9a-f-]+'], methods: ['POST'])]
    public function decrypt(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $log = $this->requestLogRepository->find(Uuid::fromString($id));

        if ($log === null) {
            return new JsonResponse(['error' => 'Request log not found.'], Response::HTTP_NOT_FOUND);
        }

        $token = $log->getToken();
        if ($token !== null && !$this->accessControlService->canViewToken($user, $token)) {
            return new JsonResponse(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        if (!$log->isEncrypted()) {
            return new JsonResponse(['error' => 'Log is not encrypted.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->encryptor->isEnabled()) {
            return new JsonResponse(['error' => 'Decryption is not available. Encryption key not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $decryptedData = [
                'requestHeaders' => $this->decryptAndDecompress($log->getRequestHeaders(), $log->isCompressed()),
                'requestBody' => $this->decryptAndDecompress($log->getRequestBody(), $log->isCompressed()),
                'responseHeaders' => $this->decryptAndDecompress($log->getResponseHeaders(), $log->isCompressed()),
                'responseBody' => $this->decryptAndDecompress($log->getResponseBody(), $log->isCompressed()),
            ];

            return new JsonResponse($decryptedData);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Decryption failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function decryptAndDecompress(?string $data, bool $isCompressed): ?string
    {
        if ($data === null || $data === '') {
            return null;
        }

        $decrypted = $this->encryptor->decrypt($data);

        if ($isCompressed && str_starts_with($decrypted, 'gzip:')) {
            $encoded = substr($decrypted, 5);
            $decoded = base64_decode($encoded, true);
            if ($decoded !== false) {
                $decompressed = @gzdecode($decoded);
                if ($decompressed !== false) {
                    return $decompressed;
                }
            }
        }

        return $decrypted;
    }

    #[Route('/export.csv', name: 'dashboard_logs_export_csv', priority: 10)]
    public function exportCsv(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $accessibleTokens = $this->accessControlService->getAccessibleTokens($user);
        $tokenIds = array_map(static fn ($token) => $token->getId(), $accessibleTokens);

        $filters = $this->buildFilters($request, $tokenIds);
        $logs = $this->requestLogRepository->findWithFilters($filters, self::MAX_EXPORT_RECORDS, 0);

        $response = new StreamedResponse(function () use ($logs) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'ID',
                'Timestamp',
                'Token',
                'Target Host',
                'Method',
                'Path',
                'Status Code',
                'Latency (ms)',
                'Drift Detected',
            ], ',', '"', '\\');

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->getId()->toRfc4122(),
                    $log->getCreatedAt()->format('Y-m-d H:i:s'),
                    $log->getToken()?->getName() ?? 'N/A',
                    $log->getTargetHost(),
                    $log->getRequestMethod(),
                    $log->getRequestPath(),
                    $log->getResponseStatusCode(),
                    $log->getLatencyMs(),
                    $log->isDriftDetected() ? 'Yes' : 'No',
                ], ',', '"', '\\');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="request-logs-' . date('Y-m-d-His') . '.csv"');

        return $response;
    }

    #[Route('/export.json', name: 'dashboard_logs_export_json', priority: 10)]
    public function exportJson(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $accessibleTokens = $this->accessControlService->getAccessibleTokens($user);
        $tokenIds = array_map(static fn ($token) => $token->getId(), $accessibleTokens);

        $filters = $this->buildFilters($request, $tokenIds);
        $logs = $this->requestLogRepository->findWithFilters($filters, self::MAX_EXPORT_RECORDS, 0);

        $response = new StreamedResponse(function () use ($logs) {
            echo "[\n";
            $first = true;
            foreach ($logs as $log) {
                if (!$first) {
                    echo ",\n";
                }
                $first = false;

                echo json_encode([
                    'id' => $log->getId()->toRfc4122(),
                    'timestamp' => $log->getCreatedAt()->format('c'),
                    'token' => $log->getToken()?->getName(),
                    'targetHost' => $log->getTargetHost(),
                    'method' => $log->getRequestMethod(),
                    'path' => $log->getRequestPath(),
                    'statusCode' => $log->getResponseStatusCode(),
                    'latencyMs' => $log->getLatencyMs(),
                    'driftDetected' => $log->isDriftDetected(),
                ], JSON_PRETTY_PRINT);
            }
            echo "\n]";
        });

        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="request-logs-' . date('Y-m-d-His') . '.json"');

        return $response;
    }

    /**
     * @param list<Uuid> $accessibleTokenIds
     * @return array{
     *     tokenIds?: list<Uuid>,
     *     tokenId?: Uuid,
     *     targetHost?: string,
     *     method?: string,
     *     pathSearch?: string,
     *     statusCodeRange?: string,
     *     latencyMin?: int,
     *     latencyMax?: int,
     *     hasDrift?: bool,
     *     from?: \DateTimeImmutable,
     *     to?: \DateTimeImmutable
     * }
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

        $method = $request->query->get('method');
        if ($method !== null && $method !== '') {
            $filters['method'] = $method;
        }

        $pathSearch = $request->query->get('path_search');
        if ($pathSearch !== null && $pathSearch !== '') {
            $filters['pathSearch'] = $pathSearch;
        }

        $statusCodeRange = $request->query->get('status_code_range');
        if ($statusCodeRange !== null && $statusCodeRange !== '' && in_array($statusCodeRange, ['2xx', '3xx', '4xx', '5xx'], true)) {
            $filters['statusCodeRange'] = $statusCodeRange;
        }

        $latencyMin = $request->query->get('latency_min');
        if ($latencyMin !== null && $latencyMin !== '' && is_numeric($latencyMin)) {
            $filters['latencyMin'] = (int) $latencyMin;
        }

        $latencyMax = $request->query->get('latency_max');
        if ($latencyMax !== null && $latencyMax !== '' && is_numeric($latencyMax)) {
            $filters['latencyMax'] = (int) $latencyMax;
        }

        $hasDrift = $request->query->get('has_drift');
        if ($hasDrift !== null && $hasDrift !== '') {
            $filters['hasDrift'] = $hasDrift === '1' || $hasDrift === 'true';
        }

        $from = $request->query->get('from');
        if ($from !== null && $from !== '') {
            $filters['from'] = new \DateTimeImmutable($from . ' 00:00:00');
        }

        $to = $request->query->get('to');
        if ($to !== null && $to !== '') {
            $filters['to'] = new \DateTimeImmutable($to . ' 23:59:59');
        }

        return $filters;
    }
}
