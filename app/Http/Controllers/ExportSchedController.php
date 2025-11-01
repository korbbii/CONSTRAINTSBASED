<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ScheduleGroup;
use App\Models\ScheduleEntry;

class ExportSchedController extends Controller
{
    public function export(Request $request)
    {
        $groupId = $request->query('group_id');
        if (!$groupId) {
            abort(404, 'No group_id provided');
        }
        
        $scheduleGroup = ScheduleGroup::findOrFail($groupId);
        
        // Get schedule entries with proper relationships
        $entries = ScheduleEntry::with(['instructor', 'subject', 'section', 'meetings.room', 'meetings.instructor'])
            ->where('group_id', $groupId)
            ->get();
        
        // Use the same consolidation logic as AutomateScheduleController
        $consolidatedSchedules = $this->consolidateCourseEntries($entries);
        
        // Group schedules by year level and block for display
        $groupedSchedules = $this->groupSchedulesByYearLevelAndBlock($consolidatedSchedules);
        
        // Debug: Log the data structure being passed to the view
        \Log::info('ExportSchedController: Data structure sample:', $groupedSchedules['1st Year A'][0] ?? []);
        
        return view('ExportSchedule', [
            'scheduleGroup' => $scheduleGroup,
            'groupedSchedules' => $groupedSchedules,
        ]);
    }
    
    private function consolidateCourseEntries($courseEntries)
    {
        $grouped = $courseEntries->groupBy(function($entry) {
            // Include group_id to prevent mixing courses from different schedule groups
            return $entry->group_id . '|' . $entry->subject_code . '|' . $entry->year_level . '|' . $entry->block;
        });

        $consolidated = [];
        foreach ($grouped as $key => $entries) {
            $consolidated[] = $this->createContinuousTimeRange($entries);
        }

        // Sort by year level, block, day, and time for organized display
        usort($consolidated, function($a, $b) {
            $yearOrder = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4];
            $dayOrder = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
            
            $yearA = $yearOrder[$a['year_level']] ?? 5;
            $yearB = $yearOrder[$b['year_level']] ?? 5;
            
            if ($yearA !== $yearB) {
                return $yearA - $yearB;
            }
            
            // Sort by block (A before B)
            $blockA = $a['block'] ?? 'A';
            $blockB = $b['block'] ?? 'A';
            if ($blockA !== $blockB) {
                return strcmp($blockA, $blockB);
            }
            
            // Sort by the first day of the week
            $daysA = $a['days'] ?? '';
            $daysB = $b['days'] ?? '';
            
            // Extract the first day from each day string (e.g., "MonWed" -> "Mon")
            $firstDayA = preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat)/', $daysA, $matchesA) ? $matchesA[1] : 'Mon';
            $firstDayB = preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat)/', $daysB, $matchesB) ? $matchesB[1] : 'Mon';
            
            $dayOrderA = $dayOrder[$firstDayA] ?? 1;
            $dayOrderB = $dayOrder[$firstDayB] ?? 1;
            
            if ($dayOrderA !== $dayOrderB) {
                return $dayOrderA - $dayOrderB;
            }
            
            // Finally sort by start time
            $timeA = $a['start_time'] ?? '00:00:00';
            $timeB = $b['start_time'] ?? '00:00:00';
            
            return strcmp($timeA, $timeB);
        });

        return $consolidated;
    }
    
    private function createContinuousTimeRange($courseEntries)
    {
        $firstEntry = $courseEntries->first();
        
        // Get days from meetings relationship and sort chronologically
        $days = $courseEntries->flatMap(function($entry) {
            return $entry->meetings->pluck('day');
        })->unique()->values()->toArray();
        
        // Sort days in weekly order (Mon, Tue, Wed, Thu, Fri, Sat)
        $sortedDays = \App\Services\DayScheduler::sortDaysInWeeklyOrder($days);
        $combinedDays = \App\Services\DayScheduler::combineDays($sortedDays);
        
        // Get time range - handle multiple sessions with different times
        $allMeetings = $courseEntries->flatMap(function($entry) {
            return $entry->meetings;
        });
        
        $timeRange = '';
        if ($allMeetings->count() > 0) {
            // Group meetings by time only (start-end) to avoid duplicate times when rooms differ
            $timeOnlyGroups = $allMeetings->groupBy(function($meeting) {
                return $meeting->start_time . '-' . $meeting->end_time;
            });
            
            if ($timeOnlyGroups->count() == 1) {
                // All sessions share the same time - show single time range
                $firstMeeting = $allMeetings->first();
                $timeRange = $this->formatTimeForDisplay($firstMeeting->start_time) . '–' . 
                            $this->formatTimeForDisplay($firstMeeting->end_time);
            } else {
                // Multiple unique time ranges - list each once
                $timeRanges = [];
                foreach ($timeOnlyGroups as $groupKey => $timeGroup) {
                    $meeting = $timeGroup->first();
                    $timeRanges[] = $this->formatTimeForDisplay($meeting->start_time) . '–' . 
                                    $this->formatTimeForDisplay($meeting->end_time);
                }
                // Ensure deterministic order
                sort($timeRanges);
                $timeRange = implode(' / ', $timeRanges);
            }
        }
        
        $primaryRoom = $this->selectPrimaryRoom($courseEntries);

        // Derive instructor from meetings (meeting-level now authoritative)
        $instructorName = 'N/A';
        $instructorCounts = $courseEntries->flatMap(function($entry) {
            return $entry->meetings->map(function($m){
                return optional($m->instructor)->name ?? optional($m->instructor)->instructor_name ?? null;
            });
        })->filter()->countBy();
        if ($instructorCounts->isNotEmpty()) {
            $instructorName = $instructorCounts->sortDesc()->keys()->first();
        } else {
            // fallback to any legacy field on entry if present
            $instructorName = $firstEntry->instructor_name ?? $firstEntry->instructor ?? 'N/A';
        }

        // Get the earliest start time and latest end time for the consolidated entry
        $earliestStart = $allMeetings->count() > 0 ? $allMeetings->pluck('start_time')->sort()->first() : null;
        $latestEnd = $allMeetings->count() > 0 ? $allMeetings->pluck('end_time')->sort()->last() : null;

        return [
            'subject_code' => $firstEntry->subject_code,
            'subject_description' => $firstEntry->subject_description,
            'instructor_name' => $instructorName,
            'year_level' => $firstEntry->year_level,
            'block' => $firstEntry->block,
            'day' => $combinedDays, // Use combined days for display
            'days' => $combinedDays,
            'start_time' => $earliestStart,
            'end_time' => $latestEnd,
            'time_range' => $timeRange,
            'room_name' => $primaryRoom ? $primaryRoom->room_name : 'TBA',
            'is_lab' => $primaryRoom ? $primaryRoom->is_lab : false,
            'units' => $firstEntry->units,
            'department' => $firstEntry->department
        ];
    }
    
    private function selectPrimaryRoom($courseEntries)
    {
        // Get room from meetings relationship instead of direct room_id
        $roomCounts = $courseEntries->flatMap(function($entry) {
            return $entry->meetings->pluck('room_id');
        })->countBy();
        
        if ($roomCounts->isEmpty()) {
            return null;
        }
        
        // Get the most frequently used room
        $mostUsedRoomId = $roomCounts->sortDesc()->keys()->first();
        
        // Find the room model from the first entry's meetings
        foreach ($courseEntries as $entry) {
            foreach ($entry->meetings as $meeting) {
                if ($meeting->room_id == $mostUsedRoomId && $meeting->room) {
                    return $meeting->room;
                }
            }
        }
        
        return null;
    }
    
    private function formatTimeForDisplay($time)
    {
        if (!$time) {
            return 'N/A';
        }
        
        try {
            return \Carbon\Carbon::parse($time)->format('g:i A');
        } catch (\Exception $e) {
            return 'N/A';
        }
    }
    
    private function groupSchedulesByYearLevelAndBlock($consolidatedSchedules)
    {
        $grouped = [];
        
        foreach ($consolidatedSchedules as $schedule) {
            $yearLevel = $schedule['year_level'] ?? '1st Year';
            $block = $schedule['block'] ?? 'A';
            $key = "{$yearLevel} {$block}";
            
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            
            $grouped[$key][] = $schedule;
        }
        
        // Sort the groups by year level and block
        $sortedGrouped = [];
        $yearOrder = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4];
        $blockOrder = ['A' => 1, 'B' => 2];
        
        $keys = array_keys($grouped);
        usort($keys, function($a, $b) use ($yearOrder, $blockOrder) {
            // Extract year level and block from key like "1st Year A"
            preg_match('/(\d+(?:st|nd|rd|th) Year)\s+([AB])/', $a, $matchesA);
            preg_match('/(\d+(?:st|nd|rd|th) Year)\s+([AB])/', $b, $matchesB);
            
            $aYear = $yearOrder[$matchesA[1] ?? '1st Year'] ?? 5;
            $bYear = $yearOrder[$matchesB[1] ?? '1st Year'] ?? 5;
            $aBlock = $blockOrder[$matchesA[2] ?? 'A'] ?? 3;
            $bBlock = $blockOrder[$matchesB[2] ?? 'A'] ?? 3;
            
            if ($aYear !== $bYear) {
                return $aYear - $bYear;
            }
            return $aBlock - $bBlock;
        });
        
        foreach ($keys as $key) {
            // Sort entries within each group by day and time
            $dayOrder = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
            
            usort($grouped[$key], function($a, $b) use ($dayOrder) {
                // Sort by the first day of the week
                $daysA = $a['days'] ?? '';
                $daysB = $b['days'] ?? '';
                
                // Extract the first day from each day string (e.g., "MonWed" -> "Mon")
                $firstDayA = preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat)/', $daysA, $matchesA) ? $matchesA[1] : 'Mon';
                $firstDayB = preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat)/', $daysB, $matchesB) ? $matchesB[1] : 'Mon';
                
                $dayOrderA = $dayOrder[$firstDayA] ?? 1;
                $dayOrderB = $dayOrder[$firstDayB] ?? 1;
                
                if ($dayOrderA !== $dayOrderB) {
                    return $dayOrderA - $dayOrderB;
                }
                
                // Finally sort by start time
                $timeA = $a['start_time'] ?? '00:00:00';
                $timeB = $b['start_time'] ?? '00:00:00';
                
                return strcmp($timeA, $timeB);
            });
            
            $sortedGrouped[$key] = $grouped[$key];
        }
        
        return $sortedGrouped;
    }
}
