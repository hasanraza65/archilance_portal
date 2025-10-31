<?php

namespace App\Helpers;

use Firebase\JWT\JWT;

class FirebaseHelper
{
    public static function getAccessToken()
    {
        $keyFile = storage_path('firebase/firebase-key.json');
        $jsonKey = json_decode(file_get_contents($keyFile), true);

        $privateKey = $jsonKey['private_key'];
        $clientEmail = $jsonKey['client_email'];

        $now = time();
        $jwt = [
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $jwtEncoded = JWT::encode($jwt, $privateKey, 'RS256');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwtEncoded,
        ]));

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        return $response['access_token'];
    }

    public static function sendFcmNotification($token, $title, $body)
    {
        $projectId = 'archilance-fcm'; // replace with your Firebase project ID
        $accessToken = self::getAccessToken();

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $message = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
            ],
        ];

        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json",
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }
}