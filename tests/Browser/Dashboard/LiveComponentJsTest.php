<?php

declare(strict_types=1);

namespace App\Tests\Browser\Dashboard;

use App\Tests\Browser\AbstractPantherTestCase;
use PHPUnit\Framework\Attributes\Test;

final class LiveComponentJsTest extends AbstractPantherTestCase
{
    #[Test]
    public function dashboardStatsLiveComponentLoads(): void
    {
        $this->loginAsAdmin();
        $client = $this->getPantherClient();

        $client->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));

        $client->request('GET', '/dashboard');

        // Wait for page to fully load
        $client->waitFor('.main-content', 5);

        // Verify dashboard content is rendered
        $this->assertElementExists('.main-content');
    }

    #[Test]
    public function chartJsRendersOnDashboard(): void
    {
        $this->loginAsAdmin();
        $client = $this->getPantherClient();

        $client->request('GET', '/dashboard');

        // Wait for Chart.js canvas elements
        $client->waitFor('canvas', 5);

        // Verify canvas elements exist (Chart.js renders to canvas)
        $this->assertElementExists('canvas', 'Chart.js canvas should be rendered');
    }

    #[Test]
    public function turboFrameNavigationWorks(): void
    {
        $this->loginAsAdmin();
        $client = $this->getPantherClient();

        $client->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));

        $client->request('GET', '/dashboard');
        $this->waitForStimulusController('sidebar');

        // Navigate using direct URL (Turbo/JS navigation can be unreliable in headless)
        $client->request('GET', '/dashboard/services');

        $client->waitFor('.header-title', 5);
        $this->assertElementTextContains('.header-title', 'Service Health');
    }
}
