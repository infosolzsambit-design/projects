<?php

use App\Http\Controllers\API\V1\AssignTeacherController;
use App\Http\Controllers\API\V1\AuditController;
use App\Http\Controllers\API\V1\Auth\AuthController;
use App\Http\Controllers\API\V1\Auth\PermissionController;
use App\Http\Controllers\API\V1\Auth\ProfileController;
use App\Http\Controllers\API\V1\Auth\RoleController;
use App\Http\Controllers\API\V1\GeneralSettingController;
use App\Http\Controllers\API\V1\Master\CourseBulkUploadController;
use App\Http\Controllers\API\V1\Master\CourseController;
use App\Http\Controllers\API\V1\Master\DepartmentBulkUploadController;
use App\Http\Controllers\API\V1\Master\DepartmentController;
use App\Http\Controllers\API\V1\Master\ExamTermController;
use App\Http\Controllers\API\V1\Master\PermissionGroupController;
use App\Http\Controllers\API\V1\Master\PermissionSubGroupController;
use App\Http\Controllers\API\V1\Master\ProgramBulkUploadController;
use App\Http\Controllers\API\V1\Master\ProgramController;
use App\Http\Controllers\API\V1\MyPendingCourseController;
use App\Http\Controllers\API\V1\QuestionAnswerSheetMappingController;
use App\Http\Controllers\API\V1\QuestionPaperController;
use App\Http\Controllers\API\V1\StudentBulkUploadController;
use App\Http\Controllers\API\V1\StudentController;
use App\Http\Controllers\API\V1\TeacherBulkUploadController;
use App\Http\Controllers\API\V1\TeacherController;
use App\Http\Controllers\API\V1\TeacherEsignController;
use App\Http\Controllers\API\V1\TeacherFaceController;
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

Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:password-reset');
Route::post('reset-password/verify', [AuthController::class, 'verifyResetToken'])
    ->middleware('throttle:password-reset');
Route::post('reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:password-reset');

// Public — paints the app's own chrome (favicon, login logo, sidebar/footer
// logos) before a visitor has logged in. See GeneralSettingController's
// BRANDING_FIELDS for exactly what this can and can't return.
Route::get('branding', [GeneralSettingController::class, 'branding']);

Route::middleware(['auth:sanctum', PinTokenToClient::class])->group(function (): void {
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('logout-all', [AuthController::class, 'logoutAll']);
    Route::get('me', [AuthController::class, 'me']);
    Route::post('change-password', [AuthController::class, 'changePassword']);

    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);
    Route::post('profile/face', [ProfileController::class, 'updateFace']);
    Route::post('profile/face/verify', [ProfileController::class, 'verifyFace']);
    Route::post('profile/esign', [ProfileController::class, 'updateEsign']);

    // Sidebar's "My Pending Course" — the logged-in teacher's own
    // not-yet-evaluated answer sheets, grouped by subject. Deliberately no
    // permission gate — see MyPendingCourseController's own docblock.
    Route::get('my-pending-courses', [MyPendingCourseController::class, 'subjects']);
    Route::get('my-pending-courses/papers', [MyPendingCourseController::class, 'papers']);
    Route::post('my-pending-courses/papers/{answer_sheet}/start-evaluation', [MyPendingCourseController::class, 'startEvaluation']);
    Route::get('my-pending-courses/papers/{answer_sheet}', [MyPendingCourseController::class, 'show']);
    Route::post('my-pending-courses/papers/{answer_sheet}/submit-marks', [MyPendingCourseController::class, 'submitMarks']);
    Route::post('my-pending-courses/papers/{answer_sheet}/save-draft', [MyPendingCourseController::class, 'saveDraft']);

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

    Route::post('courses/bulk/validate', [CourseBulkUploadController::class, 'validateRows']);
    Route::post('courses/bulk/store', [CourseBulkUploadController::class, 'store']);
    Route::post('courses/{course}/restore', [CourseController::class, 'restore'])->withTrashed();
    Route::apiResource('courses', CourseController::class);

    Route::post('programs/bulk/validate', [ProgramBulkUploadController::class, 'validateRows']);
    Route::post('programs/bulk/store', [ProgramBulkUploadController::class, 'store']);
    Route::post('programs/{program}/restore', [ProgramController::class, 'restore'])->withTrashed();
    Route::apiResource('programs', ProgramController::class);

    Route::post('departments/bulk/validate', [DepartmentBulkUploadController::class, 'validateRows']);
    Route::post('departments/bulk/store', [DepartmentBulkUploadController::class, 'store']);
    Route::post('departments/{department}/restore', [DepartmentController::class, 'restore'])->withTrashed();
    Route::apiResource('departments', DepartmentController::class);

    Route::post('exam-terms/{exam_term}/restore', [ExamTermController::class, 'restore'])->withTrashed();
    Route::apiResource('exam-terms', ExamTermController::class);

    Route::post('permission-groups/{permission_group}/restore', [PermissionGroupController::class, 'restore'])->withTrashed();
    Route::apiResource('permission-groups', PermissionGroupController::class);

    Route::post('permission-sub-groups/{permission_sub_group}/restore', [PermissionSubGroupController::class, 'restore'])->withTrashed();
    Route::apiResource('permission-sub-groups', PermissionSubGroupController::class);

    Route::post('students/bulk/validate', [StudentBulkUploadController::class, 'validateRows']);
    Route::post('students/bulk/store', [StudentBulkUploadController::class, 'store']);
    Route::post('students/{student}/restore', [StudentController::class, 'restore'])->withTrashed();
    Route::apiResource('students', StudentController::class);

    Route::post('teachers/bulk/validate', [TeacherBulkUploadController::class, 'validateRows']);
    Route::post('teachers/bulk/store', [TeacherBulkUploadController::class, 'store']);
    Route::get('teachers/{teacher}/face', [TeacherFaceController::class, 'show']);
    Route::post('teachers/{teacher}/face', [TeacherFaceController::class, 'update']);
    Route::get('teachers/{teacher}/esign', [TeacherEsignController::class, 'show']);
    Route::post('teachers/{teacher}/esign', [TeacherEsignController::class, 'update']);
    Route::get('teachers/{teacher}/assignments', [TeacherController::class, 'assignments']);
    Route::post('teachers/{teacher}/assignments/reassign', [TeacherController::class, 'reassignAssignment']);
    Route::post('teachers/{teacher}/restore', [TeacherController::class, 'restore'])->withTrashed();
    Route::apiResource('teachers', TeacherController::class);

    // Persists AssignTeacherView.vue's "Assign" click — see
    // AssignTeacherService's own docblock for what this actually stores.
    Route::post('assign-teacher', [AssignTeacherController::class, 'store']);

    Route::get('audits', [AuditController::class, 'index']);
    Route::get('audits/{audit}', [AuditController::class, 'show']);

    Route::get('general-settings', [GeneralSettingController::class, 'index']);
    Route::post('general-settings', [GeneralSettingController::class, 'update']);

    Route::post('question-papers/{question_paper}/restore', [QuestionPaperController::class, 'restore'])->withTrashed();
    Route::apiResource('question-papers', QuestionPaperController::class);

    // No update() — a packet's rows are always replaced wholesale by a
    // fresh upload, never edited row by row (see the migrations' own
    // docblocks), so there's nothing to PUT/PATCH yet.
    // Explicit parameter name — the resource name's own default
    // ("answer_sheet_mapping") wouldn't match the model's actual name
    // (QuestionAnswerSheetMapping) that the controller type-hints.
    Route::apiResource('answer-sheet-mappings', QuestionAnswerSheetMappingController::class)
        ->parameters(['answer-sheet-mappings' => 'question_answer_sheet_mapping'])
        ->only(['index', 'store', 'show', 'destroy']);
    // Phase 2 of the upload — one batch of rows/PDFs at a time, against an
    // already-created mapping from the apiResource's own store() above.
    Route::post('answer-sheet-mappings/{question_answer_sheet_mapping}/rows', [QuestionAnswerSheetMappingController::class, 'storeRows']);
    // The read-side counterpart — a packet's own rows, paginated (see
    // AnswerSheetsView.vue's "View Answer Sheets" modal). Same URI as the
    // POST above, different verb — GET lists, POST appends a batch.
    Route::get('answer-sheet-mappings/{question_answer_sheet_mapping}/rows', [QuestionAnswerSheetMappingController::class, 'rows']);
});
