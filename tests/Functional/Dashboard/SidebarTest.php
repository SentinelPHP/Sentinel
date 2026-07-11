<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use PHPUnit\Framework\Attributes\Test;

final class SidebarTest extends AbstractDashboardTestCase
{
    #[Test]
    public function sidebarHasStimulusControllerAttribute(): void
    {
        $this->loginAsUser();
        $client = $this->getClient();

        $client->request('GET', '/dashboard');
        $this->assertStimulusControllerExists('sidebar');

        // Verify sidebar controller attribute and element are present
        $this->assertElementExists('[data-controller*="sidebar"]');
        $this->assertElementExists('.sidebar');
    }

    #[Test]
    public function sidebarHasNavigationLinks(): void
    {
        $this->loginAsUser();
        $client = $this->getClient();

        $client->request('GET', '/dashboard');

        // Verify navigation links exist
        $this->assertElementExists('.sidebar .nav-link[href="/dashboard"]');
        $this->assertElementExists('.sidebar .nav-link[href="/dashboard/services"]');
        $this->assertElementExists('.sidebar .nav-link[href="/dashboard/drifts"]');
    }

    #[Test]
    public function sidebarNavigationLinksLoadPages(): void
    {
        $this->loginAsUser();
        $client = $this->getClient();

        $client->request('GET', '/dashboard/services');

        $this->assertElementTextContains('.header-title', 'Service Health');
    }
}
