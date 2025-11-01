<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\ReferenceController;
use App\Http\Controllers\ReferenceGroupController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Room routes - specific routes first to avoid conflicts
Route::get('rooms/all', [RoomController::class, 'getAll']); // Get all rooms for client-side pagination
Route::get('rooms/type/{type}', [RoomController::class, 'getByType']);
Route::post('rooms/available', [RoomController::class, 'getAvailableRooms']);

// Real-time schedule edit validation and update
use App\Http\Controllers\ScheduleEditController;
Route::post('schedule/validate-edit', [ScheduleEditController::class, 'validateEdit']);
Route::post('schedule/update-meeting', [ScheduleEditController::class, 'updateMeeting']);
Route::post('schedule/update-by-locator', [ScheduleEditController::class, 'updateByLocator']);
Route::post('schedule/locate-entry', [ScheduleEditController::class, 'locateEntry']);
Route::post('schedule/suggest-alternatives', [ScheduleEditController::class, 'suggestAlternatives']);

// Drafts API for fetching all drafts
Route::get('drafts', [App\Http\Controllers\DraftsController::class, 'index']);

// Get specific draft by ID
Route::get('drafts/{id}', [App\Http\Controllers\DraftsController::class, 'show']);

// Save schedule as draft
Route::post('drafts/save', [App\Http\Controllers\DraftsController::class, 'saveDraft']);

// Resource routes last
Route::apiResource('rooms', RoomController::class);

// Schedule automation routes
Route::post('schedule/generate', [App\Http\Controllers\AutomateScheduleController::class, 'generateSchedule']);
Route::get('schedule/get', [App\Http\Controllers\AutomateScheduleController::class, 'getSchedules']);
Route::get('schedule/get-by-group', [App\Http\Controllers\AutomateScheduleController::class, 'getScheduleByGroupId']);
Route::get('schedule/test-time-formats', [App\Http\Controllers\AutomateScheduleController::class, 'testTimeFormats']);
Route::get('schedule/test-database', [App\Http\Controllers\AutomateScheduleController::class, 'testDatabase']);
Route::get('schedule/debug-data', [App\Http\Controllers\AutomateScheduleController::class, 'debugData']);
Route::post('schedule/fix-year-levels', [App\Http\Controllers\AutomateScheduleController::class, 'fixYearLevelAssignments']);
Route::post('schedule/regenerate-sections', [App\Http\Controllers\AutomateScheduleController::class, 'regenerateSectionsAndFixAssignments']);
Route::get('schedule/debug-subject-consistency', [App\Http\Controllers\AutomateScheduleController::class, 'debugSubjectConsistency']);
Route::get('schedule/debug-sections-entries', [App\Http\Controllers\AutomateScheduleController::class, 'debugSectionsAndEntries']);
Route::get('schedule/debug-section-distribution', [App\Http\Controllers\AutomateScheduleController::class, 'debugSectionDistribution']);
Route::get('schedule/file-upload-logs', [App\Http\Controllers\AutomateScheduleController::class, 'getFileUploadLogs']);

// Instructor data routes for filter preferences
Route::get('instructor-data/current', [App\Http\Controllers\AutomateScheduleController::class, 'getCurrentInstructorData']);
Route::post('instructor-data/store', [App\Http\Controllers\AutomateScheduleController::class, 'storeInstructorDataForFilter']);

// History routes
Route::get('history', [App\Http\Controllers\HistoryController::class, 'index']);
Route::get('history/room/{room}', [App\Http\Controllers\HistoryController::class, 'getRoomHistory']);
Route::get('history/instructor', [App\Http\Controllers\HistoryController::class, 'getInstructorHistory']);
Route::get('history/subject', [App\Http\Controllers\HistoryController::class, 'getSubjectHistory']);
Route::get('history/statistics', [App\Http\Controllers\HistoryController::class, 'getStatistics']);
Route::get('history/conflicts', [App\Http\Controllers\HistoryController::class, 'getConflictsHistory']);
Route::get('history/export', [App\Http\Controllers\HistoryController::class, 'exportHistory']);
Route::get('history/recent', [App\Http\Controllers\HistoryController::class, 'getRecentChanges']);

// Reference schedule routes
Route::post('reference-schedules/upload', [ReferenceController::class, 'uploadReferenceSchedule']);
Route::get('reference-schedules/all', [ReferenceController::class, 'getAll']);
Route::get('reference-schedules/school-year/{schoolYear}', [ReferenceController::class, 'getBySchoolYear']);
Route::get('reference-schedules/education-level/{educationLevel}', [ReferenceController::class, 'getByEducationLevel']);
Route::post('reference-schedules/check-conflicts', [ReferenceController::class, 'checkConflicts']);
Route::post('reference-schedules/bulk-delete', [ReferenceController::class, 'bulkDelete']);
Route::delete('reference-schedules/clear-all', [ReferenceController::class, 'clearAll']);
Route::post('reference-schedules/add-meridiem', [ReferenceController::class, 'addMeridiemToExisting']);
Route::post('reference-schedules/fix-parsing', [ReferenceController::class, 'fixParsingIssues']);
Route::apiResource('reference-schedules', ReferenceController::class);

// Reference group routes
Route::get('reference-groups/all', [ReferenceGroupController::class, 'getAll']);
Route::get('reference-groups/school-year/{schoolYear}', [ReferenceGroupController::class, 'getBySchoolYear']);
Route::get('reference-groups/education-level/{educationLevel}', [ReferenceGroupController::class, 'getByEducationLevel']);
Route::get('reference-groups/year-level/{yearLevel}', [ReferenceGroupController::class, 'getByYearLevel']);
Route::post('reference-groups/bulk-delete', [ReferenceGroupController::class, 'bulkDelete']);
Route::apiResource('reference-groups', ReferenceGroupController::class);

