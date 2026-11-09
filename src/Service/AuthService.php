<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Settings\SettingsInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

/**
 * Аутентификация по email/паролю и выдача/проверка JWT (HS256).
 */
final class AuthService
{
    private const ALGORITHM = 'HS256';

    private const DEFAULT_TTL = 86400;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SettingsInterface $settings,
    ) {}

    /**
     * Возвращает JWT при верных кредах, null — при неверных.
     */
    public function authenticate(string $email, string $password): ?string
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, $user->getPasswordHash())) {
            return null;
        }

        return $this->issueToken($user);
    }

    public function issueToken(User $user): string
    {
        $now = time();
        $ttl = (int) $this->settings->get('jwt_ttl', self::DEFAULT_TTL);

        $payload = [
            'sub' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        return JWT::encode($payload, $this->secret(), self::ALGORITHM);
    }

    /**
     * Проверяет подпись и срок действия токена.
     *
     * @return array<string, mixed>|null payload или null, если токен невалиден
     */
    public function verifyToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret(), self::ALGORITHM));
        } catch (Throwable) {
            return null;
        }

        return (array) $decoded;
    }

    private function secret(): string
    {
        $secret = (string) $this->settings->get('jwt_secret', '');

        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET is not configured');
        }

        return $secret;
    }
}
