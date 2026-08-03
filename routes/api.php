<?php

use App\Http\Controllers\LoginApiController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Auth endpoints
Route::post('/login', [LoginApiController::class, 'login']);
Route::get('/profile', [ProfileController::class, 'me']);

// Request management endpoints
Route::get('/requests', [RequestController::class, 'index']);
Route::get('/requests/{id}', [RequestController::class, 'show']);
Route::post('/requests', [RequestController::class, 'store']);
Route::put('/requests/{id}', [RequestController::class, 'update']);
Route::post('/requests/{id}/decide-spv', [RequestController::class, 'decideSpv']);
Route::post('/requests/{id}/decide-atasan', [RequestController::class, 'decideAtasan']);
Route::post('/requests/{id}/decide-divisi', [RequestController::class, 'decideDivisi']);
Route::post('/requests/{id}/decide-direksi', [RequestController::class, 'decideDireksi']);
Route::post('/requests/{id}/register-trip', [RequestController::class, 'registerTrip']);
Route::post('/requests/{id}/assign-vehicle', [RequestController::class, 'assignVehicle']);
Route::post('/trips/{tripId}/complete', [RequestController::class, 'completeTrip']);

// User management endpoints
Route::get('/users', [UserController::class, 'index']);
Route::get('/users/{id}', [UserController::class, 'show']);
Route::get('/users/{id}/approval-chain', [UserController::class, 'approvalChain']);
Route::post('/users', [UserController::class, 'store']);
Route::put('/users/{id}', [UserController::class, 'update']);
Route::delete('/users/{id}', [UserController::class, 'destroy']);
