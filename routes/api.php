<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerOnboardingController;
use App\Http\Controllers\Api\GoamlReportController;
use App\Http\Controllers\Api\SanctionEntitiyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::get('/status', function () {
    return response()->json(['status' => 'API is running'], 200);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);

    // Customer onboarding
    Route::get('/onboarding/meta', [CustomerOnboardingController::class, 'meta']);
    Route::post('/onboarding', [CustomerOnboardingController::class, 'store']);
    Route::get('/onboarding/customers', [CustomerOnboardingController::class, 'index']);
    Route::get('/onboarding/customers/{id}', [CustomerOnboardingController::class, 'show']);
    Route::get('/onboarding/short-data-customers', [CustomerOnboardingController::class, 'shortDataCustomers']);

    // GOAML Reports
    Route::get('/goaml-reports/meta', [GoamlReportController::class, 'meta']);
    Route::get('/goaml-reports', [GoamlReportController::class, 'index']);
    Route::get('/goaml-reports/{id}', [GoamlReportController::class, 'show']);
    Route::post('/goaml-reports', [GoamlReportController::class, 'store']);
    Route::put('/goaml-reports/{id}', [GoamlReportController::class, 'update']);

    //Sanction Entities
    Route::get('/sanction-entities', [SanctionEntitiyController::class, 'index']);
    Route::get('/sanction-entities/{id}', [SanctionEntitiyController::class, 'show']);

    //country
    Route::get('/countries', [SanctionEntitiyController::class, 'countries']);

    //profile
    Route::get('/profile', [App\Http\Controllers\Api\ProfileController::class, 'show']);

    //company
    Route::get('/companies', [App\Http\Controllers\Api\CompanyController::class, 'index']);
    Route::get('/companies/{id}/users', [App\Http\Controllers\Api\CompanyController::class, 'companyUsers']);
    Route::post('/companies', [App\Http\Controllers\Api\CompanyController::class, 'store']);

    //screening log
    Route::get('/screening-logs', [App\Http\Controllers\Api\ScreeningLogController::class, 'index']);
    Route::post('/screening-logs', [App\Http\Controllers\Api\ScreeningLogController::class, 'store']);
});
