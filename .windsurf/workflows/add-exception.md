---
description: Add a custom exception class with proper HTTP response handling
---

# Add Custom Exception

## Steps

1. **Create the exception** in `src/Exception/{Name}Exception.php`:
   ```php
   <?php
   declare(strict_types=1);
   namespace App\Exception;

   final class {Name}Exception extends \RuntimeException
   {
       public function __construct(
           string $message = 'Default error message',
           int $code = 0,
           ?\Throwable $previous = null,
       ) {
           parent::__construct($message, $code, $previous);
       }

       public static function because(string $reason): self
       {
           return new self($reason);
       }
   }
   ```

2. **Update `ExceptionListener`** in `src/EventListener/ExceptionListener.php` if custom HTTP response is needed:
   ```php
   // Add to the exception handling logic:
   if ($exception instanceof {Name}Exception) {
       return new JsonResponse([
           'error' => true,
           'message' => $exception->getMessage(),
       ], Response::HTTP_XXX);
   }
   ```

3. **Create unit test** in `tests/Unit/Exception/{Name}ExceptionTest.php`:
   ```php
   <?php
   declare(strict_types=1);
   namespace App\Tests\Unit\Exception;

   use App\Exception\{Name}Exception;
   use PHPUnit\Framework\Attributes\Test;
   use PHPUnit\Framework\TestCase;

   final class {Name}ExceptionTest extends TestCase
   {
       #[Test]
       public function itCanBeCreatedWithMessage(): void
       {
           $exception = new {Name}Exception('Test message');

           $this->assertSame('Test message', $exception->getMessage());
       }

       #[Test]
       public function itCanBeCreatedWithBecauseFactory(): void
       {
           $exception = {Name}Exception::because('Something went wrong');

           $this->assertSame('Something went wrong', $exception->getMessage());
       }
   }
   ```

4. **Run static analysis**:
   ```bash
   // turbo
   ddev exec vendor/bin/phpstan analyse src/Exception/{Name}Exception.php
   ```

5. **Run tests**:
   ```bash
   // turbo
   ddev exec bin/phpunit tests/Unit/Exception/{Name}ExceptionTest.php
   ```
