<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\EmailController;
use App\Http\Controllers\Api\V1\ExamController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\Manage\AccountRequestController;
use App\Http\Controllers\Api\V1\Manage\ClassAssignmentController;
use App\Http\Controllers\Api\V1\Manage\ReviewController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\UploadController;
use Illuminate\Support\Facades\Route;

// All routes are prefixed with /api/v1 (see bootstrap/app.php).

// ---- Public ----
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::middleware('throttle:password-reset')->group(function () {
    Route::post('auth/forgot', [AuthController::class, 'forgot']);
    Route::post('auth/forgot/verify', [AuthController::class, 'verifyCode']);
    Route::post('auth/reset', [AuthController::class, 'reset']);
});
// Sign-up (or an account request, when instant sign-up is off). Many doctors may sign up from one network (a
// workshop room, a university NAT), so the limit is per minute rather than a handful per hour.
Route::post('account-requests', [AccountController::class, 'request'])->middleware('throttle:20,1');
Route::get('signup-mode', fn () => response()->json(['instant' => (bool) config('portal.signup.instant')]));
// The registration form needs the program list before anyone is signed in.
Route::get('terms', [CatalogController::class, 'terms']);
Route::get('programs', [CatalogController::class, 'programs']);
Route::get('files/{file}/raw', [FileController::class, 'raw'])->middleware('signed')->name('files.raw');

// ---- Signed-in ----
Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::patch('me', [AccountController::class, 'update']);
    Route::put('me/password', [AccountController::class, 'password']);
    Route::patch('me/preferences', [AccountController::class, 'preferences']);

    // Feature 1: pick term → program → course → class and open the course page.
    Route::get('exam-centers', [CatalogController::class, 'examCenters']);
    Route::get('my/offerings', [CatalogController::class, 'myOfferings']);
    Route::get('offerings/{offering}', [CatalogController::class, 'offering']);

    // Features 2 and 3: schedule a session, upload a session.
    Route::get('sessions', [SessionController::class, 'index']);
    Route::post('sessions', [SessionController::class, 'store']);
    Route::post('sessions/recorded', [SessionController::class, 'storeRecorded']);
    Route::patch('sessions/{session}', [SessionController::class, 'update']);
    Route::delete('sessions/{session}', [SessionController::class, 'destroy']);
    Route::post('sessions/{session}/report', [SessionController::class, 'report']);
    Route::get('sessions/{session}/join', [SessionController::class, 'join']);
    Route::post('sessions/{session}/recording', [SessionController::class, 'attachRecording']);

    // Uploads shared by features 3, 4 and 6.
    Route::middleware('throttle:uploads')->group(function () {
        Route::post('uploads', [UploadController::class, 'init']);
        Route::post('uploads/direct', [UploadController::class, 'direct']);
        Route::get('uploads/{upload}', [UploadController::class, 'show']);
        Route::put('uploads/{upload}/chunks/{index}', [UploadController::class, 'chunk'])->whereNumber('index');
        Route::post('uploads/{upload}/complete', [UploadController::class, 'complete']);
    });

    // Feature 4: upload and download files.
    Route::get('files', [FileController::class, 'index']);
    Route::post('files', [FileController::class, 'store']);
    Route::patch('files/{file}', [FileController::class, 'update']);
    Route::delete('files/{file}', [FileController::class, 'destroy']);
    Route::get('files/{file}/download', [FileController::class, 'download']);

    // Feature 5: schedule an exam.
    Route::get('exams', [ExamController::class, 'index']);
    Route::get('exams/export', [ExamController::class, 'export']);
    Route::post('exams/check', [ExamController::class, 'check']);
    Route::post('exams', [ExamController::class, 'store']);
    Route::delete('exams/{exam}', [ExamController::class, 'destroy']);

    // Feature 6: send e-mail.
    Route::get('emails', [EmailController::class, 'index']);
    Route::get('classes/{class}/recipients', [EmailController::class, 'recipients']);
    Route::post('emails', [EmailController::class, 'store'])->middleware('throttle:emails');

    // Program manager / admin: finance report and exam approvals.
    Route::prefix('manage')->middleware('can:manage')->group(function () {
        Route::get('sessions', [ReviewController::class, 'sessions']);
        Route::post('sessions/{session}/{decision}', [ReviewController::class, 'decideSession'])->whereIn('decision', ['approve', 'reject', 'pay']);
        Route::get('exams', [ReviewController::class, 'exams']);
        Route::post('exams/{exam}/{decision}', [ReviewController::class, 'decideExam'])->whereIn('decision', ['approve', 'reject']);

        // Admin only: approve new tutors and assign classes to them.
        Route::middleware('can:admin')->group(function () {
            Route::get('account-requests', [AccountRequestController::class, 'index']);
            Route::post('account-requests/{accountRequest}/{decision}', [AccountRequestController::class, 'decide'])->whereIn('decision', ['approve', 'reject']);
            Route::get('classes', [ClassAssignmentController::class, 'index']);
            Route::get('tutors', [ClassAssignmentController::class, 'tutors']);
            Route::patch('classes/{class}', [ClassAssignmentController::class, 'assign']);
        });
    });
});
