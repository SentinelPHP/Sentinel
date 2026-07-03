<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\DataProtectionStrategy;
use App\Enum\TokenMode;
use App\Tests\Factories\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * E2E Test: Create token → View in list
 */
final class CreateTokenFlowTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testCompleteCreateTokenFlow(): void
    {
        $client = static::createClient();

        // Create admin user
        $admin = UserFactory::new()->admin()->create();
        $client->loginUser($admin);

        // Step 1: Navigate to tokens list
        $client->request('GET', '/dashboard/tokens');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'API Tokens');

        // Step 2: Navigate to create new token page
        $client->request('GET', '/dashboard/tokens/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="api_token"]');

        // Step 3: Fill out the token form
        $crawler = $client->getCrawler();
        $form = $crawler->selectButton('Create Token')->form([
            'api_token[name]' => 'E2E Test Token',
            'api_token[allowedTargets]' => "api.stripe.com\napi.github.com",
            'api_token[mode]' => TokenMode::Learning->value,
            'api_token[dataProtectionStrategy]' => DataProtectionStrategy::RedactEncrypt->value,
            'api_token[learningThreshold]' => '100',
            'api_token[autoSwitchToValidating]' => true,
            'api_token[validateRequestBody]' => true,
            'api_token[isActive]' => true,
        ]);

        $client->submit($form);

        // Step 4: Follow redirect after creation
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Step 5: Verify success message
        self::assertSelectorTextContains('.alert-success', 'created');

        // Step 6: Verify token value is displayed (one-time display)
        self::assertSelectorExists('#tokenValue');

        // Step 7: Navigate back to tokens list
        $client->request('GET', '/dashboard/tokens');
        self::assertResponseIsSuccessful();

        // Step 8: Verify new token appears in list
        $crawler = $client->getCrawler();
        self::assertStringContainsString('E2E Test Token', $crawler->text());

        // Step 9: Verify token details are correct
        self::assertStringContainsString('Learning', $crawler->text());
    }

    public function testTokenCreationValidatesRequiredFields(): void
    {
        $client = static::createClient();

        $admin = UserFactory::new()->admin()->create();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/dashboard/tokens/new');

        // Submit form with empty name
        $form = $crawler->selectButton('Create Token')->form([
            'api_token[name]' => '',
            'api_token[mode]' => TokenMode::Passive->value,
        ]);

        $client->submit($form);

        // Should show validation error
        self::assertResponseIsUnprocessable();
        self::assertSelectorExists('.invalid-feedback');
    }

    public function testNonAdminCannotCreateToken(): void
    {
        $client = static::createClient();

        $user = UserFactory::createOne();
        $client->loginUser($user);

        $client->request('GET', '/dashboard/tokens/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTokenValueOnlyShownOnce(): void
    {
        $client = static::createClient();

        $admin = UserFactory::new()->admin()->create();
        $client->loginUser($admin);

        // Create token
        $crawler = $client->request('GET', '/dashboard/tokens/new');
        $form = $crawler->selectButton('Create Token')->form([
            'api_token[name]' => 'One-Time Display Token',
            'api_token[mode]' => TokenMode::Passive->value,
        ]);

        $client->submit($form);
        $client->followRedirect();

        // Token value should be visible
        self::assertSelectorExists('#tokenValue');

        // Get the current URL (token detail page)
        $tokenDetailUrl = $client->getRequest()->getUri();

        // Refresh the page
        $client->request('GET', $tokenDetailUrl);

        // Token value should no longer be visible
        self::assertSelectorNotExists('#tokenValue');
    }

    public function testCreatedTokenCanBeEdited(): void
    {
        $client = static::createClient();

        $admin = UserFactory::new()->admin()->create();
        $client->loginUser($admin);

        // Create token
        $crawler = $client->request('GET', '/dashboard/tokens/new');
        $form = $crawler->selectButton('Create Token')->form([
            'api_token[name]' => 'Editable Token',
            'api_token[mode]' => TokenMode::Passive->value,
        ]);

        $client->submit($form);
        $client->followRedirect();

        // Now edit the token
        $crawler = $client->getCrawler();
        $form = $crawler->selectButton('Save Changes')->form([
            'api_token[name]' => 'Updated Token Name',
        ]);

        $client->submit($form);
        $client->followRedirect();

        // Verify update success
        self::assertSelectorTextContains('.alert-success', 'updated');

        // Verify name changed
        $client->request('GET', '/dashboard/tokens');
        $crawler = $client->getCrawler();
        self::assertStringContainsString('Updated Token Name', $crawler->text());
    }

    public function testCreatedTokenCanBeToggled(): void
    {
        $client = static::createClient();

        $admin = UserFactory::new()->admin()->create();
        $client->loginUser($admin);

        // Create active token
        $crawler = $client->request('GET', '/dashboard/tokens/new');
        $form = $crawler->selectButton('Create Token')->form([
            'api_token[name]' => 'Toggleable Token',
            'api_token[mode]' => TokenMode::Passive->value,
            'api_token[isActive]' => true,
        ]);

        $client->submit($form);
        $client->followRedirect();

        // Go to list and toggle
        $crawler = $client->request('GET', '/dashboard/tokens');
        $csrfToken = $crawler->filter('input[name="_token"]')->first()->attr('value');

        // Find the token row and get its ID from the toggle form
        $toggleForm = $crawler->filter('form[action*="/toggle"]')->first();
        $toggleUrl = $toggleForm->attr('action');
        self::assertNotNull($toggleUrl);

        $client->request('POST', $toggleUrl, ['_token' => $csrfToken]);
        $client->followRedirect();

        // Verify toggle success
        self::assertSelectorTextContains('.alert-success', 'deactivated');
    }
}
