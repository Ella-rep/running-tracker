<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\User;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base class for API integration tests with per-test transaction rollback.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Symfony\\Component\\BrowserKit\\AbstractBrowser')) {
            self::markTestSkipped('symfony/browser-kit not installed yet.');
        }

        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();

        if (!$this->connection->isTransactionActive()) {
            $this->connection->beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->clear();
        }

        parent::tearDown();
    }

    /**
     * Creates a persisted user fixture via factory.
     */
    protected function createUserFixture(
        ?string $username = null,
        ?string $email = null,
        string $plainPassword = 'TestPass123!'
    ): User {
        $factory = new UserFactory(
            $this->entityManager,
            static::getContainer()->get(UserPasswordHasherInterface::class)
        );

        return $factory->createOne($username, $email, $plainPassword);
    }

    /**
     * Requests a JWT using API login endpoint.
     */
    protected function loginAndGetJwt(string $email, string $password): string
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR)
        );

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('token', $payload);
        self::assertIsString($payload['token']);
        self::assertNotSame('', trim($payload['token']));

        return $payload['token'];
    }

    /**
     * Creates a default auth header array with bearer token.
     *
     * @return array<string,string>
     */
    protected function authHeaders(string $jwt): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_Authorization' => 'Bearer ' . $jwt,
        ];
    }
}
