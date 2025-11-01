<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ScheduleGroup;
use App\Models\Reference;
use App\Models\ScheduleMeeting;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Services\DayScheduler;
use App\Services\TimeScheduler;

class ReferenceCheckerController extends Controller
{
    public function index()
    {
        // Get all schedule groups to check conflicts across all schedules
        $allGroups = ScheduleGroup::orderBy('created_at', 'desc')->get();
        
        // Get instructors who teach in both basic education and college
        $crossEducationInstructors = $this->getCrossEducationInstructors();
        
        return view('ReferenceChecker', compact('allGroups', 'crossEducationInstructors'));
    }
    
    /**
     * Get instructors who teach in both basic education (reference) and college (generated schedules)
     */
    private function getCrossEducationInstructors()
    {
        // Get all instructors from reference schedules (basic education)
        $referenceInstructors = Reference::with('referenceGroup')
            ->select('instructor')
            ->distinct()
            ->pluck('instructor')
            ->filter()
            ->toArray();
        
        Log::info("Found " . count($referenceInstructors) . " unique instructors in reference schedules");
        
        // Get all instructors from college schedules
        // First get all instructor_ids from ScheduleMeeting where instructor exists
        $collegeInstructorIds = ScheduleMeeting::with('instructor')
            ->whereNotNull('instructor_id')
            ->get()
            ->pluck('instructor.name')
            ->filter()
            ->unique()
            ->toArray();
        
        Log::info("Found " . count($collegeInstructorIds) . " unique instructors in college schedules");
        
        // Find instructors who appear in both using fuzzy matching
        $matchingInstructors = $this->findMatchingInstructors($referenceInstructors, $collegeInstructorIds);
        
        Log::info("Found " . count($matchingInstructors) . " instructors teaching in both basic education and college (with fuzzy matching)");
        
        // Consolidate entries: Group reference instructors that match the same college instructors
        $consolidatedMatches = [];
        foreach ($matchingInstructors as $refInstructorName => $collegeInstructorNames) {
            // Create a key from the matched college instructor names (sorted for consistency)
            sort($collegeInstructorNames);
            $key = implode('|', $collegeInstructorNames);
            
            if (!isset($consolidatedMatches[$key])) {
                $consolidatedMatches[$key] = [
                    'reference_names' => [],
                    'college_names' => $collegeInstructorNames
                ];
            }
            $consolidatedMatches[$key]['reference_names'][] = $refInstructorName;
        }
        
        // Build detailed schedule comparison for each consolidated instructor
        $results = [];
        foreach ($consolidatedMatches as $consolidated) {
            // Use the first reference name as the display name
            $instructorName = $consolidated['reference_names'][0];
            $allReferenceNames = $consolidated['reference_names'];
            $collegeInstructorNames = $consolidated['college_names'];
            
            // Get ALL reference schedules for ALL matched reference names
            $referenceSchedules = Reference::with('referenceGroup')
                ->whereIn('instructor', $allReferenceNames)
                ->get();
            
            // Get college schedules for matched college names
            $collegeSchedules = ScheduleMeeting::with(['instructor', 'entry.scheduleGroup', 'entry.subject', 'entry.section', 'room'])
                ->whereHas('instructor', function($q) use ($collegeInstructorNames) {
                    $q->whereIn('name', $collegeInstructorNames);
                })
                ->get();
            
            // Build reference schedules with conflict flags
            $formattedRefSchedules = $referenceSchedules->map(function($ref) use ($collegeSchedules) {
                $conflict = $this->checkConflict($ref, $collegeSchedules);
                return [
                    'day' => $ref->day,
                    'time' => $this->formatReferenceTime($ref->time),
                    'room' => $ref->room,
                    'subject' => $ref->subject,
                    'education_level' => $ref->referenceGroup->education_level ?? 'Unknown',
                    'year_level' => $ref->referenceGroup->year_level ?? 'Unknown',
                    'has_conflict' => $conflict,
                ];
            });
            
            // Build college schedules with conflict flags
            $formattedColSchedules = $collegeSchedules->map(function($col) use ($referenceSchedules) {
                $conflict = $this->checkConflictReverse($col, $referenceSchedules);
                return [
                    'day' => $col->day,
                    'start_time' => $this->formatTime12Hour($col->start_time),
                    'end_time' => $this->formatTime12Hour($col->end_time),
                    'room' => $col->room ? $col->room->room_name : 'No Room',
                    'subject' => $col->entry->subject ? $col->entry->subject->code : 'Unknown',
                    'section' => $col->entry->section ? $col->entry->section->code : 'Unknown',
                    'department' => $col->entry->scheduleGroup->department ?? 'Unknown',
                    'school_year' => $col->entry->scheduleGroup->school_year ?? 'Unknown',
                    'semester' => $col->entry->scheduleGroup->semester ?? 'Unknown',
                    'has_conflict' => $conflict,
                ];
            });
            
            $results[] = [
                'instructor' => $instructorName,
                'reference_schedules' => $formattedRefSchedules,
                'college_schedules' => $formattedColSchedules,
            ];
        }
        
        return $results;
    }
    
    /**
     * Find matching instructors between reference and college schedules using fuzzy matching
     * Returns array mapping reference instructor name to array of matching college instructor names
     * 
     * Handles formats like:
     * - "Leonardo, D." matches "Leonardo", "Leonardo D.", "D. Leonardo", "Dante Leonardo", "D, Leonardo"
     * - "Cagmat, B." matches "Cagmat", "Cagmat B.", "B. Cagmat", "Bob Cagmat"
     * - "Ke-e, A." matches "Arlaine Ke-e", "Ke-e"
     */
    private function findMatchingInstructors(array $referenceInstructors, array $collegeInstructors): array
    {
        $matches = [];
        
        foreach ($referenceInstructors as $refName) {
            $matchedCollegeNames = [];
            
            foreach ($collegeInstructors as $collegeName) {
                if ($this->matchInstructorNames($refName, $collegeName)) {
                    $matchedCollegeNames[] = $collegeName;
                }
            }
            
            if (!empty($matchedCollegeNames)) {
                $matches[$refName] = $matchedCollegeNames;
            }
        }
        
        return $matches;
    }
    
    /**
     * Match instructor names with fuzzy logic to handle different formats
     * Handles formats like:
     * - "Leonardo, D." matches "Leonardo", "Leonardo D.", "D. Leonardo", "Leonardo, D.", "Dante Leonardo", "D, Leonardo"
     * - "Cagmat, B." matches "Cagmat", "Cagmat B.", "B. Cagmat"
     * - "Ke-e, A." matches "Arlaine Ke-e", "Ke-e"
     */
    private function matchInstructorNames(string $name1, string $name2): bool
    {
        // Exact match
        if ($name1 === $name2) {
            return true;
        }
        
        // Normalize both names
        $name1 = trim($name1);
        $name2 = trim($name2);
        
        // Extract last names and initials from both formats
        $name1Parts = $this->extractNameParts($name1);
        $name2Parts = $this->extractNameParts($name2);
        
        // Normalize last names for comparison (handle encoding issues)
        $name1Last = $this->normalizeLastName($name1Parts['lastName']);
        $name2Last = $this->normalizeLastName($name2Parts['lastName']);
        
        // Split into variants if pipe-separated (spaces vs no spaces)
        $name1Variants = explode('|', $name1Last);
        $name2Variants = explode('|', $name2Last);
        
        // Match if any variant matches
        foreach ($name1Variants as $variant1) {
            foreach ($name2Variants as $variant2) {
                if (strcasecmp($variant1, $variant2) === 0) {
                    return true;
                }
            }
        }
        
        // Additional check: if reference name appears as suffix in college name
        // Handles "Dela Peña" in reference matching "Antonio Dela Peña" in college
        if (!empty($name1Last)) {
            // Use the first variant for suffix matching
            $refLastName = mb_strtolower($name1Variants[0], 'UTF-8');
            $collegeNameLower = mb_strtolower($name2, 'UTF-8');
            $refLastNameNoSpace = mb_strtolower(str_replace(' ', '', $name1Variants[0]), 'UTF-8');
            
            // Check both with and without spaces
            if (mb_substr($collegeNameLower, -mb_strlen($refLastName, 'UTF-8'), null, 'UTF-8') === $refLastName ||
                mb_substr($collegeNameLower, -mb_strlen($refLastNameNoSpace, 'UTF-8'), null, 'UTF-8') === $refLastNameNoSpace) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Normalize last name to handle encoding issues
     * Example: "Dela Pea" becomes "Dela Peña" to match database entries
     */
    private function normalizeLastName(string $lastName): string
    {
        if (empty($lastName)) {
            return '';
        }
        
        // Handle common encoding issues: "Pea" -> "Peña"
        $normalizations = [
            'Pea' => 'Peña',
            'Pena' => 'Peña',
            'Mono' => 'Moño',
            'Guno' => 'Guño',
            'Kee' => 'Ke-e',  // Handle hyphenated names without hyphen in reference
            'Le-e' => 'Le-e',
            'Ta-a' => 'Ta-a',
        ];
        
        // Also handle when the bad encoding is part of a larger word
        // e.g., "Delapeña" contains "Peña" but database has "Delapea"
        $wordNormalizations = [
            'Delapea' => 'Delapeña',
            'De la Pea' => 'De la Peña',
            'Dela Pea' => 'Dela Peña',
        ];
        
        $normalized = $lastName;
        
        // First try exact word match
        foreach ($normalizations as $bad => $good) {
            if (preg_match('/(\b)' . preg_quote($bad, '/') . '(\b|$)/i', $normalized)) {
                $normalized = preg_replace('/\b' . preg_quote($bad, '/') . '\b/i', $good, $normalized);
            }
        }
        
        // Then try multi-word replacements
        foreach ($wordNormalizations as $bad => $good) {
            if (stripos($normalized, $bad) !== false) {
                $normalized = str_ireplace($bad, $good, $normalized);
            }
        }
        
        // Also try to match with/without spaces and hyphens: "Dela Peña" <-> "Delapeña", "Ke-e" <-> "Kee"
        // Remove hyphens for matching (e.g., "Ke-e" should match "Kee")
        $normalized = str_replace('-', '', $normalized);
        // Replace spaces in the normalized name for comparison
        $normalizedNoSpace = str_replace(' ', '', $normalized);
        if ($normalizedNoSpace !== $normalized) {
            // Store both versions for matching
            $normalized = $normalized . '|' . $normalizedNoSpace;
        }
        
        return $normalized;
    }
    
    /**
     * Extract last name and initials from instructor name
     * Handles formats: "Leonardo, D.", "D. Leonardo", "Leonardo D.", "Leonardo", "Dante Leonardo", "D, Leonardo", "Ke-e, A.", "Dela Peña, A."
     */
    private function extractNameParts(string $name): array
    {
        $result = ['lastName' => '', 'initials' => []];
        $name = trim($name);
        
        // Handle format "Lastname, Initial." or "Lastname, I." (with period)
        // Support hyphenated names like "Ke-e, A." and enye like "Dela Peña, A."
        // Allow spaces for multi-word last names like "De La Cruz, M."
        if (preg_match('/^([\p{L}\-\s]+)\s*,\s*([\p{L}\.]+\.)$/u', $name, $matches)) {
            $result['lastName'] = trim($matches[1]);
            $initials = preg_replace('/[^\p{L}]/u', '', $matches[2]);
            if (!empty($initials)) {
                $result['initials'] = str_split(mb_strtoupper($initials, 'UTF-8'));
            }
        }
        // Handle format "Initial, Lastname" or "I, Lastname" (initials first with comma, no period)
        elseif (preg_match('/^([\p{L}]+)\s*,\s*([\p{L}\-\s]+)$/u', $name, $matches)) {
            // If first part is very short (1-2 chars), it's likely initials
            if (mb_strlen($matches[1], 'UTF-8') <= 2 && mb_strlen(trim($matches[2]), 'UTF-8') > 2) {
                $result['lastName'] = trim($matches[2]);
                $initials = preg_replace('/[^\p{L}]/u', '', $matches[1]);
                if (!empty($initials)) {
                    $result['initials'] = str_split(mb_strtoupper($initials, 'UTF-8'));
                }
            } else {
                // Otherwise assume "Lastname, Initial" format (without period)
                $result['lastName'] = trim($matches[1]);
                $initials = preg_replace('/[^\p{L}]/u', '', $matches[2]);
                if (!empty($initials)) {
                    $result['initials'] = str_split(mb_strtoupper($initials, 'UTF-8'));
                }
            }
        }
        // Handle format "Initial. Lastname" or "I. Lastname"
        elseif (preg_match('/^([\p{L}\.]+)\s+([\p{L}\-\s]+)$/u', $name, $matches)) {
            $result['lastName'] = trim($matches[2]);
            $initials = preg_replace('/[^\p{L}]/u', '', $matches[1]);
            if (!empty($initials)) {
                $result['initials'] = str_split(mb_strtoupper($initials, 'UTF-8'));
            }
        }
        // Handle format "Lastname Initial" or just "Lastname" or "Firstname Lastname"
        elseif (preg_match('/^([\p{L}\-\s]+)(?:\s+([\p{L}\.\-]+))?$/u', $name, $matches)) {
            $words = preg_split('/\s+/', trim($name));
            if (count($words) >= 2) {
                // Check if last word looks like initials (very short, with period, or just 1-2 chars)
                $lastWord = end($words);
                if (preg_match('/^[\p{L}]\.?$/u', $lastWord) || mb_strlen(preg_replace('/[^\p{L}]/u', '', $lastWord), 'UTF-8') <= 2) {
                    // Last word is an initial, remove it and take the rest as the last name
                    array_pop($words);
                    $result['lastName'] = implode(' ', $words);
                } else {
                    // Last word is a full last name (could be part of multi-word last name)
                    // For "Dela Peña", need to check if it's really a first name + last name
                    // or if it's already just a last name
                    // Take the last word as last name for now
                    $result['lastName'] = end($words);
                }
            } else {
                // Single word - could be last name or full last name
                $result['lastName'] = trim($matches[1]);
            }
            
            // Extract initials from middle parts if any
            if (!empty($matches[2])) {
                $initials = preg_replace('/[^\p{L}]/u', '', $matches[2]);
                if (!empty($initials)) {
                    $result['initials'] = str_split(mb_strtoupper($initials, 'UTF-8'));
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Correct incorrectly stored reference times for basic education
     * Converts 12-6 AM to PM (hours 1-6 in 24-hour format become 13-18)
     * Rules:
     * - 7-11 AM (07:00-11:00) stays as AM
     * - 12:00 (noon) stays as PM
     * - 1-6 AM (01:00-06:00) becomes 1-6 PM (13:00-18:00)
     * - 0:00 (midnight) becomes 12:00 PM (noon) for basic ed
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
                // Keep 12:00 as is (noon = 12 PM), 7-11 as is (AM), 13-18+ as is (already PM)
                
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
                // Keep 12:00 as is (noon = 12 PM), 7-11 as is (AM), 13-18+ as is (already PM)
                
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
                // Keep 12:00 as is (noon), 7-11 as is (AM), 13+ as is (already PM)
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
     * Check if a reference schedule conflicts with any college schedules
     */
    private function checkConflict($ref, $collegeSchedules): bool
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
     * Check if a college schedule conflicts with any reference schedules
     */
    private function checkConflictReverse($col, $referenceSchedules): bool
    {
        // Parse college day
        $colDays = DayScheduler::parseCombinedDays($col->day);
        
        // Check against each reference schedule
        foreach ($referenceSchedules as $ref) {
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


