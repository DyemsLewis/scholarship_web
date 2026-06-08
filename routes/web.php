<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProviderController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('home');
Route::view('/login', 'login')->name('login');
Route::view('/register', 'register')->name('register');
Route::view('/provider/register', 'provider-register')->name('provider.register');
Route::view('/account/setup', 'account-setup')->middleware('auth')->name('account.setup');
Route::get('/admin', [AdminController::class, 'index'])->name('admin.index');
Route::get('/admin/manage-users', [AdminController::class, 'manageUsers'])->name('admin.manage-users');
Route::get('/admin/reviews', [AdminController::class, 'reviews'])->name('admin.reviews');
Route::get('/admin/logs', [AdminController::class, 'logs'])->name('admin.logs');
Route::get('/admin/users', [AdminController::class, 'users'])->middleware('auth')->name('admin.users');
Route::post('/admin/users', [AdminController::class, 'storeUser'])->middleware('auth')->name('admin.users.store');
Route::get('/admin/log-entries', [AdminController::class, 'logEntries'])->middleware('auth')->name('admin.log-entries');
Route::get('/provider', [ProviderController::class, 'index'])->name('provider.index');
Route::get('/provider/profile', [ProviderController::class, 'profile'])->middleware('auth')->name('provider.profile');

Route::post('/login', [AuthController::class, 'login'])->name('login.store');
Route::post('/register', [AuthController::class, 'register'])->name('register.store');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
