<?php

declare(strict_types=1);

namespace SentinelPHP\Redact\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Redact\PiiRedactor;

#[CoversClass(PiiRedactor::class)]
final class PiiRedactorTest extends TestCase
{
    private PiiRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new PiiRedactor();
    }

    #[Test]
    public function itRedactsCreditCardNumbers(): void
    {
        $data = ['card' => '4111111111111111'];
        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertIsString($result['card']);
        self::assertNotSame('4111111111111111', $result['card']);
        self::assertStringContainsString('****', $result['card']);
    }

    #[Test]
    public function itRedactsEmailAddresses(): void
    {
        $data = ['email' => 'user@example.com'];
        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertNotSame('user@example.com', $result['email']);
    }

    #[Test]
    public function itRedactsPhoneNumbers(): void
    {
        $data = ['phone' => '555-123-4567'];
        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertNotSame('555-123-4567', $result['phone']);
    }

    #[Test]
    public function itRedactsSocialSecurityNumbers(): void
    {
        $data = ['ssn' => '123-45-6789'];
        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertNotSame('123-45-6789', $result['ssn']);
    }

    #[Test]
    public function itRedactsApiKeys(): void
    {
        // Use api_key pattern instead of Stripe format to avoid GitHub secret scanning
        $data = ['key' => 'api_key_abc123def456ghi789'];
        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertNotSame('api_key_abc123def456ghi789', $result['key']);
    }

    #[Test]
    public function itRedactsNestedData(): void
    {
        $data = [
            'user' => [
                'email' => 'user@example.com',
                'profile' => [
                    'phone' => '555-123-4567',
                ],
            ],
        ];

        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertIsArray($result['user']);
        self::assertIsArray($result['user']['profile']);
        self::assertNotSame('user@example.com', $result['user']['email']);
        self::assertNotSame('555-123-4567', $result['user']['profile']['phone']);
    }

    #[Test]
    public function itRedactsStrings(): void
    {
        $text = 'Contact me at user@example.com or 555-123-4567';
        $result = $this->redactor->redactString($text);

        self::assertStringNotContainsString('user@example.com', $result);
        self::assertStringNotContainsString('555-123-4567', $result);
    }

    #[Test]
    public function itRedactsFieldsByPath(): void
    {
        $this->redactor->addFieldPath('user.password');

        $data = [
            'user' => [
                'name' => 'John',
                'password' => 'secret123',
            ],
        ];

        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertIsArray($result['user']);
        self::assertSame('John', $result['user']['name']);
        self::assertNotSame('secret123', $result['user']['password']);
    }

    #[Test]
    public function itRedactsWildcardPaths(): void
    {
        $this->redactor->addFieldPath('*.secret');

        $data = [
            'config' => ['secret' => 'value1'],
            'settings' => ['secret' => 'value2'],
        ];

        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertIsArray($result['config']);
        self::assertIsArray($result['settings']);
        self::assertNotSame('value1', $result['config']['secret']);
        self::assertNotSame('value2', $result['settings']['secret']);
    }

    #[Test]
    public function itAllowsCustomPatterns(): void
    {
        $this->redactor->addPattern('custom_id', '/ID-\d{6}/', '[ID REDACTED]');

        $data = ['ref' => 'ID-123456'];
        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertSame('[ID REDACTED]', $result['ref']);
    }

    #[Test]
    public function itAllowsRemovingPatterns(): void
    {
        $this->redactor->removePattern('email');

        $data = ['email' => 'user@example.com'];
        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertSame('user@example.com', $result['email']);
    }

    #[Test]
    public function itPreservesNonSensitiveData(): void
    {
        $data = [
            'id' => 123,
            'name' => 'John Doe',
            'active' => true,
            'score' => 95.5,
        ];

        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertSame(123, $result['id']);
        self::assertSame('John Doe', $result['name']);
        self::assertTrue($result['active']);
        self::assertSame(95.5, $result['score']);
    }

    #[Test]
    public function itHandlesEmptyData(): void
    {
        self::assertSame([], $this->redactor->redact([]));
        self::assertSame('', $this->redactor->redactString(''));
    }

    #[Test]
    public function itHandlesNullValues(): void
    {
        $data = ['email' => null];
        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertNull($result['email']);
    }

    #[Test]
    #[DataProvider('creditCardProvider')]
    public function itRedactsVariousCreditCardFormats(string $card): void
    {
        $data = ['card' => $card];
        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertNotSame($card, $result['card']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function creditCardProvider(): iterable
    {
        yield 'visa' => ['4111111111111111'];
        yield 'mastercard' => ['5500000000000004'];
        yield 'amex' => ['340000000000009'];
        yield 'discover' => ['6011000000000004'];
        yield 'with dashes' => ['4111-1111-1111-1111'];
        yield 'with spaces' => ['4111 1111 1111 1111'];
    }

    #[Test]
    #[DataProvider('emailProvider')]
    public function itRedactsVariousEmailFormats(string $email): void
    {
        $data = ['email' => $email];
        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertNotSame($email, $result['email']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emailProvider(): iterable
    {
        yield 'simple' => ['user@example.com'];
        yield 'with plus' => ['user+tag@example.com'];
        yield 'subdomain' => ['user@mail.example.com'];
        yield 'long tld' => ['user@example.co.uk'];
    }

    #[Test]
    public function itRedactsJsonStrings(): void
    {
        $json = '{"email": "user@example.com", "phone": "555-123-4567"}';
        $result = $this->redactor->redact($json);

        self::assertIsString($result);
        self::assertStringNotContainsString('user@example.com', $result);
        self::assertStringNotContainsString('555-123-4567', $result);
    }

    #[Test]
    public function itRedactsObjects(): void
    {
        $obj = (object) ['email' => 'user@example.com'];
        $result = $this->redactor->redact($obj);

        self::assertIsObject($result);
        self::assertObjectHasProperty('email', $result);
        self::assertNotSame('user@example.com', $result->email); // @phpstan-ignore property.notFound
    }

    #[Test]
    public function itReturnsPatternNames(): void
    {
        $names = $this->redactor->getPatternNames();

        self::assertContains('email', $names);
        self::assertContains('phone', $names);
        self::assertContains('ssn', $names);
        self::assertContains('credit_card', $names);
    }

    #[Test]
    public function itReturnsDefaultFieldPaths(): void
    {
        $paths = $this->redactor->getDefaultFieldPaths();

        self::assertContains('$.password', $paths);
        self::assertContains('$.token', $paths);
        self::assertContains('$.api_key', $paths);
    }

    #[Test]
    public function itRedactsDefaultFieldPaths(): void
    {
        $data = [
            'password' => 'secret123',
            'token' => 'abc123',
            'name' => 'John',
        ];

        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertSame('[REDACTED]', $result['password']);
        self::assertSame('[REDACTED]', $result['token']);
        self::assertSame('John', $result['name']);
    }

    #[Test]
    public function itHandlesIndexedArrays(): void
    {
        $data = [
            'users' => [
                ['email' => 'user1@example.com'],
                ['email' => 'user2@example.com'],
            ],
        ];

        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertIsArray($result['users']);
        self::assertIsArray($result['users'][0]);
        self::assertIsArray($result['users'][1]);
        self::assertNotSame('user1@example.com', $result['users'][0]['email']);
        self::assertNotSame('user2@example.com', $result['users'][1]['email']);
    }

    #[Test]
    public function itAcceptsAdditionalPatternsViaConstructor(): void
    {
        $patterns = json_encode([
            'custom' => ['pattern' => '/SECRET-\d+/', 'replacement' => '[SECRET]'],
        ]) ?: null;

        $redactor = new PiiRedactor($patterns);
        $result = $redactor->redactString('Code: SECRET-12345');

        self::assertSame('Code: [SECRET]', $result);
    }

    #[Test]
    public function itAcceptsAdditionalFieldPathsViaConstructor(): void
    {
        $fieldPaths = json_encode(['$.custom_secret']) ?: null;

        $redactor = new PiiRedactor(null, $fieldPaths);
        $result = $redactor->redact(['custom_secret' => 'value']);

        self::assertIsArray($result);
        self::assertSame('[REDACTED]', $result['custom_secret']);
    }

    #[Test]
    public function itCanDisableDefaultPatterns(): void
    {
        $redactor = new PiiRedactor(null, null, false);
        $result = $redactor->redactString('user@example.com');

        self::assertSame('user@example.com', $result);
    }

    #[Test]
    public function itThrowsOnInvalidPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->redactor->addPattern('invalid', '/[invalid/', 'replacement');
    }

    #[Test]
    public function itHandlesCustomPatternsInRedact(): void
    {
        $data = ['code' => 'REF-12345'];
        $result = $this->redactor->redact($data, null, ['/REF-\d+/' => '[REF]']);

        self::assertIsArray($result);
        self::assertSame('[REF]', $result['code']);
    }

    #[Test]
    public function staticRedactCreditCardKeepsLast4(): void
    {
        $result = PiiRedactor::redactCreditCard('4111111111111111');
        self::assertSame('****-****-****-1111', $result);
    }

    #[Test]
    public function staticRedactCreditCardHandlesShortNumbers(): void
    {
        $result = PiiRedactor::redactCreditCard('123');
        self::assertSame('[REDACTED]', $result);
    }

    #[Test]
    public function staticRedactEmailKeepsFirstCharAndDomain(): void
    {
        $result = PiiRedactor::redactEmail('user@example.com');
        self::assertSame('u***@example.com', $result);
    }

    #[Test]
    public function staticRedactEmailHandlesInvalidEmail(): void
    {
        $result = PiiRedactor::redactEmail('notanemail');
        self::assertSame('[REDACTED]', $result);
    }

    #[Test]
    public function staticRedactPhoneKeepsLast4(): void
    {
        $result = PiiRedactor::redactPhone('555-123-4567');
        self::assertSame('+1-***-***-4567', $result);
    }

    #[Test]
    public function staticRedactPhoneHandlesShortNumbers(): void
    {
        $result = PiiRedactor::redactPhone('123');
        self::assertSame('[REDACTED]', $result);
    }

    #[Test]
    public function staticRedactSsnKeepsLast4(): void
    {
        $result = PiiRedactor::redactSsn('123-45-6789');
        self::assertSame('***-**-6789', $result);
    }

    #[Test]
    public function staticRedactSsnHandlesShortNumbers(): void
    {
        $result = PiiRedactor::redactSsn('123');
        self::assertSame('[REDACTED]', $result);
    }

    #[Test]
    public function itHandlesInvalidJsonString(): void
    {
        $result = $this->redactor->redact('not valid json with user@example.com');

        self::assertIsString($result);
        self::assertStringNotContainsString('user@example.com', $result);
    }

    #[Test]
    public function itIgnoresInvalidPatternsJson(): void
    {
        $redactor = new PiiRedactor('not valid json');
        self::assertNotEmpty($redactor->getPatternNames());
    }

    #[Test]
    public function itIgnoresInvalidFieldPathsJson(): void
    {
        $redactor = new PiiRedactor(null, 'not valid json');
        self::assertNotEmpty($redactor->getDefaultFieldPaths());
    }

    #[Test]
    public function itHandlesNestedIndexedArrays(): void
    {
        $data = [
            'matrix' => [
                [['email' => 'a@b.com']],
                [['email' => 'c@d.com']],
            ],
        ];

        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertIsArray($result['matrix']);
        self::assertIsArray($result['matrix'][0]);
        self::assertIsArray($result['matrix'][0][0]);
        self::assertIsArray($result['matrix'][1]);
        self::assertIsArray($result['matrix'][1][0]);
        self::assertNotSame('a@b.com', $result['matrix'][0][0]['email']);
        self::assertNotSame('c@d.com', $result['matrix'][1][0]['email']);
    }

    #[Test]
    public function itHandlesBearerTokens(): void
    {
        $data = ['auth' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'];
        $result = $this->redactor->redact($data);

        self::assertIsArray($result);
        self::assertSame('[REDACTED]', $result['auth']);
    }
}
