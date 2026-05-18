<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class MeteoService
{
    private const TITLE = 'Conseil meteo du jour';
    private const COLOR_INFO = '#8b9cf4';
    private const COLOR_WARNING = '#f0c040';
    private const COLOR_SUCCESS = '#4ade80';

    // Default location: Paris. Can be changed later via constructor args/env binding.
    private const DEFAULT_LAT = 48.8566;
    private const DEFAULT_LON = 2.3522;
    private const DEFAULT_CITY_LABEL = 'Paris';

    public function __construct(
        private RequestStack $requestStack,
    ) {}

    /**
     * @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string}
     */
    public function buildDailyAdvice(?\DateTimeImmutable $date = null, ?string $city = null): array
    {
        $requestedCity = trim((string) $city);
        $errors = [];
        $resolved = $this->resolveLocation($city, $errors);

        $liveAdvice = $this->buildLiveAdvice($resolved['lat'], $resolved['lon'], $resolved['label'], $errors);
        if ($liveAdvice !== null) {
            return $this->withCityFeedback($liveAdvice, $requestedCity);
        }

        $this->logFallbackErrors($errors, $city, $resolved['label']);

        $today = $date ?? new \DateTimeImmutable('today');
        return $this->withCityFeedback(
            $this->buildFallbackAdvice($today, self::DEFAULT_CITY_LABEL),
            $requestedCity
        );
    }

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string}|null */
    private function buildLiveAdvice(float $lat, float $lon, string $label, array &$errors): ?array
    {
        $data = $this->fetchWeather($lat, $lon, $errors);
        if ($data === null) {
            return null;
        }

        $advice = $this->buildAdviceFromApiPayload($data, $label);
        if ($advice === null) {
            $errors[] = 'E3: reponse meteo incomplete.';
        }

        return $advice;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string}|null
     */
    private function buildAdviceFromApiPayload(array $data, string $label): ?array
    {
        $current = is_array($data['current'] ?? null) ? $data['current'] : [];
        $daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];

        $temp = $this->asFloat($current['temperature_2m'] ?? null);
        $rain = $this->asFloat($current['precipitation'] ?? null);
        $wind = $this->asFloat($current['wind_speed_10m'] ?? null);
        $weatherCode = (int) ($current['weather_code'] ?? -1);

        $precipProbMax = null;
        if (is_array($daily['precipitation_probability_max'] ?? null) && isset($daily['precipitation_probability_max'][0])) {
            $precipProbMax = $this->asFloat($daily['precipitation_probability_max'][0]);
        }

        if ($temp === null && $rain === null && $wind === null && $precipProbMax === null) {
            return null;
        }

        $advice = [
            'title' => self::TITLE,
            'text' => 'Meteo variable: adapte l\'allure a la sensation du jour et prevois une couche legere.',
            'tone' => 'info',
            'icon' => '🌥️',
            'color' => self::COLOR_INFO,
            'badge' => $this->buildBadge('', $label),
        ];

        if ($this->isRainy($rain, $precipProbMax)) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Pluie probable: prevois une veste legere, reduis les allures rapides et privilegie un footing controle.',
                'tone' => 'warning',
                'icon' => '🌧️',
                'color' => self::COLOR_WARNING,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif ($this->isWindy($wind)) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Vent soutenu: pars prudemment, abrite tes fractions et garde de l\'energie pour le retour face au vent.',
                'tone' => 'info',
                'icon' => '💨',
                'color' => self::COLOR_INFO,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif ($temp !== null && $temp >= 28.0) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Chaleur marquee: vise une sortie plus tot/tard, baisse l\'intensite et hydrate-toi regulierement.',
                'tone' => 'warning',
                'icon' => '☀️',
                'color' => self::COLOR_WARNING,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif ($temp !== null && $temp <= 3.0) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Froid marque: echauffement progressif, extremites couvertes et allure facile sur les premiers kilometres.',
                'tone' => 'info',
                'icon' => '🧣',
                'color' => self::COLOR_INFO,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif (in_array($weatherCode, [0, 1], true)) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Conditions favorables: bonne fenetre pour ta seance. Pense quand meme a t\'hydrater.',
                'tone' => 'encourage',
                'icon' => '🌤️',
                'color' => self::COLOR_SUCCESS,
                'badge' => $this->buildBadge('', $label),
            ];
        }

        return $advice;
    }

    /** @return array<string,mixed>|null */
    private function fetchWeather(float $lat, float $lon, array &$errors): ?array
    {
        $query = http_build_query([
            'latitude' => $lat,
            'longitude' => $lon,
            'current' => 'temperature_2m,precipitation,wind_speed_10m,weather_code,is_day',
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max',
            'forecast_days' => 1,
            'timezone' => 'auto',
        ]);

        $url = 'https://api.open-meteo.com/v1/forecast?' . $query;
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw) || $raw === '') {
            $errors[] = 'E3: appel API meteo indisponible.';
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $errors[] = 'E3: payload meteo non lisible.';
            return null;
        }

        return $decoded;
    }

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string} */
    private function buildFallbackAdvice(\DateTimeImmutable $today, string $label): array
    {
        $month = (int) $today->format('n');
        $advice = [
            'title' => self::TITLE,
            'text' => 'Meteo variable: prevois une couche legere coupe-vent et adapte l\'allure selon vent/pluie.',
            'tone' => 'encourage',
            'icon' => '🌤️',
            'color' => self::COLOR_SUCCESS,
            'badge' => $this->buildBadge('', $label),
        ];

        if ($month <= 2 || $month === 12) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Temps frais: echauffe-toi 10-15 min, couvre les extremites, et reste en endurance si vent fort.',
                'tone' => 'info',
                'icon' => '🧣',
                'color' => self::COLOR_INFO,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif ($month >= 6 && $month <= 8) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Chaleur: pars tot ou tard, reduis l\'intensite, hydrate-toi avant/pendant/apres (petites gorgees regulieres).',
                'tone' => 'warning',
                'icon' => '☀️',
                'color' => self::COLOR_WARNING,
                'badge' => $this->buildBadge('', $label),
            ];
        }

        return $advice;
    }

    private function asFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function isRainy(?float $rain, ?float $precipProbMax): bool
    {
        return ($rain !== null && $rain >= 0.2)
            || ($precipProbMax !== null && $precipProbMax >= 60);
    }

    private function isWindy(?float $wind): bool
    {
        return $wind !== null && $wind >= 30.0;
    }

    private function buildBadge(string $prefix, string $label): string
    {
        $safeLabel = trim($label) !== '' ? $label : self::DEFAULT_CITY_LABEL;
        if (trim($prefix) === '') {
            return $safeLabel;
        }

        return $prefix . ' · ' . $safeLabel;
    }

    /**
     * @param array{title:string,text:string,tone:string,icon:string,color:string,badge:string} $advice
     * @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string,cityStatus:string,cityMessage:string,cityApplied:bool,requestedCity:?string,appliedCity:string}
     */
    private function withCityFeedback(array $advice, string $requestedCity): array
    {
        $appliedCity = trim((string) ($advice['badge'] ?? self::DEFAULT_CITY_LABEL));
        if ($appliedCity === '') {
            $appliedCity = self::DEFAULT_CITY_LABEL;
        }

        if ($requestedCity === '') {
            $advice['cityStatus'] = 'auto';
            $advice['cityMessage'] = 'Ville meteo automatique';
            $advice['cityApplied'] = true;
            $advice['requestedCity'] = null;
            $advice['appliedCity'] = $appliedCity;

            return $advice;
        }

        $requestedAscii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $requestedCity);
        $appliedAscii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $appliedCity);
        $requested = trim((string) preg_replace('/[^a-z0-9]+/u', ' ', strtolower((string) ($requestedAscii !== false ? $requestedAscii : $requestedCity))));
        $appliedNormalized = trim((string) preg_replace('/[^a-z0-9]+/u', ' ', strtolower((string) ($appliedAscii !== false ? $appliedAscii : $appliedCity))));
        $applied = $requested !== ''
            && $appliedNormalized !== ''
            && ($requested === $appliedNormalized || str_contains($appliedNormalized, $requested));

        $advice['cityStatus'] = $applied ? 'applied' : 'error';
        $advice['cityMessage'] = $applied
            ? 'Ville meteo appliquee: ' . $appliedCity
            : 'Ville meteo non appliquee: ' . $requestedCity . '. Ville utilisee: ' . $appliedCity . '.';
        $advice['cityApplied'] = $applied;
        $advice['requestedCity'] = $requestedCity;
        $advice['appliedCity'] = $appliedCity;

        return $advice;
    }

    private function logFallbackErrors(array $errors, ?string $requestedCity, string $resolvedLabel): void
    {
        $diagnostic = '';
        if ($errors !== []) {
            $uniq = array_values(array_unique(array_map(static fn ($e) => trim((string) $e), $errors)));
            $top = array_slice(array_values(array_filter($uniq, static fn ($e) => $e !== '')), 0, 3);
            if ($top !== []) {
                $diagnostic = 'Erreurs: ' . implode(' ', $top);
            }
        }
        $requested = trim((string) $requestedCity);

        $parts = ['Meteo fallback active'];
        $parts[] = 'ville_fallback=' . self::DEFAULT_CITY_LABEL;
        $parts[] = 'ville_resolue=' . $resolvedLabel;
        $parts[] = 'ville_demandee=' . ($requested !== '' ? $requested : 'auto');
        if ($diagnostic !== '') {
            $parts[] = $diagnostic;
        }

        error_log('[MeteoService] ' . implode(' | ', $parts));
    }

    /** @return array{lat:float,lon:float,label:string} */
    private function resolveLocation(?string $city, array &$errors): array
    {
        $cityName = trim((string) $city);
        if ($cityName !== '') {
            $byCity = $this->fetchGeoByCity($cityName);
            if ($byCity !== null) {
                return $byCity;
            }
            $errors[] = 'E1: ville introuvable.';
        }

        $byIp = $this->resolveCoordinatesFromClientIp();
        if ($byIp !== null) {
            return $byIp;
        }

        $errors[] = 'E2: geolocalisation IP indisponible.';

        return [
            'lat' => self::DEFAULT_LAT,
            'lon' => self::DEFAULT_LON,
            'label' => self::DEFAULT_CITY_LABEL,
        ];
    }

    /** @return array{lat:float,lon:float,label:string}|null */
    private function resolveCoordinatesFromClientIp(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return null;
        }

        $ip = $this->resolvePublicClientIp($request);
        if ($ip === null) {
            return null;
        }

        return $this->fetchGeoByIp($ip);
    }

    private function resolvePublicClientIp(Request $request): ?string
    {
        $resolved = null;

        $xff = $request->headers->get('X-Forwarded-For');
        if (is_string($xff) && trim($xff) !== '') {
            $chain = array_map('trim', explode(',', $xff));
            $resolved = $this->firstPublicIpFromList($chain);
        }

        if ($resolved === null) {
            $ips = $request->getClientIps();
            $resolved = $this->firstPublicIpFromList($ips);
        }

        if ($resolved === null) {
            $single = $request->getClientIp();
            if ($this->isPublicIp($single)) {
                $resolved = $single;
            }
        }

        return $resolved;
    }

    /** @param array<int,string> $ips */
    private function firstPublicIpFromList(array $ips): ?string
    {
        foreach ($ips as $candidate) {
            if ($this->isPublicIp($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isPublicIp(?string $ip): bool
    {
        if (!is_string($ip) || trim($ip) === '') {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** @return array{lat:float,lon:float,label:string}|null */
    private function fetchGeoByIp(string $ip): ?array
    {
        $coords = null;
        $url = 'https://ipapi.co/' . rawurlencode($ip) . '/json/';
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $parsed = $this->parseIpGeoPayload($decoded);
                if ($parsed !== null) {
                    $coords = $parsed;
                }
            }
        }

        return $coords;
    }

    /** @return array{lat:float,lon:float,label:string}|null */
    private function fetchGeoByCity(string $city): ?array
    {
        $coords = null;
        $query = http_build_query([
            'name' => $city,
            'count' => 1,
            'language' => 'fr',
            'format' => 'json',
        ]);

        $url = 'https://geocoding-api.open-meteo.com/v1/search?' . $query;
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && is_array($decoded['results'] ?? null) && isset($decoded['results'][0]) && is_array($decoded['results'][0])) {
                $first = $decoded['results'][0];
                $lat = $this->asFloat($first['latitude'] ?? null);
                $lon = $this->asFloat($first['longitude'] ?? null);
                if ($lat !== null && $lon !== null) {
                    $label = $this->buildGeoLabel(
                        (string) ($first['name'] ?? ''),
                        (string) ($first['country'] ?? ''),
                        trim($city)
                    );
                    $coords = [
                        'lat' => $lat,
                        'lon' => $lon,
                        'label' => $label,
                    ];
                }
            }
        }

        return $coords;
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{lat:float,lon:float,label:string}|null
     */
    private function parseIpGeoPayload(array $decoded): ?array
    {
        $lat = $this->asFloat($decoded['latitude'] ?? null);
        $lon = $this->asFloat($decoded['longitude'] ?? null);
        if ($lat === null || $lon === null) {
            return null;
        }

        $label = $this->buildGeoLabel(
            (string) ($decoded['city'] ?? ''),
            (string) ($decoded['country_name'] ?? ''),
            self::DEFAULT_CITY_LABEL
        );

        return ['lat' => $lat, 'lon' => $lon, 'label' => $label];
    }

    private function buildGeoLabel(string $city, string $country, string $fallback): string
    {
        $cityName = trim($city);
        $countryName = trim($country);

        $label = $cityName;
        if ($label === '') {
            $label = $countryName;
        } elseif ($countryName !== '' && stripos($label, $countryName) === false) {
            $label .= ', ' . $countryName;
        }

        if ($label === '') {
            $label = trim($fallback);
        }
        if ($label === '') {
            $label = self::DEFAULT_CITY_LABEL;
        }

        return $label;
    }
}
