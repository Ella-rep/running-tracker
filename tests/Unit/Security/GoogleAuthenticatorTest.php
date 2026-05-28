<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\GoogleAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Unit tests for GoogleAuthenticator.
 */
final class GoogleAuthenticatorTest extends TestCase
{
    public function testSupportsOnlyGoogleCallbackRoute(): void
    {
        $authenticator = $this->buildAuthenticator();

        $supportedRequest = Request::create('/connect/google/check');
        $supportedRequest->attributes->set('_route', 'connect_google_check');

        $unsupportedRequest = Request::create('/login');
        $unsupportedRequest->attributes->set('_route', 'app_login');

        self::assertTrue($authenticator->supports($supportedRequest));
        self::assertFalse($authenticator->supports($unsupportedRequest));
    }

    public function testOnAuthenticationSuccessRedirectsWithJwtToken(): void
    {
        $user = (new User())
            ->setUsername('runner')
            ->setEmail('runner@example.test')
            ->setPassword('hashed');

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $jwtManager
            ->expects(self::once())
            ->method('create')
            ->with($user)
            ->willReturn('jwt-xyz');

        $authenticator = $this->buildAuthenticator(jwtManager: $jwtManager);

        $response = $authenticator->onAuthenticationSuccess(Request::create('/connect/google/check'), $token, 'main');

        self::assertSame(302, $response?->getStatusCode());
        self::assertSame('/login?google_token=jwt-xyz', $response?->headers->get('Location'));
    }

    public function testOnAuthenticationFailureUsesOauthDetailsAndLogsError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'Google OAuth authentication failure.',
                self::callback(static function (array $context): bool {
                    return ($context['oauth_error'] ?? null) === 'access_denied'
                        && str_contains((string) ($context['message'] ?? ''), 'OAuth Google: access_denied: user canceled');
                })
            );

        $authenticator = $this->buildAuthenticator(logger: $logger);
        $request = Request::create('/connect/google/check?error=access_denied&error_description=user%20canceled');
        $request->attributes->set('_route', 'connect_google_check');

        $exception = new class('auth failed') extends AuthenticationException {
        };

        $response = $authenticator->onAuthenticationFailure($request, $exception);

        self::assertSame(302, $response?->getStatusCode());
        self::assertStringContainsString('/login?google_error=', (string) $response?->headers->get('Location'));
    }

    public function testResolveUserFromGoogleTokenReturnsExistingGoogleLinkedUser(): void
    {
        $linkedUser = (new User())
            ->setUsername('linked')
            ->setEmail('linked@example.test')
            ->setPassword('hashed')
            ->setGoogleId('gid-1');

        $users = $this->createMock(UserRepository::class);
        $users
            ->method('findOneBy')
            ->with(['googleId' => 'gid-1'])
            ->willReturn($linkedUser);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $authenticator = $this->buildAuthenticator(users: $users, entityManager: $entityManager);

        $resolved = $this->invokeResolveUserFromGoogleToken(
            $authenticator,
            new class {
                public function fetchUserFromToken(object $accessToken): object
                {
                    $tokenWasProvided = $accessToken !== null;
                    if (!$tokenWasProvided) {
                        throw new \LogicException('Access token missing.');
                    }

                    return new class {
                        public function getId(): string { return 'gid-1'; }
                        public function getEmail(): string { return 'linked@example.test'; }
                        public function getName(): string { return 'Linked User'; }
                    };
                }
            },
            new \stdClass()
        );

        self::assertSame($linkedUser, $resolved);
    }

    public function testResolveUserFromGoogleTokenCreatesAndPersistsNewUser(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users
            ->method('findOneBy')
            ->willReturnMap([
                [['googleId' => 'gid-new'], null],
                [['username' => 'Alice_New'], null],
            ]);
        $users
            ->method('findOneByEmailInsensitive')
            ->with('alice@example.test')
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(User::class));
        $entityManager->expects(self::once())->method('flush');

        $authenticator = $this->buildAuthenticator(users: $users, entityManager: $entityManager);

        $resolved = $this->invokeResolveUserFromGoogleToken(
            $authenticator,
            new class {
                public function fetchUserFromToken(object $accessToken): object
                {
                    $tokenWasProvided = $accessToken !== null;
                    if (!$tokenWasProvided) {
                        throw new \LogicException('Access token missing.');
                    }

                    return new class {
                        public function getId(): string { return 'gid-new'; }
                        public function getEmail(): string { return 'Alice@Example.Test'; }
                        public function getName(): string { return 'Alice New'; }
                    };
                }
            },
            new \stdClass()
        );

        self::assertSame('gid-new', $resolved->getGoogleId());
        self::assertSame('alice@example.test', $resolved->getEmail());
        self::assertSame('', $resolved->getPassword());
        self::assertSame('Alice_New', $resolved->getUsername());
    }

    public function testResolveUserFromGoogleTokenRejectsEmailAlreadyLinkedToDifferentGoogleId(): void
    {
        $existingUser = (new User())
            ->setUsername('existing')
            ->setEmail('existing@example.test')
            ->setPassword('hashed')
            ->setGoogleId('gid-old');

        $users = $this->createMock(UserRepository::class);
        $users
            ->method('findOneBy')
            ->with(['googleId' => 'gid-other'])
            ->willReturn(null);
        $users
            ->method('findOneByEmailInsensitive')
            ->with('existing@example.test')
            ->willReturn($existingUser);

        $authenticator = $this->buildAuthenticator(users: $users);

        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->invokeResolveUserFromGoogleToken(
            $authenticator,
            new class {
                public function fetchUserFromToken(object $accessToken): object
                {
                    $tokenWasProvided = $accessToken !== null;
                    if (!$tokenWasProvided) {
                        throw new \LogicException('Access token missing.');
                    }

                    return new class {
                        public function getId(): string { return 'gid-other'; }
                        public function getEmail(): string { return 'existing@example.test'; }
                        public function getName(): string { return 'Existing'; }
                    };
                }
            },
            new \stdClass()
        );
    }

    private function buildAuthenticator(
        ?UserRepository $users = null,
        ?EntityManagerInterface $entityManager = null,
        ?JWTTokenManagerInterface $jwtManager = null,
        ?LoggerInterface $logger = null
    ): GoogleAuthenticator {
        $users ??= $this->getMockBuilder(UserRepository::class)->disableOriginalConstructor()->onlyMethods([
            'findOneBy',
            'findOneByEmailInsensitive',
        ])->getMock();

        return new GoogleAuthenticator(
            $this->createMock(ClientRegistry::class),
            $users,
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $jwtManager ?? $this->createMock(JWTTokenManagerInterface::class),
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }

    private function invokeResolveUserFromGoogleToken(
        GoogleAuthenticator $authenticator,
        object $client,
        object $accessToken
    ): User {
        $resolver = \Closure::bind(
            function (object $clientArg, object $tokenArg): User {
                /** @var GoogleAuthenticator $this */
                return $this->resolveUserFromGoogleToken($clientArg, $tokenArg);
            },
            $authenticator,
            GoogleAuthenticator::class,
        );

        return $resolver($client, $accessToken);
    }
}

