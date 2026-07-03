<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Enum\DataProtectionStrategy;
use App\Enum\LogLevel;
use App\Enum\TokenMode;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\UserFactory;
use App\Tests\Factories\UserTokenAccessFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class TokenControllerTest extends WebTestCase
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

    // ==================== LIST TESTS ====================

    public function testTokenListReturns200(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/tokens');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'API Tokens');
    }

    public function testTokenListShowsEmptyState(): void
    {
        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No tokens found', $crawler->text());
    }

    public function testTokenListShowsTokens(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Production API']);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Production API', $crawler->text());
    }

    public function testTokenListFiltersBySearch(): void
    {
        ApiTokenFactory::createOne(['name' => 'Production API']);
        ApiTokenFactory::createOne(['name' => 'Staging API']);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens?search=Production');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Production API', $crawler->text());
        self::assertStringNotContainsString('Staging API', $crawler->text());
    }

    public function testTokenListFiltersByMode(): void
    {
        ApiTokenFactory::new()->learning()->create(['name' => 'Learning Token']);
        ApiTokenFactory::new()->validating()->create(['name' => 'Validating Token']);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens?mode=learning');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Learning Token', $crawler->text());
        self::assertStringNotContainsString('Validating Token', $crawler->text());
    }

    public function testTokenListFiltersByStatus(): void
    {
        ApiTokenFactory::createOne(['name' => 'Active Token', 'isActive' => true]);
        ApiTokenFactory::new()->inactive()->create(['name' => 'Inactive Token']);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens?status=active');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Active Token', $crawler->text());
        self::assertStringNotContainsString('Inactive Token', $crawler->text());
    }

    public function testTokenListOnlyShowsAccessibleTokensForNonAdmin(): void
    {
        $accessibleToken = ApiTokenFactory::createOne(['name' => 'Accessible Token']);
        ApiTokenFactory::createOne(['name' => 'Inaccessible Token']);

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $accessibleToken,
        ]);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/tokens');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Accessible Token', $crawler->text());
        self::assertStringNotContainsString('Inaccessible Token', $crawler->text());
    }

    public function testTokenListShowsPagination(): void
    {
        ApiTokenFactory::createMany(30);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.pagination');
    }

    // ==================== CREATE TESTS ====================

    public function testTokenCreatePageReturns200ForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/tokens/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="api_token"]');
    }

    public function testTokenCreatePageReturns403ForNonAdmin(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/tokens/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTokenCreateWithMinimalData(): void
    {
        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens/new');

        $form = $crawler->selectButton('Create Token')->form([
            'api_token[name]' => 'New Test Token',
            'api_token[mode]' => TokenMode::Passive->value,
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'created');
    }

    public function testTokenCreateWithFullData(): void
    {
        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens/new');

        $form = $crawler->selectButton('Create Token')->form([
            'api_token[name]' => 'Full Config Token',
            'api_token[allowedTargets]' => "api.stripe.com\n*.example.com",
            'api_token[mode]' => TokenMode::Learning->value,
            'api_token[dataProtectionStrategy]' => DataProtectionStrategy::RedactEncrypt->value,
            'api_token[logLevel]' => LogLevel::FullAudit->value,
            'api_token[learningThreshold]' => '50',
            'api_token[autoSwitchToValidating]' => true,
            'api_token[validateRequestBody]' => true,
            'api_token[isActive]' => true,
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'created');
    }

    public function testTokenCreateValidatesRequiredFields(): void
    {
        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens/new');

        $form = $crawler->selectButton('Create Token')->form([
            'api_token[name]' => '',
            'api_token[mode]' => TokenMode::Passive->value,
        ]);

        $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSelectorExists('.invalid-feedback');
    }

    public function testTokenCreateShowsPlainTokenOnlyOnce(): void
    {
        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens/new');

        $form = $crawler->selectButton('Create Token')->form([
            'api_token[name]' => 'One-Time Token Display',
            'api_token[mode]' => TokenMode::Passive->value,
        ]);

        $this->client->submit($form);
        $this->client->followRedirect();

        // The token is shown in the alert with input field
        self::assertSelectorExists('#tokenValue');

        // Refresh the page - token should no longer be visible
        $this->client->request('GET', $this->client->getRequest()->getUri());

        self::assertSelectorNotExists('#tokenValue');
    }

    // ==================== SHOW/EDIT TESTS ====================

    public function testTokenShowReturns200(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Test Token']);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/tokens/' . $token->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Test Token');
    }

    public function testTokenShowReturns404ForNonExistent(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/tokens/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTokenShowReturns403ForUnauthorizedUser(): void
    {
        $token = ApiTokenFactory::createOne();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/tokens/' . $token->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testTokenShowAllowsUserWithTokenAccess(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Accessible Token']);

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $token,
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/tokens/' . $token->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Accessible Token');
    }

    public function testTokenShowDisplaysEditFormForAdmin(): void
    {
        $token = ApiTokenFactory::createOne();

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/tokens/' . $token->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="api_token"]');
    }

    public function testTokenShowHidesEditFormForNonAdmin(): void
    {
        $token = ApiTokenFactory::createOne();

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $token,
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/tokens/' . $token->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[name="api_token"]');
    }

    public function testTokenUpdateChangesName(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Original Name']);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens/' . $token->getId());

        $form = $crawler->selectButton('Save Changes')->form([
            'api_token[name]' => 'Updated Name',
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'updated');
    }

    public function testTokenUpdateChangesMode(): void
    {
        $token = ApiTokenFactory::new()->passive()->create();

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens/' . $token->getId());

        $form = $crawler->selectButton('Save Changes')->form([
            'api_token[mode]' => TokenMode::Learning->value,
            'api_token[learningThreshold]' => '25',
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'updated');
    }

    public function testTokenUpdateChangesAllowedTargets(): void
    {
        $token = ApiTokenFactory::new()->withAllowedTargets(['old.api.com'])->create();

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens/' . $token->getId());

        $form = $crawler->selectButton('Save Changes')->form([
            'api_token[allowedTargets]' => "new.api.com\n*.stripe.com",
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'updated');
    }

    // ==================== TOGGLE TESTS ====================

    public function testTokenToggleDeactivatesActiveToken(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Active Token', 'isActive' => true]);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens');

        $csrfToken = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/dashboard/tokens/' . $token->getId() . '/toggle', [
            '_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/dashboard/tokens');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'deactivated');
    }

    public function testTokenToggleActivatesInactiveToken(): void
    {
        $token = ApiTokenFactory::new()->inactive()->create(['name' => 'Inactive Token']);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens');

        $csrfToken = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/dashboard/tokens/' . $token->getId() . '/toggle', [
            '_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/dashboard/tokens');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'activated');
    }

    public function testTokenToggleRequiresCsrfToken(): void
    {
        $token = ApiTokenFactory::createOne();

        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/dashboard/tokens/' . $token->getId() . '/toggle', [
            '_token' => 'invalid-csrf-token',
        ]);

        self::assertResponseRedirects('/dashboard/tokens');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Invalid CSRF token');
    }

    public function testTokenToggleRequiresAdmin(): void
    {
        $token = ApiTokenFactory::createOne();

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $token,
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/dashboard/tokens/' . $token->getId() . '/toggle', [
            '_token' => 'any-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== DELETE TESTS ====================

    public function testTokenDeleteRemovesToken(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Token To Delete']);
        $tokenId = $token->getId()->toRfc4122();

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/tokens/' . $tokenId);

        $csrfToken = $crawler->filter('input[name="_token"]')->last()->attr('value');

        $this->client->request('POST', '/dashboard/tokens/' . $tokenId . '/delete', [
            '_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/dashboard/tokens');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'deleted');

        $this->client->request('GET', '/dashboard/tokens/' . $tokenId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTokenDeleteRequiresCsrfToken(): void
    {
        $token = ApiTokenFactory::createOne();

        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/dashboard/tokens/' . $token->getId() . '/delete', [
            '_token' => 'invalid-csrf-token',
        ]);

        self::assertResponseRedirects('/dashboard/tokens/' . $token->getId());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Invalid CSRF token');
    }

    public function testTokenDeleteRequiresAdmin(): void
    {
        $token = ApiTokenFactory::createOne();

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $token,
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/dashboard/tokens/' . $token->getId() . '/delete', [
            '_token' => 'any-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTokenDeleteReturns404ForNonExistent(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/dashboard/tokens/00000000-0000-0000-0000-000000000000/delete', [
            '_token' => 'any-token',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ==================== ACTIVITY TESTS ====================

    public function testTokenActivityReturns200(): void
    {
        $token = ApiTokenFactory::createOne();

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/tokens/' . $token->getId() . '/activity');

        self::assertResponseIsSuccessful();
    }

    public function testTokenActivityReturns403ForUnauthorizedUser(): void
    {
        $token = ApiTokenFactory::createOne();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/tokens/' . $token->getId() . '/activity');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTokenActivityAllowsUserWithTokenAccess(): void
    {
        $token = ApiTokenFactory::createOne();

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $token,
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/tokens/' . $token->getId() . '/activity');

        self::assertResponseIsSuccessful();
    }

    // ==================== AUTHENTICATION TESTS ====================

    public function testUnauthenticatedUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/dashboard/tokens');

        self::assertResponseRedirects('/login');
    }

    public function testUnauthenticatedUserCannotCreateToken(): void
    {
        $this->client->request('GET', '/dashboard/tokens/new');

        self::assertResponseRedirects('/login');
    }
}
