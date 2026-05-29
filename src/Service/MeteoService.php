<?php

namespace App\Service;

use App\Service\LocService;

/**
 * Provides daily weather advice with city and IP-based geolocation fallback.
 */
final class MeteoService
{
    private const TITLE = 'Conseil meteo du jour';
    private const COLOR_INFO = '#8b9cf4';
    private const COLOR_WARNING = '#f0c040';
    private const COLOR_SUCCESS = '#4ade80';
    private const PROXY_TCP_SCHEME = 'tcp://';
    

    // Default location: Paris. Can be changed later via constructor args/env binding.
    private const DEFAULT_LAT = 48.8566;
    private const DEFAULT_LON = 2.3522;
    private const DEFAULT_CITY_LABEL = 'Paris';
    

    /**
     * @param RequestStack $requestStack Request stack used to resolve client IP for geolocation.
     */
    public function __construct(
        private LocService $locService,
    ) {}

    /**
     * Builds the weather advice card for the requested or inferred location.
     *
     * @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string}
     */
    public function buildDailyAdvice(?string $city = null): array
    {
        $requestedCity = trim((string) $city);
        $errors = [];
        $resolved = $this->resolveLocation($city, $errors);

        $liveAdvice = $this->buildLiveAdvice($resolved['lat'], $resolved['lon'], $resolved['label'], $errors);
        if ($liveAdvice !== null) {
            return $this->withCityFeedback($liveAdvice, $requestedCity, $resolved['source']);
        }

        return $this->withCityFeedback(
            $this->buildErrorAdvice($resolved['label']),
            $requestedCity,
            $resolved['source']
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
        $tempMax = null;
        if (is_array($daily['temperature_2m_max'] ?? null) && isset($daily['temperature_2m_max'][0])) {
            $tempMax = $this->asFloat($daily['temperature_2m_max'][0]);
        }
        $rain = $this->asFloat($current['precipitation'] ?? null);
        $wind = $this->asFloat($current['wind_speed_10m'] ?? null);
        $weatherCode = (int) ($current['weather_code'] ?? -1);

        $precipProbMax = null;
        if (is_array($daily['precipitation_probability_max'] ?? null) && isset($daily['precipitation_probability_max'][0])) {
            $precipProbMax = $this->asFloat($daily['precipitation_probability_max'][0]);
        }

        if ($temp === null && $tempMax === null && $rain === null && $wind === null && $precipProbMax === null) {
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

        if ($this->isHeatwave($temp, $tempMax)) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Alerte chaleur/canicule: si possible, privilegie une sortie tres tot ou tard, avec une intensite reduite et une hydratation reguliere.',
                'tone' => 'warning',
                'icon' => '🔥',
                'color' => self::COLOR_WARNING,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif ($this->isHot($temp, $tempMax)) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Chaleur marquee: une sortie plus tot/tard, avec une intensite adaptee et une hydratation reguliere, peut etre plus confortable.',
                'tone' => 'warning',
                'icon' => '☀️',
                'color' => self::COLOR_WARNING,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif ($this->isRainy($rain, $precipProbMax)) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Pluie probable: tu peux prevoir une veste legere, limiter les allures rapides et privilegier un footing controle.',
                'tone' => 'warning',
                'icon' => '🌧️',
                'color' => self::COLOR_WARNING,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif ($this->isWindy($wind)) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Vent soutenu: pars tranquillement, abrite tes fractions si possible et garde un peu d\'energie pour le retour face au vent.',
                'tone' => 'info',
                'icon' => '💨',
                'color' => self::COLOR_INFO,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif ($temp !== null && $temp <= 3.0) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Froid marque: un echauffement progressif, les extremites couvertes et une allure facile sur les premiers kilometres peuvent aider.',
                'tone' => 'info',
                'icon' => '🧣',
                'color' => self::COLOR_INFO,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif (in_array($weatherCode, [0, 1], true)) {
            $advice = [
                'title' => self::TITLE,
                'text' => 'Conditions favorables: bonne fenetre pour ta seance. Une hydratation adaptee reste toujours utile.',
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

        $raw = $this->fetchRawWithRetry($url, 3);
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
    private function buildErrorAdvice(string $label): array
    {
        return [
            'title' => self::TITLE,
            'text' => 'Impossible de recuperer la meteo en direct pour le moment. Reessaie dans quelques minutes ou choisis une autre ville.',
            'tone' => 'warning',
            'icon' => '⚠️',
            'color' => self::COLOR_WARNING,
            'badge' => $this->buildBadge('', $label),
        ];
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

    private function isHot(?float $temp, ?float $tempMax): bool
    {
        return ($temp !== null && $temp >= 25.0)
            || ($tempMax !== null && $tempMax >= 28.0);
    }

    private function isHeatwave(?float $temp, ?float $tempMax): bool
    {
        return ($temp !== null && $temp >= 27.0)
            || ($tempMax !== null && $tempMax >= 30.0);
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
     * @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string,cityStatus:string,cityMessage:string,cityApplied:bool,requestedCity:?string,appliedCity:string,detectedCity:?string,detectedCityStatus:string,detectedCityMessage:string}
     */
    private function withCityFeedback(array $advice, string $requestedCity, string $locationSource): array
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
            $advice['detectedCity'] = $locationSource === 'ip' ? $appliedCity : null;
            $advice['detectedCityStatus'] = $locationSource === 'ip' ? 'ok' : 'error';
            $advice['detectedCityMessage'] = $locationSource === 'ip'
                ? 'Ville detectee: ' . $appliedCity
                : 'Echec localisation,  saisissez une ville.';

            return $advice;
        }

        // Normalize both strings to compare city names despite accents or punctuation differences.
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
        $advice['detectedCity'] = null;
        $advice['detectedCityStatus'] = 'manual';
        $advice['detectedCityMessage'] = '';

        return $advice;
    }

    /** @return array{lat:float,lon:float,label:string,source:string} */
    private function resolveLocation(?string $city, array &$errors): array
    {
        $cityName = trim((string) $city);
        if ($cityName !== '') {
            $byCity = $this->fetchGeoByCity($cityName);
            if ($byCity !== null) {
                $byCity['source'] = 'city';
                return $byCity;
            }
            $errors[] = 'E1: ville introuvable.';
        }

        $byLocation = $this->locService->resolveUsersLocation();
        if($byLocation){
            $result = $this->fetchGeoByCity($byLocation);
            if ($result !== null) {
                $result['source'] = 'ip';
                return $result;
            }
        }

        $errors[] = 'E2: geolocalisation IP indisponible.';

        return [
            'lat' => self::DEFAULT_LAT,
            'lon' => self::DEFAULT_LON,
            'label' => self::DEFAULT_CITY_LABEL,
            'source' => 'default',
        ];
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

        $raw = $this->fetchRawWithRetry($url, 3);
        
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

    private function fetchRawWithRetry(string $url, int $maxAttempts = 3): ?string
    {
        $attempts = max(1, $maxAttempts);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $context = $this->buildHttpContext();
            $raw = @file_get_contents($url, false, $context);

            if (is_string($raw) && $raw !== '' && !$this->looksLikeHtmlErrorPage($raw)) {
                return $raw;
            }

            usleep(120000);
        }

        return null;
    }

    private function buildHttpContext()
    {
        $proxy = $this->resolveProxyUrl();
        $http = [
            'timeout' => 4,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: running-tracker/1.0\r\n",
        ];

        if ($proxy !== null) {
            $http['proxy'] = $proxy;
            $http['request_fulluri'] = true;
        }

        return stream_context_create([
            'http' => $http,
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
    }

    private function resolveProxyUrl(): ?string
    {
        $raw = trim((string) (getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: getenv('APT_HTTPS_PROXY') ?: ''));
        if ($raw === '') {
            $raw = trim((string) (getenv('HTTP_PROXY') ?: getenv('http_proxy') ?: getenv('APT_HTTP_PROXY') ?: ''));
        }

        if ($raw === '') {
            return null;
        }

        $normalized = $raw;
        if (str_starts_with($normalized, self::PROXY_TCP_SCHEME)) {
            return $normalized;
        }

        if (str_starts_with($normalized, 'http://')) {
            $normalized = substr($normalized, 7);
        } elseif (str_starts_with($normalized, 'https://')) {
            $normalized = substr($normalized, 8);
        } else {
            $normalized = ltrim($normalized, '/');
        }

        return self::PROXY_TCP_SCHEME . $normalized;
    }

    private function looksLikeHtmlErrorPage(string $raw): bool
    {
        $prefix = strtolower(substr(ltrim($raw), 0, 200));
        return str_contains($prefix, '<html') || str_contains($prefix, '<!doctype html');
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
