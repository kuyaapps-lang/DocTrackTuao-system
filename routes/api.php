<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DocumentAttachmentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentProcessingController;
use App\Http\Controllers\DocumentQrCodeController;
use App\Http\Controllers\DocumentRoutingController;
use App\Http\Controllers\DocumentTrackingController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\UserManagementController;

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
)-> middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| PUBLIC DOCUMENT TRACKING
|--------------------------------------------------------------------------
|
| No authentication is required.
| Only safe tracking information is exposed.
|
*/

Route::get(
    '/track/{trackingNo}',
    [DocumentTrackingController::class, 'show']
);

/*
|--------------------------------------------------------------------------
| PUBLIC QR RESOLUTION
|--------------------------------------------------------------------------
|
| Scanning an issued QR calls this endpoint.
|
| unused      -> document registration
| registered  -> document tracking
| void        -> invalid/void message
|
*/

Route::get(
    '/q/{token}',
    [DocumentQrCodeController::class, 'resolve']
);

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | AUTHENTICATED USER
    |--------------------------------------------------------------------------
    |
    | Return role/office information and the permission names that the
    | frontend may use for menus and action visibility.
    |
    */

    Route::get('/user', function (Request $request) {
        $user = $request->user()->load([
            'role',
            'department',
            'office',
        ]);

        $data = $user->toArray();
        $data['permissions'] = $user->permissionNames();

        return response()->json($data);
    });

    /*
    |--------------------------------------------------------------------------
    | AUDIT LOGS
    |--------------------------------------------------------------------------
    */

    Route::get(
        'audit-logs',
        [AuditLogController::class, 'index']
    )->middleware('can:audit.view');

    /*
    |--------------------------------------------------------------------------
    | USER MANAGEMENT
    |--------------------------------------------------------------------------
    |
    | Administrator-only. Role assignment controls capabilities while office
    | assignment controls document scope. Department is synchronized from the
    | selected office by UserManagementController.
    |
    */

    Route::get(
        'users/form-options',
        [UserManagementController::class, 'formOptions']
    )->middleware('can:users.manage');

    Route::get(
        'users',
        [UserManagementController::class, 'index']
    )->middleware('can:users.manage');

    Route::post(
        'users',
        [UserManagementController::class, 'store']
    )->middleware('can:users.manage');

    Route::match(
        ['put', 'patch'],
        'users/{user}',
        [UserManagementController::class, 'update']
    )->middleware('can:users.manage');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT PROCESSING
    |--------------------------------------------------------------------------
    */

    Route::get(
        'documents/{document}/processing',
        [DocumentProcessingController::class, 'show']
    )->middleware('can:documents.view');

    Route::put(
        'documents/{document}/processing',
        [DocumentProcessingController::class, 'update']
    )->middleware('can:documents.process');

    /*
    |--------------------------------------------------------------------------
    | QR CODE REQUEST / ISSUANCE
    |--------------------------------------------------------------------------
    */

    Route::get(
        'qr-codes',
        [DocumentQrCodeController::class, 'index']
    )->middleware('can:qr.view');

    Route::post(
        'qr-codes',
        [DocumentQrCodeController::class, 'store']
    )->middleware('can:qr.manage');

    Route::get(
        'qr-codes/{qrCode}',
        [DocumentQrCodeController::class, 'show']
    )->middleware('can:qr.view');

    Route::post(
        'qr-codes/{qrCode}/void',
        [DocumentQrCodeController::class, 'void']
    )->middleware('can:qr.manage');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT FORM OPTIONS
    |--------------------------------------------------------------------------
    */

    Route::get(
        'document-form-options',
        [DocumentController::class, 'formOptions']
    )->middleware('can:documents.create');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT LIST VIEWS
    |--------------------------------------------------------------------------
    */

    Route::get(
        'documents/incoming',
        [DocumentController::class, 'incoming']
    )->middleware('can:documents.view');

    Route::get(
        'documents/outgoing',
        [DocumentController::class, 'outgoing']
    )->middleware('can:documents.view');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT ROUTING
    |--------------------------------------------------------------------------
    */

    Route::get(
        'documents/{document}/routing-options',
        [DocumentRoutingController::class, 'options']
    )->middleware('can:documents.view');

    Route::post(
        'documents/{document}/forward',
        [DocumentRoutingController::class, 'forward']
    )->middleware('can:documents.route');

    Route::post(
        'documents/{document}/receive',
        [DocumentRoutingController::class, 'receive']
    )->middleware('can:documents.route');

    Route::get(
        'documents/{document}/history',
        [DocumentRoutingController::class, 'history']
    )->middleware('can:documents.view');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT ATTACHMENTS
    |--------------------------------------------------------------------------
    */

    Route::get(
        'documents/{document}/attachments',
        [DocumentAttachmentController::class, 'index']
    )->middleware('can:attachments.view');

    Route::post(
        'documents/{document}/attachments',
        [DocumentAttachmentController::class, 'store']
    )->middleware('can:attachments.manage');

    Route::get(
        'attachments/{attachment}/download',
        [DocumentAttachmentController::class, 'download']
    )->middleware('can:attachments.view');

    Route::delete(
        'attachments/{attachment}',
        [DocumentAttachmentController::class, 'destroy']
    )->middleware('can:attachments.manage');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT CRUD
    |--------------------------------------------------------------------------
    */

    Route::get(
        'documents',
        [DocumentController::class, 'index']
    )->middleware('can:documents.view');

    Route::post(
        'documents',
        [DocumentController::class, 'store']
    )->middleware('can:documents.create');

    Route::get(
        'documents/{document}',
        [DocumentController::class, 'show']
    )->middleware('can:documents.view');

    Route::match(
        ['put', 'patch'],
        'documents/{document}',
        [DocumentController::class, 'update']
    )->middleware('can:documents.edit');

    Route::delete(
        'documents/{document}',
        [DocumentController::class, 'destroy']
    )->middleware('can:documents.delete');

    /*
    |--------------------------------------------------------------------------
    | OFFICE ROUTES
    |--------------------------------------------------------------------------
    */

    Route::get(
        'offices',
        [OfficeController::class, 'index']
    )->middleware('can:master_data.view');

    Route::post(
        'offices',
        [OfficeController::class, 'store']
    )->middleware('can:master_data.manage');

    Route::get(
        'offices/{office}',
        [OfficeController::class, 'show']
    )->middleware('can:master_data.view');

    Route::match(
        ['put', 'patch'],
        'offices/{office}',
        [OfficeController::class, 'update']
    )->middleware('can:master_data.manage');

    Route::delete(
        'offices/{office}',
        [OfficeController::class, 'destroy']
    )->middleware('can:master_data.manage');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT TYPE ROUTES
    |--------------------------------------------------------------------------
    */

    Route::get(
        'document-types',
        [DocumentTypeController::class, 'index']
    )->middleware('can:master_data.view');

    Route::post(
        'document-types',
        [DocumentTypeController::class, 'store']
    )->middleware('can:master_data.manage');

    Route::get(
        'document-types/{documentType}',
        [DocumentTypeController::class, 'show']
    )->middleware('can:master_data.view');

    Route::match(
        ['put', 'patch'],
        'document-types/{documentType}',
        [DocumentTypeController::class, 'update']
    )->middleware('can:master_data.manage');

    Route::delete(
        'document-types/{documentType}',
        [DocumentTypeController::class, 'destroy']
    )->middleware('can:master_data.manage');
});
