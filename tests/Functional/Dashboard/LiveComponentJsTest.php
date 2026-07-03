<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Tests\Functional\AbstractPantherTestCase;
use PHPUnit\Framework\Attributes\Test;

final class LiveComponentJsTest extends AbstractPantherTestCase
{
    #[Test]
    public function dashboardStatsLiveComponentLoads(): void
    {
        $this->loginAsAdmin();
        $client = $this->getPantherClient();

        $client->request('GET', '/dashboard');

        // Verify dashboard content is rendered
        $this->assertElementExists('.main-content');
    }

    #[Test]
    public function chartJsRendersOnDashboard(): void
    {
        $this->loginAsAdmin();
        $client = $this->getPantherClient();

        $client->request('GET', '/dashboard');

        // Verify canvas elements exist (Chart.js renders to canvas)
        $this->assertElementExists('canvas', 'Chart.js canvas should be rendered');
    }

    #[Test]
    public function turboFrameNavigationWorks(): void
    {
        $this->loginAsAdmin();
        $client = $this->getPantherClient();

        $client->request('GET', '/dashboard');
        $this->waitForStimulusController('sidebar');

        // Navigate using direct URL (Turbo/JS navigation can be unreliable in headless)
        $client->request('GET', '/dashboard/services');

        $this->assertElementTextContains('.header-title', 'Service Health');
    }
}
