<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class OneDriveService
{
    protected string $accessToken;

    public function __construct()
    {
        $this->accessToken = $this->fetchAccessToken();
    }

   protected function fetchAccessToken(): string
{
    $tenant = config('services.onedrive.tenant_id');

    $response = Http::asForm()->post(
        "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
        [
            'client_id'     => config('services.onedrive.client_id'),
            'client_secret' => config('services.onedrive.client_secret'),
            'refresh_token' => config('services.onedrive.refresh_token'),
            'grant_type'    => 'refresh_token',
        ]
    );

    $body = mb_convert_encoding($response->body(), 'UTF-8', 'UTF-8');
    $json = json_decode($body, true);

    if (!isset($json['access_token'])) {
       \Log::error('OneDrive token refresh failed', [
            'response' => preg_replace('//u', '', $body)
        ]);
        throw new \Exception('OneDrive authentication failed. Check credentials.');
    }

    return $json['access_token'];
}


    public function upload(string $path, string $contents)
    {
        return Http::withToken($this->accessToken)
            ->withHeaders([
                'Content-Type' => 'application/octet-stream', // important for binary files
            ])
            ->send('PUT', "https://graph.microsoft.com/v1.0/me/drive/root:/{$path}:/content", [
                'body' => $contents
            ]);
    }
    
    
    public function getImageUrl(string $path)
    {
        $response = Http::withToken($this->accessToken)
            ->post("https://graph.microsoft.com/v1.0/me/drive/root:/{$path}:/createLink", [
                'type' => 'view',   // 'view' for read-only link
                'scope' => 'anonymous' // anyone with link can view
            ]);
    
        $json = $response->json();
    
        if (!isset($json['link']['webUrl'])) {
            throw new \Exception('Failed to generate OneDrive share link');
        }
    
        return $json['link']['webUrl']; // this is your <img> URL
    }
    
    
    public function getDirectFileUrl(string $path)
    {
        // Get metadata for the file
        $response = Http::withToken($this->accessToken)
            ->get("https://graph.microsoft.com/v1.0/me/drive/root:/{$path}");
    
        $json = $response->json();
    
        if (!isset($json['@microsoft.graph.downloadUrl'])) {
            throw new \Exception('Failed to get direct download URL');
        }
    
        return $json['@microsoft.graph.downloadUrl'];
    }
    
    
    public function streamFile(string $path)
    {
        // 1. Get temporary download URL
        $response = Http::withToken($this->accessToken)
            ->get("https://graph.microsoft.com/v1.0/me/drive/root:/{$path}:/content");
    
        // 2. Return raw body
        return $response->body();
    }

    public function delete(string $path)
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->delete("https://graph.microsoft.com/v1.0/me/drive/root:/{$path}");

            if ($response->failed()) {
                \Log::error('OneDrive file delete failed', [
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new \Exception("Failed to delete OneDrive file: {$path}");
            }

            return true; // Successfully deleted
        } catch (Exception $e) {
            \Log::error('OneDrive delete exception', [
                'path' => $path,
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

}
