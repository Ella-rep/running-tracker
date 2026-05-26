<?php

namespace App\Service;

final class LocService
{
   
    private $geoKey;
    
    public function __construct(string $geoKey
     ) {
        $this->geoKey=$geoKey;
        }


    public function resolveUsersLocation():mixed
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
{
    $client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
}
else if (isset($_SERVER['HTTP_X_REAL_IP']))
{
    $client_ip = $_SERVER['HTTP_X_REAL_IP'];
}
else
{
    $client_ip = $_SERVER['REMOTE_ADDR'];
}

$user_agent = $_SERVER['HTTP_USER_AGENT'];


    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://apiip.net/api/check?ip=" . $client_ip."&accessKey=".$this->geoKey,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([]),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ]
    ]);
    $response = curl_exec($curl);

    if (curl_errno($curl))
    {
        $error = curl_error($curl);
    }
    else
    {
        $response = json_decode($response);

        if (is_object($response) && isset($response->city) && is_string($response->city) && $response->city !== '') {
            return $response->city;
        }
    }
    return false;

    }


    
}
