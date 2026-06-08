<?php

namespace App\Controller;

use App\Entity\PlanDetails;
use App\Entity\Race;
use App\Entity\User;
use App\Repository\PlanDetailsRepository;
use App\Repository\RaceRepository;
use App\Service\RaceLogSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders the races and courses page shell.
 */
class CoursesController extends AbstractController
{
    private const FLASH_ERROR = 'error';
    private const FLASH_SUCCESS = 'success';

    /**
     * Displays the courses page.
     */
    #[Route('/courses', name: 'app_courses')]
    public function index(RaceRepository $raceRepository): Response
    {
        $races = [];
        $user = $this->getUser();
        if ($user instanceof User) {
            $races = $raceRepository->findBy(['user' => $user], ['date' => 'ASC']);

            usort($races, static function (Race $a, Race $b): int {
                $aDone = $a->getStatusClass() === 'badge-done';
                $bDone = $b->getStatusClass() === 'badge-done';
                if ($aDone !== $bDone) {
                    return $aDone ? 1 : -1;
                }

                $dateCompare = strcmp($a->getDate(), $b->getDate());
                if ($dateCompare !== 0) {
                    return $dateCompare;
                }

                return (int) (($a->getId() ?? 0) <=> ($b->getId() ?? 0));
            });
        }

        return $this->render('courses/index.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
            'racesView' => $races,
        ]);
    }

    #[Route('/courses/create', name: 'app_courses_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if (!$this->isCsrfTokenValid('courses.create', (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_courses');
        }

        $name = trim((string) $request->request->get('name', ''));
        $date = trim((string) $request->request->get('date', ''));
        if ($name === '' || $date === '') {
            $this->addFlash(self::FLASH_ERROR, 'Nom et date requis.');
            return $this->redirectToRoute('app_courses');
        }

        $race = (new Race())
            ->setUser($user)
            ->setName($name)
            ->setDate($date)
            ->setDistance($this->nullableString($request->request->get('distance')))
            ->setObjective($this->nullableString($request->request->get('objective')))
            ->setResult($this->nullableString($request->request->get('result')));

        $entityManager->persist($race);
        $entityManager->flush();

        $this->addFlash(self::FLASH_SUCCESS, 'Course ajoutée.');
        return $this->redirectToRoute('app_courses');
    }

    #[Route('/courses/{id<\d+>}/delete', name: 'app_courses_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, RaceRepository $raceRepository, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $race = $raceRepository->find($id);
        if (!$race instanceof Race || $race->getUser()->getId() !== $user->getId()) {
            $this->addFlash(self::FLASH_ERROR, 'Course introuvable.');
            return $this->redirectToRoute('app_courses');
        }

        if (!$this->isCsrfTokenValid('courses.delete.' . $id, (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_courses');
        }

        $entityManager->remove($race);
        $entityManager->flush();

        $this->addFlash(self::FLASH_SUCCESS, 'Course supprimée.');
        return $this->redirectToRoute('app_courses');
    }

    #[Route('/courses/{id<\d+>}/result', name: 'app_courses_result', methods: ['POST'])]
    public function updateResult(int $id, Request $request, RaceRepository $raceRepository, EntityManagerInterface $entityManager, RaceLogSyncService $raceLogSync, PlanDetailsRepository $planDetailsRepository): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $race = $raceRepository->find($id);
        if (!$race instanceof Race || $race->getUser()->getId() !== $user->getId()) {
            $this->addFlash(self::FLASH_ERROR, 'Course introuvable.');
            return $this->redirectToRoute('app_courses');
        }

        if (!$this->isCsrfTokenValid('courses.result.' . $id, (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_courses');
        }

        $dnfStatus = $this->nullableString($request->request->get('dnf_status'));
        if (in_array($dnfStatus, ['dns', 'dnf'], true)) {
            $race->setDnfStatus($dnfStatus);
            $race->setDnfComment($this->nullableString($request->request->get('dnf_comment')));
            $race->setResult(null);
        } else {
            $race->setDnfStatus(null);
            $race->setDnfComment(null);
            $race->setResult($this->nullableString($request->request->get('result')));
        }
        $entityManager->flush();

        // Auto-cancel plan sessions on the same date when a race is marked DNS.
        if ($user instanceof User) {
            $raceDate = $race->getDate(); // 'Y-m-d' string
            $planSessions = $planDetailsRepository->findBy(['user' => $user]);
            foreach ($planSessions as $ps) {
                if (!$ps instanceof PlanDetails) continue;
                $sessionDate = $ps->getSessionDate()?->format('Y-m-d');
                if ($sessionDate !== $raceDate) continue;
                $sessionType = strtoupper(trim((string) ($ps->getSessionType() ?? '')));
                if ($sessionType !== 'RACE') continue;
                // DNS → cancel; anything else → restore
                $ps->setIsCancelled($race->getDnfStatus() === 'dns');
            }
            $entityManager->flush();
        }

        // Mirror a validated result (not DNS/DNF) into the run-log journal.
        $extra = [
            'km' => $this->nullableString($request->request->get('km')),
            'dplus' => $this->nullableString($request->request->get('dplus')),
            'bpm' => $this->nullableString($request->request->get('bpm')),
            'perceivedEffort' => $this->nullableString($request->request->get('perceivedEffort')),
            'notes' => $this->nullableString($request->request->get('notes')),
        ];
        $raceLogSync->syncForRace($race, array_filter($extra, static fn ($v) => $v !== null), overwrite: true);

        $this->addFlash(self::FLASH_SUCCESS, 'Résultat mis à jour.');
        return $this->redirectToRoute('app_courses');
    }

    #[Route('/courses/{id<\d+>}/update', name: 'app_courses_update', methods: ['POST'])]
    public function update(int $id, Request $request, RaceRepository $raceRepository, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $race = $raceRepository->find($id);
        if (!$race instanceof Race || $race->getUser()->getId() !== $user->getId()) {
            $this->addFlash(self::FLASH_ERROR, 'Course introuvable.');
            return $this->redirectToRoute('app_courses');
        }

        if (!$this->isCsrfTokenValid('courses.update.' . $id, (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_courses');
        }

        $name = trim((string) $request->request->get('name', ''));
        $date = trim((string) $request->request->get('date', ''));
        if ($name === '' || $date === '') {
            $this->addFlash(self::FLASH_ERROR, 'Nom et date requis.');
            return $this->redirectToRoute('app_courses');
        }

        $race
            ->setName($name)
            ->setDate($date)
            ->setDistance($this->nullableString($request->request->get('distance')))
            ->setObjective($this->nullableString($request->request->get('objective')));

        $entityManager->flush();

        $this->addFlash(self::FLASH_SUCCESS, 'Course mise à jour.');
        return $this->redirectToRoute('app_courses');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
