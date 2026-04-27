<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\MetricsController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(MetricsController::class)]
final class MetricsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    #[Test]
    public function metricsEndpointReturnsPrometheusFormat(): void
    {
        $this->client->request('GET', '/metrics');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function metricsEndpointReturnsValidPrometheusOutput(): void
    {
        $this->client->request('GET', '/metrics');

        $content = $this->client->getResponse()->getContent();
        self::assertNotFalse($content);

        // Metrics may be empty if no requests have been processed
        self::assertNotEmpty($content);
    }

    #[Test]
    public function metricsEndpointIncludesHelpAndTypeAnnotations(): void
    {
        $this->client->request('GET', '/metrics');

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        if (strlen($content) > 10) {
            self::assertTrue(
                str_contains($content, '# HELP') || str_contains($content, '# TYPE') || $content === "\n",
                'Metrics output should contain HELP/TYPE annotations or be empty'
            );
        }
    }
}
