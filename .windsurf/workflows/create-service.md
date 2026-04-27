---
description: Create a new service with interface, implementation, wiring, and tests
---

# Create New Service

## Steps

1. **Create the interface** in `src/Service/{Name}Interface.php`:
   ```php
   <?php
   declare(strict_types=1);
   namespace App\Service;

   interface {Name}Interface
   {
       // Define public contract methods
   }
   ```

2. **Create the implementation** in `src/Service/{Name}.php`:
   ```php
   <?php
   declare(strict_types=1);
   namespace App\Service;

   final class {Name} implements {Name}Interface
   {
       public function __construct(
           // Use readonly constructor promotion for dependencies
       ) {
       }
   }
   ```

3. **Wire in `config/services.yaml`**:
   ```yaml
   App\Service\{Name}Interface:
       class: App\Service\{Name}
       arguments:
           # Add any non-autowirable arguments
   ```

4. **Create unit test** in `tests/Unit/Service/{Name}Test.php`:
   ```php
   <?php
   declare(strict_types=1);
   namespace App\Tests\Unit\Service;

   use App\Service\{Name};
   use PHPUnit\Framework\Attributes\Test;
   use PHPUnit\Framework\TestCase;

   final class {Name}Test extends TestCase
   {
       #[Test]
       public function itDoesExpectedBehavior(): void
       {
           // Arrange, Act, Assert
       }
   }
   ```

5. **Run static analysis**:
   ```bash
   // turbo
   ddev exec vendor/bin/phpstan analyse src/Service/{Name}.php src/Service/{Name}Interface.php
   ```

6. **Run tests**:
   ```bash
   // turbo
   ddev exec bin/phpunit tests/Unit/Service/{Name}Test.php
   ```
