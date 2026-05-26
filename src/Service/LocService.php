<?php

namespace App\Service;

final class LocService
{
    private const GEO_API_URL = 'https://apiip.net/api/check';
    private const LOG_PREFIX = '[LocService] ';

    private string $geoKey;

    public function __construct(
        string $geoKey
    ) {
        $this->geoKey = $geoKey;
    }


    public function resolveUsersLocation(): ?string
    {
        $resolvedCity = null;

        if ($this->isGeolocationDisabled()) {
            error_log(self::LOG_PREFIX . 'IP geolocation disabled by GEO_IP_ENABLED env flag.');
        } elseif (trim($this->geoKey) === '') {
            error_log(self::LOG_PREFIX . 'Missing GEO_KEY. Falling back to manual city.');
        } else {
            $clientIp = $this->resolveClientIp();
            if ($clientIp !== null) {
                $response = $this->fetchGeoPayload($clientIp);
                if ($response !== null) {
                    $resolvedCity = $this->extractCity($response['body'], $response['status']);
                }
            }
        }

        return $resolvedCity;
    }

    private function resolveClientIp(): ?string
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $rawForwardedFor = (string) $_SERVER['HTTP_X_FORWARDED_FOR'];
            $clientIp = trim((string) explode(',', $rawForwardedFor)[0]);
        } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $clientIp = trim((string) $_SERVER['HTTP_X_REAL_IP']);
        } else {
            $clientIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        }

        if ($clientIp === '') {
            error_log(self::LOG_PREFIX . 'No client IP found in request server variables.');
            return null;
        }

        return $clientIp;
    }

    /** @return array{body:string,status:int}|null */
    private function fetchGeoPayload(string $clientIp): ?array
    {
        $payload = null;
        $curl = curl_init();
        if ($curl === false) {
            error_log(self::LOG_PREFIX . 'curl_init failed.');
        } else {
            $url = self::GEO_API_URL . '?ip=' . rawurlencode($clientIp) . '&accessKey=' . rawurlencode($this->geoKey);
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: running-tracker/1.0',
                ]
            ]);
            $body = curl_exec($curl);

            if (curl_errno($curl)) {
                error_log(self::LOG_PREFIX . 'cURL error: ' . curl_error($curl));
            } else {
                $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if (!is_string($body) || $body === '') {
                    error_log(self::LOG_PREFIX . 'Empty response from IP geolocation API. HTTP status=' . $status);
                } else {
                    $payload = ['body' => $body, 'status' => $status];
                }
            }

            curl_close($curl);
        }

        return $payload;
    }

    private function extractCity(string $payload, int $status): ?string
    {
        $decoded = json_decode($payload);
        if (!is_object($decoded)) {
            error_log(self::LOG_PREFIX . 'Invalid JSON payload from IP geolocation API. HTTP status=' . $status);
            return null;
        }

        if (!isset($decoded->city) || !is_string($decoded->city) || trim($decoded->city) === '') {
            error_log(self::LOG_PREFIX . 'No city in IP geolocation response. HTTP status=' . $status);
            return null;
        }

        return trim($decoded->city);
    }

    private function isGeolocationDisabled(): bool
    {
        $flag = strtolower(trim((string) (getenv('GEO_IP_ENABLED') ?: '1')));
        return in_array($flag, ['0', 'false', 'no', 'off'], true);
    }
}
