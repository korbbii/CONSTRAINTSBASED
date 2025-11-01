<?php

namespace App\Http\Controllers;

use App\Models\ScheduleGroup;
use App\Models\ScheduleEntry;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HistoryController extends Controller
{
    /**
     * Display schedule history by various filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = ScheduleGroup::with(['scheduleEntries.meetings.room']);

        // Filter by department
        if ($request->has('department')) {
            $query->where('department', 'like', '%' . $request->department . '%');
        }

        // Filter by school year
        if ($request->has('school_year')) {
            $query->where('school_year', $request->school_year);
        }

        // Filter by semester
        if ($request->has('semester')) {
            $query->where('semester', $request->semester);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $scheduleGroups = $query->orderBy('created_at', 'desc')->get();
        
        // Transform data to include only required fields
        $transformedData = $scheduleGroups->map(function ($group) {
            return [
                'group_id' => $group->group_id,
                'department' => $group->department,
                'school_year' => $group->school_year,
                'semester' => $group->semester,
                'date' => $group->created_at->format('M d, Y'),
                'created_at' => $group->created_at,
                'updated_at' => $group->updated_at
            ];
        });

        return response()->json([
            'data' => $transformedData,
            'total' => $transformedData->count()
        ]);
    }

    /**
     * Get schedule history for a specific room
     */
    public function getRoomHistory(Request $request, Room $room): JsonResponse
    {
        $query = ScheduleEntry::with(['scheduleGroup', 'room', 'meetings.room'])
            ->where('room_id', $room->room_id);

        // Filter by academic period
        if ($request->has('school_year')) {
            $query->whereHas('scheduleGroup', function ($q) use ($request) {
                $q->where('school_year', $request->school_year);
            });
        }

        if ($request->has('semester')) {
            $query->whereHas('scheduleGroup', function ($q) use ($request) {
                $q->where('semester', $request->semester);
            });
        }

        $schedules = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json([
            'room' => $room,
            'schedules' => $schedules
        ]);
    }

    /**
     * Get instructor schedule history
     */
    public function getInstructorHistory(Request $request): JsonResponse
    {
        $request->validate([
            'instructor' => 'required|string'
        ]);

        $schedules = ScheduleEntry::with(['scheduleGroup', 'room', 'meetings.room'])
            ->where('instructor', 'like', '%' . $request->instructor . '%')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($schedules);
    }

    /**
     * Get subject schedule history
     */
    public function getSubjectHistory(Request $request): JsonResponse
    {
        $request->validate([
            'subject_code' => 'required|string'
        ]);

        $schedules = ScheduleEntry::with(['scheduleGroup', 'room', 'meetings.room'])
            ->where('subject_code', 'like', '%' . $request->subject_code . '%')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($schedules);
    }

    /**
     * Get schedule statistics and analytics
     */
    public function getStatistics(Request $request): JsonResponse
    {
        $query = ScheduleEntry::with(['scheduleGroup', 'meetings']);

        // Apply filters if provided
        if ($request->has('school_year')) {
            $query->whereHas('scheduleGroup', function ($q) use ($request) {
                $q->where('school_year', $request->school_year);
            });
        }

        if ($request->has('semester')) {
            $query->whereHas('scheduleGroup', function ($q) use ($request) {
                $q->where('semester', $request->semester);
            });
        }

        if ($request->has('department')) {
            $query->whereHas('scheduleGroup', function ($q) use ($request) {
                $q->where('department', 'like', '%' . $request->department . '%');
            });
        }

        $statistics = [
            'total_schedules' => $query->count(),
            'schedules_by_department' => $query->join('schedule_groups', 'schedule_entries.group_id', '=', 'schedule_groups.group_id')
                ->select('schedule_groups.department', DB::raw('count(*) as count'))
                ->groupBy('schedule_groups.department')
                ->get(),
            'schedules_by_day' => $query->select('day', DB::raw('count(*) as count'))
                ->groupBy('day')
                ->orderBy('day')
                ->get(),
            'schedules_by_room_type' => $query->join('rooms', 'schedule_entries.room_id', '=', 'rooms.room_id')
                ->select('rooms.is_lab', DB::raw('count(*) as count'))
                ->groupBy('rooms.is_lab')
                ->get(),
            'top_instructors' => $query->select('instructor', DB::raw('count(*) as count'))
                ->groupBy('instructor')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get(),
            'top_subjects' => $query->select('subject_code', 'subject_description', DB::raw('count(*) as count'))
                ->groupBy('subject_code', 'subject_description')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get()
        ];

        return response()->json($statistics);
    }

    /**
     * Helper method to get year level semester text
     */
    private function getYearLevelSemesterText($scheduleEntries)
    {
        $yearLevels = $scheduleEntries->pluck('year_level')->unique()->sort()->values();
        
        // Remove "Year" from each year level and format nicely
        $formattedYearLevels = $yearLevels->map(function ($yearLevel) {
            return str_replace(' Year', '', $yearLevel);
        });
        
        if ($formattedYearLevels->count() === 1) {
            return $formattedYearLevels->first() . ', 1st Semester';
        } else {
            $yearLevelText = $formattedYearLevels->implode(', ');
            return $yearLevelText . ', 1st Semester';
        }
    }

    /**
     * Get schedule conflicts history
     */
    public function getConflictsHistory(Request $request): JsonResponse
    {
        $request->validate([
            'school_year' => 'required|string',
            'semester' => 'required|string'
        ]);

        $schedules = ScheduleEntry::with(['scheduleGroup', 'room', 'meetings.room'])
            ->whereHas('scheduleGroup', function ($q) use ($request) {
                $q->where('school_year', $request->school_year)
                  ->where('semester', $request->semester);
            })
            ->get();

        $conflicts = [];
        
        foreach ($schedules as $schedule) {
            // Get all meetings for this schedule
            $scheduleMeetings = $schedule->meetings;
            
            foreach ($scheduleMeetings as $meeting) {
                $conflictingSchedules = ScheduleEntry::with(['scheduleGroup', 'room', 'meetings.room'])
                    ->whereHas('scheduleGroup', function ($q) use ($request) {
                        $q->where('school_year', $request->school_year)
                          ->where('semester', $request->semester);
                    })
                    ->whereHas('meetings', function ($q) use ($meeting) {
                        $q->where('day', $meeting->day)
                          ->where('room_id', $meeting->room_id)
                          ->where(function ($timeQuery) use ($meeting) {
                              $timeQuery->where(function ($tq) use ($meeting) {
                                  $tq->where('start_time', '<', $meeting->end_time)
                                    ->where('end_time', '>', $meeting->start_time);
                              });
                          });
                    })
                    ->where('entry_id', '!=', $schedule->entry_id)
                    ->get();

                if ($conflictingSchedules->count() > 0) {
                    $conflicts[] = [
                        'schedule' => $schedule,
                        'meeting' => $meeting,
                        'conflicts' => $conflictingSchedules
                    ];
                }
            }
        }

        return response()->json($conflicts);
    }

    /**
     * Export schedule history to CSV
     */
    public function exportHistory(Request $request): JsonResponse
    {
        $query = ScheduleEntry::with(['scheduleGroup', 'room', 'meetings']);

        // Apply filters
        if ($request->has('school_year')) {
            $query->whereHas('scheduleGroup', function ($q) use ($request) {
                $q->where('school_year', $request->school_year);
            });
        }

        if ($request->has('semester')) {
            $query->whereHas('scheduleGroup', function ($q) use ($request) {
                $q->where('semester', $request->semester);
            });
        }

        if ($request->has('department')) {
            $query->whereHas('scheduleGroup', function ($q) use ($request) {
                $q->where('department', 'like', '%' . $request->department . '%');
            });
        }

        $schedules = $query->get();

        // Generate CSV data
        $csvData = [];
        $csvData[] = [
            'Schedule ID',
            'Room Name',
            'Instructor',
            'Subject Code',
            'Subject Description',
            'Unit',
            'Day',
            'Start Time',
            'End Time',
            'Department',
            'School Year',
            'Semester',
            'Block',
            'Year Level',
            'Created At'
        ];

        foreach ($schedules as $schedule) {
            $csvData[] = [
                $schedule->entry_id,
                $schedule->room->room_name ?? 'N/A',
                $schedule->instructor,
                $schedule->subject_code,
                $schedule->subject_description,
                $schedule->unit,
                $schedule->day,
                $schedule->start_time,
                $schedule->end_time,
                $schedule->scheduleGroup->department ?? 'N/A',
                $schedule->scheduleGroup->school_year ?? 'N/A',
                $schedule->scheduleGroup->semester ?? 'N/A',
                $schedule->block,
                $schedule->year_level,
                $schedule->created_at
            ];
        }

        return response()->json([
            'message' => 'CSV data generated successfully',
            'data' => $csvData,
            'total_records' => count($schedules)
        ]);
    }

    /**
     * Get recent schedule changes
     */
    public function getRecentChanges(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 50);
        
        $recentSchedules = ScheduleEntry::with(['scheduleGroup', 'room', 'meetings.room'])
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($recentSchedules);
    }
} 