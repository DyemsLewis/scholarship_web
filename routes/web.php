<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProviderController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/login', [PageController::class, 'login'])->name('login');
Route::get('/register', [PageController::class, 'register'])->name('register');
Route::get('/provider/register', [PageController::class, 'providerRegister'])->name('provider.register');
Route::get('/account/setup', [PageController::class, 'accountSetup'])->middleware('auth')->name('account.setup');
Route::get('/admin', [AdminController::class, 'index'])->name('admin.index');
Route::get('/admin/manage-users', [AdminController::class, 'manageUsers'])->name('admin.manage-users');
Route::get('/admin/reviews', [AdminController::class, 'reviews'])->name('admin.reviews');
Route::get('/admin/logs', [AdminController::class, 'logs'])->name('admin.logs');
Route::get('/admin/users', [AdminController::class, 'users'])->middleware('auth')->name('admin.users');
Route::post('/admin/users', [AdminController::class, 'storeUser'])->middleware('auth')->name('admin.users.store');
Route::get('/admin/log-entries', [AdminController::class, 'logEntries'])->middleware('auth')->name('admin.log-entries');
Route::get('/provider', [ProviderController::class, 'index'])->name('provider.index');
Route::get('/provider/programs', [ProviderController::class, 'programs'])->name('provider.programs');
Route::get('/provider/applications', [ProviderController::class, 'applications'])->name('provider.applications');
Route::get('/provider/profile', [ProviderController::class, 'profile'])->middleware('auth')->name('provider.profile');
Route::get('/provider/scholarships', [ProviderController::class, 'scholarships'])->middleware('auth')->name('provider.scholarships');
Route::post('/provider/scholarships', [ProviderController::class, 'storeScholarship'])->middleware('auth')->name('provider.scholarships.store');
Route::put('/provider/scholarships/{scholarship}', [ProviderController::class, 'updateScholarship'])->middleware('auth')->name('provider.scholarships.update');

Route::post('/login', [AuthController::class, 'login'])->name('login.store');
Route::post('/register', [AuthController::class, 'register'])->name('register.store');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
