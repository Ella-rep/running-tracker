<?php

namespace App\Service;

/**
 * Provides daily weather advice with manual city selection and Paris fallback.
 */
final class MeteoService
{
    private const TITLE = 'Conseil meteo du jour';
    private const COLOR_INFO = '#8b9cf4';
    private const COLOR_WARNING = '#f0c040';
    private const COLOR_SUCCESS = '#4ade80';
    private const PROXY_TCP_SCHEME = 'tcp://';

    /**
     * Full display config per weather condition.
     * Each entry defines the icon, color, tone, and pool of advice messages for that condition.
     * Add or edit conditions here without touching the resolution or rendering logic.
     *
     * @var array<string,array{icon:string,color:string,tone:string,messages:array<int,string>}>
     */
    private const ADVICE_CONFIG = [
        'heatwave' => [
            'icon'     => '🔥',
            'color'    => self::COLOR_WARNING,
            'tone'     => 'warning',
            'messages' => [
                'Alerte chaleur/canicule: si possible, privilégie une sortie très tôt ou tard, avec une intensité réduite et une hydratation régulière.',
                'Conditions de canicule: fais ta sortie en début ou fin de journée. Ralentis l\'intensité et bois beaucoup.',
                'Canicule détectée: opte pour une séance très facile, très tôt le matin ou tard le soir. Protection solaire et hydratation essentielles.',
            ],
        ],
        'hot' => [
            'icon'     => '☀️',
            'color'    => self::COLOR_WARNING,
            'tone'     => 'warning',
            'messages' => [
                'Chaleur marquée: une sortie plus tôt/tard, avec une intensité adaptée et une hydratation régulière, peut être plus confortable.',
                'Chaleur attendue: préfère une séance légère en matinée ou soirée. Bois régulièrement pendant la sortie.',
                'Températures élevées: opte pour un footing facile et reste hydraté. Les températures diminueront progressivement en fin de journée.',
            ],
        ],
        'rainy' => [
            'icon'     => '🌧️',
            'color'    => self::COLOR_WARNING,
            'tone'     => 'warning',
            'messages' => [
                'Pluie probable: tu peux prévoir une veste légère, limiter les allures rapides et privilégier un footing contrôle.',
                'Pluie en vue: protège-toi avec une veste imperméable. Ralentis pour mieux gérer l\'adhérence au sol.',
                'Précipitation probable: choisis un parcours avec bon drainage et porte des vêtements qui sèchent rapidement.',
            ],
        ],
        'windy' => [
            'icon'     => '💨',
            'color'    => self::COLOR_INFO,
            'tone'     => 'info',
            'messages' => [
                'Vent soutenu: pars tranquillement, abrite ta sortie si possible et garde un peu d\'énergie pour le retour face au vent.',
                'Vent fort attendu: choisis un itinéraire abrité et économise l\'énergie pour affronter le vent au retour.',
                'Conditions vents forts: évite les parcours boisés, tu peux choisir un itinéraire entre les maisons ou grands bâtiments pour minimiser les rafales.',
            ],
        ],
        'cold' => [
            'icon'     => '🧣',
            'color'    => self::COLOR_INFO,
            'tone'     => 'info',
            'messages' => [
                'Froid marqué: un échauffement progressif, les extrémités couvertes et une allure facile sur les premiers kilomètres peuvent aider.',
                'Froid détecté: bien couvrir les mains et les oreilles. Réalise un vrai petit échauffement avant de partir.',
                'Température négative: couches thermales et gants sont tes amis. Pars tranquille et augmente l\'intensité progressivement.',
            ],
        ],
        'clear' => [
            'icon'     => '🌤️',
            'color'    => self::COLOR_SUCCESS,
            'tone'     => 'encourage',
            'messages' => [
                'Conditions favorables: bonne fenêtre pour ta séance. Une hydratation adaptée reste toujours utile.',
                'Beau temps: c\'est le moment idéal pour un bon footing. N\'oublie pas l\'hydratation et la protection solaire.',
                'Conditions idéales: saisis cette opportunité pour faire une belle séance. Profite du beau temps.',
            ],
        ],
        'default' => [
            'icon'     => '🌥️',
            'color'    => self::COLOR_INFO,
            'tone'     => 'info',
            'messages' => [
                'Météo variable: adapte l\'allure a la sensation du jour et prévois une couche légère.',
                'Conditions mixtes: reste attentif aux changements et hydrate-toi régulièrement.',
            ],
        ],
    ];

    // Default location: Paris. Can be changed later via constructor args/env binding.
    private const DEFAULT_LAT = 48.8566;
    private const DEFAULT_LON = 2.3522;
    private const DEFAULT_CITY_LABEL = 'Paris';

    /**
     * Builds the weather advice card for the requested or inferred location.
     *
     * @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string,tempMin:?float,tempMax:?float}
     */
    public function buildDailyAdvice(?string $city = null): array
    {
        $requestedCity = trim((string) $city);
        $errors = [];
        $resolved = $this->resolveLocation($city, $errors);

        $liveAdvice = $this->buildLiveAdvice($resolved['lat'], $resolved['lon'], $resolved['label'], $errors);
        if ($liveAdvice !== null) {
            return $this->withCityFeedback($liveAdvice, $requestedCity);
        }

        return $this->withCityFeedback(
            $this->buildErrorAdvice($resolved['label']),
            $requestedCity
        );
    }

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string,tempMin:?float,tempMax:?float}|null */
    private function buildLiveAdvice(float $lat, float $lon, string $label, array &$errors): ?array
    {
        $data = $this->fetchWeather($lat, $lon, $errors);
        if ($data === null) {
            return null;
        }

        $advice = $this->buildAdviceFromApiPayload($data, $label);
        if ($advice === null) {
            $errors[] = 'E3: réponse météo incomplète.';
        }

        return $advice;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string,tempMin:?float,tempMax:?float}|null
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
        $tempMin = null;
        if (is_array($daily['temperature_2m_min'] ?? null) && isset($daily['temperature_2m_min'][0])) {
            $tempMin = $this->asFloat($daily['temperature_2m_min'][0]);
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

        $conditionKey = $this->resolveConditionKey($temp, $tempMax, $rain, $precipProbMax, $wind, $weatherCode);
        $config = self::ADVICE_CONFIG[$conditionKey];

        return [
            'title'   => self::TITLE,
            'text'    => $this->pickRandomAdvice($config['messages']),
            'tone'    => $config['tone'],
            'icon'    => $config['icon'],
            'color'   => $config['color'],
            'badge'   => $this->buildBadge('', $label),
            'tempMin' => $tempMin,
            'tempMax' => $tempMax,
        ];
    }

    /**
     * Maps weather data to a condition key defined in ADVICE_CONFIG.
     * Conditions are evaluated from most to least severe.
     */
    private function resolveConditionKey(?float $temp, ?float $tempMax, ?float $rain, ?float $precipProbMax, ?float $wind, int $weatherCode): string
    {
        return match (true) {
            $this->isHeatwave($temp, $tempMax) => 'heatwave',
            $this->isHot($temp, $tempMax) => 'hot',
            $this->isRainy($rain, $precipProbMax) => 'rainy',
            $this->isWindy($wind) => 'windy',
            $temp !== null && $temp <= 3.0 => 'cold',
            in_array($weatherCode, [0, 1], true) => 'clear',
            default => 'default',
        };
    }

    /** @param array<string> $messages */
    private function pickRandomAdvice(array $messages): string
    {
        if (empty($messages)) {
            return 'Meteo variable.';
        }
        return $messages[array_rand($messages)];
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

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string,tempMin:?float,tempMax:?float} */
    private function buildErrorAdvice(string $label): array
    {
        return [
            'title' => self::TITLE,
            'text' => 'Impossible de recuperer la meteo en direct pour le moment. Reessaie dans quelques minutes ou choisis une autre ville.',
            'tone' => 'warning',
            'icon' => '⚠️',
            'color' => self::COLOR_WARNING,
            'badge' => $this->buildBadge('', $label),
            'tempMin' => null,
            'tempMax' => null,
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
        // Rain trigger from either live precipitation or daily probability.
        return ($rain !== null && $rain >= 0.2)
            || ($precipProbMax !== null && $precipProbMax >= 60);
    }

    private function isWindy(?float $wind): bool
    {
        // Wind speed threshold in km/h.
        return $wind !== null && $wind >= 30.0;
    }

    private function isHot(?float $temp, ?float $tempMax): bool
    {
        // "Hot" warning threshold for current or max daily temperature.
        return ($temp !== null && $temp >= 25.0)
            || ($tempMax !== null && $tempMax >= 28.0);
    }

    private function isHeatwave(?float $temp, ?float $tempMax): bool
    {
        // Higher threshold used for explicit heatwave/canicule messaging.
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
     * @param array{title:string,text:string,tone:string,icon:string,color:string,badge:string,tempMin:?float,tempMax:?float} $advice
     * @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string,tempMin:?float,tempMax:?float,cityStatus:string,cityMessage:string,cityApplied:bool,requestedCity:?string,appliedCity:string,detectedCity:?string,detectedCityStatus:string,detectedCityMessage:string}
     */
    private function withCityFeedback(array $advice, string $requestedCity): array
    {
        $appliedCity = trim((string) ($advice['badge'] ?? self::DEFAULT_CITY_LABEL));
        if ($appliedCity === '') {
            $appliedCity = self::DEFAULT_CITY_LABEL;
        }

        if ($requestedCity === '') {
            $advice['cityStatus'] = 'default';
            $advice['cityMessage'] = 'Ville météo par défaut: ' . $appliedCity;
            $advice['cityApplied'] = true;
            $advice['requestedCity'] = null;
            $advice['appliedCity'] = $appliedCity;
            $advice['detectedCity'] = $appliedCity;
            $advice['detectedCityStatus'] = 'ok';
            $advice['detectedCityMessage'] = 'Ville par défaut: ' . $appliedCity;

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
            ? 'Ville météo appliquée: ' . $appliedCity
            : 'Ville météo non appliquée: ' . $requestedCity . '. Ville utilisée: ' . $appliedCity . '.';
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
        // Retry a few times to smooth transient network/proxy errors.
        $attempts = max(1, $maxAttempts);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $context = $this->buildHttpContext();
            $raw = @file_get_contents($url, false, $context);

            if (is_string($raw) && $raw !== '' && !$this->looksLikeHtmlErrorPage($raw)) {
                return $raw;
            }

            // Small backoff between attempts (120ms).
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

        // Normalize proxy URL into tcp://host:port for stream contexts.
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
