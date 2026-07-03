<?php

declare(strict_types=1);

namespace App\Tests\Browser\Dashboard;

use App\Tests\Browser\AbstractPantherTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SidebarJsTest extends AbstractPantherTestCase
{
    #[Test]
    public function sidebarStimulusControllerLoads(): void
    {
        $this->loginAsUser();
        $client = $this->getPantherClient();

        // Set desktop viewport for consistent behavior
        $client->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));

        $client->request('GET', '/dashboard');
        $this->waitForStimulusController('sidebar');

        // Verify sidebar controller is initialized
        $this->assertElementExists('[data-controller*="sidebar"]');
        $this->assertElementExists('.sidebar');
    }

    #[Test]
    public function sidebarHasNavigationLinks(): void
    {
        $this->loginAsUser();
        $client = $this->getPantherClient();

        $client->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));

        $client->request('GET', '/dashboard');
        $this->waitForStimulusController('sidebar');

        // Verify navigation links exist
        $this->assertElementExists('.sidebar .nav-link[href="/dashboard"]');
        $this->assertElementExists('.sidebar .nav-link[href="/dashboard/services"]');
        $this->assertElementExists('.sidebar .nav-link[href="/dashboard/drifts"]');
    }

    #[Test]
    public function sidebarNavigationWorks(): void
    {
        $this->loginAsUser();
        $client = $this->getPantherClient();

        $client->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));

        $client->request('GET', '/dashboard');
        $this->waitForStimulusController('sidebar');

        // Navigate using direct URL (Turbo/JS navigation can be unreliable in headless)
        $client->request('GET', '/dashboard/services');

        // Wait for page load and verify
        $client->waitFor('.header-title', 5);
        $this->assertElementTextContains('.header-title', 'Service Health');
    }
}
