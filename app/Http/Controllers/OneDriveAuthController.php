<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Microsoft\Graph\Graph;
use Illuminate\Support\Facades\Http;
use App\Models\OneDriveToken;

class OneDriveAuthController extends Controller
{
    public function redirect()
    {
        $query = http_build_query([
            'client_id' => config('filesystems.disks.onedrive.clientId'),
            'response_type' => 'code',
            'redirect_uri' => config('filesystems.disks.onedrive.redirectUri'),
            'response_mode' => 'query',
            'scope' => 'offline_access Files.ReadWrite.All User.Read',
        ]);

        return redirect("https://login.microsoftonline.com/common/oauth2/v2.0/authorize?$query");
    }

    public function callback(Request $request)
    {
        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            [
                'client_id' => config('filesystems.disks.onedrive.clientId'),
                'client_secret' => config('filesystems.disks.onedrive.clientSecret'),
                'grant_type' => 'authorization_code',
                'code' => $request->code,
                'redirect_uri' => config('filesystems.disks.onedrive.redirectUri'),
            ]
        )->json();

        OneDriveToken::updateOrCreate(
            ['id' => 1],
            [
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'],
                'expires_at' => now()->addSeconds($response['expires_in']),
            ]
        );

        return 'OneDrive connected successfully';
    }
}

