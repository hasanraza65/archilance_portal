<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\AuthController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/clear-cache', function () {
    Artisan::call('cache:clear');
    Artisan::call('config:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');
    Artisan::call('config:cache');

    return '<h3>✅ All caches cleared successfully!</h3>';
});


Route::get('/test-email', function () {
    try {
        Mail::raw('This is a test email from Laravel using Microsoft 365 SMTP configuration.', function ($message) {
            $message->to('Ranahasanraza24@gmail.com')
                    ->subject('✅ Test Email from Laravel (Microsoft 365)');
        });

        return '<h3>✅ Test email has been sent successfully to Ranahasanraza24@gmail.com</h3>';
    } catch (\Exception $e) {
        return '<h3>❌ Error sending email:</h3><pre>' . $e->getMessage() . '</pre>';
    }
});




