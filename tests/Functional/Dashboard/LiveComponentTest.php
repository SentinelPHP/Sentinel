<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use PHPUnit\Framework\Attributes\Test;

final class LiveComponentTest extends AbstractDashboardTestCase
{
    #[Test]
    public function dashboardStatsLiveComponentLoads(): void
    {
        $this->loginAsAdmin();
        $client = $this->getClient();

        $client->request('GET', '/dashboard');

        // Verify dashboard content is rendered
        $this->assertElementExists('.main-content');
    }

    #[Test]
    public function dashboardContainsChartCanvas(): void
    {
        $this->loginAsAdmin();
        $client = $this->getClient();

        $client->request('GET', '/dashboard');

        // Verify canvas elements exist in the rendered HTML (Chart.js targets canvas elements)
        $this->assertElementExists('canvas', 'Chart.js canvas should be rendered');
    }

    #[Test]
    public function servicesPageLoads(): void
    {
        $this->loginAsAdmin();
        $client = $this->getClient();

        $client->request('GET', '/dashboard/services');

        $this->assertElementTextContains('.header-title', 'Service Health');
    }
}
