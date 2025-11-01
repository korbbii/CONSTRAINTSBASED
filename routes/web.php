<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AutomateScheduleController;
use App\Http\Controllers\ConflictCheckerController;
use App\Http\Controllers\ReferenceCheckerController;
use App\Http\Controllers\RoomReferenceController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('GenerateSched');
});

Route::get('/export', [App\Http\Controllers\ExportSchedController::class, 'export'])->name('export.schedule');

// Main schedule generation route
Route::post('/generate-schedule', [AutomateScheduleController::class, 'generateSchedule']);

// Test route for consolidation
Route::get('/test-consolidation', [AutomateScheduleController::class, 'testConsolidation']);

// Debug route for subject consistency
Route::post('/debug-subject-consistency', [AutomateScheduleController::class, 'debugSubjectConsistency']);

// New: generate schedule using Python OR-Tools
Route::post('/generate-ortools', [AutomateScheduleController::class, 'generateScheduleOrtools']);

// New routes for simplified session options (Option A and Option B only)
Route::post('/get-session-options', [AutomateScheduleController::class, 'getSessionOptions']);
Route::post('/generate-schedule-with-options', [AutomateScheduleController::class, 'generateScheduleWithOptions']);

// Conflict checker route
Route::get('/conflict-checker', [ConflictCheckerController::class, 'index'])->name('conflict.checker');
Route::get('/reference-checker', [ReferenceCheckerController::class, 'index'])->name('check.reference');
Route::get('/room-reference-checker', [RoomReferenceController::class, 'index'])->name('check.room.reference');

// Debug route to check schedule data
Route::get('/debug-schedule-data', function() {
    try {
        $entries = App\Models\ScheduleEntry::with(['instructor', 'subject', 'section', 'room', 'meetings'])->take(3)->get();
        
        $data = [];
        foreach ($entries as $entry) {
            $data[] = [
                'entry_id' => $entry->entry_id,
                'subject_code' => $entry->subject_code,
                'subject_description' => $entry->subject_description,
                'instructor_name' => $entry->instructor_name,
                'units' => $entry->units,
                'day' => $entry->day,
                'start_time' => $entry->start_time,
                'end_time' => $entry->end_time,
                'year_level' => $entry->year_level,
                'block' => $entry->block,
                'room_name' => $entry->room ? $entry->room->room_name : 'No room',
                'meetings_count' => $entry->meetings->count(),
                'meetings_data' => $entry->meetings->toArray()
            ];
        }
        
        return response()->json([
            'success' => true,
            'total_entries' => $entries->count(),
            'data' => $data
        ]);
    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
});
