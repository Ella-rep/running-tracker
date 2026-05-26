<?php

namespace App\Service;

final class LocService
{

    private string $geoKey;

    public function __construct(
        string $geoKey
    ) {
        $this->geoKey = $geoKey;
    }


    public function resolveUsersLocation(): mixed
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $client_ip = $_SERVER['HTTP_X_REAL_IP'];
        } else {
            $client_ip = $_SERVER['REMOTE_ADDR'];
        }


        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://apiip.net/api/check?ip=" . $client_ip . "&accessKey=" . $this->geoKey,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([]),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json"
            ]
        ]);
        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            curl_close($curl);
            return false;
        } else {
            $response = json_decode($response);
            curl_close($curl);

            if (is_object($response) && isset($response->city) && is_string($response->city) && $response->city !== '') {
                return $response->city;
            }
        }
        return false;
    }
}
