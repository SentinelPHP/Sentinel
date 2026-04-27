<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dto;

use SentinelPHP\Dto\Enum\SchemaType;
use App\Service\Dto\DefaultDtoNamingStrategy;
use App\ValueObject\SchemaMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultDtoNamingStrategy::class)]
#[UsesClass(SchemaMetadata::class)]
#[UsesClass(SchemaType::class)]
final class DefaultDtoNamingStrategyTest extends TestCase
{
    private DefaultDtoNamingStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new DefaultDtoNamingStrategy();
    }

    #[Test]
    #[DataProvider('classNameProvider')]
    public function itGeneratesCorrectClassName(string $path, string $method, SchemaType $type, string $expected): void
    {
        $metadata = new SchemaMetadata($method, $path, $type);

        $result = $this->strategy->generateClassName($metadata);

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{path: string, method: string, type: SchemaType, expected: string}>
     */
    public static function classNameProvider(): iterable
    {
        yield 'simple GET users response' => [
            'path' => '/users',
            'method' => 'GET',
            'type' => SchemaType::Response,
            'expected' => 'GetUsersResponse',
        ];

        yield 'GET users with ID response' => [
            'path' => '/users/{id}',
            'method' => 'GET',
            'type' => SchemaType::Response,
            'expected' => 'GetUsersIdResponse',
        ];

        yield 'POST users request' => [
            'path' => '/users',
            'method' => 'POST',
            'type' => SchemaType::Request,
            'expected' => 'PostUsersRequest',
        ];

        yield 'nested path with version' => [
            'path' => '/api/v1/orders',
            'method' => 'GET',
            'type' => SchemaType::Response,
            'expected' => 'GetApiV1OrdersResponse',
        ];

        yield 'path with multiple parameters' => [
            'path' => '/users/{userId}/posts/{postId}',
            'method' => 'DELETE',
            'type' => SchemaType::Response,
            'expected' => 'DeleteUsersUseridPostsPostidResponse',
        ];

        yield 'path with hyphens' => [
            'path' => '/user-profiles',
            'method' => 'GET',
            'type' => SchemaType::Response,
            'expected' => 'GetUserProfilesResponse',
        ];

        yield 'root path' => [
            'path' => '/',
            'method' => 'GET',
            'type' => SchemaType::Response,
            'expected' => 'GetRootResponse',
        ];

        yield 'lowercase method normalized' => [
            'path' => '/items',
            'method' => 'patch',
            'type' => SchemaType::Response,
            'expected' => 'PatchItemsResponse',
        ];
    }

    #[Test]
    #[DataProvider('propertyNameProvider')]
    public function itGeneratesCorrectPropertyName(string $jsonPath, string $expected): void
    {
        $result = $this->strategy->generatePropertyName($jsonPath);

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{jsonPath: string, expected: string}>
     */
    public static function propertyNameProvider(): iterable
    {
        yield 'simple field' => [
            'jsonPath' => 'name',
            'expected' => 'name',
        ];

        yield 'snake_case field' => [
            'jsonPath' => 'user_name',
            'expected' => 'userName',
        ];

        yield 'kebab-case field' => [
            'jsonPath' => 'user-name',
            'expected' => 'username',
        ];

        yield 'nested path with dots' => [
            'jsonPath' => 'data.items',
            'expected' => 'dataitems',
        ];

        yield 'array index path' => [
            'jsonPath' => 'items[0]',
            'expected' => 'items0',
        ];

        yield 'complex nested path' => [
            'jsonPath' => 'data.items[0].id',
            'expected' => 'dataitems0id',
        ];

        yield 'multiple underscores' => [
            'jsonPath' => 'user__name',
            'expected' => 'userName',
        ];

        yield 'starting with number gets prefix' => [
            'jsonPath' => '123field',
            'expected' => 'prop123field',
        ];

        yield 'reserved word gets suffix' => [
            'jsonPath' => 'class',
            'expected' => 'classValue',
        ];

        yield 'reserved word array' => [
            'jsonPath' => 'array',
            'expected' => 'arrayValue',
        ];

        yield 'empty string returns default' => [
            'jsonPath' => '',
            'expected' => 'property',
        ];

        yield 'special characters removed' => [
            'jsonPath' => 'field@name!',
            'expected' => 'fieldname',
        ];
    }

    #[Test]
    public function itHandlesReservedWordsCorrectly(): void
    {
        $reservedWords = ['class', 'function', 'return', 'public', 'private', 'static', 'int', 'string', 'bool', 'float', 'array', 'null', 'true', 'false'];

        foreach ($reservedWords as $word) {
            $result = $this->strategy->generatePropertyName($word);
            self::assertSame($word . 'Value', $result, "Reserved word '{$word}' should get 'Value' suffix");
        }
    }

}
