<?php

namespace App\Controller;

use App\Entity\RunLog;
use App\Entity\User;
use App\Repository\RunLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders the run log page shell.
 */
class LogController extends AbstractController
{
    private const FLASH_ERROR = 'error';
    private const FLASH_SUCCESS = 'success';

    /**
     * Displays the log page.
     */
    #[Route('/log', name: 'app_log')]
    public function index(RunLogRepository $runLogRepository): Response
    {
        $user = $this->getUser();
        $logs = [];
        if ($user instanceof User) {
            $logs = $runLogRepository->findBy(['user' => $user], ['date' => 'DESC'], 200);
        }

        return $this->render('log/index.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
            'logsView' => $logs,
        ]);
    }

    #[Route('/log/create', name: 'app_log_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if (!$this->isCsrfTokenValid('log.create', (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_log');
        }

        $date = trim((string) $request->request->get('date', ''));
        $duration = trim((string) $request->request->get('duration', ''));
        $km = (float) $request->request->get('km', 0);

        if ($date === '' || $duration === '' || $km <= 0) {
            $this->addFlash(self::FLASH_ERROR, 'Date, km et durée requis.');
            return $this->redirectToRoute('app_log');
        }

        $log = (new RunLog())
            ->setUser($user)
            ->setDate($date)
            ->setKm($km)
            ->setDuration($duration)
            ->setDplus($this->nullableInt($request->request->get('dplus')))
            ->setBpm($this->nullableInt($request->request->get('bpm')))
            ->setRunType($this->nullableString($request->request->get('runType')))
            ->setCourseName($this->nullableString($request->request->get('courseName')));

        $setPerceivedEffort = 'setPerceivedEffort';
        $log->{$setPerceivedEffort}($this->nullableString($request->request->get('perceivedEffort')));

        if ($request->request->has('notes')) {
            $log->setNotes($this->nullableString($request->request->get('notes')));
        }

        $this->computeDerivedMetrics($log);

        $entityManager->persist($log);
        $entityManager->flush();

        $this->addFlash(self::FLASH_SUCCESS, 'Sortie enregistrée.');
        return $this->redirectToRoute('app_log');
    }

    #[Route('/log/{id<\d+>}/delete', name: 'app_log_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, RunLogRepository $runLogRepository, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $log = $runLogRepository->find($id);
        if (!$log instanceof RunLog || $log->getUser()->getId() !== $user->getId()) {
            $this->addFlash(self::FLASH_ERROR, 'Sortie introuvable.');
            return $this->redirectToRoute('app_log');
        }

        if (!$this->isCsrfTokenValid('log.delete.' . $id, (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_log');
        }

        $entityManager->remove($log);
        $entityManager->flush();

        $this->addFlash(self::FLASH_SUCCESS, 'Sortie supprimée.');
        return $this->redirectToRoute('app_log');
    }

    #[Route('/log/{id<\d+>}/update', name: 'app_log_update', methods: ['POST'])]
    public function update(int $id, Request $request, RunLogRepository $runLogRepository, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $log = $runLogRepository->find($id);
        if (!$log instanceof RunLog || $log->getUser()->getId() !== $user->getId()) {
            $this->addFlash(self::FLASH_ERROR, 'Sortie introuvable.');
            return $this->redirectToRoute('app_log');
        }

        if (!$this->isCsrfTokenValid('log.update.' . $id, (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_log');
        }

        $date = trim((string) $request->request->get('date', ''));
        $duration = trim((string) $request->request->get('duration', ''));
        $km = (float) $request->request->get('km', 0);

        if ($date === '' || $duration === '' || $km <= 0) {
            $this->addFlash(self::FLASH_ERROR, 'Date, km et durée requis.');
            return $this->redirectToRoute('app_log');
        }

        $log
            ->setDate($date)
            ->setKm($km)
            ->setDuration($duration)
            ->setDplus($this->nullableInt($request->request->get('dplus')))
            ->setBpm($this->nullableInt($request->request->get('bpm')))
            ->setRunType($this->nullableString($request->request->get('runType')))
            ->setCourseName($this->nullableString($request->request->get('courseName')));

        $setPerceivedEffort = 'setPerceivedEffort';
        $log->{$setPerceivedEffort}($this->nullableString($request->request->get('perceivedEffort')));

        if ($request->request->has('notes')) {
            $log->setNotes($this->nullableString($request->request->get('notes')));
        }

        $this->computeDerivedMetrics($log);
        $entityManager->flush();

        $this->addFlash(self::FLASH_SUCCESS, 'Sortie mise à jour.');
        return $this->redirectToRoute('app_log');
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function computeDerivedMetrics(RunLog $log): void
    {
        $km = (float) ($log->getKm() ?? 0.0);
        $durationSec = $this->durationToSeconds($log->getDuration());

        if ($km > 0 && $durationSec !== null && $durationSec > 0) {
            $allureSec = (int) round($durationSec / $km);
            $log->setAllure($this->secondsToMinSec($allureSec));

            $dplus = (int) ($log->getDplus() ?? 0);
            if ($dplus > 0) {
                $grade = $dplus / ($km * 1000.0);
                $gapSec = (int) round($allureSec - ($grade * 7.5 * $allureSec));
                $log->setGap($gapSec > 0 ? $this->secondsToMinSec($gapSec) : null);
            } else {
                $log->setGap(null);
            }

            return;
        }

        $log->setAllure(null);
        $log->setGap(null);
    }

    private function durationToSeconds(?string $duration): ?int
    {
        if (!is_string($duration)) {
            return null;
        }

        $parts = array_map('intval', explode(':', trim($duration)));
        if (count($parts) === 3) {
            return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
        }
        if (count($parts) === 2) {
            return $parts[0] * 60 + $parts[1];
        }

        return null;
    }

    private function secondsToMinSec(int $seconds): string
    {
        $m = intdiv(max(0, $seconds), 60);
        $s = max(0, $seconds) % 60;
        return sprintf('%02d:%02d', $m, $s);
    }
}
