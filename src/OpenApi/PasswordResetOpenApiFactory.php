<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;

final class PasswordResetOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(private readonly OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();

        $requestResetOperation = new Operation(
            operationId: 'postResetPasswordRequest',
            tags: ['Auth'],
            summary: 'Demander un lien de réinitialisation',
            description: 'Déclenche l\'envoi d\'un email de réinitialisation pour l\'adresse fournie.',
            requestBody: new RequestBody(
                description: 'Adresse email du compte',
                content: new \ArrayObject([
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['email'],
                            'properties' => [
                                'email' => ['type' => 'string', 'format' => 'email'],
                            ],
                        ],
                        'example' => [
                            'email' => 'user@example.com',
                        ],
                    ],
                ]),
                required: true,
            ),
            responses: [
                '200' => new Response(
                    description: 'Réponse générique pour éviter l\'énumération des comptes.',
                    content: new \ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'message' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ]),
                ),
                '400' => new Response(description: 'Payload invalide (email manquant ou JSON invalide).'),
                '500' => new Response(description: 'Erreur interne lors de l\'envoi.'),
            ],
        );

        $confirmResetOperation = new Operation(
            operationId: 'postResetPasswordConfirm',
            tags: ['Auth'],
            summary: 'Confirmer la réinitialisation',
            description: 'Met à jour le mot de passe à partir du token de réinitialisation.',
            requestBody: new RequestBody(
                description: 'Token reçu par email et nouveau mot de passe',
                content: new \ArrayObject([
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['token', 'plainPassword'],
                            'properties' => [
                                'token' => ['type' => 'string'],
                                'plainPassword' => ['type' => 'string', 'minLength' => 6],
                            ],
                        ],
                        'example' => [
                            'token' => 'reset_token_here',
                            'plainPassword' => 'newStrongPassword',
                        ],
                    ],
                ]),
                required: true,
            ),
            responses: [
                '200' => new Response(description: 'Mot de passe mis à jour.'),
                '400' => new Response(description: 'Token invalide/expiré ou mot de passe invalide.'),
                '500' => new Response(description: 'Erreur interne lors de la réinitialisation.'),
            ],
        );

        $paths->addPath('/api/auth/reset-password/request', new PathItem(post: $requestResetOperation));
        $paths->addPath('/api/auth/reset-password/confirm', new PathItem(post: $confirmResetOperation));

        return $openApi;
    }
}
