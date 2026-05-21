<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Plan;
use App\Entity\User;
use App\Repository\PlanRepository;
use App\Service\PlanSessionReplaceService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PlanSessionsReplaceProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private PlanRepository $planRepository,
        private PlanSessionReplaceService $replaceService,
        private RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Plan
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        $planId = (int) ($uriVariables['id'] ?? 0);
        $plan = $this->planRepository->find($planId);
        if (!$plan instanceof Plan) {
            throw new NotFoundHttpException('Plan not found.');
        }

        if ($plan->getUser()->getId() !== $user->getId()) {
            throw new AccessDeniedHttpException('Forbidden for this plan.');
        }

        [$sessions, $doneMap] = $this->extractPayloadFromRequest();
        if ($sessions === [] && $doneMap === []) {
            [$sessions, $doneMap] = $this->extractPayloadFromData($data);
        }

        $this->replaceService->replaceForPlan($plan, $user, $sessions, $doneMap);

        return $plan;
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int|string, bool>} */
    private function extractPayloadFromRequest(): array
    {
        $sessions = [];
        $doneMap = [];
        $request = $this->requestStack->getCurrentRequest();
        $rawPayload = is_string($request?->getContent()) ? trim((string) $request->getContent()) : '';
        if ($rawPayload !== '') {
            try {
                $decoded = json_decode($rawPayload, true, 512, \JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $sessions = is_array($decoded['sessions'] ?? null) ? $decoded['sessions'] : [];
                    $doneMap = is_array($decoded['doneMap'] ?? null) ? $decoded['doneMap'] : [];
                }
            } catch (\JsonException) {
                // Ignore invalid JSON here and fallback to deserialized data.
            }
        }

        return [$sessions, $doneMap];
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int|string, bool>} */
    private function extractPayloadFromData(mixed $data): array
    {
        if (is_object($data)) {
            $sessions = is_array($data->sessions ?? null) ? $data->sessions : [];
            $doneMap = is_array($data->doneMap ?? null) ? $data->doneMap : [];

            return [$sessions, $doneMap];
        }

        if (is_array($data)) {
            $sessions = is_array($data['sessions'] ?? null) ? $data['sessions'] : [];
            $doneMap = is_array($data['doneMap'] ?? null) ? $data['doneMap'] : [];

            return [$sessions, $doneMap];
        }

        return [[], []];
    }
}
