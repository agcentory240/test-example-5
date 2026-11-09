<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Entity\User;
use App\Middleware\CorsMiddleware;
use App\Middleware\JsonBodyParsingMiddleware;
use App\Settings\Settings;
use App\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Полное Slim-приложение для интеграционных тестов API: реальные роуты из
 * config/routes.php, реальные middleware (AuthMiddleware, JSON-body parser)
 * и контроллеры. БД — SQLite in-memory, MessageBus — заглушка без RabbitMQ.
 */
final class TestApplication
{
    public const JWT_SECRET = 'test-jwt-secret-please-change-32-bytes';

    private function __construct(
        public readonly App $app,
        public readonly EntityManagerInterface $em,
        public readonly string $varDir,
    ) {}

    public static function create(): self
    {
        $varDir = TempDir::create('api_test_');
        $em = DoctrineTestHelper::createEntityManager();

        $settings = [
            'app_env' => 'test',
            'debug' => true,
            'display_error_details' => true,
            'log_errors' => false,
            'log_error_details' => false,
            'cors_allowed_origins' => ['http://localhost:4200'],
            'import_upload_dir' => $varDir . '/uploads/imports',
            'import_max_file_size' => 10 * 1024 * 1024,
            'import_status_dir' => $varDir . '/imports',
            'import_public_dir' => $varDir . '/public',
            'rate_limit_dir' => $varDir . '/rate_limit',
            'rate_limit_max' => 100,
            'rate_limit_window' => 3600,
            'jwt_secret' => self::JWT_SECRET,
            'jwt_ttl' => 86400,
        ];

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions([
            'settings' => $settings,
            SettingsInterface::class => new Settings($settings),
            EntityManagerInterface::class => $em,
            Connection::class => $em->getConnection(),
            ResponseFactoryInterface::class => new ResponseFactory(),
            LoggerInterface::class => new NullLogger(),
            MessageBusInterface::class => new class implements MessageBusInterface {
                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    return new Envelope($message, $stamps);
                }
            },
        ]);

        $container = $builder->build();

        $app = AppFactory::createFromContainer($container);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        $app->add($container->get(JsonBodyParsingMiddleware::class));
        $app->add($container->get(CorsMiddleware::class));

        (require dirname(__DIR__, 2) . '/config/routes.php')($app);

        return new self($app, $em, $varDir);
    }

    public function createUser(string $email, string $password): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Логинится через реальный POST /api/login и возвращает JWT.
     */
    public function token(string $email = 'admin@admin', string $password = 'admin'): string
    {
        $response = $this->request('POST', '/api/login', [], [
            'email' => $email,
            'password' => $password,
        ]);

        $payload = $this->json($response);

        if ($response->getStatusCode() !== 200 || !isset($payload['token']) || !is_string($payload['token'])) {
            throw new RuntimeException('Cannot obtain JWT in test setup');
        }

        return $payload['token'];
    }

    /**
     * @param array<string, string>    $headers
     * @param array<string, mixed>|null $json
     */
    public function request(string $method, string $uri, array $headers = [], ?array $json = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri, ['REMOTE_ADDR' => '127.0.0.1']);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(
                    (string) json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ));
        }

        return $this->app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    public function destroy(): void
    {
        TempDir::remove($this->varDir);
    }
}
