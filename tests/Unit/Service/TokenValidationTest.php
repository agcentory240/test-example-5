<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Repository\UserRepository;
use App\Service\AuthService;
use App\Settings\Settings;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Tests\Support\DoctrineTestHelper;

/**
 * Валидация JWT в AuthService: подпись, срок действия, обязательный sub.
 */
final class TokenValidationTest extends TestCase
{
    private const SECRET = 'test-jwt-secret-please-change-32-bytes';

    private const ALGORITHM = 'HS256';

    private AuthService $auth;

    protected function setUp(): void
    {
        $em = DoctrineTestHelper::createEntityManager();
        $settings = new Settings([
            'jwt_secret' => self::SECRET,
            'jwt_ttl' => 86400,
        ]);

        $this->auth = new AuthService(new UserRepository($em), $settings);
    }

    public function testValidTokenReturnsPayload(): void
    {
        $token = JWT::encode(
            ['sub' => '42', 'exp' => time() + 60],
            self::SECRET,
            self::ALGORITHM,
        );

        $payload = $this->auth->verifyToken($token);

        self::assertIsArray($payload);
        self::assertSame('42', $payload['sub']);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = JWT::encode(
            ['sub' => '42', 'iat' => time() - 7200, 'exp' => time() - 60],
            self::SECRET,
            self::ALGORITHM,
        );

        self::assertNull($this->auth->verifyToken($token));
    }

    public function testTokenWithTamperedSignatureIsRejected(): void
    {
        $token = JWT::encode(
            ['sub' => '42', 'exp' => time() + 60],
            'another-secret-that-is-also-long-enough-123',
            self::ALGORITHM,
        );

        self::assertNull($this->auth->verifyToken($token));
    }

    public function testTokenWithoutSubjectIsRejected(): void
    {
        $token = JWT::encode(
            ['email' => 'admin@admin', 'exp' => time() + 60],
            self::SECRET,
            self::ALGORITHM,
        );

        self::assertNull($this->auth->verifyToken($token));
    }

    public function testTokenWithEmptySubjectIsRejected(): void
    {
        $token = JWT::encode(
            ['sub' => '', 'exp' => time() + 60],
            self::SECRET,
            self::ALGORITHM,
        );

        self::assertNull($this->auth->verifyToken($token));
    }

    public function testMalformedTokenIsRejected(): void
    {
        self::assertNull($this->auth->verifyToken('not-a-jwt'));
    }
}
