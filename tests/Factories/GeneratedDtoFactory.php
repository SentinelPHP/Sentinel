<?php

declare(strict_types=1);

namespace App\Tests\Factories;

use App\Entity\GeneratedDto;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<GeneratedDto>
 */
final class GeneratedDtoFactory extends PersistentObjectFactory
{
    public function __construct()
    {
    }

    #[\Override]
    public static function class(): string
    {
        return GeneratedDto::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        $className = 'Get' . ucfirst(self::faker()->word()) . 'Response';

        return [
            'schema' => ApiSchemaFactory::new(),
            'className' => $className,
            'namespace' => 'App\\Dto\\Generated',
            'phpCode' => $this->generatePhpCode($className),
            'version' => 1,
            'isCurrent' => true,
        ];
    }

    public function withClassName(string $className): static
    {
        return $this->with([
            'className' => $className,
            'phpCode' => $this->generatePhpCode($className),
        ]);
    }

    public function withNamespace(string $namespace): static
    {
        return $this->with(['namespace' => $namespace]);
    }

    public function withPhpCode(string $phpCode): static
    {
        return $this->with(['phpCode' => $phpCode]);
    }

    public function withVersion(int $version): static
    {
        return $this->with(['version' => $version]);
    }

    public function current(): static
    {
        return $this->with(['isCurrent' => true]);
    }

    public function notCurrent(): static
    {
        return $this->with(['isCurrent' => false]);
    }

    private function generatePhpCode(string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Dto\Generated;

final readonly class {$className}
{
    public function __construct(
        public int \$id,
        public string \$name,
    ) {
    }
}
PHP;
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this;
    }
}
