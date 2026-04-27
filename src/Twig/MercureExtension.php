<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\Mercure\HubInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MercureExtension extends AbstractExtension
{
    public function __construct(
        private readonly HubInterface $hub,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mercure_public_url', [$this, 'getMercurePublicUrl']),
            new TwigFunction('mercure_topics', [$this, 'getMercureTopics']),
        ];
    }

    public function getMercurePublicUrl(): string
    {
        return $this->hub->getPublicUrl();
    }

    /**
     * @return list<string>
     */
    public function getMercureTopics(): array
    {
        return [
            'sentinel/drift',
            'sentinel/health',
            'sentinel/threshold',
        ];
    }
}
