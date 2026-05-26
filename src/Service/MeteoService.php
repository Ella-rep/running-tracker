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
        error_log('[MeteoService] buildDailyAdvice START | city=' . ($city ?? 'null'));
        $requestedCity = trim((string) $city);
        $errors = [];
        $resolved = $this->resolveLocation($city, $errors);

        error_log('[MeteoService] resolved location | label=' . $resolved['label'] . ' | lat=' . $resolved['lat'] . ' | lon=' . $resolved['lon'] . ' | source=' . $resolved['source']);

        $liveAdvice = $this->buildLiveAdvice($resolved['lat'], $resolved['lon'], $resolved['label'], $errors);
        if ($liveAdvice !== null) {
            error_log('[MeteoService] liveAdvice SUCCESS | advice=' . json_encode($liveAdvice));
            return $this->withCityFeedback($liveAdvice, $requestedCity, $resolved['source']);
        }

        error_log('[MeteoService] liveAdvice FAILED | errors=' . json_encode($errors));
        $this->logFallbackErrors($errors, $city, $resolved['label']);

        return $this->withCityFeedback(
            $this->buildErrorAdvice($resolved['label']),
            $requestedCity,
            $resolved['source']
        );
    }

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string}|null */
    private function buildLiveAdvice(float $lat, float $lon, string $label, array &$errors): ?array
    {
        error_log('[MeteoService] buildLiveAdvice START | lat=' . $lat . ' | lon=' . $lon . ' | label=' . $label);
        $data = $this->fetchWeather($lat, $lon, $errors);
        if ($data === null) {
            error_log('[MeteoService] buildLiveAdvice fetchWeather returned null');
            return null;
        }

        $advice = $this->buildAdviceFromApiPayload($data, $label);
        if ($advice === null) {
            error_log('[MeteoService] buildLiveAdvice buildAdviceFromApiPayload returned null');
            $errors[] = 'E3: reponse meteo incomplete.';
        } else {
            error_log('[MeteoService] buildLiveAdvice SUCCESS | advice=' . json_encode($advice));
        }

        return $advice;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string}|null
     */
    private function buildAdviceFromApiPayload(array $data, string $label): ?array
    {
        error_log('[MeteoService] buildAdviceFromApiPayload START | label=' . $label);
        $current = is_array($data['current'] ?? null) ? $data['current'] : [];
        $daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];

        error_log('[MeteoService] buildAdviceFromApiPayload current=' . json_encode($current));
        error_log('[MeteoService] buildAdviceFromApiPayload daily=' . json_encode($daily));

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

        error_log('[MeteoService] buildAdviceFromApiPayload parsed | temp=' . ($temp ?? 'null') . ' | tempMax=' . ($tempMax ?? 'null') . ' | rain=' . ($rain ?? 'null') . ' | wind=' . ($wind ?? 'null') . ' | weatherCode=' . $weatherCode . ' | precipProbMax=' . ($precipProbMax ?? 'null'));

        if ($temp === null && $tempMax === null && $rain === null && $wind === null && $precipProbMax === null) {
            error_log('[MeteoService] buildAdviceFromApiPayload NO DATA FOUND');
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
            error_log('[MeteoService] buildAdviceFromApiPayload HEATWAVE detected');
            $advice = [
                'title' => self::TITLE,
                'text' => 'Alerte chaleur/canicule: privilegie une sortie tres tot ou tard, reduis nettement l\'intensite et hydrate-toi tres regulierement.',
                'tone' => 'warning',
                'icon' => '🔥',
                'color' => self::COLOR_WARNING,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif ($this->isHot($temp, $tempMax)) {
            error_log('[MeteoService] buildAdviceFromApiPayload HOT detected');
            $advice = [
                'title' => self::TITLE,
                'text' => 'Chaleur marquee: vise une sortie plus tot/tard, baisse l\'intensite et hydrate-toi regulierement.',
                'tone' => 'warning',
                'icon' => '☀️',
                'color' => self::COLOR_WARNING,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif ($this->isRainy($rain, $precipProbMax)) {
            error_log('[MeteoService] buildAdviceFromApiPayload RAINY detected');
            $advice = [
                'title' => self::TITLE,
                'text' => 'Pluie probable: prevois une veste legere, reduis les allures rapides et privilegie un footing controle.',
                'tone' => 'warning',
                'icon' => '🌧️',
                'color' => self::COLOR_WARNING,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif ($this->isWindy($wind)) {
            error_log('[MeteoService] buildAdviceFromApiPayload WINDY detected');
            $advice = [
                'title' => self::TITLE,
                'text' => 'Vent soutenu: pars prudemment, abrite tes fractions et garde de l\'energie pour le retour face au vent.',
                'tone' => 'info',
                'icon' => '💨',
                'color' => self::COLOR_INFO,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif ($temp !== null && $temp <= 3.0) {
            error_log('[MeteoService] buildAdviceFromApiPayload COLD detected');
            $advice = [
                'title' => self::TITLE,
                'text' => 'Froid marque: echauffement progressif, extremites couvertes et allure facile sur les premiers kilometres.',
                'tone' => 'info',
                'icon' => '🧣',
                'color' => self::COLOR_INFO,
                'badge' => $this->buildBadge('', $label),
            ];
        } elseif (in_array($weatherCode, [0, 1], true)) {
            error_log('[MeteoService] buildAdviceFromApiPayload CLEAR detected');
            $advice = [
                'title' => self::TITLE,
                'text' => 'Conditions favorables: bonne fenetre pour ta seance. Pense quand meme a t\'hydrater.',
                'tone' => 'encourage',
                'icon' => '🌤️',
                'color' => self::COLOR_SUCCESS,
                'badge' => $this->buildBadge('', $label),
            ];
        }

        error_log('[MeteoService] buildAdviceFromApiPayload SUCCESS | advice=' . json_encode($advice));
        return $advice;
    }

    /** @return array<string,mixed>|null */
    private function fetchWeather(float $lat, float $lon, array &$errors): ?array
    {
        error_log('[MeteoService] fetchWeather START | lat=' . $lat . ' | lon=' . $lon);
        $query = http_build_query([
            'latitude' => $lat,
            'longitude' => $lon,
            'current' => 'temperature_2m,precipitation,wind_speed_10m,weather_code,is_day',
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max',
            'forecast_days' => 1,
            'timezone' => 'auto',
        ]);

        $url = 'https://api.open-meteo.com/v1/forecast?' . $query;
        error_log('[MeteoService] fetchWeather URL=' . $url);
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw) || $raw === '') {
            error_log('[MeteoService] fetchWeather FAILED | raw empty or not string');
            $errors[] = 'E3: appel API meteo indisponible.';
            return null;
        }

        error_log('[MeteoService] fetchWeather raw response | length=' . strlen($raw) . ' | first 200 chars=' . substr($raw, 0, 200));

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log('[MeteoService] fetchWeather FAILED | JSON decode failed');
            $errors[] = 'E3: payload meteo non lisible.';
            return null;
        }

        error_log('[MeteoService] fetchWeather SUCCESS | decoded=' . json_encode($decoded));
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

    /** @return array{lat:float,lon:float,label:string,source:string} */
    private function resolveLocation(?string $city, array &$errors): array
    {
        error_log('[MeteoService] resolveLocation START | city=' . ($city ?? 'null'));
        $cityName = trim((string) $city);
        if ($cityName !== '') {
            error_log('[MeteoService] resolveLocation trying city=' . $cityName);
            $byCity = $this->fetchGeoByCity($cityName);
            if ($byCity !== null) {
                $byCity['source'] = 'city';
                error_log('[MeteoService] resolveLocation SUCCESS via city | result=' . json_encode($byCity));
                return $byCity;
            }
            error_log('[MeteoService] resolveLocation city not found');
            $errors[] = 'E1: ville introuvable.';
        }
        
        error_log('[MeteoService] resolveLocation trying LocService');
        $byLocation = $this->locService->resolveUsersLocation();
        if($byLocation){
            error_log('[MeteoService] resolveLocation got location from LocService | location=' . $byLocation);
            $result = $this->fetchGeoByCity($byLocation);
            if ($result !== null) {
                $result['source'] = 'ip';
                error_log('[MeteoService] resolveLocation SUCCESS via IP | result=' . json_encode($result));
                return $result;
            }
        }

        error_log('[MeteoService] resolveLocation LocService failed, using default');
        $errors[] = 'E2: geolocalisation IP indisponible.';

        $default = [
            'lat' => self::DEFAULT_LAT,
            'lon' => self::DEFAULT_LON,
            'label' => self::DEFAULT_CITY_LABEL,
            'source' => 'default',
        ];
        error_log('[MeteoService] resolveLocation DEFAULT FALLBACK | result=' . json_encode($default));
        return $default;
    }
    
    /** @return array{lat:float,lon:float,label:string}|null */
    private function fetchGeoByCity(string $city): ?array
    {
        error_log('[MeteoService] fetchGeoByCity START | city=' . $city);
        $coords = null;
        $query = http_build_query([
            'name' => $city,
            'count' => 1,
            'language' => 'fr',
            'format' => 'json',
        ]);

        $url = 'https://geocoding-api.open-meteo.com/v1/search?' . $query;
        error_log('[MeteoService] fetchGeoByCity URL=' . $url);
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        error_log('[MeteoService] fetchGeoByCity response | raw=' . ($raw ? 'received (' . strlen($raw) . ' bytes)' : 'null/empty'));
        
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            error_log('[MeteoService] fetchGeoByCity decoded | decoded=' . json_encode($decoded));
            
            if (is_array($decoded) && is_array($decoded['results'] ?? null) && isset($decoded['results'][0]) && is_array($decoded['results'][0])) {
                $first = $decoded['results'][0];
                error_log('[MeteoService] fetchGeoByCity first result | city=' . ($first['name'] ?? 'null') . ' | country=' . ($first['country'] ?? 'null'));
                
                $lat = $this->asFloat($first['latitude'] ?? null);
                $lon = $this->asFloat($first['longitude'] ?? null);
                error_log('[MeteoService] fetchGeoByCity lat/lon parsed | lat=' . ($lat ?? 'null') . ' | lon=' . ($lon ?? 'null'));
                
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
                    error_log('[MeteoService] fetchGeoByCity SUCCESS | coords=' . json_encode($coords));
                }
            }
        }

        if ($coords === null) {
            error_log('[MeteoService] fetchGeoByCity FAILED | no valid coords extracted');
        }
        return $coords;
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
