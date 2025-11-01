<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Centralized resource availability tracking for conflict-free scheduling
 * 
 * Tracks instructor, room, and section availability with proper multi-day support
 * Provides fast lookup methods for conflict checking before assignment
 */
class ResourceTracker
{
    /**
     * Instructor availability tracking
     * Structure: [instructor_name][day][time_slot] = schedule_data
     */
    private array $instructorAvailability = [];
    
    /**
     * Room occupancy tracking
     * Structure: [room_id][day][time_slot] = schedule_data
     */
    private array $roomOccupancy = [];
    
    /**
     * Section schedule tracking
     * Structure: [section_name][day][time_slot] = schedule_data
     */
    private array $sectionSchedules = [];
    
    /**
     * Track resource usage counts for load balancing
     */
    private array $instructorLoadCount = [];
    private array $roomLoadCount = [];
    private array $sectionLoadCount = [];
    
    /**
     * Track day distribution for load balancing
     */
    private array $dayDistribution = [
        'Mon' => 0, 'Tue' => 0, 'Wed' => 0, 
        'Thu' => 0, 'Fri' => 0, 'Sat' => 0
    ];

    /**
     * Validate if an instructor is available for a specific time slot
     * Uses fuzzy matching to handle different name formats (e.g., "Gudin, J." vs "GUDIN")
     */
    public function isInstructorAvailable(string $instructorName, string $day, string $startTime, string $endTime): bool
    {
        $individualDays = DayScheduler::parseCombinedDays($day);
        
        foreach ($individualDays as $singleDay) {
            $normalizedDay = DayScheduler::normalizeDay($singleDay);
            
            // Check all instructor schedules for fuzzy matches
            foreach ($this->instructorAvailability as $storedInstructorName => $daySchedules) {
                // Use fuzzy matching to handle different name formats
                if ($this->matchInstructorNames($instructorName, $storedInstructorName)) {
                    if (isset($daySchedules[$normalizedDay])) {
                        $slots = $daySchedules[$normalizedDay];
                        $idx = $this->lowerBoundByEndBeforeStart($slots, $startTime);
                        $len = count($slots);
                        for ($i = $idx; $i < $len; $i++) {
                            $existingSlot = $slots[$i];
                            if ($existingSlot['start_time'] >= $endTime) { break; }
                            if (TimeScheduler::timesOverlap($startTime, $endTime, $existingSlot['start_time'], $existingSlot['end_time'])) {
                                // Only log conflicts, not availability checks
                                Log::warning("❌ INSTRUCTOR CONFLICT: {$instructorName} (matched {$storedInstructorName}) already scheduled at {$normalizedDay} {$existingSlot['start_time']}-{$existingSlot['end_time']}, requested {$startTime}-{$endTime}");
                                return false;
                            }
                        }
                    }
                }
            }
        }
        
        return true;
    }
    
    /**
     * Match instructor names with fuzzy logic to handle different formats
     * Handles formats like:
     * - "Leonardo, D." matches "Leonardo", "Leonardo D.", "D. Leonardo", "Leonardo, D."
     * - "Gudin, J." matches "GUDIN", "Gudin J.", "J. Gudin"
     * - "Dela Pea, A." matches "Arnold Delapeña", "Delapeña", "Dela Pea"
     * FIXED: Now handles multi-word last names and special characters correctly
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
        
        // Extract last names from both formats
        $name1Parts = $this->extractNameParts($name1);
        $name2Parts = $this->extractNameParts($name2);
        
        // Match if last names are the same (case-insensitive)
        if (!empty($name1Parts['lastName']) && !empty($name2Parts['lastName'])) {
            $lastName1 = $this->normalizeLastName($name1Parts['lastName']);
            $lastName2 = $this->normalizeLastName($name2Parts['lastName']);
            
            // Exact match after normalization
            if (strcasecmp($lastName1, $lastName2) === 0) {
                return true;
            }
            
            // Check if one contains the other (for compound names like "Dela Pea" vs "Delapeña")
            // Also handle cases where one is slightly longer due to encoding differences
            if (strlen($lastName1) > 3 && strlen($lastName2) > 3) {
                if (stripos($lastName1, $lastName2) !== false || stripos($lastName2, $lastName1) !== false) {
                    return true;
                }
                // Handle partial matches: "delapea" should match "delapena" (one character difference)
                // Check if they differ by only 1-2 characters (accounting for encoding variations)
                $len1 = strlen($lastName1);
                $len2 = strlen($lastName2);
                if (abs($len1 - $len2) <= 2) {
                    // Check if one is a substring of the other when we remove 1-2 chars
                    if ($len1 >= $len2 && similar_text($lastName1, $lastName2) >= min($len1, $len2) - 2) {
                        return true;
                    } elseif ($len2 >= $len1 && similar_text($lastName1, $lastName2) >= min($len1, $len2) - 2) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Normalize last name for matching (remove spaces, handle special chars)
     * Examples: "Dela Pea" → "delapea", "Delapeña" → "delapena"
     */
    private function normalizeLastName(string $lastName): string
    {
        // Remove spaces and special characters, convert to lowercase
        $normalized = strtolower(trim($lastName));
        
        // Handle common encoding/variation issues before normalization
        // "Pea" without tilde should match "Peña" with tilde
        $variations = [
            'pea' => 'pena',  // "Dela Pea" should match "Dela Peña" → "delapena"
        ];
        
        // Check if any variation appears in the name
        foreach ($variations as $variant => $canonical) {
            $normalized = str_replace($variant, $canonical, $normalized);
        }
        
        // Remove spaces and hyphens (for names like "Ke-e" to match "Kee", "Dela Pea" to match "Delapea")
        $normalized = str_replace([' ', '-'], '', $normalized);
        // Normalize special characters (ñ → n, etc.)
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        return $normalized;
    }
    
    /**
     * Extract last name and initials from instructor name
     * Handles formats: "Leonardo, D.", "D. Leonardo", "Leonardo D.", "Leonardo", "GUDIN"
     * FIXED: Now handles multi-word last names like "Dela Pea, A." and full names like "Arnold Delapeña"
     */
    private function extractNameParts(string $name): array
    {
        $result = ['lastName' => '', 'initials' => []];
        $name = trim($name);
        
        // Handle format "Lastname, Initial." or "Multi-word Lastname, I." (e.g., "Dela Pea, A.")
        // Match everything before comma as last name (allows spaces and special chars like ñ)
        if (preg_match('/^(.+?)\s*,\s*([A-Za-z\.]+)$/', $name, $matches)) {
            $result['lastName'] = trim($matches[1]);
            $initials = preg_replace('/[^A-Za-z]/', '', $matches[2]);
            if (!empty($initials)) {
                $result['initials'] = str_split(strtoupper($initials));
            }
        }
        // Handle format "Initial. Lastname" or "I. Lastname" (e.g., "D. Leonardo", "A. Delapeña")
        elseif (preg_match('/^([A-Za-z\.]+)\s+(.+)$/', $name, $matches)) {
            // Check if first part looks like an initial (single letter/letter+dot)
            $firstPart = trim($matches[1]);
            if (preg_match('/^[A-Za-z]\.?$/', $firstPart)) {
                // It's an initial, so second part is the last name (can be multi-word)
                $result['lastName'] = trim($matches[2]);
                $initials = preg_replace('/[^A-Za-z]/', '', $firstPart);
                if (!empty($initials)) {
                    $result['initials'] = str_split(strtoupper($initials));
                }
            } else {
                // First part is not an initial, so this might be "FirstName LastName" format
                // Take the last word as the last name
                $parts = explode(' ', trim($matches[2]));
                $result['lastName'] = end($parts);
            }
        }
        // Handle format "Firstname Lastname" or "Full Name" (e.g., "Arnold Delapeña", "Keith Dianne Abao")
        // Take the last word as the last name
        elseif (preg_match('/^(.+)$/', $name, $matches)) {
            $words = preg_split('/\s+/', trim($name));
            if (count($words) >= 2) {
                // Multiple words - take last word as last name
                $result['lastName'] = end($words);
            } else {
                // Single word - it's the last name
                $result['lastName'] = trim($name);
            }
        }
        
        return $result;
    }

    /**
     * Validate if a room is available for a specific time slot
     */
    public function isRoomAvailable(int $roomId, string $day, string $startTime, string $endTime): bool
    {
        $individualDays = DayScheduler::parseCombinedDays($day);
        
        foreach ($individualDays as $singleDay) {
            $normalizedDay = DayScheduler::normalizeDay($singleDay);
            
            if (isset($this->roomOccupancy[$roomId][$normalizedDay])) {
                $slots = $this->roomOccupancy[$roomId][$normalizedDay];
                $idx = $this->lowerBoundByEndBeforeStart($slots, $startTime);
                $len = count($slots);
                for ($i = $idx; $i < $len; $i++) {
                    $existingSlot = $slots[$i];
                    if ($existingSlot['start_time'] >= $endTime) { break; }
                    if (TimeScheduler::timesOverlap($startTime, $endTime, $existingSlot['start_time'], $existingSlot['end_time'])) {
                        if ((bool) env('LOG_SCHEDULER_VERBOSE', false)) {
                            Log::debug("ROOM CONFLICT: Room {$roomId} already scheduled at {$normalizedDay} {$existingSlot['start_time']}-{$existingSlot['end_time']}");
                        }
                        return false;
                    }
                }
            }
        }
        
        return true;
    }

    /**
     * Validate if a section is available for a specific time slot
     */
    public function isSectionAvailable(string $sectionName, string $day, string $startTime, string $endTime): bool
    {
        $individualDays = DayScheduler::parseCombinedDays($day);
        
        foreach ($individualDays as $singleDay) {
            $normalizedDay = DayScheduler::normalizeDay($singleDay);
            
            if (isset($this->sectionSchedules[$sectionName][$normalizedDay])) {
                $slots = $this->sectionSchedules[$sectionName][$normalizedDay];
                $idx = $this->lowerBoundByEndBeforeStart($slots, $startTime);
                $len = count($slots);
                for ($i = $idx; $i < $len; $i++) {
                    $existingSlot = $slots[$i];
                    if ($existingSlot['start_time'] >= $endTime) { break; }
                    if (TimeScheduler::timesOverlap($startTime, $endTime, $existingSlot['start_time'], $existingSlot['end_time'])) {
                        if ((bool) env('LOG_SCHEDULER_VERBOSE', false)) {
                            Log::debug("SECTION CONFLICT: Section {$sectionName} already scheduled at {$normalizedDay} {$existingSlot['start_time']}-{$existingSlot['end_time']}");
                        }
                        return false;
                    }
                }
            }
        }
        
        return true;
    }

    /**
     * Binary search for first index where slot['end_time'] > startTime.
     * Assumes $slots sorted by start_time ascending.
     */
    private function lowerBoundByEndBeforeStart(array $slots, string $startTime): int
    {
        $lo = 0; $hi = count($slots);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($slots[$mid]['end_time'] <= $startTime) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /**
     * Reserve instructor time slot
     */
    public function reserveInstructor(string $instructorName, string $day, string $startTime, string $endTime, array $scheduleData): void
    {
        $individualDays = DayScheduler::parseCombinedDays($day);
        
        foreach ($individualDays as $singleDay) {
            $normalizedDay = DayScheduler::normalizeDay($singleDay);
            
            if (!isset($this->instructorAvailability[$instructorName])) {
                $this->instructorAvailability[$instructorName] = [];
            }
            if (!isset($this->instructorAvailability[$instructorName][$normalizedDay])) {
                $this->instructorAvailability[$instructorName][$normalizedDay] = [];
            }
            
            $this->instructorAvailability[$instructorName][$normalizedDay][] = [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'schedule_data' => $scheduleData
            ];
            usort($this->instructorAvailability[$instructorName][$normalizedDay], function($a, $b) {
                return strcmp($a['start_time'], $b['start_time']);
            });
        }
        
        // Track load count
        $this->instructorLoadCount[$instructorName] = ($this->instructorLoadCount[$instructorName] ?? 0) + 1;
        
        if ((bool) env('LOG_SCHEDULER_VERBOSE', false)) {
            Log::debug("RESERVED: Instructor {$instructorName} at {$day} {$startTime}-{$endTime}");
        }
    }

    /**
     * Reserve room time slot
     */
    public function reserveRoom(int $roomId, string $day, string $startTime, string $endTime, array $scheduleData): void
    {
        $individualDays = DayScheduler::parseCombinedDays($day);
        
        foreach ($individualDays as $singleDay) {
            $normalizedDay = DayScheduler::normalizeDay($singleDay);
            
            if (!isset($this->roomOccupancy[$roomId])) {
                $this->roomOccupancy[$roomId] = [];
            }
            if (!isset($this->roomOccupancy[$roomId][$normalizedDay])) {
                $this->roomOccupancy[$roomId][$normalizedDay] = [];
            }
            
            $this->roomOccupancy[$roomId][$normalizedDay][] = [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'schedule_data' => $scheduleData
            ];
            usort($this->roomOccupancy[$roomId][$normalizedDay], function($a, $b) {
                return strcmp($a['start_time'], $b['start_time']);
            });
        }
        
        // Track load count
        $this->roomLoadCount[$roomId] = ($this->roomLoadCount[$roomId] ?? 0) + 1;
        
        if ((bool) env('LOG_SCHEDULER_VERBOSE', false)) {
            Log::debug("RESERVED: Room {$roomId} at {$day} {$startTime}-{$endTime}");
        }
    }

    /**
     * Reserve section time slot
     */
    public function reserveSection(string $sectionName, string $day, string $startTime, string $endTime, array $scheduleData): void
    {
        $individualDays = DayScheduler::parseCombinedDays($day);
        
        foreach ($individualDays as $singleDay) {
            $normalizedDay = DayScheduler::normalizeDay($singleDay);
            
            if (!isset($this->sectionSchedules[$sectionName])) {
                $this->sectionSchedules[$sectionName] = [];
            }
            if (!isset($this->sectionSchedules[$sectionName][$normalizedDay])) {
                $this->sectionSchedules[$sectionName][$normalizedDay] = [];
            }
            
            $this->sectionSchedules[$sectionName][$normalizedDay][] = [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'schedule_data' => $scheduleData
            ];
            usort($this->sectionSchedules[$sectionName][$normalizedDay], function($a, $b) {
                return strcmp($a['start_time'], $b['start_time']);
            });
        }
        
        // Track load count
        $this->sectionLoadCount[$sectionName] = ($this->sectionLoadCount[$sectionName] ?? 0) + 1;
        
        // Track day distribution
        foreach ($individualDays as $singleDay) {
            $normalizedDay = DayScheduler::normalizeDay($singleDay);
            if (isset($this->dayDistribution[$normalizedDay])) {
                $this->dayDistribution[$normalizedDay]++;
            }
        }
        
        if ((bool) env('LOG_SCHEDULER_VERBOSE', false)) {
            Log::debug("RESERVED: Section {$sectionName} at {$day} {$startTime}-{$endTime}");
        }
    }

    /**
     * Comprehensive validation before assignment - checks ALL resources
     */
    public function validateBeforeAssignment(
        string $instructorName, 
        int $roomId, 
        string $sectionName, 
        string $day, 
        string $startTime, 
        string $endTime
    ): array {
        $conflicts = [];
        
        // Check instructor availability
        if (!$this->isInstructorAvailable($instructorName, $day, $startTime, $endTime)) {
            $conflicts[] = [
                'type' => 'instructor',
                'resource' => $instructorName,
                'day' => $day,
                'time' => "{$startTime}-{$endTime}",
                'message' => "Instructor {$instructorName} already scheduled at {$day} {$startTime}-{$endTime}"
            ];
        }
        
        // Check room availability
        if (!$this->isRoomAvailable($roomId, $day, $startTime, $endTime)) {
            $conflicts[] = [
                'type' => 'room',
                'resource' => $roomId,
                'day' => $day,
                'time' => "{$startTime}-{$endTime}",
                'message' => "Room {$roomId} already scheduled at {$day} {$startTime}-{$endTime}"
            ];
        }
        
        // Check section availability
        if (!$this->isSectionAvailable($sectionName, $day, $startTime, $endTime)) {
            $conflicts[] = [
                'type' => 'section',
                'resource' => $sectionName,
                'day' => $day,
                'time' => "{$startTime}-{$endTime}",
                'message' => "Section {$sectionName} already scheduled at {$day} {$startTime}-{$endTime}"
            ];
        }
        
        return $conflicts;
    }

    /**
     * Atomic resource reservation - reserves all resources together or none
     */
    public function reserveAllResources(
        string $instructorName, 
        int $roomId, 
        string $sectionName, 
        string $day, 
        string $startTime, 
        string $endTime, 
        array $scheduleData
    ): bool {
        // Validate all resources first
        $conflicts = $this->validateBeforeAssignment($instructorName, $roomId, $sectionName, $day, $startTime, $endTime);
        
        if (!empty($conflicts)) {
            if ((bool) env('LOG_SCHEDULER_VERBOSE', false)) {
                Log::warning("RESERVATION FAILED: Conflicts detected", $conflicts);
            }
            return false;
        }
        
        // Reserve all resources atomically
        $this->reserveInstructor($instructorName, $day, $startTime, $endTime, $scheduleData);
        $this->reserveRoom($roomId, $day, $startTime, $endTime, $scheduleData);
        $this->reserveSection($sectionName, $day, $startTime, $endTime, $scheduleData);
        
        if ((bool) env('LOG_SCHEDULER_VERBOSE', false)) {
            // Reservation successful
        }
        return true;
    }

    /**
     * Trusted reservation variant: assumes caller validated availability already.
     * Skips validateBeforeAssignment to avoid duplicate checks in hot paths.
     */
    public function reserveAllResourcesTrusted(
        string $instructorName,
        int $roomId,
        string $sectionName,
        string $day,
        string $startTime,
        string $endTime,
        array $scheduleData
    ): void {
        $this->reserveInstructor($instructorName, $day, $startTime, $endTime, $scheduleData);
        $this->reserveRoom($roomId, $day, $startTime, $endTime, $scheduleData);
        $this->reserveSection($sectionName, $day, $startTime, $endTime, $scheduleData);
        if ((bool) env('LOG_SCHEDULER_VERBOSE', false)) {
            // Reservation trusted
        }
    }

    /**
     * Get instructor load for load balancing
     */
    public function getInstructorLoad(string $instructorName): int
    {
        return $this->instructorLoadCount[$instructorName] ?? 0;
    }

    /**
     * Get room load for load balancing
     */
    public function getRoomLoad(int $roomId): int
    {
        return $this->roomLoadCount[$roomId] ?? 0;
    }

    /**
     * Get section load for load balancing
     */
    public function getSectionLoad(string $sectionName): int
    {
        return $this->sectionLoadCount[$sectionName] ?? 0;
    }

    /**
     * Get day distribution for load balancing
     */
    public function getDayDistribution(): array
    {
        return $this->dayDistribution;
    }

    /**
     * Get least loaded day for better distribution
     */
    public function getLeastLoadedDay(): string
    {
        $minLoad = min($this->dayDistribution);
        foreach ($this->dayDistribution as $day => $load) {
            if ($load === $minLoad) {
                return $day;
            }
        }
        return 'Mon'; // fallback
    }

    /**
     * Get resource usage statistics
     */
    public function getResourceStatistics(): array
    {
        return [
            'instructor_loads' => $this->instructorLoadCount,
            'room_loads' => $this->roomLoadCount,
            'section_loads' => $this->sectionLoadCount,
            'day_distribution' => $this->dayDistribution,
            'total_instructor_slots' => array_sum($this->instructorLoadCount),
            'total_room_slots' => array_sum($this->roomLoadCount),
            'total_section_slots' => array_sum($this->sectionLoadCount)
        ];
    }

    /**
     * Clear all reservations (for testing or reset)
     */
    public function clearAllReservations(): void
    {
        $this->instructorAvailability = [];
        $this->roomOccupancy = [];
        $this->sectionSchedules = [];
        $this->instructorLoadCount = [];
        $this->roomLoadCount = [];
        $this->sectionLoadCount = [];
        $this->dayDistribution = [
            'Mon' => 0, 'Tue' => 0, 'Wed' => 0, 
            'Thu' => 0, 'Fri' => 0, 'Sat' => 0
        ];
        
        // All reservations cleared
    }

    /**
     * Load existing schedules into tracker (for reference schedule integration)
     */
    public function loadExistingSchedules(array $existingSchedules): void
    {
        foreach ($existingSchedules as $schedule) {
            $instructorName = $schedule['instructor'] ?? 'Unknown';
            $roomId = $schedule['room_id'] ?? 0;
            $sectionName = $schedule['section'] ?? 'Unknown';
            $day = $schedule['day'] ?? 'Mon';
            $startTime = $schedule['start_time'] ?? '00:00:00';
            $endTime = $schedule['end_time'] ?? '00:00:00';
            
            // Reserve without validation (existing schedules are assumed valid)
            $this->reserveInstructor($instructorName, $day, $startTime, $endTime, $schedule);
            $this->reserveRoom($roomId, $day, $startTime, $endTime, $schedule);
            $this->reserveSection($sectionName, $day, $startTime, $endTime, $schedule);
        }
        
        // Existing schedules loaded
    }
}
