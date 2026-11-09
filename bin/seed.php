#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var \Psr\Container\ContainerInterface $container */
$container = require dirname(__DIR__) . '/config/container.php';

$email = 'admin@admin';
$password = 'admin';

/** @var EntityManagerInterface $em */
$em = $container->get(EntityManagerInterface::class);

$existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);

if ($existing !== null) {
    fwrite(STDOUT, sprintf("User %s already exists — skipped.\n", $email));

    exit(0);
}

$user = (new User())
    ->setEmail($email)
    ->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));

$em->persist($user);
$em->flush();

fwrite(STDOUT, sprintf("Created user %s with password %s.\n", $email, $password));
