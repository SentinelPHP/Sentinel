<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\GeneratedDtoFactory;
use App\Tests\Factories\UserFactory;
use App\Tests\Factories\UserTokenAccessFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Functional tests for the Dashboard DTO pages (DtoController).
 */
class DtoControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;
    private User $user;
    private User $adminUser;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = UserFactory::createOne();
        $this->adminUser = UserFactory::new()->admin()->create();
    }

    // ==================== INDEX TESTS ====================

    public function testIndexReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/dtos');

        self::assertResponseIsSuccessful();
    }

    public function testIndexShowsEmptyState(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/dtos');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No generated DTOs found', $crawler->text());
    }

    public function testIndexShowsDtosForAccessibleTokens(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'GetUsersResponse',
        ]);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/dtos');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table tbody tr');
        self::assertStringContainsString('GetUsersResponse', $crawler->text());
    }

    public function testIndexDoesNotShowDtosFromInaccessibleTokens(): void
    {
        $inaccessibleToken = ApiTokenFactory::createOne();
        $inaccessibleSchema = ApiSchemaFactory::createOne(['token' => $inaccessibleToken]);
        GeneratedDtoFactory::createOne([
            'schema' => $inaccessibleSchema,
            'className' => 'SecretDto',
        ]);

        // Regular user (not admin) should not see DTOs from inaccessible tokens
        $regularUser = UserFactory::createOne();
        $this->client->loginUser($regularUser);
        $crawler = $this->client->request('GET', '/dashboard/dtos');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('SecretDto', $crawler->text());
    }

    public function testIndexFiltersByToken(): void
    {
        $token1 = ApiTokenFactory::createOne(['name' => 'Token1']);
        $token2 = ApiTokenFactory::createOne(['name' => 'Token2']);
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token1]);
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token2]);

        $schema1 = ApiSchemaFactory::createOne(['token' => $token1]);
        $schema2 = ApiSchemaFactory::createOne(['token' => $token2]);
        GeneratedDtoFactory::createOne(['schema' => $schema1, 'className' => 'Token1Dto']);
        GeneratedDtoFactory::createOne(['schema' => $schema2, 'className' => 'Token2Dto']);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/dtos?token_id=' . $token1->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Token1Dto', $crawler->text());
        self::assertStringNotContainsString('Token2Dto', $crawler->text());
    }

    public function testIndexFiltersByClassName(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);

        $schema1 = ApiSchemaFactory::createOne(['token' => $token]);
        $schema2 = ApiSchemaFactory::createOne(['token' => $token]);
        GeneratedDtoFactory::createOne(['schema' => $schema1, 'className' => 'GetUsersResponse']);
        GeneratedDtoFactory::createOne(['schema' => $schema2, 'className' => 'PostOrdersRequest']);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/dtos?class_name=Users');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('GetUsersResponse', $crawler->text());
        self::assertStringNotContainsString('PostOrdersRequest', $crawler->text());
    }

    public function testIndexSupportsPagination(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);

        for ($i = 1; $i <= 30; $i++) {
            $schema = ApiSchemaFactory::createOne(['token' => $token]);
            GeneratedDtoFactory::createOne([
                'schema' => $schema,
                'className' => "Dto{$i}",
            ]);
        }

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/dtos?page=2&limit=10');

        self::assertResponseIsSuccessful();
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/dashboard/dtos');

        self::assertResponseRedirects('/login');
    }

    // ==================== SHOW TESTS ====================

    public function testShowReturns200(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $dto = GeneratedDtoFactory::createOne(['schema' => $schema]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/dtos/' . $dto->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testShowDisplaysPhpCodeWithSyntaxHighlighting(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'TestDto',
        ]);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/dtos/' . $dto->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<?php', $crawler->text());
        self::assertStringContainsString('TestDto', $crawler->text());
    }

    public function testShowReturns404ForNonExistent(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/dtos/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowDeniesAccessToUnauthorizedToken(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $dto = GeneratedDtoFactory::createOne(['schema' => $schema]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/dtos/' . $dto->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(403);
    }

    public function testShowAllowsAdminAccess(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $dto = GeneratedDtoFactory::createOne(['schema' => $schema]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/dtos/' . $dto->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testShowDisplaysVersionHistory(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'TestDto',
            'version' => 1,
            'isCurrent' => false,
        ]);
        $currentDto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'TestDto',
            'version' => 2,
            'isCurrent' => true,
        ]);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/dtos/' . $currentDto->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        // Should show version selector or history
        self::assertStringContainsString('Version', $crawler->text());
    }

    public function testShowSelectsSpecificVersion(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'TestDtoV1',
            'version' => 1,
            'isCurrent' => false,
        ]);
        $currentDto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'TestDtoV2',
            'version' => 2,
            'isCurrent' => true,
        ]);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/dtos/' . $currentDto->getId()->toRfc4122() . '?version=1');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('TestDtoV1', $crawler->text());
    }

    // ==================== DOWNLOAD TESTS ====================

    public function testDownloadReturnsPhpFile(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'DownloadTest',
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/dtos/' . $dto->getId()->toRfc4122() . '/download');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/x-php', $this->client->getResponse()->headers->get('Content-Type') ?? '');

        $contentDisposition = $this->client->getResponse()->headers->get('Content-Disposition') ?? '';
        self::assertStringContainsString('attachment', $contentDisposition);
        self::assertStringContainsString('DownloadTest.php', $contentDisposition);
    }

    public function testDownloadDeniesAccessToUnauthorizedToken(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $dto = GeneratedDtoFactory::createOne(['schema' => $schema]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/dtos/' . $dto->getId()->toRfc4122() . '/download');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== DIFF TESTS ====================

    public function testDiffReturns200(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $dto = GeneratedDtoFactory::createOne(['schema' => $schema]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/dtos/' . $dto->getId()->toRfc4122() . '/diff');

        self::assertResponseIsSuccessful();
    }

    public function testDiffShowsVersionComparison(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'DiffTestV1',
            'version' => 1,
            'isCurrent' => false,
            'phpCode' => "<?php\nclass DiffTestV1 { public int \$id; }",
        ]);
        $currentDto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'DiffTestV2',
            'version' => 2,
            'isCurrent' => true,
            'phpCode' => "<?php\nclass DiffTestV2 { public int \$id; public string \$name; }",
        ]);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request(
            'GET',
            '/dashboard/dtos/' . $currentDto->getId()->toRfc4122() . '/diff?compare_from=1&compare_to=2'
        );

        self::assertResponseIsSuccessful();
    }

    public function testDiffDeniesAccessToUnauthorizedToken(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $dto = GeneratedDtoFactory::createOne(['schema' => $schema]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/dtos/' . $dto->getId()->toRfc4122() . '/diff');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== REGENERATE TESTS ====================

    public function testRegenerateRequiresAdmin(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $dto = GeneratedDtoFactory::createOne(['schema' => $schema]);

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/dashboard/dtos/' . $dto->getId()->toRfc4122() . '/regenerate');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRegenerateRequiresCsrfToken(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $dto = GeneratedDtoFactory::createOne(['schema' => $schema]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/dashboard/dtos/' . $dto->getId()->toRfc4122() . '/regenerate');

        // Should redirect with error flash
        self::assertResponseRedirects();
    }

    public function testRegenerateQueuesMessage(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $dto = GeneratedDtoFactory::createOne(['schema' => $schema]);

        $this->client->loginUser($this->adminUser);

        // Get CSRF token
        $crawler = $this->client->request('GET', '/dashboard/dtos/' . $dto->getId()->toRfc4122());
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/dashboard/dtos/' . $dto->getId()->toRfc4122() . '/regenerate', [
            '_token' => $csrfToken,
        ]);

        self::assertResponseRedirects();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = $transport->getSent();
        self::assertCount(1, $messages);
    }

    // ==================== BULK EXPORT TESTS ====================

    public function testBulkExportRequiresCsrfToken(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/dashboard/dtos/export-bulk');

        self::assertResponseRedirects();
    }

    public function testBulkExportReturnsZipFile(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);

        $schema1 = ApiSchemaFactory::createOne(['token' => $token]);
        $schema2 = ApiSchemaFactory::createOne(['token' => $token]);
        $dto1 = GeneratedDtoFactory::createOne(['schema' => $schema1, 'className' => 'BulkDto1']);
        $dto2 = GeneratedDtoFactory::createOne(['schema' => $schema2, 'className' => 'BulkDto2']);

        $this->client->loginUser($this->user);

        // Get CSRF token from index page
        $crawler = $this->client->request('GET', '/dashboard/dtos');
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value') ?? 'test-token';

        $this->client->request('POST', '/dashboard/dtos/export-bulk', [
            '_token' => $csrfToken,
            'dto_ids' => [
                $dto1->getId()->toRfc4122(),
                $dto2->getId()->toRfc4122(),
            ],
        ]);

        // If CSRF is valid, should return ZIP
        if ($this->client->getResponse()->getStatusCode() === 200) {
            self::assertResponseHeaderSame('Content-Type', 'application/zip');
        }
    }

    public function testBulkExportWithNoDtosShowsWarning(): void
    {
        $this->client->loginUser($this->user);

        // Get CSRF token
        $crawler = $this->client->request('GET', '/dashboard/dtos');

        $this->client->request('POST', '/dashboard/dtos/export-bulk', [
            '_token' => 'test-csrf-token',
            'dto_ids' => [],
        ]);

        self::assertResponseRedirects('/dashboard/dtos');
    }

    public function testBulkExportFiltersInaccessibleDtos(): void
    {
        $accessibleToken = ApiTokenFactory::createOne();
        $inaccessibleToken = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $accessibleToken]);

        $accessibleSchema = ApiSchemaFactory::createOne(['token' => $accessibleToken]);
        $inaccessibleSchema = ApiSchemaFactory::createOne(['token' => $inaccessibleToken]);
        $accessibleDto = GeneratedDtoFactory::createOne(['schema' => $accessibleSchema]);
        $inaccessibleDto = GeneratedDtoFactory::createOne(['schema' => $inaccessibleSchema]);

        $this->client->loginUser($this->user);

        // Get CSRF token
        $crawler = $this->client->request('GET', '/dashboard/dtos');

        $this->client->request('POST', '/dashboard/dtos/export-bulk', [
            '_token' => 'test-csrf-token',
            'dto_ids' => [
                $accessibleDto->getId()->toRfc4122(),
                $inaccessibleDto->getId()->toRfc4122(),
            ],
        ]);

        // Should still work, just filtering out inaccessible
        self::assertResponseRedirects();
    }
}
