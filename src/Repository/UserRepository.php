<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Репозиторий пользователей: только запросы к БД, без бизнес-логики.
 */
final class UserRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function findByEmail(string $email): ?User
    {
        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        return $user;
    }
}
