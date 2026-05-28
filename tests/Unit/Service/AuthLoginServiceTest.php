<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuthLoginService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Unit tests for AuthLoginService.
 */
final class AuthLoginServiceTest extends TestCase
{
    /**
     * Returns a 400 payload when credentials are missing.
     */
    public function testAuthenticateReturnsMissingCredentialsError(): void
    {
        $service = new AuthLoginService(
            $this->createMock(UserRepository::class),
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(JWTTokenManagerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $result = $service->authenticate('', '');

        self::assertSame(400, $result['status']);
        self::assertSame('missing_credentials', $result['payload']['code']);
    }

    /**
     * Returns a 401 payload when credentials are invalid.
     */
    public function testAuthenticateReturnsInvalidCredentialsWhenUserNotFound(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'nobody@example.test'])
            ->willReturn(null);

        $service = new AuthLoginService(
            $users,
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(JWTTokenManagerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $result = $service->authenticate('nobody@example.test', 'bad-password');

        self::assertSame(401, $result['status']);
        self::assertSame('invalid_credentials', $result['payload']['code']);
    }

    /**
     * Returns JWT payload when credentials are valid.
     */
    public function testAuthenticateReturnsJwtTokenWhenCredentialsAreValid(): void
    {
        $user = (new User())
            ->setUsername('alice')
            ->setEmail('alice@example.test')
            ->setPassword('hashed');

        $users = $this->createMock(UserRepository::class);
        $users
            ->method('findOneBy')
            ->with(['email' => 'alice@example.test'])
            ->willReturn($user);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher
            ->expects(self::once())
            ->method('isPasswordValid')
            ->with($user, 'good-password')
            ->willReturn(true);

        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $jwtManager
            ->expects(self::once())
            ->method('create')
            ->with($user)
            ->willReturn('jwt-token-value');

        $service = new AuthLoginService(
            $users,
            $hasher,
            $jwtManager,
            $this->createMock(LoggerInterface::class),
        );

        $result = $service->authenticate('alice@example.test', 'good-password');

        self::assertSame(200, $result['status']);
        self::assertSame('jwt-token-value', $result['payload']['token']);
    }

    /**
     * Returns a 500 payload and logs error when JWT generation fails.
     */
    public function testAuthenticateReturnsServerErrorWhenJwtGenerationFails(): void
    {
        $user = (new User())
            ->setUsername('bob')
            ->setEmail('bob@example.test')
            ->setPassword('hashed');

        $users = $this->createMock(UserRepository::class);
        $users
            ->method('findOneBy')
            ->with(['email' => 'bob@example.test'])
            ->willReturn($user);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher
            ->method('isPasswordValid')
            ->with($user, 'good-password')
            ->willReturn(true);

        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $jwtManager
            ->method('create')
            ->with($user)
            ->willThrowException(new \RuntimeException('bad key'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'JWT generation failed during login.',
                self::callback(static function (array $context): bool {
                    return ($context['email'] ?? null) === 'bob@example.test'
                        && isset($context['exception_class'])
                        && isset($context['exception_message']);
                })
            );

        $service = new AuthLoginService($users, $hasher, $jwtManager, $logger);

        $result = $service->authenticate('bob@example.test', 'good-password');

        self::assertSame(500, $result['status']);
        self::assertSame('jwt_generation_failed', $result['payload']['code']);
    }
}
