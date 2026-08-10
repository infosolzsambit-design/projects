<?php

use App\Http\Controllers\API\V1\AuditController;
use App\Http\Controllers\API\V1\Auth\AuthController;
use App\Http\Controllers\API\V1\Auth\PermissionController;
use App\Http\Controllers\API\V1\Auth\RoleController;
use App\Http\Controllers\API\V1\Master\CourseController;
use App\Http\Controllers\API\V1\Master\DepartmentController;
use App\Http\Controllers\API\V1\Master\ProgramController;
use App\Http\Controllers\API\V1\User\UserController;
use App\Http\Middleware\PinTokenToClient;
use Illuminate\Support\Facades\Route;

// Every route below is automatically prefixed with /api/v1 (see bootstrap/app.php)
// and runs through the "api" middleware group (ApiKeyAuth first). Permission
// checks for each action live on the controllers themselves via the
// Illuminate\Routing\Controllers\HasMiddleware contract — see each
// controller's static middleware() method.

Route::post('login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::middleware(['auth:sanctum', PinTokenToClient::class])->group(function (): void {
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('logout-all', [AuthController::class, 'logoutAll']);
    Route::get('me', [AuthController::class, 'me']);
    Route::post('change-password', [AuthController::class, 'changePassword']);

    Route::post('users/{user}/restore', [UserController::class, 'restore'])->withTrashed();
    Route::delete('users/{user}/force', [UserController::class, 'forceDestroy'])->withTrashed();
    Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
    Route::post('users/{user}/roles/assign', [UserController::class, 'assignRole']);
    Route::post('users/{user}/roles/remove', [UserController::class, 'removeRole']);
    Route::post('users/{user}/roles/sync', [UserController::class, 'syncRoles']);
    Route::post('users/{user}/permissions/assign', [UserController::class, 'assignPermission']);
    Route::post('users/{user}/permissions/remove', [UserController::class, 'removePermission']);
    Route::post('users/{user}/permissions/sync', [UserController::class, 'syncPermissions']);
    Route::apiResource('users', UserController::class);

    Route::post('roles/{role}/permissions/sync', [RoleController::class, 'syncPermissions']);
    Route::apiResource('roles', RoleController::class);

    Route::apiResource('permissions', PermissionController::class);

    Route::post('courses/{course}/restore', [CourseController::class, 'restore'])->withTrashed();
    Route::apiResource('courses', CourseController::class);

    Route::post('programs/{program}/restore', [ProgramController::class, 'restore'])->withTrashed();
    Route::apiResource('programs', ProgramController::class);

    Route::post('departments/{department}/restore', [DepartmentController::class, 'restore'])->withTrashed();
    Route::apiResource('departments', DepartmentController::class);

    Route::get('audits', [AuditController::class, 'index']);
    Route::get('audits/{audit}', [AuditController::class, 'show']);
});
