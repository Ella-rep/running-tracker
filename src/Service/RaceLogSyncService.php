<?php

namespace App\Service;

use App\Entity\Race;
use App\Entity\RunLog;
use App\Entity\User;
use App\Repository\RunLogRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps the run-log journal in sync with finished official races.
 *
 * When a race gets a recorded result (and is not DNS/DNF), a matching RunLog
 * of type "Race" is created so the race shows up in the journal and feeds the
 * training-load / projection metrics. Used both interactively (when a result
 * is validated) and in bulk (admin backfill of already-entered races).
 */
final class RaceLogSyncService
{
    private const RACE_RUN_TYPE = 'Race';

    public function __construct(
        private RunLogRepository $runLogs,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Creates or updates the RunLog mirroring a finished race.
     *
     * Returns the synced RunLog, or null when nothing was written (race not
     * finished, DNS/DNF, missing data, or already present without overwrite).
     *
     * @param array{km?:float|int|string|null,dplus?:int|string|null,bpm?:int|string|null,perceivedEffort?:string|null,notes?:string|null,duration?:string|null} $extra
     */
    public function syncForRace(Race $race, array $extra = [], bool $overwrite = false, bool $flush = true): ?RunLog
    {
        // DNS / DNF races never produce a log.
        if (in_array($race->getDnfStatus(), ['dns', 'dnf'], true)) {
            return null;
        }

        // The race result is the run duration, unless the caller overrides it.
        $result = trim((string) ($race->getResult() ?? ''));
        $duration = trim((string) ($extra['duration'] ?? $result));
        if ($duration === '') {
            return null; // no time recorded → nothing to log
        }

        $date = trim((string) $race->getDate());
        if ($date === '') {
            return null;
        }

        $km = $this->resolveKm($race, $extra['km'] ?? null);
        if ($km === null || $km <= 0) {
            return null; // cannot build a meaningful log without a distance
        }

        $existing = $this->findExistingLog($race);
        if ($existing instanceof RunLog && !$overwrite) {
            return null; // already mirrored — don't touch the user's data
        }

        $log = $existing ?? (new RunLog())->setUser($race->getUser());

        $log->setDate($date)
            ->setKm($km)
            ->setDuration($duration)
            ->setRunType(self::RACE_RUN_TYPE)
            ->setCourseName($race->getName());

        if (array_key_exists('dplus', $extra)) {
            $log->setDplus($this->nullableInt($extra['dplus']));
        }
        if (array_key_exists('bpm', $extra)) {
            $log->setBpm($this->nullableInt($extra['bpm']));
        }
        if (array_key_exists('perceivedEffort', $extra)) {
            $log->setPerceivedEffort($this->nullableString($extra['perceivedEffort']));
        }
        if (array_key_exists('notes', $extra)) {
            $log->setNotes($this->nullableString($extra['notes']));
        }

        $this->computeDerivedMetrics($log);

        $this->em->persist($log);
        if ($flush) {
            $this->em->flush();
        }

        return $log;
    }

    /**
     * Creates missing logs for every finished race of a user.
     * Never overwrites logs that already exist.
     *
     * @return int number of logs created
     */
    public function backfillForUser(User $user): int
    {
        /** @var array<int, Race> $races */
        $races = $this->em->getRepository(Race::class)->findBy(['user' => $user], ['date' => 'ASC']);

        $created = 0;
        foreach ($races as $race) {
            $log = $this->syncForRace($race, [], false, false);
            if ($log !== null) {
                $created++;
            }
        }

        if ($created > 0) {
            $this->em->flush();
        }

        return $created;
    }

    private function findExistingLog(Race $race): ?RunLog
    {
        $date = trim((string) $race->getDate());
        $name = trim((string) $race->getName());

        $candidates = $this->runLogs->findBy([
            'user' => $race->getUser(),
            'date' => $date,
        ]);

        foreach ($candidates as $log) {
            $type = strtoupper((string) ($log->getRunType() ?? ''));
            $sameName = strcasecmp(trim((string) ($log->getCourseName() ?? '')), $name) === 0;
            if ($sameName || $type === 'RACE') {
                return $log;
            }
        }

        return null;
    }

    private function resolveKm(Race $race, mixed $kmOverride): ?float
    {
        if ($kmOverride !== null && $kmOverride !== '') {
            $km = (float) str_replace(',', '.', (string) $kmOverride);
            if ($km > 0) {
                return $km;
            }
        }

        return $this->parseDistanceKm((string) ($race->getDistance() ?? ''));
    }

    private function parseDistanceKm(string $raw): ?float
    {
        $lower = strtolower(trim($raw));
        if ($lower === '') {
            return null;
        }
        if (str_contains($lower, 'semi') || (str_contains($lower, '21') && !str_contains($lower, '421'))) {
            return 21.1;
        }
        if (str_contains($lower, 'marathon') || str_contains($lower, '42')) {
            return 42.2;
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*km?/i', $raw, $matches)) {
            return (float) str_replace(',', '.', $matches[1]);
        }
        return null;
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

        $raw = trim($duration);
        if ($raw === '') {
            return null;
        }

        $candidates = [$raw];
        if (str_contains($raw, '/')) {
            foreach (explode('/', $raw) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $candidates[] = $part;
                }
            }
        }

        $seconds = null;
        foreach (array_unique($candidates) as $value) {
            if (preg_match("/^(\\d+)[\\'’]?$/", $value, $m) === 1) {
                $seconds = (int) $m[1] * 60;
                break;
            }

            if (preg_match('/^(\d{1,3}):([0-5]\d):([0-5]\d)$/', $value, $m) === 1) {
                $seconds = ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
                break;
            }

            if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $value, $m) === 1) {
                $seconds = ((int) $m[1]) * 60 + (int) $m[2];
                break;
            }
        }

        return $seconds;
    }

    private function secondsToMinSec(int $seconds): string
    {
        $m = intdiv(max(0, $seconds), 60);
        $s = max(0, $seconds) % 60;

        return sprintf('%02d:%02d', $m, $s);
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
}
