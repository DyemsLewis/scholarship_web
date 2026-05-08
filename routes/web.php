<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('home');
Route::view('/login', 'login')->name('login');
Route::view('/register', 'register')->name('register');
