<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\SchemaDriftFactory;
use App\Tests\Factories\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * E2E Test: Login → View dashboard → Inspect drift
 */
final class LoginDashboardDriftFlowTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testCompleteLoginDashboardDriftFlow(): void
    {
        $client = static::createClient();

        // Create test data
        $user = UserFactory::new()->admin()->create();
        $token = ApiTokenFactory::createOne(['name' => 'Production API']);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'endpointPath' => '/api/users',
            'httpMethod' => 'GET',
        ]);
        $drift = SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $token,
            'severity' => DriftSeverity::Critical,
            'driftType' => DriftType::FieldAdded,
            'path' => '$.newField',
            'expectedValue' => null,
            'actualValue' => ['type' => 'string'],
        ]);

        // Step 1: Login user (simulating successful authentication)
        $client->loginUser($user);

        // Step 2: Navigate to dashboard
        $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Dashboard Overview');

        // Step 4: Verify dashboard shows drift stats
        self::assertSelectorExists('.card');

        // Step 5: Navigate to drifts list
        $client->clickLink('Drift Inspector');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Drift Inspector');

        // Step 6: Verify drift is visible in list
        self::assertSelectorExists('.badge.bg-danger');
        self::assertSelectorTextContains('code', '$.newField');

        // Step 7: Click on drift to view details
        $client->request('GET', '/dashboard/drifts/' . $drift->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Drift Details');

        // Step 8: Verify diff viewer is displayed
        self::assertSelectorExists('.diff-panel');

        // Step 9: Verify drift metadata is shown
        self::assertSelectorTextContains('body', 'Critical');
        self::assertSelectorTextContains('body', 'Field added');
    }

    public function testUnauthenticatedUserCannotAccessDashboard(): void
    {
        $client = static::createClient();

        $client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function testUserCanLogoutAfterViewingDrift(): void
    {
        $client = static::createClient();

        $user = UserFactory::new()->admin()->create();
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $drift = SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $token,
        ]);

        // Login
        $client->loginUser($user);

        // View drift
        $client->request('GET', '/dashboard/drifts/' . $drift->getId());
        self::assertResponseIsSuccessful();

        // Logout
        $client->request('GET', '/logout');
        $client->followRedirect();

        // Verify logged out
        $client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testRegularUserCanOnlyViewAccessibleDrifts(): void
    {
        $client = static::createClient();

        $user = UserFactory::createOne();
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $drift = SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $token,
        ]);

        $client->loginUser($user);

        // User without token access should get 403
        $client->request('GET', '/dashboard/drifts/' . $drift->getId());
        self::assertResponseStatusCodeSame(403);
    }
}
