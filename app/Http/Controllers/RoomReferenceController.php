<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ScheduleGroup;
use App\Models\Reference;
use App\Models\ScheduleMeeting;
use App\Models\Room;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Services\DayScheduler;
use App\Services\TimeScheduler;

class RoomReferenceController extends Controller
{
    public function index()
    {
        // Get all schedule groups to check conflicts across all schedules
        $allGroups = ScheduleGroup::orderBy('created_at', 'desc')->get();
        
        // Get rooms used in both basic education and college
        $crossEducationRooms = $this->getCrossEducationRooms();
        
        return view('RoomReferenceChecker', compact('allGroups', 'crossEducationRooms'));
    }
    
    /**
     * Get rooms used in both basic education (reference) and college (generated schedules)
     */
    private function getCrossEducationRooms()
    {
        // Get all unique room names from reference schedules (basic education)
        $referenceRooms = Reference::with('referenceGroup')
            ->select('room')
            ->distinct()
            ->whereNotNull('room')
            ->where('room', '!=', '')
            ->pluck('room')
            ->filter()
            ->unique()
            ->toArray();
        
        Log::info("Found " . count($referenceRooms) . " unique rooms in reference schedules");
        
        // Get all unique room names from college schedules (via room relationship)
        $collegeRoomNames = ScheduleMeeting::with('room')
            ->whereNotNull('room_id')
            ->get()
            ->pluck('room.room_name')
            ->filter()
            ->unique()
            ->toArray();
        
        Log::info("Found " . count($collegeRoomNames) . " unique rooms in college schedules");
        
        // Find rooms that appear in both using name matching (handle variations)
        $matchingRooms = $this->findMatchingRooms($referenceRooms, $collegeRoomNames);
        
        Log::info("Found " . count($matchingRooms) . " rooms used in both basic education and college");
        
        // Consolidate entries: Group reference room names that match the same college room names
        $consolidatedMatches = [];
        foreach ($matchingRooms as $refRoomName => $collegeRoomNames) {
            // Create a key from the matched college room names (sorted for consistency)
            sort($collegeRoomNames);
            $key = implode('|', $collegeRoomNames);
            
            if (!isset($consolidatedMatches[$key])) {
                $consolidatedMatches[$key] = [
                    'reference_names' => [],
                    'college_names' => $collegeRoomNames
                ];
            }
            $consolidatedMatches[$key]['reference_names'][] = $refRoomName;
        }
        
        // Build detailed schedule comparison for each consolidated room
        $results = [];
        foreach ($consolidatedMatches as $consolidated) {
            // Use the first reference name as the display name
            $roomName = $consolidated['reference_names'][0];
            $allReferenceNames = $consolidated['reference_names'];
            $collegeRoomNames = $consolidated['college_names'];
            
            // Get ALL reference schedules for ALL matched reference room names
            $referenceSchedules = Reference::with('referenceGroup')
                ->whereIn('room', $allReferenceNames)
                ->get();
            
            // Get college schedules for matched college room names
            $collegeSchedules = ScheduleMeeting::with(['instructor', 'entry.scheduleGroup', 'entry.subject', 'entry.section', 'room'])
                ->whereHas('room', function($q) use ($collegeRoomNames) {
                    $q->whereIn('room_name', $collegeRoomNames);
                })
                ->get();
            
            // Build reference schedules with conflict flags
            $formattedRefSchedules = $referenceSchedules->map(function($ref) use ($collegeSchedules) {
                $conflict = $this->checkRoomConflict($ref, $collegeSchedules);
                return [
                    'day' => $ref->day,
                    'time' => $this->formatReferenceTime($ref->time),
                    'room' => $ref->room,
                    'subject' => $ref->subject,
                    'instructor' => $ref->instructor,
                    'education_level' => $ref->referenceGroup->education_level ?? 'Unknown',
                    'year_level' => $ref->referenceGroup->year_level ?? 'Unknown',
                    'has_conflict' => $conflict,
                ];
            });
            
            // Build college schedules with conflict flags
            $formattedColSchedules = $collegeSchedules->map(function($col) use ($referenceSchedules) {
                $conflict = $this->checkRoomConflictReverse($col, $referenceSchedules);
                return [
                    'day' => $col->day,
                    'start_time' => $this->formatTime12Hour($col->start_time),
                    'end_time' => $this->formatTime12Hour($col->end_time),
                    'room' => $col->room ? $col->room->room_name : 'No Room',
                    'subject' => $col->entry->subject ? $col->entry->subject->code : 'Unknown',
                    'section' => $col->entry->section ? $col->entry->section->code : 'Unknown',
                    'instructor' => $col->instructor ? $col->instructor->name : 'Unknown',
                    'department' => $col->entry->scheduleGroup->department ?? 'Unknown',
                    'school_year' => $col->entry->scheduleGroup->school_year ?? 'Unknown',
                    'semester' => $col->entry->scheduleGroup->semester ?? 'Unknown',
                    'has_conflict' => $conflict,
                ];
            });
            
            $results[] = [
                'room' => $roomName,
                'reference_schedules' => $formattedRefSchedules,
                'college_schedules' => $formattedColSchedules,
            ];
        }
        
        return $results;
    }
    
    /**
     * Find matching rooms between reference and college schedules
     * Returns array mapping reference room name to array of matching college room names
     * Handles variations like "HS 203" vs "HS203" or case differences
     */
    private function findMatchingRooms(array $referenceRooms, array $collegeRooms): array
    {
        $matches = [];
        
        foreach ($referenceRooms as $refRoom) {
            $matchedCollegeRooms = [];
            
            foreach ($collegeRooms as $collegeRoom) {
                if ($this->matchRoomNames($refRoom, $collegeRoom)) {
                    $matchedCollegeRooms[] = $collegeRoom;
                }
            }
            
            if (!empty($matchedCollegeRooms)) {
                $matches[$refRoom] = $matchedCollegeRooms;
            }
        }
        
        return $matches;
    }
    
    /**
     * Match room names with fuzzy logic to handle variations
     * Handles variations like:
     * - "HS 203" matches "HS 203", "HS203", "hs 203"
     * - "204 H.S BLDG" matches "HS 204"
     * - Case-insensitive matching
     */
    private function matchRoomNames(string $room1, string $room2): bool
    {
        // Exact match (case-insensitive)
        if (strcasecmp(trim($room1), trim($room2)) === 0) {
            return true;
        }
        
        // Normalize both room names (remove spaces, convert to lowercase)
        $normalized1 = strtolower(str_replace(' ', '', trim($room1)));
        $normalized2 = strtolower(str_replace(' ', '', trim($room2)));
        
        // Exact match after normalization
        if ($normalized1 === $normalized2) {
            return true;
        }
        
        // Handle variations like "204 H.S BLDG" vs "HS 204"
        // Extract numbers and building codes
        preg_match('/(\d+)/', $room1, $matches1);
        preg_match('/(\d+)/', $room2, $matches2);
        
        if (!empty($matches1[1]) && !empty($matches2[1]) && $matches1[1] === $matches2[1]) {
            // Same room number, check if building matches (fuzzy)
            $building1 = preg_replace('/[\d\s]/', '', strtolower($room1));
            $building2 = preg_replace('/[\d\s]/', '', strtolower($room2));
            
            // Check if one contains the other (for "hs" vs "h.s" or "hsbldg")
            if (stripos($building1, $building2) !== false || stripos($building2, $building1) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Correct incorrectly stored reference times for basic education
     * Converts 12-6 AM to PM (hours 1-6 in 24-hour format become 13-18)
     */
    private function correctReferenceTime(string $time): string
    {
        if (empty($time) || $time === 'N/A') {
            return $time;
        }
        
        try {
            // Reference format is "HH:MM:SS-HH:MM:SS"
            if (strpos($time, '-') !== false) {
                list($start, $end) = explode('-', $time, 2);
                $start = trim($start);
                $end = trim($end);
                
                // Parse hours
                $startHour = (int) explode(':', $start)[0];
                $endHour = (int) explode(':', $end)[0];
                
                // Correct start time: convert 1-6 AM (01:00-06:00) to PM (13:00-18:00)
                if ($startHour >= 1 && $startHour <= 6) {
                    // Convert 1-6 AM to 1-6 PM (add 12 hours)
                    $startHour += 12;
                    $startParts = explode(':', $start);
                    $start = sprintf('%02d:%s:%s', $startHour, $startParts[1], $startParts[2] ?? '00');
                } elseif ($startHour == 0) {
                    // Convert midnight (00:00) to noon (12:00 PM) for basic ed
                    $startParts = explode(':', $start);
                    $start = sprintf('12:%s:%s', $startParts[1], $startParts[2] ?? '00');
                }
                
                // Correct end time: convert 1-6 AM (01:00-06:00) to PM (13:00-18:00)
                if ($endHour >= 1 && $endHour <= 6) {
                    // Convert 1-6 AM to 1-6 PM (add 12 hours)
                    $endHour += 12;
                    $endParts = explode(':', $end);
                    $end = sprintf('%02d:%s:%s', $endHour, $endParts[1], $endParts[2] ?? '00');
                } elseif ($endHour == 0) {
                    // Convert midnight (00:00) to noon (12:00 PM) for basic ed
                    $endParts = explode(':', $end);
                    $end = sprintf('12:%s:%s', $endParts[1], $endParts[2] ?? '00');
                }
                
                return $start . '-' . $end;
            } else {
                // Single time value
                $hour = (int) explode(':', $time)[0];
                if ($hour >= 1 && $hour <= 6) {
                    // Convert 1-6 AM to 1-6 PM (add 12 hours)
                    $hour += 12;
                    $parts = explode(':', $time);
                    return sprintf('%02d:%s:%s', $hour, $parts[1], $parts[2] ?? '00');
                } elseif ($hour == 0) {
                    // Convert midnight (00:00) to noon (12:00 PM) for basic ed
                    $parts = explode(':', $time);
                    return sprintf('12:%s:%s', $parts[1], $parts[2] ?? '00');
                }
                return $time;
            }
        } catch (\Exception $e) {
            Log::warning('Error correcting reference time: ' . $e->getMessage());
            return $time;
        }
    }
    
    /**
     * Format reference time (e.g., "07:30:00-08:30:00") to 12-hour format
     */
    private function formatReferenceTime(string $time): string
    {
        if (empty($time) || $time === 'N/A') {
            return 'N/A';
        }
        
        try {
            // First correct the time if needed (12-6 AM should be PM)
            $correctedTime = $this->correctReferenceTime($time);
            
            // Reference format is "HH:MM:SS-HH:MM:SS"
            if (strpos($correctedTime, '-') !== false) {
                list($start, $end) = explode('-', $correctedTime, 2);
                $startFormatted = $this->formatTime12Hour($start);
                $endFormatted = $this->formatTime12Hour($end);
                return $startFormatted . ' - ' . $endFormatted;
            } else {
                // Fallback for single time
                return $this->formatTime12Hour($correctedTime);
            }
        } catch (\Exception $e) {
            return $time;
        }
    }
    
    /**
     * Convert 24-hour time to 12-hour with AM/PM
     */
    private function formatTime12Hour(string $time24): string
    {
        if (empty($time24) || $time24 === 'N/A') {
            return 'N/A';
        }
        
        try {
            return Carbon::createFromFormat('H:i:s', $time24)->format('g:i A');
        } catch (\Exception $e) {
            // Try parsing without seconds
            try {
                return Carbon::createFromFormat('H:i', $time24)->format('g:i A');
            } catch (\Exception $e2) {
                return $time24;
            }
        }
    }
    
    /**
     * Check if a reference schedule conflicts with any college schedules (by room)
     */
    private function checkRoomConflict($ref, $collegeSchedules): bool
    {
        // Parse reference time (format: "HH:MM:SS-HH:MM:SS")
        $refTime = $ref->time;
        if (empty($refTime) || strpos($refTime, '-') === false) {
            return false;
        }
        
        // Correct the reference time before checking conflicts (12-6 AM should be PM)
        $correctedTime = $this->correctReferenceTime($refTime);
        list($refStart, $refEnd) = explode('-', $correctedTime, 2);
        $refStart = trim($refStart);
        $refEnd = trim($refEnd);
        
        // Parse reference day
        $refDay = DayScheduler::normalizeDay($ref->day);
        
        // Check against each college schedule
        foreach ($collegeSchedules as $col) {
            // Check if room matches (fuzzy match)
            if (!$this->matchRoomNames($ref->room, $col->room ? $col->room->room_name : '')) {
                continue;
            }
            
            // Parse college day
            $colDays = DayScheduler::parseCombinedDays($col->day);
            
            // Check if days overlap
            if (!in_array($refDay, $colDays)) {
                continue;
            }
            
            // Check if times overlap
            if ($this->timesOverlap($refStart, $refEnd, $col->start_time, $col->end_time)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if a college schedule conflicts with any reference schedules (by room)
     */
    private function checkRoomConflictReverse($col, $referenceSchedules): bool
    {
        // Parse college day
        $colDays = DayScheduler::parseCombinedDays($col->day);
        $colRoomName = $col->room ? $col->room->room_name : '';
        
        // Check against each reference schedule
        foreach ($referenceSchedules as $ref) {
            // Check if room matches (fuzzy match)
            if (!$this->matchRoomNames($ref->room, $colRoomName)) {
                continue;
            }
            
            // Parse reference day
            $refDay = DayScheduler::normalizeDay($ref->day);
            
            // Check if days overlap
            if (!in_array($refDay, $colDays)) {
                continue;
            }
            
            // Parse reference time (format: "HH:MM:SS-HH:MM:SS")
            $refTime = $ref->time;
            if (empty($refTime) || strpos($refTime, '-') === false) {
                continue;
            }
            
            // Correct the reference time before checking conflicts (12-6 AM should be PM)
            $correctedTime = $this->correctReferenceTime($refTime);
            list($refStart, $refEnd) = explode('-', $correctedTime, 2);
            $refStart = trim($refStart);
            $refEnd = trim($refEnd);
            
            // Check if times overlap
            if ($this->timesOverlap($refStart, $refEnd, $col->start_time, $col->end_time)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if two time ranges overlap
     */
    private function timesOverlap(string $start1, string $end1, string $start2, string $end2): bool
    {
        $start1Minutes = TimeScheduler::timeToMinutes($start1);
        $end1Minutes = TimeScheduler::timeToMinutes($end1);
        $start2Minutes = TimeScheduler::timeToMinutes($start2);
        $end2Minutes = TimeScheduler::timeToMinutes($end2);
        
        // Two time ranges overlap if one starts before the other ends
        return ($start1Minutes < $end2Minutes) && ($start2Minutes < $end1Minutes);
    }
}
