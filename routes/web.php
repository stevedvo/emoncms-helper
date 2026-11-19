<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\LogBrowserController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::middleware('guest')->group(function()
{
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function()
{
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::redirect('/home', '/');
    Route::get('/dashboard/feeds', [DashboardController::class, 'feeds'])->name('dashboard.feeds');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
	Route::put('/settings/tariff', [SettingsController::class, 'updateTariff'])->name('settings.tariff.update');
	Route::get('/logs/{type}', [LogBrowserController::class, 'index'])->whereIn('type', ['activity', 'api'])->name('logs.index');
	Route::post('/logs/{type}/searches', [LogBrowserController::class, 'store'])->whereIn('type', ['activity', 'api'])->name('logs.searches.store');
	Route::delete('/logs/{type}/searches/{search}', [LogBrowserController::class, 'destroy'])->whereIn('type', ['activity', 'api'])->whereNumber('search')->name('logs.searches.destroy');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});
