<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Race;
use App\Entity\User;
use App\Service\RaceLogSyncService;
use App\Service\RpgEventService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Persists a race and mirrors a finished result into the run-log journal,
 * so validating an official race automatically adds it to the logs.
 */
final class RaceProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $em,
        private RaceLogSyncService $raceLogSync,
        private RpgEventService $rpgEvents,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Race
    {
        if (!$data instanceof Race) {
            throw new \InvalidArgumentException('Race expected.');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        $data->setUser($user);
        $this->em->persist($data);
        $this->em->flush();

        // Validating a race (a result, no DNS/DNF) mirrors it into the journal.
        $this->raceLogSync->syncForRace($data, $data->getLogExtra(), overwrite: true);
        $this->rpgEvents->processRace($data, $user);

        return $data;
    }
}
