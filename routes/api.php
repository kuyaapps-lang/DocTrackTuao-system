<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\DocumentRoutingController;
use App\Http\Controllers\DocumentTrackingController;
use App\Http\Controllers\OfficeController;

/*
|--------------------------------------------------------------------------
| AUTH ROUTES
|--------------------------------------------------------------------------
*/

Route::post(
    '/login',
    [AuthController::class, 'login']
);

Route::post(
    '/logout',
    [AuthController::class, 'logout']
);

/*
|--------------------------------------------------------------------------
| PUBLIC DOCUMENT TRACKING
|--------------------------------------------------------------------------
|
| No authentication is required.
|
| The controller exposes only safe tracking information.
|
*/

Route::get(
    '/track/{trackingNo}',
    [DocumentTrackingController::class, 'show']
);

/*
|--------------------------------------------------------------------------
| AUTHENTICATED USER
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->get(
    '/user',
    function (Request $request) {
        return $request->user();
    }
);

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT FORM OPTIONS
    |--------------------------------------------------------------------------
    */

    Route::get(
        'document-form-options',
        [DocumentController::class, 'formOptions']
    );

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT LIST VIEWS
    |--------------------------------------------------------------------------
    */

    Route::get(
        'documents/incoming',
        [DocumentController::class, 'incoming']
    );

    Route::get(
        'documents/outgoing',
        [DocumentController::class, 'outgoing']
    );

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT ROUTING
    |--------------------------------------------------------------------------
    */

    Route::get(
        'documents/{document}/routing-options',
        [DocumentRoutingController::class, 'options']
    );

    Route::post(
        'documents/{document}/forward',
        [DocumentRoutingController::class, 'forward']
    );

    Route::post(
        'documents/{document}/receive',
        [DocumentRoutingController::class, 'receive']
    );

    Route::get(
        'documents/{document}/history',
        [DocumentRoutingController::class, 'history']
    );

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT CRUD
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'documents',
        DocumentController::class
    );

    /*
    |--------------------------------------------------------------------------
    | OFFICE ROUTES
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'offices',
        OfficeController::class
    );

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT TYPE ROUTES
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'document-types',
        DocumentTypeController::class
    );
});