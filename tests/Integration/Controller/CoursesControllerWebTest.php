<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Race;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Integration tests for courses page backend-first actions.
 */
final class CoursesControllerWebTest extends WebTestCase
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
     * Ensures authenticated user can create, update, set result and delete own race.
     */
    public function testOwnerCanCreateUpdateResultAndDeleteRace(): void
    {
        $user = $this->createUserFixture();
        $this->client->loginUser($user);
        $this->client->request('GET', '/courses');

        $this->client->request('POST', '/courses/create', [
            '_token' => $this->csrf('courses.create'),
            'name' => '10km de Test',
            'date' => '2026-10-10',
            'distance' => '10km',
            'objective' => '00:50:00',
        ]);

        self::assertResponseRedirects('/courses');

        $race = $this->entityManager->getRepository(Race::class)->findOneBy([
            'user' => $user,
            'name' => '10km de Test',
        ]);

        self::assertInstanceOf(Race::class, $race);

        $this->client->request('POST', '/courses/' . $race->getId() . '/update', [
            '_token' => $this->csrf('courses.update.' . $race->getId()),
            'name' => 'Semi de Test',
            'date' => '2026-11-15',
            'distance' => '21.1km',
            'objective' => '01:45:00',
        ]);

        self::assertResponseRedirects('/courses');

        $this->client->request('POST', '/courses/' . $race->getId() . '/result', [
            '_token' => $this->csrf('courses.result.' . $race->getId()),
            'result' => '01:43:21',
        ]);

        self::assertResponseRedirects('/courses');

        $updated = $this->entityManager->getRepository(Race::class)->find($race->getId());
        self::assertInstanceOf(Race::class, $updated);
        self::assertSame('Semi de Test', $updated->getName());
        self::assertSame('2026-11-15', $updated->getDate());
        self::assertSame('21.1km', $updated->getDistance());
        self::assertSame('01:45:00', $updated->getObjective());
        self::assertSame('01:43:21', $updated->getResult());

        $this->client->request('POST', '/courses/' . $race->getId() . '/delete', [
            '_token' => $this->csrf('courses.delete.' . $race->getId()),
        ]);

        self::assertResponseRedirects('/courses');
        self::assertNull($this->entityManager->getRepository(Race::class)->find($race->getId()));
    }

    /**
     * Ensures user cannot update another user's race.
     */
    public function testUserCannotUpdateAnotherUsersRace(): void
    {
        $owner = $this->createUserFixture('owner_race');
        $intruder = $this->createUserFixture('intruder_race');

        $race = (new Race())
            ->setUser($owner)
            ->setName('Race owner')
            ->setDate('2026-09-01')
            ->setDistance('10km')
            ->setObjective('00:48:00');

        $this->entityManager->persist($race);
        $this->entityManager->flush();

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/courses');

        $this->client->request('POST', '/courses/' . $race->getId() . '/update', [
            '_token' => $this->csrf('courses.update.' . $race->getId()),
            'name' => 'Race hacked',
            'date' => '2026-09-02',
            'distance' => '21.1km',
            'objective' => '01:40:00',
        ]);

        self::assertResponseRedirects('/courses');

        $unchanged = $this->entityManager->getRepository(Race::class)->find($race->getId());
        self::assertInstanceOf(Race::class, $unchanged);
        self::assertSame('Race owner', $unchanged->getName());
        self::assertSame('2026-09-01', $unchanged->getDate());
        self::assertSame('10km', $unchanged->getDistance());
        self::assertSame('00:48:00', $unchanged->getObjective());
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

    /**
     * Generates a CSRF token value for submitted forms.
     */
    private function csrf(string $tokenId): string
    {
        /** @var RequestStack $requestStack */
        $requestStack = static::getContainer()->get(RequestStack::class);
        $session = $this->client->getRequest()->getSession();
        $session->start();

        $request = Request::create('/');
        $request->setSession($session);
        $requestStack->push($request);

        $tokenManager = static::getContainer()->get(CsrfTokenManagerInterface::class);
        $value = $tokenManager->getToken($tokenId)->getValue();

        $requestStack->pop();

        return $value;
    }
}
