<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\RunLog;
use App\Entity\User;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Integration tests for log page backend-first actions.
 */
final class LogControllerWebTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

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
     * Ensures authenticated user can create, update and delete own log via web forms.
     */
    public function testOwnerCanCreateUpdateAndDeleteLog(): void
    {
        $user = $this->createUserFixture();
        $this->authenticateClient($user);

        $this->client->request('POST', '/log/create', [
            '_token' => $this->csrfFromForm('/log', '/log/create'),
            'date' => '2026-05-20',
            'km' => '10.00',
            'duration' => '00:50:00',
            'dplus' => '120',
            'bpm' => '150',
            'runType' => 'EF',
            'notes' => 'Sortie test',
        ]);

        self::assertResponseRedirects('/log');

        $log = $this->entityManager->getRepository(RunLog::class)->findOneBy([
            'user' => $user,
            'date' => '2026-05-20',
        ]);

        self::assertInstanceOf(RunLog::class, $log);
        self::assertSame('05:00', $log->getAllure());

        $this->client->request('POST', '/log/' . $log->getId() . '/update', [
            '_token' => $this->csrfFromForm('/log', '/log/' . $log->getId() . '/update'),
            'date' => '2026-05-21',
            'km' => '12.00',
            'duration' => '01:00:00',
            'dplus' => '0',
            'bpm' => '148',
            'runType' => 'SL',
            'notes' => 'Sortie maj',
        ]);

        self::assertResponseRedirects('/log');

    $this->entityManager->clear();
        $updated = $this->entityManager->getRepository(RunLog::class)->find($log->getId());
        self::assertInstanceOf(RunLog::class, $updated);
        self::assertSame('2026-05-21', $updated->getDate());
        self::assertSame(12.0, $updated->getKm());
        self::assertSame('SL', $updated->getRunType());
        self::assertSame('Sortie maj', $updated->getNotes());

        $this->client->request('POST', '/log/' . $log->getId() . '/delete', [
            '_token' => $this->csrfFromForm('/log', '/log/' . $log->getId() . '/delete'),
        ]);

        self::assertResponseRedirects('/log');
        self::assertNull($this->entityManager->getRepository(RunLog::class)->find($log->getId()));
    }

    /**
     * Ensures user cannot update another user's log.
     */
    public function testUserCannotUpdateAnotherUsersLog(): void
    {
        $owner = $this->createUserFixture('owner_log');
        $intruder = $this->createUserFixture('intruder_log');

        $log = (new RunLog())
            ->setUser($owner)
            ->setDate('2026-05-01')
            ->setKm(5.0)
            ->setDuration('00:25:00')
            ->setRunType('EF');

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        $this->authenticateClient($intruder);

        $this->client->request('POST', '/log/' . $log->getId() . '/update', [
            '_token' => $this->csrfFromForm('/log', '/log/' . $log->getId() . '/update'),
            'date' => '2026-05-30',
            'km' => '8.00',
            'duration' => '00:40:00',
            'runType' => 'FL',
        ]);

        self::assertResponseRedirects('/log');

        $unchanged = $this->entityManager->getRepository(RunLog::class)->find($log->getId());
        self::assertInstanceOf(RunLog::class, $unchanged);
        self::assertSame('2026-05-01', $unchanged->getDate());
        self::assertSame(5.0, $unchanged->getKm());
        self::assertSame('EF', $unchanged->getRunType());
    }

    /**
     * Creates and persists a test user fixture.
     */
    private function createUserFixture(?string $username = null): \App\Entity\User
    {
        $factory = new UserFactory(
            $this->entityManager,
            static::getContainer()->get(UserPasswordHasherInterface::class)
        );

        return $factory->createOne($username);
    }

    private function csrfFromForm(string $pagePath, string $actionSuffix): string
    {
        $crawler = $this->client->request('GET', $pagePath);
        $selector = sprintf('form[action$="%s"] input[name="_token"]', $actionSuffix);
        $tokenInput = $crawler->filter($selector);

        self::assertGreaterThan(0, $tokenInput->count(), 'Missing CSRF token for form ' . $actionSuffix);

        $token = $tokenInput->first()->attr('value');
        self::assertNotNull($token, 'Missing CSRF token value for form ' . $actionSuffix);

        return $token;
    }

    private function authenticateClient(User $user): void
    {
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $jwt = $jwtManager->create($user);
        $this->client->setServerParameter('HTTP_Authorization', 'Bearer ' . $jwt);
    }
}
