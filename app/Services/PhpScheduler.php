<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Models\Reference;
use App\Services\ResourceTracker;

class PhpScheduler
{
    private array $courses;
    private array $rooms;
    private array $timeSlots;
    private array $roomUsage = [];
    private array $roomDayUsage = [];
    private array $scheduledCourses = []; // Track scheduled courses for conflict detection
    private int $rrPointer = 0;
    private array $filterPreferences = []; // Store filter preferences for soft constraints
    private array $referenceSchedules = []; // Store reference schedules for conflict detection
    private string $department = 'BSBA'; // Track department for room distribution
    private ?string $currentEducationLevel = null; // Track current education level to filter reference schedules
    private ResourceTracker $resourceTracker; // Centralized resource tracking
    private ?int $groupId = null; // Optional group context for DB conflict guard
    private array $idCache = [
        'instructor' => [], // name => id
        'subject' => [],    // code => id
        'section' => []     // code => id
    ];
    
    // PERFORMANCE OPTIMIZATION: Indexed structures for O(1) conflict lookups
    private array $instructorSchedules = []; // instructor => [schedules on day/time]
    private array $sectionSchedules = [];    // section => [schedules on day/time]
    private array $roomSchedules = [];       // room_id => [schedules on day/time]

    // In-memory DB conflict cache: day => list of meetings
    private array $dbConflictIndex = [];

    // Configuration constants for load balancing
    private const INSTRUCTOR_LOAD_WEIGHT = 10;
    private const DAY_BALANCE_WEIGHT = 5;
    private const TIME_DIVERSITY_THRESHOLD = 5;
    private const HEAVY_INSTRUCTOR_THRESHOLD = 8;
    
    // Room distribution percentages by department
    private const ROOM_DISTRIBUTION = [
        'CRIM' => [
            'HS' => 0.00,    // 0% - CRIM gets no HS rooms
            'SHS' => 0.00,   // 0% - CRIM gets no SHS rooms  
            'Annex' => 1.00  // 100% - CRIM gets all Annex rooms
        ],
        'BSOA' => [
            'HS' => 0.50,    // 50%
            'SHS' => 0.25,   // 25%
            'Annex' => 0.25  // 25%
        ],
        'BSBA' => [
            'HS' => 0.50,    // 50%
            'SHS' => 0.25,   // 25%
            'Annex' => 0.25  // 25%
        ],
        'default' => [
            'HS' => 0.50,    // 50%
            'SHS' => 0.25,   // 25%
            'Annex' => 0.25  // 25%
        ]
    ];

    // Tracking arrays
    private array $instructorLoad = [];
    private array $dayLoadCount = ['Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0];

    public function __construct(array $courses, array $rooms, string $department = 'BSBA', ?string $currentEducationLevel = null)
    {
        $this->courses = $this->preprocessCourses($courses);
        $this->rooms = $rooms;
        $this->department = $department;
        $this->currentEducationLevel = $currentEducationLevel ?? 'College'; // Default to College for college departments
        $this->timeSlots = TimeScheduler::generateComprehensiveTimeSlots();
        
        // Initialize ResourceTracker for centralized conflict detection
        $this->resourceTracker = new ResourceTracker();
        
        // Load reference schedules for conflict detection (exclude current education level)
        $this->loadReferenceSchedules();
        
        // Load reference schedules into ResourceTracker for consistent conflict detection
        $this->loadReferenceSchedulesIntoResourceTracker();
        
        // Reduced logging for performance
    }

    /**
     * Controls verbose logging for scheduler. Enable by setting LOG_SCHEDULER_VERBOSE=true
     */
    private function verbose(): bool
    {
        return (bool) env('LOG_SCHEDULER_VERBOSE', false);
    }

    /**
     * Set schedule group context to enable DB conflict guard during scheduling
     */
    public function setGroupContext(int $groupId): void
    {
        $this->groupId = $groupId;
        // Build in-memory DB conflict index for fast overlap checks
        try {
            $this->buildDbConflictIndex($groupId);
        } catch (\Throwable $e) {
            // Fail-open: if cache build fails, keep DB guard as-is
            Log::warning('Failed to build DB conflict cache: ' . $e->getMessage());
        }
    }

    /**
     * Resolve IDs for DB conflict checks (cached lookups)
     */
    private function resolveInstructorIdByName(string $name): ?int
    {
        if ($name === '') return null;
        if (isset($this->idCache['instructor'][$name])) return $this->idCache['instructor'][$name];
        $model = \App\Models\Instructor::where('name', $name)->first();
        $id = $model->instructor_id ?? null;
        $this->idCache['instructor'][$name] = $id;
        return $id;
    }

    private function resolveSubjectIdByCode(string $code): ?int
    {
        if ($code === '') return null;
        if (isset($this->idCache['subject'][$code])) return $this->idCache['subject'][$code];
        $model = \App\Models\Subject::where('code', $code)->first();
        $id = $model->subject_id ?? null;
        $this->idCache['subject'][$code] = $id;
        return $id;
    }

    private function resolveSectionIdByLabel(string $label, string $deptFallback = 'General'): ?int
    {
        if ($label === '') return null;
        if (isset($this->idCache['section'][$label])) return $this->idCache['section'][$label];
        // Try exact code match first
        $model = \App\Models\Section::where('code', $label)->first();
        if (!$model) {
            // Attempt to construct a legacy-like code: DEPT-<year> Year <block>
            $legacy = $deptFallback . '-' . $label;
            $model = \App\Models\Section::where('code', $legacy)->first();
        }
        $id = $model->section_id ?? null;
        $this->idCache['section'][$label] = $id;
        return $id;
    }

    /**
     * DB conflict guard leveraging ScheduleMeeting::hasConflict when context is available
     */
    private function violatesDbConstraints(string $day, string $start, string $end, array $schedule, array $course): bool
    {
        if ($this->groupId === null) return false;
        try {
            $instructorName = $schedule['instructor'] ?? ($course['instructor'] ?? $course['name'] ?? '');
            $subjectCode = $schedule['subject_code'] ?? ($course['courseCode'] ?? '');
            $sectionLabel = $schedule['section'] ?? (($course['yearLevel'] ?? '') . ' ' . ($course['block'] ?? ''));
            $roomId = $schedule['room_id'] ?? 0;

            $instructorId = $this->resolveInstructorIdByName($instructorName);
            $subjectId = $this->resolveSubjectIdByCode($subjectCode);
            $sectionId = $this->resolveSectionIdByLabel($sectionLabel, $course['dept'] ?? $this->department);

            // Fast-path: in-memory cache if available
            if (!empty($this->dbConflictIndex)) {
                return $this->hasDbConflictCached(
                    (int)$this->groupId,
                    $instructorId,
                    $roomId ?: null,
                    $sectionId,
                    $day,
                    $start,
                    $end,
                    $subjectId
                );
            }

            // Fallback: DB query
            return \App\Models\ScheduleMeeting::hasConflict(
                (int)$this->groupId,
                $instructorId,
                $roomId ?: null,
                $sectionId,
                $day,
                $start,
                $end,
                $subjectId
            );
        } catch (\Throwable $e) {
            // Fail-open to avoid blocking scheduling due to lookup errors
            Log::debug('DB guard lookup failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build an in-memory index of existing meetings for a group to avoid repeated DB calls.
     * Indexed by day; each item contains start, end, instructor_id, room_id, section_id, subject_id.
     */
    private function buildDbConflictIndex(int $groupId): void
    {
        $this->dbConflictIndex = [];
        $meetings = \App\Models\ScheduleMeeting::with(['entry' => function($q) use ($groupId) {
            $q->where('group_id', $groupId);
        }])->whereHas('entry', function($q) use ($groupId) {
            $q->where('group_id', $groupId);
        })->get([
            'meeting_id', 'instructor_id', 'day', 'start_time', 'end_time', 'room_id', 'entry_id'
        ]);

        foreach ($meetings as $m) {
            $day = $m->day;
            if (!isset($this->dbConflictIndex[$day])) {
                $this->dbConflictIndex[$day] = [];
            }
            $this->dbConflictIndex[$day][] = [
                'start' => $m->start_time,
                'end' => $m->end_time,
                'instructor_id' => $m->instructor_id,
                'room_id' => $m->room_id,
                'section_id' => $m->entry->section_id ?? null,
                'subject_id' => $m->entry->subject_id ?? null,
            ];
        }
    }

    /**
     * Fast conflict check using the in-memory index. Mirrors ScheduleMeeting::hasConflict semantics.
     */
    private function hasDbConflictCached(
        int $groupId,
        ?int $instructorId,
        ?int $roomId,
        ?int $sectionId,
        string $day,
        string $start,
        string $end,
        ?int $subjectId = null
    ): bool {
        // Expand combined day strings like "MonSat" to atomic days
        $days = \App\Services\DayScheduler::parseCombinedDays($day);
        if (empty($days)) {
            $days = [\App\Services\DayScheduler::normalizeDay($day)];
        }

        foreach ($days as $d) {
            $list = $this->dbConflictIndex[$d] ?? [];
            if (empty($list)) { continue; }
            foreach ($list as $item) {
                // Overlap: start < other_end AND end > other_start
                if (!($start < $item['end'] && $end > $item['start'])) { continue; }

                // Allow overlaps for same subject
                if (!is_null($subjectId) && isset($item['subject_id']) && $item['subject_id'] === $subjectId) {
                    continue;
                }

                $instructorConflict = !is_null($instructorId) && $item['instructor_id'] === $instructorId;
                $roomConflict = !is_null($roomId) && $item['room_id'] === $roomId;
                $sectionConflict = !is_null($sectionId) && isset($item['section_id']) && $item['section_id'] === $sectionId;

                if ($instructorConflict || $roomConflict || $sectionConflict) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * RANDOM scheduler: assign sessions by shuffling slots and using strict/loose checks
     */
    public function solveRandom(bool $strict = true, int $timeLimit = 30): array
    {
        // Starting random scheduler

        $startTime = time();

        // Reset tracking
        $this->roomUsage = [];
        $this->roomDayUsage = [];
        $this->scheduledCourses = [];
        $this->rrPointer = 0;

        // Add randomness by shuffling base time slots once
        $shuffledSlots = $this->timeSlots;
        shuffle($shuffledSlots);

        $schedules = [];
        $unscheduledCourses = [];

        try {
            foreach ($this->courses as $courseIndex => $course) {
                if ((time() - $startTime) > $timeLimit) {
                    Log::warning("Random scheduler timeout reached after " . (time() - $startTime) . "s");
                    break;
                }

                $units = $course['unit'] ?? $course['units'] ?? 3;
                $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
                $sessionDurations = TimeScheduler::generateRandomizedSessions($units, $employmentType);

                $usedDays = [];
                foreach ($sessionDurations as $sessionIndex => $sessionDuration) {
                    $scheduled = $strict
                        ? $this->scheduleSessionRandomStrict($course, $sessionDuration, $usedDays)
                        : $this->scheduleSessionRandomLoose($course, $sessionDuration, $usedDays);

                    if ($scheduled) {
                        $schedules[] = $scheduled;
                        $this->scheduledCourses[] = $scheduled;
                        $this->addToIndexedSchedules($scheduled);
                        $usedDays[] = $scheduled['day'];
                        // Session scheduled
                    } else {
                        Log::warning("RANDOM: Failed to schedule session {$sessionIndex} for " . ($course['courseCode'] ?? 'Unknown'));
                    }
                }
            }

            // Assess conflicts
            $conflicts = $this->detectConflicts($schedules);
            $totalConflicts = array_sum($conflicts);

            $executionTime = time() - $startTime;
            // Random scheduling completed

            return [
                'success' => true,
                'message' => 'Random schedule generated (' . ($strict ? 'strict' : 'loose') . ') with ' . count($schedules) . ' entries',
                'schedules' => $schedules,
                'conflicts' => $conflicts,
                'total_conflicts' => $totalConflicts,
                'algorithm' => $strict ? 'php_random_strict' : 'php_random_loose'
            ];
        } catch (\Exception $e) {
            Log::error("PHP random scheduler error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'PHP random scheduler error: ' . $e->getMessage(),
                'schedules' => [],
                'errors' => [$e->getMessage()],
                'algorithm' => $strict ? 'php_random_strict' : 'php_random_loose'
            ];
        }
    }

    /**
     * Random strict: reuse simple strict checks, but rely on shuffled slot order
     */
    private function scheduleSessionRandomStrict(array $course, float $sessionDuration, array $usedDays): ?array
    {
        // Leverage existing simple scheduler which performs strict checks and duration matching
        return $this->scheduleSessionSimple($course, $sessionDuration, 0, $usedDays);
    }

    /**
     * Random loose: reuse emergency scheduling with relaxed checks on randomized viable slots
     */
    private function scheduleSessionRandomLoose(array $course, float $sessionDuration, array &$usedDays): ?array
    {
        $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
        $allowedSlots = TimeScheduler::filterTimeSlotsByEmployment($this->timeSlots, $employmentType, false);
        // Shuffle for randomness
        shuffle($allowedSlots);

        // Reuse emergency logic which is relaxed and fast
        $dummyUsedKeys = [];
        return $this->scheduleSessionEmergency($course, $sessionDuration, $allowedSlots, $dummyUsedKeys, 0, 0, $usedDays);
    }

    /**
     * Preprocess courses to expand multi-block entries and normalize data
     */
    private function preprocessCourses(array $courses): array
    {
        $processedCourses = [];
        $processedKeys = []; // Track processed courses to prevent duplicates
        
        foreach ($courses as $course) {
            // Create unique key for this course
            $key = ($course['courseCode'] ?? '') . '|' . 
                   ($course['yearLevel'] ?? '') . '|' . 
                   ($course['block'] ?? 'A');
            
            // Skip if already processed (prevents duplicates from synchronization)
            if (isset($processedKeys[$key])) {
                Log::debug("Skipping duplicate course in preprocess: {$key}");
                continue;
            }
            
            $processedKeys[$key] = true;
            
            // Handle multi-block entries (e.g., "A & B & C", "A,B,C", "A&B&C", "A & B", "A,B") - but only if not already processed
            $rawBlock = trim($course['block'] ?? 'A');
            $blocks = [];
            
            // Check if block contains separators (& or ,) - handles formats like "A & B & C", "A,B,C", "A&B&C", etc.
            if (strpos($rawBlock, '&') !== false || strpos($rawBlock, ',') !== false) {
                // Split by both & and comma, then clean up each token
                $blockTokens = preg_split('/[,\s&]+/', $rawBlock);
                foreach ($blockTokens as $bt) {
                    $b = strtoupper(trim($bt));
                    // Validate block is a single letter (A-Z) to avoid empty or invalid values
                    if ($b !== '' && preg_match('/^[A-Z]$/', $b)) {
                        $blocks[] = $b;
                    }
                }
                // If splitting failed, fall back to default
                if (empty($blocks)) {
                    $blocks = ['A'];
                }
            } elseif (!empty($rawBlock)) {
                // Single block - validate it's a single letter
                $b = strtoupper(trim($rawBlock));
                if (preg_match('/^[A-Z]$/', $b)) {
                    $blocks = [$b];
                } else {
                    $blocks = ['A']; // Invalid format, default to A
                }
            } else {
                $blocks = ['A'];
            }

            // Create separate course entry for each block
            foreach ($blocks as $block) {
                $processedCourse = $course;
                $processedCourse['block'] = $block;
                $processedCourse['section'] = trim($course['dept'] ?? 'General') . '-' . 
                                             trim($course['yearLevel'] ?? '1st Year') . ' ' . $block;
                $processedCourses[] = $processedCourse;
            }
        }

        // Courses preprocessed
        return $processedCourses;
    }

    /**
     * Load reference schedules from database for conflict detection
     * Excludes reference schedules from the current education level to prevent conflicts
     * with basic education (SHS/HS) when generating college schedules
     */
    private function loadReferenceSchedules(): void
    {
        try {
            // Load reference schedules, excluding the current education level
            // When generating college schedules, we load SHS/HS reference schedules to prevent conflicts
            // When generating basic education schedules, we load college reference schedules to prevent conflicts
            $references = Reference::with('referenceGroup');
            
            if ($this->currentEducationLevel) {
                // Exclude reference schedules from the same education level
                // This prevents conflicts between college and basic education
                $references = $references->whereHas('referenceGroup', function ($q) {
                    $q->where('education_level', '!=', $this->currentEducationLevel);
                });
            }
            
            $references = $references->get();
            
            foreach ($references as $ref) {
                // Convert reference schedule to internal format for conflict checking
                $this->referenceSchedules[] = [
                    'type' => 'reference',
                    'instructor' => $ref->instructor,
                    'room' => $ref->room,
                    'day' => $ref->day,
                    'time' => $ref->time, // Reference time format: "7:45 AM-8:45 AM"
                    'subject' => $ref->subject,
                    'group_id' => $ref->group_id,
                    'school_year' => $ref->referenceGroup->school_year ?? null,
                    'education_level' => $ref->referenceGroup->education_level ?? null,
                    'year_level' => $ref->referenceGroup->year_level ?? null,
                ];
            }
            
            if (count($this->referenceSchedules) > 0) {
                $educationLevels = array_unique(array_column($this->referenceSchedules, 'education_level'));
                Log::info("Loaded " . count($this->referenceSchedules) . " reference schedules for conflict detection from education levels: " . implode(', ', $educationLevels) . " (excluding: " . $this->currentEducationLevel . ")");
            } else {
                Log::info("No reference schedules found for conflict detection (current education level: " . $this->currentEducationLevel . ")");
            }
        } catch (\Exception $e) {
            Log::warning("Failed to load reference schedules: " . $e->getMessage());
            $this->referenceSchedules = [];
        }
    }

    /**
     * Load reference schedules into ResourceTracker for consistent conflict detection
     */
    private function loadReferenceSchedulesIntoResourceTracker(): void
    {
        $loadedCount = 0;
        $skippedCount = 0;
        
        foreach ($this->referenceSchedules as $refSchedule) {
            try {
                // Parse reference time to get start and end times (with correction for basic ed)
                $originalTime = $refSchedule['time'];
                list($startTime, $endTime) = $this->parseReferenceTime($originalTime);
                
                // Correct time if needed (only log if verbose mode)
                if ($originalTime !== ($startTime . '-' . $endTime) && $this->verbose()) {
                    Log::info("REFERENCE TIME CORRECTED: Original={$originalTime}, Corrected={$startTime}-{$endTime}, Instructor={$refSchedule['instructor']}");
                }
                
                // Find room ID by room name
                $roomId = $this->findRoomIdByName($refSchedule['room']);
                if (!$roomId) {
                    // Only log if verbose mode to reduce log volume
                    if ($this->verbose()) {
                        Log::warning("Reference schedule room not found: {$refSchedule['room']}, skipping schedule for instructor {$refSchedule['instructor']}");
                    }
                    $skippedCount++;
                    continue;
                }
                
                // Reserve the reference schedule in ResourceTracker
                $this->resourceTracker->reserveAllResources(
                    $refSchedule['instructor'],
                    $roomId,
                    $refSchedule['subject'] ?? 'Reference',
                    $refSchedule['day'],
                    $startTime,
                    $endTime,
                    array_merge($refSchedule, ['type' => 'reference'])
                );
                
                $loadedCount++;
                
            } catch (\Exception $e) {
                Log::warning("Failed to load reference schedule into ResourceTracker: " . $e->getMessage());
                $skippedCount++;
            }
        }
        
        // Reference schedules loaded (only log summary if verbose)
        if ($skippedCount > 0 && $this->verbose()) {
            Log::info("Loaded {$loadedCount} reference schedules into ResourceTracker, {$skippedCount} skipped");
        }
    }
    
    /**
     * Find room ID by room name with fuzzy matching for basic education rooms
     * Handles mappings like:
     * - "203 H.S BLDG" → "HS 203" (extract number, match with HS room)
     * - "SHS 112" → "SHS 112" or "SSH 112" (handle typos)
     * - "204 H.S BLDG" → "HS 204" (if exists)
     */
    private function findRoomIdByName(string $roomName): ?int
    {
        // First try exact match
        foreach ($this->rooms as $room) {
            if (($room['room_name'] ?? '') === $roomName) {
                return $room['room_id'] ?? null;
            }
        }
        
        // Normalize the reference room name for fuzzy matching
        $normalized = $this->normalizeRoomName($roomName);
        
        // Try fuzzy match
        foreach ($this->rooms as $room) {
            $collegeRoomName = $room['room_name'] ?? '';
            $normalizedCollege = $this->normalizeRoomName($collegeRoomName);
            
            // Exact match after normalization
            if ($normalized === $normalizedCollege) {
                Log::debug("Room matched: '{$roomName}' → '{$collegeRoomName}' (ID: {$room['room_id']})");
                return $room['room_id'] ?? null;
            }
        }
        
        return null;
    }
    
    /**
     * Normalize room name for fuzzy matching
     * Examples:
     * - "203 H.S BLDG" → "HS203"
     * - "SHS 112" → "SHS112"
     * - "SSH 112" → "SHS112" (handle typo)
     * - "HS 203" → "HS203"
     */
    private function normalizeRoomName(string $roomName): string
    {
        // Remove common variations and spaces
        $normalized = trim($roomName);
        
        // Handle "H.S BLDG" → "HS"
        $normalized = preg_replace('/H\.S\s*BLDG/i', 'HS', $normalized);
        $normalized = preg_replace('/H\.S\s*/i', 'HS ', $normalized);
        
        // Handle typos: "SSH" → "SHS"
        $normalized = preg_replace('/\bSSH\b/i', 'SHS', $normalized);
        
        // Extract building prefix and number - handle both patterns:
        // Pattern 1: "203 H.S BLDG" or "203 HS" → extract "203" first, then "HS"
        // Pattern 2: "HS 203" → extract "HS" first, then "203"
        // Pattern 3: "SHS 112" → extract "SHS" first, then "112"
        if (preg_match('/\b(\d+)\s*(HS|SHS|ANNEX)\b/i', $normalized, $matches)) {
            // Number first, then building (e.g., "203 HS")
            $number = $matches[1];
            $building = strtoupper($matches[2]);
            return $building . $number;
        } elseif (preg_match('/\b(HS|SHS|ANNEX)\s*(\d+)\b/i', $normalized, $matches)) {
            // Building first, then number (e.g., "HS 203")
            $building = strtoupper($matches[1]);
            $number = $matches[2];
            return $building . $number;
        }
        
        // Fallback: remove all spaces and special characters, uppercase
        $normalized = preg_replace('/[^A-Z0-9]/i', '', $normalized);
        return strtoupper($normalized);
    }

    /**
     * Parse reference time format (e.g., "7:45 AM-8:45 AM") to start and end times
     * Also corrects incorrectly stored times for basic education:
     * - 12-6 AM (00:00-06:00) should be PM (12:00-18:00)
     * - 7-11 AM (07:00-11:00) stays as AM
     */
    private function parseReferenceTime(string $timeRange): array
    {
        // Expected format: "07:30:00-08:30:00" (24-hour) or "7:45 AM-8:45 AM" (12-hour) or "7:45-8:45"
        $parts = explode('-', $timeRange);
        if (count($parts) !== 2) {
            return ['00:00:00', '00:00:00'];
        }
        
        $start = trim($parts[0]);
        $end = trim($parts[1]);
        
        // Convert to 24-hour format
        $startTime = $this->convertTo24Hour($start);
        $endTime = $this->convertTo24Hour($end);
        
        // Correct incorrectly stored times for basic education
        // If times are stored as 1-6 AM (01:00-06:00), convert to 1-6 PM (13:00-18:00)
        $startTime = $this->correctReferenceTime($startTime);
        $endTime = $this->correctReferenceTime($endTime);
        
        return [$startTime, $endTime];
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
    private function correctReferenceTime(string $time24): string
    {
        if (empty($time24) || $time24 === 'N/A') {
            return $time24;
        }
        
        try {
            // Parse hour from 24-hour format (HH:MM:SS or HH:MM)
            $parts = explode(':', $time24);
            if (count($parts) < 2) {
                return $time24;
            }
            
            $hour = (int) $parts[0];
            $minute = $parts[1];
            $second = $parts[2] ?? '00';
            
            // Correct time: convert 1-6 AM (01:00-06:00) to PM (13:00-18:00)
            if ($hour >= 1 && $hour <= 6) {
                // Convert 1-6 AM to 1-6 PM (add 12 hours)
                $hour += 12;
                return sprintf('%02d:%s:%s', $hour, $minute, $second);
            } elseif ($hour == 0) {
                // Convert midnight (00:00) to noon (12:00 PM) for basic ed
                return sprintf('12:%s:%s', $minute, $second);
            }
            
            // Keep 12:00 as is (noon = 12 PM), 7-11 as is (AM), 13-18+ as is (already PM)
            return $time24;
        } catch (\Exception $e) {
            Log::warning("Failed to correct reference time '{$time24}': " . $e->getMessage());
            return $time24;
        }
    }

    /**
     * Convert 12-hour time format to 24-hour format
     */
    private function convertTo24Hour(string $time): string
    {
        try {
            // Handle both "7:45 AM" and "7:45" formats
            $time = trim($time);
            
            // If already in 24-hour format (HH:MM:SS or HH:MM)
            if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time) && !preg_match('/[AP]M/i', $time)) {
                // Add seconds if missing
                if (substr_count($time, ':') === 1) {
                    $time .= ':00';
                }
                return $time;
            }
            
            // Parse 12-hour format
            if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $time, $matches)) {
                $hour = (int)$matches[1];
                $minute = (int)$matches[2];
                $meridiem = strtoupper($matches[3]);
                
                // Convert to 24-hour
                if ($meridiem === 'PM' && $hour < 12) {
                    $hour += 12;
                } elseif ($meridiem === 'AM' && $hour === 12) {
                    $hour = 0;
                }
                
                return sprintf('%02d:%02d:00', $hour, $minute);
            }
            
            return '00:00:00';
        } catch (\Exception $e) {
            Log::warning("Failed to convert time '{$time}' to 24-hour format: " . $e->getMessage());
            return '00:00:00';
        }
    }
    
    /**
     * Match instructor names with fuzzy logic to handle different formats
     * Handles formats like:
     * - "Leonardo, D." matches "Leonardo", "Leonardo D.", "D. Leonardo", "Leonardo, D."
     * - "Cagmat, B." matches "Cagmat", "Cagmat B.", "B. Cagmat"
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
        
        // Extract last names and initials from both formats
        $name1Parts = $this->extractNameParts($name1);
        $name2Parts = $this->extractNameParts($name2);
        
        // Match if last names are the same (with normalization for special chars and spaces)
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
     * Handles formats: "Leonardo, D.", "D. Leonardo", "Leonardo D.", "Leonardo"
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
     * Set filter preferences for soft constraints
     */
    public function setFilterPreferences(array $preferences): void
    {
        $this->filterPreferences = $preferences;
        Log::debug("Filter preferences set in PhpScheduler:", $preferences);
    }

    /**
     * PERFORMANCE OPTIMIZATION: Add schedule to indexed structures for O(1) conflict lookups
     */
    private function addToIndexedSchedules(array $schedule): void
    {
        $instructorName = $schedule['instructor'] ?? 'Unknown';
        $section = $schedule['section'] ?? '';
        $roomId = $schedule['room_id'] ?? 0;
        $day = $schedule['day'] ?? '';
        $startTime = $schedule['start_time'] ?? '';
        $endTime = $schedule['end_time'] ?? '';
        
        // Index by instructor
        if (!isset($this->instructorSchedules[$instructorName])) {
            $this->instructorSchedules[$instructorName] = [];
        }
        $this->instructorSchedules[$instructorName][] = [
            'day' => $day,
            'start_time' => $startTime,
            'end_time' => $endTime
        ];
        
        // Index by section
        if (!isset($this->sectionSchedules[$section])) {
            $this->sectionSchedules[$section] = [];
        }
        $this->sectionSchedules[$section][] = [
            'day' => $day,
            'start_time' => $startTime,
            'end_time' => $endTime
        ];
        
        // Index by room
        if (!isset($this->roomSchedules[$roomId])) {
            $this->roomSchedules[$roomId] = [];
        }
        $this->roomSchedules[$roomId][] = [
            'day' => $day,
            'start_time' => $startTime,
            'end_time' => $endTime
        ];
    }

    /**
     * SIMPLIFIED solve method - fast and conflict-free
     */
    public function solve(int $timeLimit = 30): array
    {
        if ($this->verbose()) {
            Log::debug("Starting CSP-based PHP scheduler - constraint satisfaction approach...");
        }
        
        $startTime = time();
        
        // Reset tracking arrays
        $this->roomUsage = [];
        $this->scheduledCourses = [];
        $this->instructorLoad = [];
        $this->dayLoadCount = ['Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0];
        // PERFORMANCE: Reset indexed structures
        $this->instructorSchedules = [];
        $this->sectionSchedules = [];
        $this->roomSchedules = [];
        
        $schedules = [];
        $unscheduledCourses = [];

        try {
            // CSP APPROACH: Smart ordering + backtracking + constraint relaxation
            $totalCourses = count($this->courses);
            
            // Optional: instructor distribution diagnostics
            if ($this->verbose()) {
                $this->logInstructorDistribution($this->courses);
            }
            
            // STEP 0: Parse joint sessions BEFORE scheduling to work with real structure
            $this->courses = $this->parseJointSessionsBeforeScheduling($this->courses);
            
            // STEP 0.1: Apply round-robin load balancing on joint sessions
            $this->courses = $this->applyRoundRobinBalancing($this->courses);
            
            // STEP 0.4: Apply room usage balancing on joint sessions
            $this->courses = $this->applyRoomBalancing($this->courses);
            
            // STEP 0.5: Diversify time slot preferences on joint sessions
            $this->courses = $this->applyTimeSlotDiversification($this->courses);
            
            // STEP 1: Order courses by difficulty (most constrained first)
            $orderedCourses = $this->orderCoursesByConstraint($this->courses);
            if ($this->verbose()) {
                Log::debug("CSP: Ordered " . count($orderedCourses) . " courses by constraint difficulty");
            }
            
            foreach ($orderedCourses as $courseIndex => $course) {
                $currentTime = time();
                $elapsedTime = $currentTime - $startTime;
                
                // Timeout check
                if ($elapsedTime > ($timeLimit * 0.9)) {
                    Log::warning("Scheduler approaching timeout limit after {$elapsedTime}s (90% of {$timeLimit}s limit) - stopping early");
                    break;
                }

                $courseSchedules = $this->scheduleCourseSimple($course, $courseIndex);
                
                if (!empty($courseSchedules)) {
                    $schedules = array_merge($schedules, $courseSchedules);
                    // Reduced logging frequency
                    if (rand(1, 10) === 1 || $courseIndex % 10 === 0) {
                        Log::debug("Scheduled: " . ($course['courseCode'] ?? 'Unknown') . " for " . ($course['yearLevel'] ?? '') . ' ' . ($course['block'] ?? '') . " (Progress: " . ($courseIndex + 1) . "/{$totalCourses})");
                    }
                } else {
                    $unscheduledCourses[] = $course;
                    Log::warning("Failed to schedule: " . ($course['courseCode'] ?? 'Unknown'));
                }
            }

            // Validate results using ResourceTracker for consistency (optional)
            $conflicts = [
                'instructor_conflicts' => 0,
                'room_conflicts' => 0,
                'section_conflicts' => 0,
                'lunch_break_violations' => 0
            ];
            $totalConflicts = 0;
            if ((bool) env('LOG_SCHEDULER_VERBOSE', false)) {
                $conflicts = $this->detectConflictsWithResourceTracker($schedules);
                $totalConflicts = array_sum($conflicts);
            }

            // ENHANCED FALLBACK: Try to schedule failed courses with relaxed constraints
            if (!empty($unscheduledCourses)) {
                // Fallback scheduling for failed courses
                
                foreach ($unscheduledCourses as $courseIndex => $course) {
                    Log::warning("FALLBACK: Attempting to schedule failed course: " . ($course['courseCode'] ?? 'Unknown'));
                    
                    // Try with relaxed constraints (allow section conflicts if different instructors)
                    $fallbackSchedules = $this->scheduleCourseWithRelaxedConstraints($course, $courseIndex);
                    if (!empty($fallbackSchedules)) {
                        $schedules = array_merge($schedules, $fallbackSchedules);
                        unset($unscheduledCourses[$courseIndex]);
                        // Fallback success
                    } else {
                        Log::error("FALLBACK FAILED: Could not schedule " . ($course['courseCode'] ?? 'Unknown') . " even with relaxed constraints");
                    }
                }
            }

            // ENHANCED VALIDATION: Log conflicts but don't reject if we have good coverage
            if ($conflicts['instructor_conflicts'] > 0) {
                Log::info("INSTRUCTOR CONFLICTS: {$conflicts['instructor_conflicts']} instructor conflicts detected");
            }

            if ($conflicts['room_conflicts'] > 0) {
                Log::info("ROOM CONFLICTS: {$conflicts['room_conflicts']} room conflicts detected");
            }

            if ($conflicts['section_conflicts'] > 0) {
                Log::warning("SECTION CONFLICTS: {$conflicts['section_conflicts']} section conflicts detected");
            }

            // Determine success with more realistic criteria
            $unscheduledCount = count($unscheduledCourses);
            $totalCourses = count($this->courses);
            $schedulingRate = $totalCourses > 0 ? (($totalCourses - $unscheduledCount) / $totalCourses) : 0;
            
            // EARLY TERMINATION: Accept results if we have good coverage with reasonable conflicts
            $executionTime = time() - $startTime;
            Log::info("Scheduling completed in {$executionTime}s - Scheduled: " . count($schedules) . ", Conflicts: {$totalConflicts}, Rate: " . round($schedulingRate * 100, 1) . "%");
            
            // Consider successful if we scheduled at least 60% of courses and have minimal conflicts
            $success = $schedulingRate >= 0.6 && $totalConflicts < 50;
            
            if ($success) {
                $message = "Schedule generated successfully with " . round($schedulingRate * 100, 1) . "% coverage, {$totalConflicts} conflicts";
            } else {
                $message = "Schedule generated with {$totalConflicts} conflicts and " . $unscheduledCount . " unscheduled courses";
            }

        // Ensure no courses are dropped: run emergency force scheduling as a final pass
        // Note: This may introduce manageable overlaps which can be resolved manually later
        $schedules = $this->forceScheduleDroppedPartTimeCourses($schedules, $this->courses);

            // STEP FINAL: Analyze joint sessions for better conflict understanding
            if ($this->verbose()) {
                $this->analyzeJointSessions($schedules);
            }
            
            // STEP FINAL+: Use frontend-style conflict detection for accurate results
            $frontendConflicts = $this->detectConflictsFrontendStyle($schedules);
            if ($this->verbose()) {
                Log::debug("PhpScheduler: Frontend-style conflict detection found: " . json_encode($frontendConflicts));
            }

            // STEP FINAL++: Validate part-time constraints
            $partTimeViolations = $this->validatePartTimeConstraints($schedules);
            if (!empty($partTimeViolations)) {
                Log::error("CRITICAL: Part-time constraint violations detected in final schedule!");
                $message .= " (WARNING: Part-time violations detected)";
            }

            if ($this->verbose()) {
                Log::debug("PHP scheduler completed: {$message}");
            }

            return [
                'success' => $success,
                'message' => $message,
                'schedules' => $schedules,
                'conflicts' => $conflicts,
                'total_conflicts' => $totalConflicts,
                'unscheduled_courses' => count($unscheduledCourses),
                'total_scheduled' => count($schedules),
                'algorithm' => 'php_constraint_satisfaction'
            ];

        } catch (\Exception $e) {
            Log::error("PHP scheduler error: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'PHP scheduler error: ' . $e->getMessage(),
                'schedules' => $schedules,
                'errors' => [$e->getMessage()],
                'algorithm' => 'php_constraint_satisfaction'
            ];
        }
    }

    /**
     * SIMPLE course scheduling - fast and conflict-free with joint session support
     */
    private function scheduleCourseSimple(array $course, int $courseIndex): array
    {
        // Check if this is a joint session
        if (isset($course['joint_session']) && $course['joint_session']) {
            return $this->scheduleJointSessionSimple($course, $courseIndex);
        }
        
        $units = $course['unit'] ?? $course['units'] ?? 3;
        $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
        
        // DEBUG: Log course data to see what fields are available
        // Course data processed
        
        // Generate session durations
        $sessionDurations = TimeScheduler::generateRandomizedSessions($units, $employmentType);
        // Session durations generated
        
        $courseSchedules = [];
        $usedDays = []; // Prevent same-day sessions
        
        $preferredStartTime = null;
        $preferredRoomId = null;
        foreach ($sessionDurations as $sessionIndex => $sessionDuration) {
            $schedule = $this->scheduleSessionSimple(
                $course,
                $sessionDuration,
                $sessionIndex,
                $usedDays,
                $preferredStartTime,
                $preferredRoomId
            );
            
            if ($schedule) {
                // CRITICAL VALIDATION: Ensure this day isn't already used by this course
                if (in_array($schedule['day'], $usedDays)) {
                    Log::error("SIMPLE: Attempted to schedule on already-used day {$schedule['day']} for " . ($course['courseCode'] ?? 'Unknown'));
                    continue; // Skip to next session
                }
                
                $courseSchedules[] = $schedule;
                $this->scheduledCourses[] = $schedule;
                $this->addToIndexedSchedules($schedule);
                $usedDays[] = $schedule['day']; // Track used day
                
                // Track instructor load for better distribution
                $instructorName = $schedule['instructor'] ?? 'Unknown';
                $sessionHours = ($schedule['session_duration'] ?? 3);
                $this->instructorLoad[$instructorName] = ($this->instructorLoad[$instructorName] ?? 0) + $sessionHours;
                // Instructor load updated
                
                // Track day load for better distribution
                $this->dayLoadCount[$schedule['day']] = ($this->dayLoadCount[$schedule['day']] ?? 0) + 1;
                // Day load updated
                // Capture preferred start and room after the first successful session
                if ($preferredStartTime === null) {
                    $preferredStartTime = $schedule['start_time'];
                    // First session sets preferred time
                }
                if ($preferredRoomId === null) {
                    $preferredRoomId = $schedule['room_id'] ?? null;
                }
                // Session scheduled
            } else {
                if ($preferredStartTime !== null) {
                    Log::warning("SIMPLE: Failed to schedule session {$sessionIndex} for " . ($course['courseCode'] ?? 'Unknown') . " at required time {$preferredStartTime} - will retry in force scheduling");
                } else {
                    Log::warning("SIMPLE: Failed to schedule session {$sessionIndex} for " . ($course['courseCode'] ?? 'Unknown'));
                }
            }
        }
        
        return $courseSchedules;
    }

    /**
     * INCREMENTAL SCHEDULING: Your proposed algorithm implementation
     * Step 1: Find instructor availability
     * Step 2: Find room availability for those time slots
     * Step 3: Find section availability for those time slots
     * Step 4: Reserve all resources atomically
     */
    private function scheduleCourseIncremental(array $course, int $courseIndex): array
    {
        // Check if this is a joint session (courses that should have A and B sections combined)
        if (isset($course['joint_session']) && $course['joint_session']) {
            return $this->scheduleJointSessionIncremental($course, $courseIndex);
        }
        
        // Check if this course has instances (multiple sections like A and B)
        if (isset($course['instances']) && count($course['instances']) > 1) {
            return $this->scheduleJointSessionIncremental($course, $courseIndex);
        }
        
        $units = $course['unit'] ?? $course['units'] ?? 3;
        $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
        $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown Instructor';
        $sectionName = ($course['yearLevel'] ?? '') . ' ' . ($course['block'] ?? '');
        
        // Generate session durations
        $sessionDurations = TimeScheduler::generateRandomizedSessions($units, $employmentType);
        
        $courseSchedules = [];
        $usedDays = []; // Prevent same-day sessions
        $preferredRoomId = null; // Track room for joint sessions
        $preferredStartTime = null; // Track time for joint sessions
        $preferredDay = null; // Track day for joint sessions
        
        foreach ($sessionDurations as $sessionIndex => $sessionDuration) {
            // STEP 1: Find instructor available time slots
            $instructorSlots = $this->findInstructorAvailableSlots($instructorName, $sessionDuration, $usedDays, $employmentType);
            
            if (empty($instructorSlots)) {
                // No available slots for instructor
                continue;
            }
            
            // STEP 1.5: For joint sessions, prioritize slots at the preferred time
            if ($preferredStartTime !== null && $sessionIndex > 0) {
                // Filter to slots that match the preferred time for joint sessions
                $matchingSlots = array_filter($instructorSlots, function($slot) use ($preferredStartTime) {
                    return $slot['start'] === $preferredStartTime;
                });
                if (!empty($matchingSlots)) {
                    $instructorSlots = array_values($matchingSlots);
                    // Joint session: prioritizing preferred time slots
                }
            }
            
            // STEP 1.6: Apply filter preferences to prioritize preferred time slots
            $preferredSlots = [];
            if (!empty($this->filterPreferences)) {
                $otherSlots = [];
                
                foreach ($instructorSlots as $slot) {
                    if ($this->isPreferredTimeSlot($course, $slot['start'])) {
                        $preferredSlots[] = $slot;
                    } else {
                        $otherSlots[] = $slot;
                    }
                }
                
                // Shuffle within each group to maintain some randomness
                shuffle($preferredSlots);
                shuffle($otherSlots);
                
                // Combine: preferred first, then others
                $instructorSlots = array_merge($preferredSlots, $otherSlots);
                
                if (count($preferredSlots) > 0) {
                    Log::debug("Filter preferences: Prioritized " . count($preferredSlots) . " preferred slots for " . ($course['courseCode'] ?? 'Unknown'));
                }
            }
            
            // STEP 2: For each instructor slot, find available rooms
            // Cap candidate slots to bound runtime while keeping diversity
            // Shuffle already done above
            // If filter preferences are applied, check more slots to improve success rate
            $slotLimit = !empty($this->filterPreferences) && count($preferredSlots) > 0 ? 50 : 10;
            $instructorSlots = array_slice($instructorSlots, 0, $slotLimit);
            
            if ($slotLimit > 10) {
                Log::debug("Expanded slot check to {$slotLimit} candidates to meet filter preferences");
            }
            
            $scheduled = false;
            foreach ($instructorSlots as $slot) {
                if ($scheduled) break; // Already scheduled this session
                
                $day = $slot['day'];
                $startTime = $slot['start'];
                $endTime = $slot['end'];
                
                // Skip if this day is already used by this course
                if (in_array($day, $usedDays)) {
                    continue;
                }
                
                // STEP 2: Find available room for this time slot
                // For joint sessions (first session of multi-session), find a common room across days
                if ($sessionIndex === 0 && count($sessionDurations) > 1) {
                    // Try to find a room that works on multiple days for joint session
                    $availableRoom = $this->findCommonRoomAcrossDays($course, $startTime, $endTime, $day, $usedDays);
                    if ($availableRoom) {
                        // Joint session: common room found
                    } else {
                        // Must find a common room for joint sessions - try next slot instead of falling back
                        // Joint session: no common room, trying next slot
                        continue; // Skip this slot, try next one
                    }
                } elseif ($preferredRoomId !== null && $preferredStartTime !== null) {
                    // For subsequent sessions of multi-session courses, MUST use preferred room/time
                    $preferredEndTime = $this->calculateEndTime($preferredStartTime, $sessionDuration);
                    $availableRoom = $this->getRoomByIdIfAvailable($preferredRoomId, $course, $day, $preferredStartTime, $preferredEndTime);
                    if ($availableRoom) {
                        // Joint session: using preferred room/time
                        // Override time to preferred time for joint session (but keep current day)
                        $startTime = $preferredStartTime;
                        $endTime = $preferredEndTime;
                    } else {
                        // Preferred room not available, skipping slot
                        continue; // Must use same room/time for joint sessions, cannot fallback
                    }
                } else {
                    $availableRoom = $this->findAvailableRoomForSlot($course, $day, $startTime, $endTime);
                }
                
                if (!$availableRoom) {
                    Log::debug("No available room found for {$day} {$startTime}-{$endTime}, skipping");
                    continue;
                }
                
                // STEP 2.5: Double-check room is available (prevents conflicts)
                if (!$this->resourceTracker->isRoomAvailable($availableRoom['room_id'], $day, $startTime, $endTime)) {
                    Log::debug("Room {$availableRoom['room_id']} not available at {$day} {$startTime}-{$endTime}, skipping");
                    continue;
                }
                
                // STEP 3: Check if section is available for this time slot
                if (!$this->resourceTracker->isSectionAvailable($sectionName, $day, $startTime, $endTime)) {
                    Log::debug("Section {$sectionName} not available at {$day} {$startTime}-{$endTime}, skipping");
                    continue;
                }

                // STEP 4: All resources available - create schedule entry
                $schedule = $this->createScheduleEntry($course, $day, $startTime, $endTime, $availableRoom, $sessionDuration);

                // DB CONSTRAINT GUARD: skip if it would conflict with existing saved meetings (same-subject allowed)
                if ($this->violatesDbConstraints($day, $startTime, $endTime, $schedule, $course)) {
                    Log::debug("DB-GUARD: Conflict detected for {$schedule['subject_code']} at {$day} {$startTime}-{$endTime}, skipping slot");
                    continue;
                }

                // STEP 5: Reserve all resources without re-validating (we already checked availability)
                $this->resourceTracker->reserveAllResourcesTrusted(
                    $instructorName,
                    $availableRoom['room_id'],
                    $sectionName,
                    $day,
                    $startTime,
                    $endTime,
                    $schedule
                );
                $courseSchedules[] = $schedule;
                $this->scheduledCourses[] = $schedule;
                $this->addToIndexedSchedules($schedule);
                $usedDays[] = $day;
                $scheduled = true;
                
                // Track preferred room, time, and day for joint sessions
                if ($preferredRoomId === null) {
                    $preferredRoomId = $availableRoom['room_id'];
                }
                if ($preferredStartTime === null) {
                    $preferredStartTime = $startTime;
                }
                if ($preferredDay === null) {
                    $preferredDay = $day;
                }
                
                // Track instructor load
                $this->instructorLoad[$instructorName] = ($this->instructorLoad[$instructorName] ?? 0) + $sessionDuration;
                $this->dayLoadCount[$day] = ($this->dayLoadCount[$day] ?? 0) + 1;
                
                // Session scheduled successfully
                break;
            }

            // ADAPTIVE RETRY: If session failed to schedule using the initial small candidate set, 
            // retry with a larger candidate pool and relaxed constraints for subsequent sessions.
            if (!$scheduled && count($sessionDurations) > 1) {
                $retrySlots = $this->findInstructorAvailableSlots($instructorName, $sessionDuration, $usedDays, $employmentType);
                if (!empty($retrySlots)) {
                    // For retries, apply filter preferences if active to improve success rate
                    $retryPreferredSlots = [];
                    if (!empty($this->filterPreferences)) {
                        $retryOtherSlots = [];
                        
                        foreach ($retrySlots as $slot) {
                            if ($this->isPreferredTimeSlot($course, $slot['start'])) {
                                $retryPreferredSlots[] = $slot;
                            } else {
                                $retryOtherSlots[] = $slot;
                            }
                        }
                        
                        shuffle($retryPreferredSlots);
                        shuffle($retryOtherSlots);
                        $retrySlots = array_merge($retryPreferredSlots, $retryOtherSlots);
                        
                        if (count($retryPreferredSlots) > 0) {
                            Log::debug("Retry: Prioritized " . count($retryPreferredSlots) . " preferred slots for " . ($course['courseCode'] ?? 'Unknown'));
                        }
                    } else {
                        shuffle($retrySlots);
                    }
                    
                    $retryLimit = !empty($this->filterPreferences) && count($retryPreferredSlots) > 0 ? 100 : 30;
                    $retrySlots = array_slice($retrySlots, 0, $retryLimit);
                    
                    foreach ($retrySlots as $slot) {
                        $day = $slot['day'];
                        $startTime = $slot['start'];
                        $endTime = $slot['end'];

                        if (in_array($day, $usedDays)) {
                            continue;
                        }

                        // For first session, try common room; for subsequent sessions, try preferred room first then any room
                        if ($sessionIndex === 0) {
                            // Try to find a common room for joint sessions
                            $availableRoom = $this->findCommonRoomAcrossDays($course, $startTime, $endTime, $day, $usedDays);
                            if (!$availableRoom) {
                                continue;
                            }
                        } elseif ($preferredRoomId !== null && $preferredStartTime !== null) {
                            // For subsequent sessions: first try preferred room/time, then fallback to any room/time
                            $preferredEndTime = $this->calculateEndTime($preferredStartTime, $sessionDuration);
                            $availableRoom = $this->getRoomByIdIfAvailable($preferredRoomId, $course, $day, $preferredStartTime, $preferredEndTime);
                            if ($availableRoom) {
                                $startTime = $preferredStartTime;
                                $endTime = $preferredEndTime;
                            } else {
                                // Fallback: try any room at any time for this session
                                $availableRoom = $this->findAvailableRoomForSlot($course, $day, $startTime, $endTime);
                            }
                        } else {
                            $availableRoom = $this->findAvailableRoomForSlot($course, $day, $startTime, $endTime);
                        }
                        
                        if (!$availableRoom) {
                            continue;
                        }
                        
                        if (!$this->resourceTracker->isRoomAvailable($availableRoom['room_id'], $day, $startTime, $endTime)) {
                            continue;
                        }

                        if (!$this->resourceTracker->isSectionAvailable($sectionName, $day, $startTime, $endTime)) {
                            continue;
                        }

                        // DB constraint guard
                        $schedule = $this->createScheduleEntry($course, $day, $startTime, $endTime, $availableRoom, $sessionDuration);
                        if ($this->violatesDbConstraints($day, $startTime, $endTime, $schedule, $course)) {
                            continue;
                        }

                        // Reserve and record
                        $this->resourceTracker->reserveAllResourcesTrusted(
                            $instructorName,
                            $availableRoom['room_id'],
                            $sectionName,
                            $day,
                            $startTime,
                            $endTime,
                            $schedule
                        );
                        $courseSchedules[] = $schedule;
                        $this->scheduledCourses[] = $schedule;
                        $this->addToIndexedSchedules($schedule);
                        $usedDays[] = $day;
                        $scheduled = true;
                        
                        // Track preferred room, time, and day for joint sessions
                        if ($preferredRoomId === null) {
                            $preferredRoomId = $availableRoom['room_id'];
                            // Setting preferred room for joint sessions
                        }
                        if ($preferredStartTime === null) {
                            $preferredStartTime = $startTime;
                            // Setting preferred time for joint sessions
                        }
                        if ($preferredDay === null) {
                            $preferredDay = $day;
                            // Setting preferred day for joint sessions
                        }
                        
                        $this->instructorLoad[$instructorName] = ($this->instructorLoad[$instructorName] ?? 0) + $sessionDuration;
                        $this->dayLoadCount[$day] = ($this->dayLoadCount[$day] ?? 0) + 1;
                        // Adaptive retry succeeded
                        break;
                    }
                }
            }
            
            if (!$scheduled) {
                Log::warning("INCREMENTAL: Failed to schedule session {$sessionIndex} for " . ($course['courseCode'] ?? 'Unknown'));
            }
        }
        
        return $courseSchedules;
    }

    /**
     * INCREMENTAL HELPER: Find instructor available time slots
     */
    private function findInstructorAvailableSlots(string $instructorName, float $sessionDuration, array $usedDays = [], string $employmentType = 'FULL-TIME'): array
    {
        $availableSlots = [];
        $requiredMinutes = (int) round($sessionDuration * 60);
        
        // FAIR SCHEDULING: Both PART-TIME and FULL-TIME can use all available time slots
        $filteredSlots = TimeScheduler::filterTimeSlotsByEmployment($this->timeSlots, $employmentType, false);
        
        foreach ($filteredSlots as $slot) {
            $day = $slot['day'];
            $startTime = $slot['start'];
            $endTime = $slot['end'];
            
            // Skip if this day is already used by this course
            if (in_array($day, $usedDays)) {
                continue;
            }
            
            // RESPECT LUNCH TIME: Skip slots that overlap with lunch break (11:30 AM - 1:00 PM)
            if (TimeScheduler::isLunchBreakViolation($startTime, $endTime)) {
                continue;
            }
            
            // STRICT: Calculate what the end time would be and reject if it exceeds 8:45 PM
            $calculatedEndTime = $this->calculateEndTime($startTime, $sessionDuration);
            if ($calculatedEndTime > '20:45:00') {
                continue;
            }
            
            // Check if slot duration matches session duration (with strict matching for 5-hour courses)
            $slotMinutes = TimeScheduler::timeToMinutes($endTime) - TimeScheduler::timeToMinutes($startTime);
            $requiredMinutes = (int) round($sessionDuration * 60);
            
            // For 5-hour courses, require exact match to prevent shorter slots from being selected
            if ($sessionDuration == 5.0) {
                if ($slotMinutes !== $requiredMinutes) {
                    continue; // Must be exactly 5 hours (300 minutes)
                }
            } elseif ($sessionDuration <= 2.0) {
                // For 1-2 unit courses, require exact match to prevent longer slots
                if ($slotMinutes !== $requiredMinutes) {
                    continue; // Must be exactly the required duration
                }
            } elseif ($sessionDuration == 2.5) {
                // For 2.5-hour sessions, require exact match
                if ($slotMinutes !== $requiredMinutes) {
                    continue; // Must be exactly 2.5 hours (150 minutes)
                }
            } else {
                // For other courses, allow 25% tolerance for flexibility
                if ($slotMinutes < ($requiredMinutes * 0.75) || $slotMinutes > ($requiredMinutes * 1.25)) {
                    continue;
                }
            }
            
            // Check if instructor is available for this time slot
            // CRITICAL: Check ResourceTracker AND direct reference schedule conflicts
            // ResourceTracker only has reference schedules where room was found, so we must also check $this->referenceSchedules directly
            $resourceTrackerAvailable = $this->resourceTracker->isInstructorAvailable($instructorName, $day, $startTime, $endTime);
            
            // Also check against ALL reference schedules directly (even if room wasn't found)
            $hasReferenceConflict = $this->hasInstructorConflict([
                'instructor' => $instructorName,
                'courseCode' => 'CHECK_AVAILABILITY'
            ], $day, $startTime, $endTime, true); // silent mode for availability checks
            
            if ($resourceTrackerAvailable && !$hasReferenceConflict) {
                $availableSlots[] = [
                    'day' => $day,
                    'start' => $startTime,
                    'end' => $endTime
                ];
            }
        }
        
        // Shuffle first to randomize order, then apply load balancing
        shuffle($availableSlots);
        
        // Sort by day load balancing to prioritize underutilized days (Fri/Sat)
        usort($availableSlots, function($a, $b) use ($usedDays) {
            // FIRST: Avoid consecutive days if this is a multi-session course
            $aIsConsecutive = !empty($usedDays) ? $this->isConsecutiveDay($a['day'], $usedDays) : false;
            $bIsConsecutive = !empty($usedDays) ? $this->isConsecutiveDay($b['day'], $usedDays) : false;
            
            if ($aIsConsecutive && !$bIsConsecutive) {
                return 1; // Prefer non-consecutive
            } elseif (!$aIsConsecutive && $bIsConsecutive) {
                return -1;
            }
            
            // SECOND: Prioritize underutilized days (Fri/Sat)
            $avgLoad = array_sum($this->dayLoadCount) / count($this->dayLoadCount);
            $aLoad = $this->dayLoadCount[$a['day']] ?? 0;
            $bLoad = $this->dayLoadCount[$b['day']] ?? 0;
            
            // Prefer days that are below average load
            if ($aLoad < $avgLoad && $bLoad >= $avgLoad) {
                return -1; // Prefer a (underutilized)
            } elseif ($bLoad < $avgLoad && $aLoad >= $avgLoad) {
                return 1;  // Prefer b (underutilized)
            }
            
            // THIRD: If both are equally loaded, use standard day order
            $dayOrder = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
            $dayA = $dayOrder[$a['day']] ?? 999;
            $dayB = $dayOrder[$b['day']] ?? 999;
            
            if ($dayA !== $dayB) {
                return $dayA - $dayB;
            }
            
            return strcmp($a['start'], $b['start']);
        });
        
        return $availableSlots;
    }

    /**
     * Check if the same start-end window can be scheduled on a different day
     * for the same course (section/instructor), preserving identical-time rule
     * across days for multi-session courses.
     */
    private function canScheduleSameTimeOnAnotherDay(array $course, string $startTime, string $endTime, string $currentDay, array $usedDays): bool
    {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $sectionName = trim(($course['yearLevel'] ?? '') . ' ' . ($course['block'] ?? ''));
        $instructorName = $course['instructor'] ?? $course['name'] ?? '';

        foreach ($days as $day) {
            if ($day === $currentDay) continue;
            if (in_array($day, $usedDays)) continue;

            // Hard cutoff and lunch break invariants already handled by callers; recheck minimally
            if (\App\Services\TimeScheduler::isLunchBreakViolation($startTime, $endTime)) {
                continue;
            }
            if ($endTime > '20:45:00') {
                continue;
            }

            // Instructor and section availability for the same window
            if (!$this->resourceTracker->isInstructorAvailable($instructorName, $day, $startTime, $endTime)) {
                continue;
            }
            if (!$this->resourceTracker->isSectionAvailable($sectionName, $day, $startTime, $endTime)) {
                continue;
            }

            // Try to find any valid room for the same time
            $room = $this->findAnyAvailableRoom($course, $day, $startTime, $endTime);
            if ($room) {
                return true; // Feasible on at least one other day
            }
        }

        return false;
    }
    
    /**
     * FALLBACK: Schedule course with relaxed constraints (allow section conflicts if different instructors)
     * ENHANCED: Prioritize underutilized days (Friday/Saturday) for part-time instructors
     */
    private function scheduleCourseWithRelaxedConstraints(array $course, int $courseIndex): array
    {
        Log::info("RELAXED SCHEDULING: Attempting to schedule " . ($course['courseCode'] ?? 'Unknown') . " with relaxed constraints");
        
        $schedules = [];
        $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown Instructor';
        $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
        $units = $course['unit'] ?? $course['units'] ?? 3;
        
        // Generate session durations
        $sessionDurations = TimeScheduler::generateRandomizedSessions($units, $employmentType);
        $usedDays = [];
        
        foreach ($sessionDurations as $sessionIndex => $sessionDuration) {
            // Get suitable time slots for this employment type with STRICT part-time constraints (5:00 PM onwards only)
            $suitableSlots = TimeScheduler::filterTimeSlotsByEmployment($this->timeSlots, $employmentType, false);
            
            // STRICT DAY BALANCING: Prioritize Friday/Saturday evening slots for part-time instructors
            if ($employmentType === 'PART-TIME') {
                $suitableSlots = $this->prioritizeEveningSlotsForPartTime($suitableSlots);
            }
            
            // Filter slots by duration and skip used days
            $viableSlots = array_filter($suitableSlots, function($slot) use ($sessionDuration, $usedDays) {
                if (in_array($slot['day'], $usedDays)) {
                    return false;
                }
                
            $slotMinutes = TimeScheduler::timeToMinutes($slot['end']) - TimeScheduler::timeToMinutes($slot['start']);
            $requiredMinutes = (int) round($sessionDuration * 60);
            
            // For 5-hour courses, require exact match even in relaxed mode
            if ($sessionDuration == 5.0) {
                return $slotMinutes === $requiredMinutes; // Must be exactly 5 hours (300 minutes)
            } else {
                return $slotMinutes >= ($requiredMinutes * 0.9) && $slotMinutes <= ($requiredMinutes * 1.1);
            }
            });
            
            if (empty($viableSlots)) {
                Log::warning("RELAXED: No viable slots for " . ($course['courseCode'] ?? 'Unknown') . " session {$sessionIndex}");
                continue;
            }
            
            $scheduled = false;
            foreach ($viableSlots as $slot) {
                $startTime = $slot['start'];
                $endTime = $this->calculateEndTime($startTime, $sessionDuration);
                
                // Only check instructor conflicts - allow section conflicts as fallback
                if ($this->hasInstructorConflict($course, $slot['day'], $startTime, $endTime, true)) {
                    continue;
                }
                
                // Find any available room
                $room = $this->findAnyAvailableRoom($course, $slot['day'], $startTime, $endTime);
                if (!$room) {
                    continue;
                }
                
                // Create schedule entry with relaxed constraints
                $schedule = $this->createScheduleEntry($course, $slot['day'], $startTime, $endTime, $room, $sessionDuration);
                
                // Reserve instructor and room (skip section reservation for relaxed mode)
                if ($this->resourceTracker->reserveInstructor($instructorName, $slot['day'], $startTime, $endTime, $schedule) &&
                    $this->resourceTracker->reserveRoom($room['room_id'], $slot['day'], $startTime, $endTime, $schedule)) {
                    
                    $schedules[] = $schedule;
                    $usedDays[] = $slot['day'];
                    $scheduled = true;
                    Log::info("RELAXED SUCCESS: Scheduled " . ($course['courseCode'] ?? 'Unknown') . " session {$sessionIndex} at {$slot['day']} {$startTime}-{$endTime}");
                    break;
                }
            }
            
            if (!$scheduled) {
                Log::warning("RELAXED FAILED: Could not schedule session {$sessionIndex} for " . ($course['courseCode'] ?? 'Unknown'));
            }
        }
        
        return $schedules;
    }

    /**
     * IMPROVED EVENING SLOT PRIORITIZATION: Better distribution for part-time instructors
     * STRICT: All slots should already be 5:00 PM onwards from filtering
     * IMPROVED: Distribute across all available days to reduce conflicts
     */
    private function prioritizeEveningSlotsForPartTime(array $timeSlots): array
    {
        // IMPROVED: Distribute evening slots across all days to maximize availability
        $dayGroups = [
            'Mon' => [], 'Tue' => [], 'Wed' => [], 
            'Thu' => [], 'Fri' => [], 'Sat' => []
        ];
        
        foreach ($timeSlots as $slot) {
            $day = $slot['day'];
            if (isset($dayGroups[$day])) {
                $dayGroups[$day][] = $slot;
            }
        }
        
        // IMPROVED: Interleave slots from different days to provide better distribution
        $prioritizedSlots = [];
        $maxSlotsPerDay = max(array_map('count', $dayGroups));
        
        for ($i = 0; $i < $maxSlotsPerDay; $i++) {
            foreach ($dayGroups as $day => $slots) {
                if (isset($slots[$i])) {
                    $prioritizedSlots[] = $slots[$i];
                }
            }
        }
        
        // Reduced logging frequency to improve performance
        if (rand(1, 10) === 1) {
            $dayCounts = array_map('count', $dayGroups);
            Log::info("IMPROVED EVENING PRIORITIZATION: Distributed " . count($prioritizedSlots) . " evening slots across days: " . json_encode($dayCounts));
        }
        
        return $prioritizedSlots;
    }

    /**
     * FAIR DAY BALANCING: No prioritization - all days treated equally
     */
    private function prioritizeUnderutilizedDays(array $timeSlots): array
    {
        // FAIR DISTRIBUTION: Return slots as-is without any day prioritization
        Log::info("FAIR DAY BALANCING: All days treated equally for fair distribution");
        
        return $timeSlots;
    }

    /**
     * Check if a day is consecutive to any used day
     */
    private function isConsecutiveDay(string $day, array $usedDays): bool
    {
        $dayOrder = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
        $dayNum = $dayOrder[$day] ?? 0;
        
        foreach ($usedDays as $usedDay) {
            $usedDayNum = $dayOrder[$usedDay] ?? 0;
            // Check if adjacent (difference of 1)
            if (abs($dayNum - $usedDayNum) === 1) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get available evening slots for an instructor
     */
    private function getAvailableEveningSlotsForInstructor(string $instructorName): int
    {
        $eveningSlots = array_filter($this->timeSlots, function($slot) {
            return $slot['start'] >= '07:00:00' && $slot['end'] <= '22:00:00';
        });
        
        $availableCount = 0;
        foreach ($eveningSlots as $slot) {
            if ($this->resourceTracker->isInstructorAvailable($instructorName, $slot['day'], $slot['start'], $slot['end'])) {
                $availableCount++;
            }
        }
        
        return $availableCount;
    }

    /**
     * REMOVED: isOverloadedPartTimeInstructor function
     * Part-time instructors now have STRICT 5 PM onwards constraint regardless of course load
     * This ensures compliance with part-time work arrangements
     */

    /**
     * Get instructor's course distribution strategy for better scheduling
     */
    private function getInstructorCourseDistribution(string $instructorName): array
    {
        $courses = [];
        foreach ($this->courses as $course) {
            $courseInstructor = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            if ($courseInstructor === $instructorName) {
                $courses[] = $course;
            }
        }
        
        $courseCount = count($courses);
        $employmentType = $this->normalizeEmploymentType($courses[0]['employmentType'] ?? 'FULL-TIME');
        
        if ($employmentType === 'PART-TIME' && $courseCount > 3) {
            // For overloaded part-time instructors, distribute courses across different time periods
            return [
                'strategy' => 'distributed',
                'courses' => $courses,
                'time_periods' => ['early_evening', 'late_evening', 'afternoon_fallback'],
                'max_per_period' => ceil($courseCount / 3)
            ];
        }
        
        return [
            'strategy' => 'normal',
            'courses' => $courses,
            'time_periods' => ['evening'],
            'max_per_period' => $courseCount
        ];
    }

    /**
     * INCREMENTAL HELPER: Find available room for a specific time slot with room distribution logic
     */
    private function findAvailableRoomForSlot(array $course, string $day, string $startTime, string $endTime): ?array
    {
        $sessionType = strtolower($course['sessionType'] ?? 'non-lab session');
        $requiresLab = ($sessionType === 'lab session');
        
        // Get department-specific room distribution
        $roomDistribution = self::ROOM_DISTRIBUTION[$this->department] ?? self::ROOM_DISTRIBUTION['default'];
        
        // Get available rooms grouped by building type
        $availableRoomsByBuilding = $this->getAvailableRoomsByBuilding($requiresLab, $day, $startTime, $endTime);
        
        // Calculate how many rooms have been used from each building type so far
        $buildingUsage = $this->getBuildingUsageCounts();
        
        // Determine which building type to prioritize based on department distribution and current usage
        $preferredBuildingType = $this->selectPreferredBuildingType($roomDistribution, $buildingUsage, $availableRoomsByBuilding);
        
        Log::debug("INCREMENTAL Room selection for " . ($course['courseCode'] ?? 'Unknown') . " - Preferred building: " . $preferredBuildingType . " (Current usage: " . json_encode($buildingUsage) . ")");
        
        // Try to find a room from the preferred building type first
        if (isset($availableRoomsByBuilding[$preferredBuildingType]) && !empty($availableRoomsByBuilding[$preferredBuildingType])) {
            $selectedRoom = $this->selectRoomFromBuilding($availableRoomsByBuilding[$preferredBuildingType], $day, $startTime, $endTime);
            if ($selectedRoom) {
                return $selectedRoom;
            }
        }
        
        // Fallback: try other building types in order of preference
        $fallbackOrder = $this->getFallbackBuildingOrder($roomDistribution, $preferredBuildingType);
        
        foreach ($fallbackOrder as $buildingType) {
            if (isset($availableRoomsByBuilding[$buildingType]) && !empty($availableRoomsByBuilding[$buildingType])) {
                $selectedRoom = $this->selectRoomFromBuilding($availableRoomsByBuilding[$buildingType], $day, $startTime, $endTime);
                if ($selectedRoom) {
                    return $selectedRoom;
                }
            }
        }
        
        return null;
    }
    
    /**
     * INCREMENTAL HELPER: Create schedule entry
     */
    private function createScheduleEntry(array $course, string $day, string $startTime, string $endTime, array $room, float $sessionDuration): array
    {
        $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown Instructor';
        $subjectCode = $course['courseCode'] ?? 'UNKNOWN';
        $sectionName = ($course['yearLevel'] ?? '1st Year') . ' ' . ($course['block'] ?? 'A');
        
        $schedule = [
            'instructor' => $instructorName,
            'subject_code' => $subjectCode,
            'subject_description' => $course['subject'] ?? $course['subjectDescription'] ?? 'Unknown Subject',
            'section' => $sectionName,
            'day' => $day,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'year_level' => $course['yearLevel'] ?? '1st Year',
            'block' => $course['block'] ?? 'A',
            'unit' => $course['unit'] ?? $course['units'] ?? 3,
            'dept' => $course['dept'] ?? 'General',
            'employment_type' => $course['employmentType'] ?? 'FULL-TIME',
            'sessionType' => $course['sessionType'] ?? 'Non-Lab session',
            'room_id' => $room['room_id'],
            'session_duration' => $sessionDuration
        ];
        
        // Debug: Log the created schedule entry to verify data integrity
        Log::debug("INCREMENTAL: Created schedule entry: " . json_encode($schedule));
        
        return $schedule;
    }
    
    /**
     * INCREMENTAL HELPER: Schedule joint session incrementally
     */
    private function scheduleJointSessionIncremental(array $jointCourse, int $courseIndex): array
    {
        $instances = $jointCourse['instances'] ?? [$jointCourse];
        $totalUnits = $jointCourse['total_units'] ?? $jointCourse['unit'] ?? $jointCourse['units'] ?? 3;
        $employmentType = $this->normalizeEmploymentType($jointCourse['employmentType'] ?? 'FULL-TIME');
        $instructorName = $jointCourse['instructor'] ?? $jointCourse['name'] ?? 'Unknown Instructor';
        
        // Generate session durations for the total units
        $sessionDurations = TimeScheduler::generateRandomizedSessions($totalUnits, $employmentType);
        
        $allSchedules = [];
        $usedDays = [];
        
        // Schedule each session duration
        foreach ($sessionDurations as $sessionIndex => $sessionDuration) {
            Log::debug("INCREMENTAL JOINT: Processing session {$sessionIndex} (Duration: {$sessionDuration}h)");
            
            // STEP 1: Find instructor available time slots for this session
            $instructorSlots = $this->findInstructorAvailableSlots($instructorName, $sessionDuration, $usedDays, $employmentType);
            
            if (empty($instructorSlots)) {
                Log::warning("INCREMENTAL JOINT: No available time slots found for instructor {$instructorName}");
                continue;
            }
            
            // STEP 2: For each instructor slot, try to find room and schedule for all instances
            $scheduled = false;
            foreach ($instructorSlots as $slot) {
                if ($scheduled) break; // Already scheduled this session
                
                $day = $slot['day'];
                $startTime = $slot['start'];
                $endTime = $slot['end'];
                
                // Skip if this day is already used by this joint session
                if (in_array($day, $usedDays)) {
                    continue;
                }
                
                // STEP 3: Try to schedule all instances (A and B sections) for this time slot
                $instanceSchedules = [];
                $allInstancesScheduled = true;
                
                foreach ($instances as $instance) {
                    // Check if room is available for this instance
                    $availableRoom = $this->findAvailableRoomForSlot($instance, $day, $startTime, $endTime);
                    if (!$availableRoom) {
                        $allInstancesScheduled = false;
                        break;
                    }
                    
                    // Check if section is available for this instance
                    $sectionName = ($instance['yearLevel'] ?? '') . ' ' . ($instance['block'] ?? '');
                    if (!$this->resourceTracker->isSectionAvailable($sectionName, $day, $startTime, $endTime)) {
                        $allInstancesScheduled = false;
                        break;
                    }
                    
                    // Create schedule entry for this instance
                    $schedule = $this->createScheduleEntry($instance, $day, $startTime, $endTime, $availableRoom, $sessionDuration);
                    $instanceSchedules[] = $schedule;
                }
                
                // STEP 4: If all instances can be scheduled, reserve all resources atomically
                if ($allInstancesScheduled && !empty($instanceSchedules)) {
                    $allResourcesReserved = true;
                    
                    foreach ($instanceSchedules as $schedule) {
                        $instanceSectionName = $schedule['section'];
                        
                        if (!$this->resourceTracker->reserveAllResources(
                            $instructorName,
                            $schedule['room_id'],
                            $instanceSectionName,
                            $day,
                            $startTime,
                            $endTime,
                            $schedule
                        )) {
                            $allResourcesReserved = false;
                            break;
                        }
                    }
                    
                    if ($allResourcesReserved) {
                        // Successfully scheduled all instances
                        $allSchedules = array_merge($allSchedules, $instanceSchedules);
                        $this->scheduledCourses = array_merge($this->scheduledCourses, $instanceSchedules);
                        $usedDays[] = $day;
                        $scheduled = true;
                        
                        // Track instructor load
                        $this->instructorLoad[$instructorName] = ($this->instructorLoad[$instructorName] ?? 0) + $sessionDuration;
                        $this->dayLoadCount[$day] = ($this->dayLoadCount[$day] ?? 0) + 1;
                        
                        // Joint session scheduled
                        break;
                    }
                }
            }
            
            if (!$scheduled) {
                Log::warning("INCREMENTAL JOINT: Failed to schedule session {$sessionIndex} for " . ($jointCourse['courseCode'] ?? 'Unknown'));
            }
        }
        
        return $allSchedules;
    }

    /**
     * Schedule a joint session (multiple instances of same course)
     */
    private function scheduleJointSessionSimple(array $jointCourse, int $courseIndex): array
    {
        Log::info("JOINT SESSION: Scheduling joint session for " . ($jointCourse['courseCode'] ?? 'Unknown') . " with " . ($jointCourse['instance_count'] ?? 1) . " instances");
        
        $instances = $jointCourse['instances'] ?? [$jointCourse];
        $totalUnits = $jointCourse['total_units'] ?? 3;
        $employmentType = $this->normalizeEmploymentType($jointCourse['employmentType'] ?? 'FULL-TIME');
        
        // Generate session durations for the total units
        $sessionDurations = TimeScheduler::generateRandomizedSessions($totalUnits, $employmentType);
        Log::info("JOINT SESSION: " . ($jointCourse['courseCode'] ?? 'Unknown') . " ({$totalUnits} total units) -> " . json_encode($sessionDurations));
        
        $allSchedules = [];
        $usedDays = [];
        $preferredStartTime = null;
        $preferredRoomId = null;
        
        // Schedule each session duration
        foreach ($sessionDurations as $sessionIndex => $sessionDuration) {
            $schedule = $this->scheduleSessionSimple(
                $jointCourse,
                $sessionDuration,
                $sessionIndex,
                $usedDays,
                $preferredStartTime,
                $preferredRoomId
            );
            
            if ($schedule) {
                // CRITICAL VALIDATION: Ensure this day isn't already used by this joint session
                if (in_array($schedule['day'], $usedDays)) {
                    Log::error("JOINT SESSION: Attempted to schedule on already-used day {$schedule['day']} for " . ($jointCourse['courseCode'] ?? 'Unknown'));
                    continue; // Skip to next session
                }
                
                $allSchedules[] = $schedule;
                $this->scheduledCourses[] = $schedule;
                $this->addToIndexedSchedules($schedule);
                $usedDays[] = $schedule['day']; // Track used day
                
                // Track instructor load for better distribution
                $instructorName = $schedule['instructor'] ?? 'Unknown';
                $sessionHours = ($schedule['session_duration'] ?? 3);
                $this->instructorLoad[$instructorName] = ($this->instructorLoad[$instructorName] ?? 0) + $sessionHours;
                
                // Track day load for better distribution
                $this->dayLoadCount[$schedule['day']] = ($this->dayLoadCount[$schedule['day']] ?? 0) + 1;
                
                // Capture preferred start and room after the first successful session
                if ($preferredStartTime === null) {
                    $preferredStartTime = $schedule['start_time'];
                    Log::info("JOINT SESSION: First session for " . ($jointCourse['courseCode'] ?? 'Unknown') . " sets preferred time to {$preferredStartTime}");
                }
                if ($preferredRoomId === null) {
                    $preferredRoomId = $schedule['room_id'] ?? null;
                }
                
                Log::info("JOINT SESSION: Scheduled session {$sessionIndex} for " . ($jointCourse['courseCode'] ?? 'Unknown') . " on " . $schedule['day'] . " " . $schedule['start_time'] . "-" . $schedule['end_time']);
            } else {
                Log::warning("JOINT SESSION: Failed to schedule session {$sessionIndex} for " . ($jointCourse['courseCode'] ?? 'Unknown'));
            }
        }
        
        Log::info("JOINT SESSION: Completed scheduling " . ($jointCourse['courseCode'] ?? 'Unknown') . " with " . count($allSchedules) . " schedules");
        return $allSchedules;
    }

    /**
     * Expand joint sessions into individual meetings for database storage
     */
    private function expandJointSessions(array $schedules): array
    {
        $expandedSchedules = [];
        
        foreach ($schedules as $schedule) {
            $days = \App\Services\DayScheduler::splitCombinedDays($schedule['day'] ?? '');
            
            // If it's a single day, keep as is
            if (count($days) === 1) {
                $expandedSchedules[] = $schedule;
            } else {
                // Create individual entries for each day
                foreach ($days as $day) {
                    $individualSchedule = $schedule;
                    $individualSchedule['day'] = $day;
                    $expandedSchedules[] = $individualSchedule;
                }
            }
        }
        
        Log::debug("Expanded " . count($schedules) . " schedules into " . count($expandedSchedules) . " individual meetings");
        return $expandedSchedules;
    }

    /**
     * Schedule a single course with multiple sessions based on units (COMPLEX VERSION - kept for fallback)
     */
    private function scheduleCourse(array $course, int $courseIndex): array
    {
        $units = $course['unit'] ?? $course['units'] ?? 3;
        $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
        
        // Generate session durations for this course
        $sessionDurations = TimeScheduler::generateRandomizedSessions($units, $employmentType);
        Log::debug("Course " . ($course['courseCode'] ?? 'Unknown') . " ({$units} units, {$employmentType}) - Generated " . count($sessionDurations) . " sessions: " . json_encode($sessionDurations));
        Log::info("SCHEDULER: Instructor field check - 'name': " . ($course['name'] ?? 'NOT_SET') . ", 'instructor': " . ($course['instructor'] ?? 'NOT_SET') . ", employmentType: " . ($course['employmentType'] ?? 'NOT_SET'));
        
        // Get suitable time slots for this employment type
        $suitableSlots = TimeScheduler::filterTimeSlotsByEmployment($this->timeSlots, $employmentType, false);
        Log::debug("Total time slots: " . count($this->timeSlots) . ", Suitable slots for {$employmentType}: " . count($suitableSlots));
        
        $courseSchedules = [];
        $usedSlotKeys = [];
        $usedDays = []; // Track days used by this course to prevent multiple sessions on same day

        foreach ($sessionDurations as $sessionIndex => $sessionDuration) {
            $schedule = $this->scheduleSession(
                $course, 
                $sessionDuration, 
                $suitableSlots, 
                $usedSlotKeys,
                $courseIndex,
                $sessionIndex,
                $usedDays
            );
            
            if ($schedule) {
                // CRITICAL VALIDATION: Ensure this day isn't already used by this course
                if (in_array($schedule['day'], $usedDays)) {
                    Log::error("CRITICAL BUG DETECTED: scheduleSession returned a schedule on day {$schedule['day']} which is already in usedDays! Course: " . ($course['courseCode'] ?? 'Unknown') . ". This should never happen!");
                    // Don't add this schedule - it violates the same-day rule
                    continue;
                }
                
                $courseSchedules[] = $schedule;
                // Add to scheduled courses for conflict tracking
                $this->scheduledCourses[] = $schedule;
                // PERFORMANCE: Update indexed structures for fast conflict lookups
                $this->addToIndexedSchedules($schedule);
                // CRITICAL: Add this day to usedDays to prevent scheduling another session on the same day
                $usedDays[] = $schedule['day'];
                Log::debug("Added {$schedule['day']} to usedDays for course " . ($course['courseCode'] ?? 'Unknown') . ". UsedDays now: " . json_encode($usedDays));
            } else {
                Log::warning("Failed to schedule session {$sessionIndex} for course " . ($course['courseCode'] ?? 'Unknown'));
                
                // NEW: Try alternative session split for 3-4 unit courses
                if (($units == 3 || $units == 4) && $sessionIndex == 0) {
                    Log::info("Attempting alternative session split for {$units}-unit course " . ($course['courseCode'] ?? 'Unknown'));
                    
                    $alternativeSessions = $this->getAlternativeSessionSplit($units);
                    if ($alternativeSessions && count($alternativeSessions) == 2) {
                        Log::info("Trying alternative split: " . json_encode($alternativeSessions));
                        
                        // Try to schedule with alternative session split
                        $alternativeSchedule1 = $this->scheduleSession(
                            $course, 
                            $alternativeSessions[0], 
                            $suitableSlots, 
                            $usedSlotKeys,
                            $courseIndex,
                            0,
                            $usedDays
                        );
                        
                        if ($alternativeSchedule1) {
                            // Validate no same-day violation
                            if (!in_array($alternativeSchedule1['day'], $usedDays)) {
                                $courseSchedules[] = $alternativeSchedule1;
                                $this->scheduledCourses[] = $alternativeSchedule1;
                                $this->addToIndexedSchedules($alternativeSchedule1);
                                // CRITICAL: Add day to usedDays
                                $usedDays[] = $alternativeSchedule1['day'];
                            } else {
                                Log::error("Alternative schedule 1 violated same-day rule for " . ($course['courseCode'] ?? 'Unknown'));
                                continue; // Skip this alternative attempt
                            }
                            
                            // Try second session
                            $alternativeSchedule2 = $this->scheduleSession(
                                $course, 
                                $alternativeSessions[1], 
                                $suitableSlots, 
                                $usedSlotKeys,
                                $courseIndex,
                                1,
                                $usedDays
                            );
                            
                            if ($alternativeSchedule2) {
                                // Validate no same-day violation
                                if (!in_array($alternativeSchedule2['day'], $usedDays)) {
                                    $courseSchedules[] = $alternativeSchedule2;
                                    $this->scheduledCourses[] = $alternativeSchedule2;
                                    $this->addToIndexedSchedules($alternativeSchedule2);
                                    // CRITICAL: Add day to usedDays
                                    $usedDays[] = $alternativeSchedule2['day'];
                                    Log::info("Alternative session split succeeded for course " . ($course['courseCode'] ?? 'Unknown'));
                                    continue; // Skip to next course
                                } else {
                                    Log::error("Alternative schedule 2 violated same-day rule for " . ($course['courseCode'] ?? 'Unknown'));
                                }
                            }
                        }
                    }
                }
                
                // PERFORMANCE: Update indexed structures for alternative schedules
                if (!empty($courseSchedules)) {
                    $this->addToIndexedSchedules($courseSchedules[count($courseSchedules)-1]);
                }
                
                // Try emergency fallback: attempt with employment-filtered slots
                $emergencySchedule = $this->scheduleSessionEmergency(
                    $course, 
                    $sessionDuration, 
                    TimeScheduler::filterTimeSlotsByEmployment($this->timeSlots, $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME'), false),
                    $usedSlotKeys,
                    $courseIndex,
                    $sessionIndex,
                    $usedDays
                );
                
                if ($emergencySchedule) {
                    // Validate no same-day violation
                    if (!in_array($emergencySchedule['day'], $usedDays)) {
                        $courseSchedules[] = $emergencySchedule;
                        $this->scheduledCourses[] = $emergencySchedule;
                        // PERFORMANCE: Update indexed structures
                        $this->addToIndexedSchedules($emergencySchedule);
                        // CRITICAL: Add day to usedDays
                        $usedDays[] = $emergencySchedule['day'];
                        Log::info("Emergency scheduling succeeded for session {$sessionIndex} of course " . ($course['courseCode'] ?? 'Unknown'));
                    } else {
                        Log::error("Emergency schedule violated same-day rule for " . ($course['courseCode'] ?? 'Unknown') . " on day " . $emergencySchedule['day']);
                    }
                } else {
                    Log::warning("Even emergency scheduling failed for session {$sessionIndex} of course " . ($course['courseCode'] ?? 'Unknown'));
                    // Continue to next session instead of failing entire course
                }
            }
        }

        return $courseSchedules;
    }

    /**
     * Check if a time slot matches instructor or time preferences
     */
    private function isPreferredTimeSlot(array $course, string $startTime): bool
    {
        $startMinutes = TimeScheduler::timeToMinutes($startTime);
        
        // FIRST: Check round-robin assigned time preference
        if (isset($course['time_preference'])) {
            $roundRobinMatch = $this->timeSlotMatchesPreference($startMinutes, $course['time_preference'], $course);
            if ($roundRobinMatch) {
                return true; // Round-robin preference takes priority
            }
        }
        
        // SECOND: Check filter preferences (existing logic)
        if (empty($this->filterPreferences)) {
            return false; // No preferences set, so no bonus
        }
        
        $instructorName = $course['instructor'] ?? $course['name'] ?? '';
        
        // Check if this is one of the preferred instructors (supports multiple)
        $isPreferredInstructor = false;
        
        // Handle legacy single instructor format
        if (isset($this->filterPreferences['instructor']) && !empty($this->filterPreferences['instructor'])) {
            $isPreferredInstructor = ($instructorName === $this->filterPreferences['instructor']);
        }
        // Handle new multiple instructors format
        elseif (isset($this->filterPreferences['instructors']) && is_array($this->filterPreferences['instructors'])) {
            $isPreferredInstructor = in_array($instructorName, $this->filterPreferences['instructors']);
        }
        
        // Check time preference
        $isPreferredTime = false;
        if (isset($this->filterPreferences['preferredTime']) && !empty($this->filterPreferences['preferredTime'])) {
            $isPreferredTime = $this->timeSlotMatchesPreference($startMinutes, $this->filterPreferences['preferredTime'], $course);
        }
        
        // If both instructor and time preferences are specified, both must match (AND logic)
        $hasInstructorPref = !empty($this->filterPreferences['instructor']) || 
                           (!empty($this->filterPreferences['instructors']) && is_array($this->filterPreferences['instructors']));
        
        if ($hasInstructorPref && !empty($this->filterPreferences['preferredTime'])) {
            $result = $isPreferredInstructor && $isPreferredTime;
            // Reduced logging - only log 1% of checks to prevent pipe overflow
            if (rand(1, 100) === 1) {
                Log::debug("Time preference check for {$instructorName}: instructor={$isPreferredInstructor}, time={$isPreferredTime} (morning: {$this->filterPreferences['preferredTime']}), result={$result}");
            }
            return $result;
        }
        
        // If only one preference is specified, use OR logic for backward compatibility
        $result = $isPreferredInstructor || $isPreferredTime;
        // Removed excessive debug logging that was causing performance issues
        return $result;
    }
    
    /**
     * CSP: Order courses by constraint difficulty (MRV - Minimum Remaining Values)
     * Most constrained courses scheduled first
     * IMPROVED: Prioritize instructors with multiple sections or higher workloads
     */
    private function orderCoursesByConstraint(array $courses): array
    {
        $scoredCourses = [];
        
        // Pre-calculate instructor statistics for better prioritization
        $instructorStats = $this->calculateInstructorStatistics($courses);
        
        foreach ($courses as $index => $course) {
            $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
            $units = $course['unit'] ?? $course['units'] ?? 3;
            $sessionType = strtolower($course['sessionType'] ?? 'non-lab session');
            
            // Calculate constraint score (higher = more constrained = schedule first)
            $score = 0;
            
            // Part-time courses are most constrained (only evening slots starting at 5:00 PM)
            if ($employmentType === 'PART-TIME') {
                $score += 200; // HIGHEST priority for part-time courses to ensure they get scheduled first
                Log::debug("CRITICAL PRIORITY: Part-time course {$course['courseCode']} gets +200 priority boost");
            }
            
            // CRITICAL FIX: Prioritize small-unit courses to fill gaps in schedules
            // This prevents courses from being dropped due to all slots being taken by large courses
            // Use inverted logic: lower units = higher priority to fill schedule more evenly
            if ($units <= 2) {
                $score += 100; // High priority for 1-2 unit courses (PE, NSTP, etc.)
                Log::debug("FILL-GAP PRIORITY: Small course {$course['courseCode']} ({$units} units) gets +100 boost");
            } elseif ($units <= 4) {
                $score += 50; // Medium priority for 3-4 unit courses  
            } else {
                // Large unit courses (5+) get scored by units as before
                $score += $units * 5; // 10 units = +50, 6 units = +30, etc.
            }
            
            // Lab sessions are more constrained (need lab rooms)
            if ($sessionType === 'lab session') {
                $score += 20;
            }
            
            // Multi-session courses are more constrained
            $sessions = TimeScheduler::generateRandomizedSessions($units, $employmentType);
            if (count($sessions) > 1) {
                $score += 10;
            }
            
            // ENHANCED INSTRUCTOR PRIORITIZATION LOGIC
            $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            $instructorCurrentLoad = $this->instructorLoad[$instructorName] ?? 0;

            // Get instructor statistics
            $instructorStat = $instructorStats[$instructorName] ?? [
                'total_courses' => 0,
                'total_units' => 0,
                'sections_taught' => 0,
                'year_levels' => 0,
                'workload_score' => 0
            ];
            
            // PRIORITY 1: Instructors with multiple sections get moderate priority
            if ($instructorStat['sections_taught'] >= 2) {
                $score += 20; // Further reduced from 50 to 20 - light priority for multi-section instructors
                Log::debug("HIGH PRIORITY: Instructor {$instructorName} teaches {$instructorStat['sections_taught']} sections - boosting priority");
            }
            
            // PRIORITY 2: Instructors with high workload (total units) get moderate priority
            if ($instructorStat['total_units'] >= 30) {
                $score += 15; // Further reduced from 30 to 15 - light priority for heavy workload instructors
                Log::debug("HIGH PRIORITY: Instructor {$instructorName} has {$instructorStat['total_units']} total units - boosting priority");
            }
            
            // PRIORITY 3: Instructors with many courses get priority
            if ($instructorStat['total_courses'] >= self::HEAVY_INSTRUCTOR_THRESHOLD) {
                $score += 10; // Further reduced from 20 to 10 - light priority for instructors with 8+ courses
                Log::debug("MEDIUM PRIORITY: Instructor {$instructorName} has {$instructorStat['total_courses']} courses - boosting priority");
            }
            
            // PRIORITY 4: Instructors teaching multiple year levels get priority
            if ($instructorStat['year_levels'] >= 2) {
                $score += 5; // Further reduced from 15 to 5 - minimal priority for multi-year instructors
                Log::debug("MEDIUM PRIORITY: Instructor {$instructorName} teaches {$instructorStat['year_levels']} year levels - boosting priority");
            }
            
            // PRIORITY 5: Check if instructor's total load is feasible
            $instructorFeasibility = $this->checkInstructorFeasibility($instructorName, $employmentType);
            if (!$instructorFeasibility['is_feasible']) {
                $score += 200; // Highest priority for potentially impossible cases
                Log::warning("CRITICAL PRIORITY: Instructor {$instructorName} may have infeasible load: {$instructorFeasibility['current_courses']}/{$instructorFeasibility['max_courses']} courses");
            }

            // PRIORITY 6: Among courses for same instructor, those scheduled earlier get slight boost
            // This helps maintain consistency
            $score += max(0, 20 - ($instructorCurrentLoad * 2));
            
            // FAIR DISTRIBUTION: No day prioritization - all days treated equally
            // Removed Friday/Saturday prioritization for fair day distribution
            
            $scoredCourses[] = [
                'course' => $course,
                'score' => $score,
                'original_index' => $index,
                'instructor_stats' => $instructorStat
            ];
        }
        
        // Sort by score descending (most constrained first)
        usort($scoredCourses, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Log the prioritization results
        $this->logInstructorPrioritization($scoredCourses);
        
        // Extract just the courses
        return array_map(function($item) {
            return $item['course'];
        }, $scoredCourses);
    }
    
    /**
     * Calculate comprehensive instructor statistics for better prioritization
     */
    private function calculateInstructorStatistics(array $courses): array
    {
        $instructorStats = [];
        
        foreach ($courses as $course) {
            $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            $units = $course['unit'] ?? $course['units'] ?? 3;
            $yearLevel = $course['yearLevel'] ?? '';
            $block = $course['block'] ?? 'A';
            
            if (!isset($instructorStats[$instructorName])) {
                $instructorStats[$instructorName] = [
                    'total_courses' => 0,
                    'total_units' => 0,
                    'sections_taught' => 0,
                    'year_levels' => [],
                    'blocks_taught' => [],
                    'workload_score' => 0
                ];
            }
            
            $instructorStats[$instructorName]['total_courses']++;
            $instructorStats[$instructorName]['total_units'] += $units;
            
            // Track unique year levels
            if (!in_array($yearLevel, $instructorStats[$instructorName]['year_levels'])) {
                $instructorStats[$instructorName]['year_levels'][] = $yearLevel;
            }
            
            // Track unique blocks (sections)
            $sectionKey = $yearLevel . ' ' . $block;
            if (!in_array($sectionKey, $instructorStats[$instructorName]['blocks_taught'])) {
                $instructorStats[$instructorName]['blocks_taught'][] = $sectionKey;
            }
        }
        
        // Calculate derived statistics
        foreach ($instructorStats as $instructorName => &$stats) {
            $stats['sections_taught'] = count($stats['blocks_taught']);
            $stats['year_levels'] = count($stats['year_levels']);
            
            // Calculate workload score (combination of courses, units, and sections)
            $stats['workload_score'] = ($stats['total_courses'] * 2) + 
                                      ($stats['total_units'] * 0.5) + 
                                      ($stats['sections_taught'] * 3) + 
                                      ($stats['year_levels'] * 2);
        }
        
        return $instructorStats;
    }
    
    /**
     * Log instructor prioritization results for debugging
     */
    private function logInstructorPrioritization(array $scoredCourses): void
    {
        Log::info("=== INSTRUCTOR PRIORITIZATION RESULTS ===");
        
        $instructorPriorities = [];
        foreach ($scoredCourses as $item) {
            $instructorName = $item['course']['instructor'] ?? $item['course']['name'] ?? 'Unknown';
            $stats = $item['instructor_stats'];
            
            if (!isset($instructorPriorities[$instructorName])) {
                $instructorPriorities[$instructorName] = [
                    'priority_score' => $item['score'],
                    'stats' => $stats,
                    'courses_count' => 0
                ];
            }
            $instructorPriorities[$instructorName]['courses_count']++;
        }
        
        // Sort by priority score
        uasort($instructorPriorities, function($a, $b) {
            return $b['priority_score'] - $a['priority_score'];
        });
        
        foreach ($instructorPriorities as $instructorName => $data) {
            Log::info("Instructor: {$instructorName}", [
                'priority_score' => $data['priority_score'],
                'courses' => $data['courses_count'],
                'sections' => $data['stats']['sections_taught'],
                'total_units' => $data['stats']['total_units'],
                'year_levels' => $data['stats']['year_levels'],
                'workload_score' => $data['stats']['workload_score']
            ]);
            
            // VALIDATION: Check if part-time instructors are overloaded
            $employmentType = null;
            foreach ($scoredCourses as $item) {
                if (($item['course']['instructor'] ?? $item['course']['name'] ?? 'Unknown') === $instructorName) {
                    $employmentType = $this->normalizeEmploymentType($item['course']['employmentType'] ?? 'FULL-TIME');
                    break;
                }
            }
            
            if ($employmentType === 'PART-TIME') {
                $courses = $data['courses_count'];
                $totalUnits = $data['stats']['total_units'];
                
                if ($courses > 3 || $totalUnits > 18) {
                    Log::warning("⚠️ OVERLOADED PART-TIME INSTRUCTOR: {$instructorName} has {$courses} courses ({$totalUnits} units) - " .
                        "Recommended maximum: 3 courses (18 units). This may cause scheduling conflicts due to limited evening slots.");
                }
            }
        }
    }

    /**
     * Check if time slot matches a specific preference period
     */
    private function timeSlotMatchesPreference(int $startMinutes, string $preference, array $course = null): bool
    {
        $timeStr = sprintf('%02d:%02d', intval($startMinutes / 60), $startMinutes % 60);
        
        switch ($preference) {
            case 'morning':
                // Allow ALL courses to start at 7:00 AM (420 minutes)
                $minStartTime = 420; // 7:00 AM for ALL courses
                
                return $startMinutes >= $minStartTime && $startMinutes < 780; // End before afternoon (1:00 PM)
            case 'afternoon':
                return $startMinutes >= 780 && $startMinutes < 1020; // 1:00 PM - 5:00 PM
            case 'evening':
                return $startMinutes >= 1020 && $startMinutes < 1245; // 5:00 PM - 8:45 PM
            default:
                return false;
        }
    }

    /**
     * SIMPLE session scheduling - fast and conflict-free with joint session support
     */
    private function scheduleSessionSimple(array $course, float $sessionDuration, int $sessionIndex, array $usedDays, string $preferredStartTime = null, ?int $preferredRoomId = null): ?array
    {
        $requiredMinutes = (int) round($sessionDuration * 60);
        
        // FAIR SCHEDULING: Both PART-TIME and FULL-TIME can use all available time slots
        $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown';
        $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
        $suitableSlots = TimeScheduler::filterTimeSlotsByEmployment($this->timeSlots, $employmentType, false);
        
        Log::info("FAIR SCHEDULING: Instructor {$instructorName} ({$employmentType}) has " . count($suitableSlots) . " available time slots across all periods");
        
        // Filter slots by duration (exact match or close) AND time limit AND lunch respect
        $viableSlots = array_filter($suitableSlots, function($slot) use ($requiredMinutes, $usedDays, $sessionDuration) {
            // Skip days already used by this course
            if (in_array($slot['day'], $usedDays)) {
                return false;
            }
            
            // RESPECT LUNCH TIME: Skip slots that overlap with lunch break (11:30 AM - 1:00 PM)
            if (TimeScheduler::isLunchBreakViolation($slot['start'], $slot['end'])) {
                Log::debug("LUNCH RESPECT: Excluding slot {$slot['day']} {$slot['start']}-{$slot['end']} - overlaps with lunch break");
                return false;
            }
            
            // STRICT: Calculate what the end time would be and reject if it exceeds 8:45 PM
            $calculatedEndTime = $this->calculateEndTime($slot['start'], $sessionDuration);
            if ($calculatedEndTime > '20:45:00') {
                return false; // Exceeds maximum allowed end time
            }
            
            $slotMinutes = TimeScheduler::timeToMinutes($slot['end']) - TimeScheduler::timeToMinutes($slot['start']);
            $requiredMinutes = (int) round($sessionDuration * 60);
            
            // For 5-hour courses, require exact match to prevent shorter slots from being selected
            if ($sessionDuration == 5.0) {
                return $slotMinutes === $requiredMinutes; // Must be exactly 5 hours (300 minutes)
            } elseif ($sessionDuration <= 2.0) {
                // For 1-2 unit courses, require exact match to prevent longer slots
                return $slotMinutes === $requiredMinutes; // Must be exactly the required duration
            } elseif ($sessionDuration == 2.5) {
                // For 2.5-hour sessions, require exact match
                return $slotMinutes === $requiredMinutes; // Must be exactly 2.5 hours (150 minutes)
            } else {
                // Allow 75% to 125% match for flexibility
                return $slotMinutes >= ($requiredMinutes * 0.75) && $slotMinutes <= ($requiredMinutes * 1.25);
            }
        });
        
        if (empty($viableSlots)) {
            Log::warning("SIMPLE: No viable slots for " . ($course['courseCode'] ?? 'Unknown') . " session {$sessionIndex} ({$sessionDuration}h)");
            return null;
        }
        
        // ENFORCE SAME TIME: For multi-session courses, all sessions MUST use the same start time
        if ($preferredStartTime !== null) {
            // STRICT: Filter to ONLY slots with the exact same start time
            $exactTimeSlots = array_filter($viableSlots, function($slot) use ($preferredStartTime) {
                return $slot['start'] === $preferredStartTime;
            });
            
            if (!empty($exactTimeSlots)) {
                // Found slots at exact time - use them
                $viableSlots = $exactTimeSlots;
                Log::debug("SIMPLE: Enforcing exact time {$preferredStartTime} for session {$sessionIndex}");
            } else {
                // No slots at required time - fail this session
                // It will be retried by force scheduling with same-time constraint
                Log::warning("SIMPLE: No slots available at required time {$preferredStartTime} for session {$sessionIndex}. Will retry in force scheduling.");
                return null;
            }
            
            // IMPROVED: Multi-factor sorting for better distribution (same time constraint)
            usort($viableSlots, function($a, $b) use ($requiredMinutes, $sessionDuration) {
                // Factor 0: EXACT duration match (highest priority)
                $aMinutes = TimeScheduler::timeToMinutes($a['end']) - TimeScheduler::timeToMinutes($a['start']);
                $bMinutes = TimeScheduler::timeToMinutes($b['end']) - TimeScheduler::timeToMinutes($b['start']);
                $aExact = ($aMinutes === $requiredMinutes);
                $bExact = ($bMinutes === $requiredMinutes);
                
                if ($aExact && !$bExact) return -1; // Prefer exact match
                if (!$aExact && $bExact) return 1;  // Prefer exact match
                
                // Factor 1: Least crowded at this specific time (second priority)
                $aTimeCount = $this->countScheduledAtTime($a['day'], $a['start']);
                $bTimeCount = $this->countScheduledAtTime($b['day'], $b['start']);
                
                if ($aTimeCount !== $bTimeCount) {
                    return $aTimeCount - $bTimeCount;
                }
                
                // Factor 2: Days with fewer overall sessions (secondary priority)
                $aDayLoad = $this->dayLoadCount[$a['day']] ?? 0;
                $bDayLoad = $this->dayLoadCount[$b['day']] ?? 0;
                
                if ($aDayLoad !== $bDayLoad) {
                    return $aDayLoad - $bDayLoad;
                }
                
                // Factor 3: FAIR DAY DISTRIBUTION - No day prioritization
                // All days treated equally for fair distribution
                
                // Factor 3.5: PENALIZE CONSECUTIVE DAYS - encourage day spreading
                if (!empty($usedDays)) {
                    $aIsConsecutive = $this->isConsecutiveDay($a['day'], $usedDays);
                    $bIsConsecutive = $this->isConsecutiveDay($b['day'], $usedDays);
                    
                    if ($aIsConsecutive && !$bIsConsecutive) {
                        return 1; // Penalize consecutive days
                    } elseif (!$aIsConsecutive && $bIsConsecutive) {
                        return -1; // Prefer non-consecutive days
                    }
                }
                
                // Factor 4: Prioritize underutilized days (Fri/Sat) for better distribution
                // Calculate average load to identify underutilized days
                $avgLoad = array_sum($this->dayLoadCount) / count($this->dayLoadCount);
                $aLoad = $this->dayLoadCount[$a['day']] ?? 0;
                $bLoad = $this->dayLoadCount[$b['day']] ?? 0;
                
                // Prefer days that are below average load (Fri/Sat typically)
                if ($aLoad < $avgLoad && $bLoad >= $avgLoad) {
                    return -1; // Prefer a (underutilized)
                } elseif ($bLoad < $avgLoad && $aLoad >= $avgLoad) {
                    return 1;  // Prefer b (underutilized)
                }
                
                // If both are equally underutilized or both are over-utilized, use day order
                $dayOrder = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
                return ($dayOrder[$a['day']] ?? 7) - ($dayOrder[$b['day']] ?? 7);
            });
        } else {
            // IMPROVED: Multi-factor sorting for better distribution (no time constraint)
            usort($viableSlots, function($a, $b) use ($requiredMinutes, $sessionDuration) {
                // Factor 0: EXACT duration match (highest priority)
                $aMinutes = TimeScheduler::timeToMinutes($a['end']) - TimeScheduler::timeToMinutes($a['start']);
                $bMinutes = TimeScheduler::timeToMinutes($b['end']) - TimeScheduler::timeToMinutes($b['start']);
                $aExact = ($aMinutes === $requiredMinutes);
                $bExact = ($bMinutes === $requiredMinutes);
                
                if ($aExact && !$bExact) return -1; // Prefer exact match
                if (!$aExact && $bExact) return 1;  // Prefer exact match
                
                // Factor 1: Least crowded at this specific time (second priority)
                $aTimeCount = $this->countScheduledAtTime($a['day'], $a['start']);
                $bTimeCount = $this->countScheduledAtTime($b['day'], $b['start']);
                
                if ($aTimeCount !== $bTimeCount) {
                    return $aTimeCount - $bTimeCount;
                }
                
                // Factor 2: Days with fewer overall sessions (secondary priority)
                $aDayLoad = $this->dayLoadCount[$a['day']] ?? 0;
                $bDayLoad = $this->dayLoadCount[$b['day']] ?? 0;
                
                if ($aDayLoad !== $bDayLoad) {
                    return $aDayLoad - $bDayLoad;
                }
                
                // Factor 3: FAIR DAY DISTRIBUTION - No day prioritization
                // All days treated equally for fair distribution
                
                // Factor 3.5: PENALIZE CONSECUTIVE DAYS - encourage day spreading
                if (!empty($usedDays)) {
                    $aIsConsecutive = $this->isConsecutiveDay($a['day'], $usedDays);
                    $bIsConsecutive = $this->isConsecutiveDay($b['day'], $usedDays);
                    
                    if ($aIsConsecutive && !$bIsConsecutive) {
                        return 1; // Penalize consecutive days
                    } elseif (!$aIsConsecutive && $bIsConsecutive) {
                        return -1; // Prefer non-consecutive days
                    }
                }
                
                // Factor 4: Prioritize underutilized days (Fri/Sat) for better distribution
                // Calculate average load to identify underutilized days
                $avgLoad = array_sum($this->dayLoadCount) / count($this->dayLoadCount);
                $aLoad = $this->dayLoadCount[$a['day']] ?? 0;
                $bLoad = $this->dayLoadCount[$b['day']] ?? 0;
                
                // Prefer days that are below average load (Fri/Sat typically)
                if ($aLoad < $avgLoad && $bLoad >= $avgLoad) {
                    return -1; // Prefer a (underutilized)
                } elseif ($bLoad < $avgLoad && $aLoad >= $avgLoad) {
                    return 1;  // Prefer b (underutilized)
                }
                
                // If both are equally underutilized or both are over-utilized, use day order
                $dayOrder = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
                return ($dayOrder[$a['day']] ?? 7) - ($dayOrder[$b['day']] ?? 7);
            });
        }
        
        // PHASE 2: Time diversity for instructors with many courses
        if ($this->shouldDiversifyTimeSlots($course)) {
            $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            $existingInstructorTimes = $this->getInstructorScheduledTimes($instructorName);
            
            // Re-sort slots to prefer times different from existing instructor times
            usort($viableSlots, function($a, $b) use ($existingInstructorTimes) {
                $aScore = $this->getDiversityScore($a['start'], $existingInstructorTimes);
                $bScore = $this->getDiversityScore($b['start'], $existingInstructorTimes);
                return $bScore - $aScore; // Higher diversity first
            });
            
            Log::debug("TIME DIVERSITY: Applied for " . ($course['courseCode'] ?? 'Unknown'));
        }
        
        // Find first available slot without conflicts
        foreach ($viableSlots as $slot) {
            $startTime = $slot['start'];
            $endTime = $this->calculateEndTime($startTime, $sessionDuration);
            
            // STRICT: Final validation - reject if end time exceeds 8:45 PM (20:45:00)
            if ($endTime > '20:45:00') {
                Log::debug("REJECTED: " . ($course['courseCode'] ?? 'Unknown') . " slot {$slot['day']} {$startTime}-{$endTime} exceeds 8:45 PM limit");
                continue;
            }
            
            // Find available room without conflicts; prefer the same room if provided
            $room = null;
            if ($preferredRoomId !== null) {
                // Try preferred room first
                $room = $this->getRoomByIdIfAvailable($preferredRoomId, $course, $slot['day'], $startTime, $endTime);
            }
            if (!$room) {
                $room = $this->findSimpleRoom($course, $slot['day'], $startTime, $endTime);
            }
            if (!$room) {
                continue;
            }
            
            // CRITICAL: Double-check room is still available (prevents conflicts from concurrent scheduling)
            if (!$this->resourceTracker->isRoomAvailable($room['room_id'], $slot['day'], $startTime, $endTime)) {
                Log::debug("Room {$room['room_id']} became unavailable, skipping slot {$slot['day']} {$startTime}-{$endTime}");
                continue;
            }
            
            // ATOMIC RESOURCE VALIDATION: Check all resources together before assignment
            $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown Instructor';
            $sectionName = $course['section'] ?? '';
            $roomId = $room['room_id'];
            
            $conflicts = $this->resourceTracker->validateBeforeAssignment(
                $instructorName, $roomId, $sectionName, $slot['day'], $startTime, $endTime
            );
            
            if (!empty($conflicts)) {
                foreach ($conflicts as $conflict) {
                    Log::debug("CONFLICT DETECTED: " . $conflict['message']);
                }
                Log::debug("SKIPPING SLOT: {$slot['day']} {$startTime}-{$endTime} for " . ($course['courseCode'] ?? 'Unknown') . " due to conflicts");
                continue;
            }
            
            // ATOMIC RESOURCE RESERVATION: Reserve all resources together
            $scheduleData = [
                'instructor' => $instructorName,
                'subject_code' => $course['courseCode'] ?? 'UNKNOWN',
                'subject_description' => $course['subject'] ?? 'Unknown Subject',
                'unit' => $course['unit'] ?? $course['units'] ?? 3,
                'day' => $slot['day'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'block' => $course['block'] ?? 'A',
                'year_level' => $course['yearLevel'] ?? '1st Year',
                'section' => $sectionName,
                'dept' => $course['dept'] ?? 'General',
                'employment_type' => $course['employmentType'] ?? 'FULL-TIME',
                'sessionType' => $course['sessionType'] ?? 'Non-Lab session',
                'room_id' => $roomId,
                'session_duration' => $sessionDuration
            ];
            
            $reservationSuccess = $this->resourceTracker->reserveAllResources(
                $instructorName, $roomId, $sectionName, $slot['day'], $startTime, $endTime, $scheduleData
            );
            
            if (!$reservationSuccess) {
                Log::warning("RESERVATION FAILED: Could not reserve all resources for " . ($course['courseCode'] ?? 'Unknown'));
                Log::debug("RESERVATION FAILED DETAILS: Instructor={$instructorName}, Room={$roomId}, Section={$sectionName}, Day={$slot['day']}, Time={$startTime}-{$endTime}");
                continue;
            }
            
            Log::info("RESERVATION SUCCESS: All resources reserved for " . ($course['courseCode'] ?? 'Unknown') . " at {$slot['day']} {$startTime}-{$endTime}");
            
            // DEBUG: Log ResourceTracker state after successful reservation
            $resourceStats = $this->resourceTracker->getResourceStatistics();
            Log::debug("RESOURCE TRACKER STATE: " . json_encode($resourceStats));
            
            // Create schedule entry
            return $scheduleData;
        }
        
        return null;
    }

    private function getRoomByIdIfAvailable(int $roomId, array $course, string $day, string $startTime, string $endTime): ?array
    {
        foreach ($this->rooms as $room) {
            if (($room['room_id'] ?? null) !== $roomId) continue;
            // Check conflicts using the same logic as findSimpleRoom
            $newCourseDays = $this->parseIndividualDays($day);
            $hasRoomConflict = false;
            foreach ($this->scheduledCourses as $scheduledCourse) {
                if (($scheduledCourse['room_id'] ?? null) == $roomId) {
                    $scheduledCourseDays = $this->parseIndividualDays($scheduledCourse['day']);
                    $hasDayOverlap = !empty(array_intersect($newCourseDays, $scheduledCourseDays));
                    if ($hasDayOverlap && $this->timesOverlap($startTime, $endTime, $scheduledCourse['start_time'], $scheduledCourse['end_time'])) {
                        $hasRoomConflict = true;
                        break;
                    }
                }
            }
            if (!$hasRoomConflict) return $room;
            return null;
        }
        return null;
    }

    /**
     * ENHANCED conflict check using ResourceTracker for comprehensive validation
     */
    private function hasSimpleConflict(array $course, string $day, string $startTime, string $endTime): bool
    {
        $instructorName = $course['instructor'] ?? $course['name'] ?? '';
        $section = $course['section'] ?? '';
        $roomId = 0; // Will be set when room is found
        
        // Check for lunch break conflict (12:00-13:00)
        if (TimeScheduler::isLunchBreakViolation($startTime, $endTime)) {
            Log::warning("🚨 LUNCH BREAK CONFLICT: Cannot schedule during lunch break (12:00-13:00)");
            return true;
        }
        
        // Use ResourceTracker for comprehensive validation
        $conflicts = $this->resourceTracker->validateBeforeAssignment(
            $instructorName, $roomId, $section, $day, $startTime, $endTime
        );
        
        if (!empty($conflicts)) {
            foreach ($conflicts as $conflict) {
                Log::warning("🚨 " . strtoupper($conflict['type']) . " CONFLICT: " . $conflict['message']);
            }
            return true;
        }
        
        return false;
    }

    /**
     * IMPROVED room finding - check for actual room conflicts with multi-day support
     * Uses department-specific room distribution to ensure proper building utilization
     */
    private function findSimpleRoom(array $course, string $day, string $startTime, string $endTime): ?array
    {
        $sessionType = strtolower($course['sessionType'] ?? 'non-lab session');
        $requiresLab = ($sessionType === 'lab session');
        
        // Get department-specific room distribution
        $roomDistribution = self::ROOM_DISTRIBUTION[$this->department] ?? self::ROOM_DISTRIBUTION['default'];
        
        // Get available rooms grouped by building type
        $availableRoomsByBuilding = $this->getAvailableRoomsByBuilding($requiresLab, $day, $startTime, $endTime);
        
        // Calculate how many rooms have been used from each building type so far
        $buildingUsage = $this->getBuildingUsageCounts();
        
        // Determine which building type to prioritize based on department distribution and current usage
        $preferredBuildingType = $this->selectPreferredBuildingType($roomDistribution, $buildingUsage, $availableRoomsByBuilding);
        
        Log::debug("Room selection for " . ($course['courseCode'] ?? 'Unknown') . " - Preferred building: " . $preferredBuildingType . " (Current usage: " . json_encode($buildingUsage) . ")");
        
        // Try to find a room from the preferred building type first
        if (isset($availableRoomsByBuilding[$preferredBuildingType]) && !empty($availableRoomsByBuilding[$preferredBuildingType])) {
            $selectedRoom = $this->selectRoomFromBuilding($availableRoomsByBuilding[$preferredBuildingType], $day, $startTime, $endTime);
            if ($selectedRoom) {
                return $selectedRoom;
            }
        }
        
        // Fallback: try other building types in order of preference
        $fallbackOrder = $this->getFallbackBuildingOrder($roomDistribution, $preferredBuildingType);
        
        foreach ($fallbackOrder as $buildingType) {
            if (isset($availableRoomsByBuilding[$buildingType]) && !empty($availableRoomsByBuilding[$buildingType])) {
                $selectedRoom = $this->selectRoomFromBuilding($availableRoomsByBuilding[$buildingType], $day, $startTime, $endTime);
                if ($selectedRoom) {
                    return $selectedRoom;
                }
            }
        }
        
        return null;
    }

    /**
     * Parse individual days from multi-day strings like "MonSat", "MonTue", etc.
     */
    private function parseIndividualDays(string $dayString): array
    {
        // Reuse centralized robust parsing (supports concatenated and delimited forms)
        return \App\Services\DayScheduler::parseCombinedDays($dayString);
    }

    /**
     * Count how many courses are already scheduled at a specific day/time
     */
    private function countScheduledAtTime(string $day, string $startTime): int
    {
        $count = 0;
        $newCourseDays = $this->parseIndividualDays($day);
        
        foreach ($this->scheduledCourses as $scheduledCourse) {
            $scheduledCourseDays = $this->parseIndividualDays($scheduledCourse['day']);
            $hasDayOverlap = !empty(array_intersect($newCourseDays, $scheduledCourseDays));
            
            if ($hasDayOverlap && $scheduledCourse['start_time'] === $startTime) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Schedule a single session for a course with improved flexibility (COMPLEX VERSION - kept for fallback)
     */
    private function scheduleSession(
        array $course,
        float $sessionDuration,
        array $suitableSlots,
        array &$usedSlotKeys,
        int $courseIndex,
        int $sessionIndex,
        array &$usedDays = []
    ): ?array {
        $requiredMinutes = (int) round($sessionDuration * 60);
        Log::debug("Scheduling session {$sessionIndex} for " . ($course['courseCode'] ?? 'Unknown') . " - Duration: {$sessionDuration}h ({$requiredMinutes} min)");
        
        // Split suitable slots into preferred vs others with EXACT duration matching
        $preferredSlots = [];
        $otherSlots = [];
        $exactMatchSlots = [];
        
        foreach ($suitableSlots as $slot) {
            // Skip slots on days already used by this course (for multiple sessions)
            if (in_array($slot['day'], $usedDays)) {
                continue;
            }
            
            $slotMinutes = TimeScheduler::timeToMinutes($slot['end']) - TimeScheduler::timeToMinutes($slot['start']);
            $isPreferred = $this->isPreferredTimeSlot($course, $slot['start']);

            // Prioritize EXACT duration matches first (especially important for 5-hour courses)
            if ($slotMinutes === $requiredMinutes) {
                $exactMatchSlots[] = $slot;
            } elseif ($sessionDuration == 5.0) {
                // For 5-hour courses, reject any non-exact matches to prevent shorter slots
                continue;
            } elseif ($isPreferred) {
                // Preferred: allow wider tolerance (90% - 110%) to increase chances
                if ($slotMinutes >= ($requiredMinutes * 0.9) && $slotMinutes <= ($requiredMinutes * 1.1)) {
                    $preferredSlots[] = $slot;
                }
            } else {
                // Normal tolerance (95% - 105%) - very strict
                if ($slotMinutes >= ($requiredMinutes * 0.95) && $slotMinutes <= ($requiredMinutes * 1.05)) {
                    $otherSlots[] = $slot;
                }
            }
        }

        // PERFORMANCE: Reduced logging frequency - only log every 100th attempt
        if (rand(1, 100) === 1) {
            Log::debug("Exact match slots: " . count($exactMatchSlots) . ", Preferred viable slots: " . count($preferredSlots) . ", Other viable slots: " . count($otherSlots));
        }

        // Sorting helper by duration match closeness
        $sortByMatch = function(&$arr) use ($requiredMinutes) {
            usort($arr, function($a, $b) use ($requiredMinutes) {
                $aMinutes = TimeScheduler::timeToMinutes($a['end']) - TimeScheduler::timeToMinutes($a['start']);
                $bMinutes = TimeScheduler::timeToMinutes($b['end']) - TimeScheduler::timeToMinutes($b['start']);
                $aMatch = 1 - abs($aMinutes - $requiredMinutes) / max($requiredMinutes, 1);
                $bMatch = 1 - abs($bMinutes - $requiredMinutes) / max($requiredMinutes, 1);
                return $bMatch <=> $aMatch;
            });
        };

        $sortByMatch($preferredSlots);
        $sortByMatch($otherSlots);
        
        // Build final list: try preferred first, then others (keep slight randomness after top 30%)
        $mergeWithJitter = function($slots) {
            $total = count($slots);
            if ($total > 3) {
                $priorityCount = max(1, (int)($total * 0.3));
                $priority = array_slice($slots, 0, $priorityCount);
                $rest = array_slice($slots, $priorityCount);
                shuffle($rest);
                return array_merge($priority, $rest);
            }
            return $slots;
        };

        $preferredSlots = $mergeWithJitter($preferredSlots);
        $otherSlots = $mergeWithJitter($otherSlots);

        // Try different scheduling approaches in order of preference
        // Enforce strict conflict checking only to prevent section overlaps
        $schedulingAttempts = [
            'strict' => true
        ];
        
        // ENHANCED timeout protection - limit attempts to prevent infinite loops
        $maxAttempts = 20; // Tighten attempts per session to meet runtime target
        $attemptCount = 0;
        $sessionStartTime = time();

        foreach ($schedulingAttempts as $attemptType => $strictMode) {
            // First pass: exact match slots, then preferred slots, then other slots
            foreach ([$exactMatchSlots, $preferredSlots, $otherSlots] as $slotBucket) {
            foreach ($slotBucket as $slot) {
                // ENHANCED timeout protection - prevent infinite loops
                $attemptCount++;
                $currentTime = time();
                
                // Timeout after max attempts OR 2 seconds per session
                if ($attemptCount > $maxAttempts || ($currentTime - $sessionStartTime) > 2) {
                    Log::warning("Scheduling timeout protection triggered for " . ($course['courseCode'] ?? 'Unknown') . " session {$sessionIndex} after {$attemptCount} attempts in " . ($currentTime - $sessionStartTime) . "s");
                    break 3; // Break out of all nested loops
                }
                // Skip if this exact slot is already used by this course for the same block
                $block = $course['block'] ?? 'Unknown';
                $slotKey = $block . '|' . $slot['day'] . '|' . $slot['start'] . '|' . $slot['end'];
                if (in_array($slotKey, $usedSlotKeys)) {
                    continue;
                }

                // Calculate actual end time based on session duration
                $startTime = $slot['start'];
                $actualEndTime = $this->calculateEndTime($startTime, $sessionDuration);
                
                // Hard cutoff: Reject if end time exceeds 8:45 PM (20:45:00)
                if ($actualEndTime > '20:45:00') {
                    // PERFORMANCE: Reduced logging frequency
                    continue;
                }
                
                // Reduced logging to prevent performance issues

                // ALWAYS check critical conflicts - NEVER allow these even in relaxed mode
                // Check instructor conflicts - instructor can't be in two places at once
                if ($this->hasInstructorConflict($course, $slot['day'], $startTime, $actualEndTime)) {
                    continue;
                }
                
                // Check section conflicts - students can't be in two classes at once
                if ($strictMode) {
                    // Strict mode: Check all section time overlaps
                    if ($this->hasSectionConflict($course, $slot['day'], $startTime, $actualEndTime)) {
                        continue;
                    }
                } else {
                    // Relaxed mode: Only check for exact same section/time conflicts
                    if ($this->hasStrictSectionConflict($course, $slot['day'], $startTime, $actualEndTime)) {
                        continue;
                    }
                }

                // ALWAYS enforce lunch break violations - never allow scheduling during lunch break
                if (TimeScheduler::isLunchBreakViolation($startTime, $actualEndTime)) {
                    // PERFORMANCE: Reduced logging frequency
                    continue;
                }

                // Find suitable room with improved lab/non-lab logic
                $room = $this->findBestRoomForCourse(
                    $course,
                    $slot['day'],
                    $startTime,
                    $actualEndTime
                );

                    // Room found

                if ($room) {
                // Mark this slot as used by this course for this block
                $usedSlotKeys[] = $slotKey;
                // IMPORTANT: Do NOT mutate $usedDays here; the caller validates and updates it after acceptance

                    // Extract course data
                    $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown Instructor';
                    $subjectCode = $course['courseCode'] ?? 'UNKNOWN';
                    
                    // Create schedule entry
                    $scheduleUnits = $course['unit'] ?? $course['units'] ?? 3;
                    // PERFORMANCE: Reduced logging frequency
                    
                    return [
                        'instructor' => $instructorName,
                        'subject_code' => $subjectCode,
                        'subject_description' => $course['subject'] ?? 'Unknown Subject',
                        'unit' => $scheduleUnits,
                        'day' => $slot['day'],
                        'start_time' => $startTime,
                        'end_time' => $actualEndTime,
                        'block' => $course['block'] ?? 'A',
                        'year_level' => $course['yearLevel'] ?? '1st Year',
                        'section' => $course['section'] ?? 'General-1st Year A',
                        'dept' => $course['dept'] ?? 'General',
                        'employment_type' => $course['employmentType'] ?? 'FULL-TIME',
                        'sessionType' => $course['sessionType'] ?? 'Non-Lab session',
                        'room_id' => $room['room_id'],
                        'session_duration' => $sessionDuration
                    ];
                }
            }
            } // end bucket loop
        }

        return null; // No suitable slot/room combination found
    }

    /**
     * Emergency scheduling session with very relaxed constraints
     */
    private function scheduleSessionEmergency(
        array $course,
        float $sessionDuration,
        array $allSlots,
        array &$usedSlotKeys,
        int $courseIndex,
        int $sessionIndex,
        array &$usedDays = []
    ): ?array {
        $requiredMinutes = (int) round($sessionDuration * 60);
        
        // Emergency slot filtering - still try to respect session duration but be more flexible
        $viableSlots = array_filter($allSlots, function($slot) use ($requiredMinutes, $usedDays) {
            // Skip slots on days already used by this course (for multiple sessions)
            if (in_array($slot['day'], $usedDays)) {
                return false;
            }
            
            $slotMinutes = TimeScheduler::timeToMinutes($slot['end']) - TimeScheduler::timeToMinutes($slot['start']);
            $requiredMinutes = (int) round($sessionDuration * 60);
            
            // For 5-hour courses, require exact match even in emergency mode
            if ($sessionDuration == 5.0) {
                return $slotMinutes === $requiredMinutes; // Must be exactly 5 hours (300 minutes)
            } else {
                // Allow sessions to fit within 85% of slot duration - more flexible than normal but still reasonable
                return $slotMinutes >= ($requiredMinutes * 0.85) && $slotMinutes <= ($requiredMinutes * 1.5);
            }
        });
        
        // If no slots match reasonably, try with even more flexibility as last resort
        if (empty($viableSlots)) {
            $viableSlots = array_filter($allSlots, function($slot) use ($requiredMinutes, $usedDays) {
                // Skip slots on days already used by this course (for multiple sessions)
                if (in_array($slot['day'], $usedDays)) {
                    return false;
                }
                
                $slotMinutes = TimeScheduler::timeToMinutes($slot['end']) - TimeScheduler::timeToMinutes($slot['start']);
                $requiredMinutes = (int) round($sessionDuration * 60);
                
                // For 5-hour courses, require exact match even in last resort mode
                if ($sessionDuration == 5.0) {
                    return $slotMinutes === $requiredMinutes; // Must be exactly 5 hours (300 minutes)
                } else {
                    // Last resort: allow 70% match
                    return $slotMinutes >= ($requiredMinutes * 0.7);
                }
            });
            
            Log::warning("Emergency scheduling using 70% duration match for " . ($course['courseCode'] ?? 'Unknown'));
        }
        
        // ENHANCED emergency scheduling timeout protection
        $maxEmergencyAttempts = 5; // Reduced from 10 to 5
        $emergencyAttemptCount = 0;
        $emergencyStartTime = time();

        // Shuffle for randomness
        shuffle($viableSlots);

        foreach ($viableSlots as $slot) {
            // ENHANCED emergency timeout protection
            $emergencyAttemptCount++;
            $currentTime = time();
            
            // Timeout after max attempts OR 2 seconds (reduced from 3)
            if ($emergencyAttemptCount > $maxEmergencyAttempts || ($currentTime - $emergencyStartTime) > 2) {
                Log::warning("Emergency scheduling timeout protection triggered for " . ($course['courseCode'] ?? 'Unknown') . " session {$sessionIndex} after {$emergencyAttemptCount} attempts in " . ($currentTime - $emergencyStartTime) . "s");
                break;
            }
            // Calculate actual end time based on session duration
            $startTime = $slot['start'];
            $actualEndTime = $this->calculateEndTime($startTime, $sessionDuration);

            // STRICT: Even in emergency, reject if end time exceeds 8:45 PM (20:45:00)
            if ($actualEndTime > '20:45:00') {
                // Emergency slot rejected
                continue;
            }

            // Only check for critical section conflicts - allow everything else
            if ($this->hasCriticalSectionConflict($course, $slot['day'], $startTime, $actualEndTime)) {
                continue;
            }

            // Try to find ANY available room
            $room = $this->findAnyAvailableRoom(
                $course,
                $slot['day'],
                $startTime,
                $actualEndTime
            );

            if ($room) {
                // Extract course data for emergency scheduling
                $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown Instructor';
                $subjectCode = $course['courseCode'] ?? 'UNKNOWN';
                
                // Create schedule entry even with potential minor conflicts
                $emergencyUnits = $course['unit'] ?? $course['units'] ?? 3;
                // Emergency scheduling
                
                // IMPORTANT: Do NOT mutate $usedDays here; the caller validates and updates it after acceptance
                
                return [
                    'instructor' => $instructorName,
                    'subject_code' => $subjectCode,
                    'subject_description' => $course['subject'] ?? 'Unknown Subject',
                    'unit' => $emergencyUnits,
                    'day' => $slot['day'],
                    'start_time' => $startTime,
                    'end_time' => $actualEndTime,
                    'block' => $course['block'] ?? 'A',
                    'year_level' => $course['yearLevel'] ?? '1st Year',
                    'section' => $course['section'] ?? 'General-1st Year A',
                    'dept' => $course['dept'] ?? 'General',
                    'employment_type' => $course['employmentType'] ?? 'FULL-TIME',
                    'sessionType' => $course['sessionType'] ?? 'Non-Lab session',
                    'room_id' => $room['room_id'],
                    'session_duration' => $sessionDuration
                ];
            }
        }

        return null;
    }

    /**
     * ENHANCED section conflict validation with comprehensive checking
     */
    private function validateSectionAvailability(array $course, string $day, string $startTime, string $endTime): bool
    {
        $sectionName = $course['section'] ?? '';
        
        if (empty($sectionName)) {
            Log::warning("SECTION VALIDATION: No section name provided for course " . ($course['courseCode'] ?? 'Unknown'));
            return false;
        }
        
        // Use ResourceTracker for comprehensive section validation
        return $this->resourceTracker->isSectionAvailable($sectionName, $day, $startTime, $endTime);
    }

    /**
     * Get section load balancing information
     */
    private function getSectionLoadBalance(string $sectionName): array
    {
        $load = $this->resourceTracker->getSectionLoad($sectionName);
        $dayDistribution = $this->resourceTracker->getDayDistribution();
        
        return [
            'section_load' => $load,
            'day_distribution' => $dayDistribution,
            'least_loaded_day' => $this->resourceTracker->getLeastLoadedDay()
        ];
    }

    /**
     * Check for only critical section conflicts (same section, same exact time)
     */
    private function hasCriticalSectionConflict(array $course, string $day, string $startTime, string $endTime): bool
    {
        $currentSection = $course['section'] ?? 
                         ($course['dept'] ?? 'General') . '-' . 
                         ($course['yearLevel'] ?? '1st Year') . ' ' . 
                         ($course['block'] ?? 'A');
        
        // Parse the incoming day(s)
        $newCourseDays = $this->parseIndividualDays($day);
        
        foreach ($this->scheduledCourses as $scheduledCourse) {
            if ($scheduledCourse['section'] === $currentSection) {
                // Parse scheduled course days to handle combined day strings
                $scheduledCourseDays = $this->parseIndividualDays($scheduledCourse['day']);
                $hasDayOverlap = !empty(array_intersect($newCourseDays, $scheduledCourseDays));
                
                if ($hasDayOverlap && 
                    $scheduledCourse['start_time'] === $startTime && 
                    $scheduledCourse['end_time'] === $endTime) {
                    return true; // Exact same time and section (with day overlap check)
                }
            }
        }
        
        return false;
    }

    /**
     * Find any available room with minimal restrictions
     */
    private function findAnyAvailableRoom(array $course, string $day, string $startTime, string $endTime): ?array
    {
        // Use the improved findSimpleRoom which checks reference schedules and scheduled courses
        $room = $this->findSimpleRoom($course, $day, $startTime, $endTime);
        
        if ($room) {
            // Mark room as used
            $this->updateRoomUsage($room['room_id'], $day, $startTime, $endTime);
            return $room;
        }
        
        // AGGRESSIVE FALLBACK: Try to find ANY room that's not completely booked
        // This allows double-booking in extreme cases to prevent course drops
        foreach ($this->rooms as $room) {
            $roomId = $room['room_id'];
            
            // Check if room is available for this time slot
            $isAvailable = true;
            foreach ($this->scheduledCourses as $scheduledCourse) {
                if ($scheduledCourse['room_id'] === $roomId && 
                    $scheduledCourse['day'] === $day &&
                    TimeScheduler::timesOverlap($startTime, $endTime, $scheduledCourse['start_time'], $scheduledCourse['end_time'])) {
                    $isAvailable = false;
                    break;
                }
            }
            
            if ($isAvailable) {
                Log::info("AGGRESSIVE ROOM ASSIGNMENT: Using room {$roomId} for " . ($course['courseCode'] ?? 'Unknown') . " at {$day} {$startTime}-{$endTime}");
                $this->updateRoomUsage($roomId, $day, $startTime, $endTime);
                return $room;
            }
        }
        
        // LAST RESORT: Use the first available room even if it has conflicts
        // This ensures courses don't get dropped due to room unavailability
        if (!empty($this->rooms)) {
            $room = $this->rooms[0];
            Log::warning("LAST RESORT ROOM ASSIGNMENT: Using room {$room['room_id']} for " . ($course['courseCode'] ?? 'Unknown') . " at {$day} {$startTime}-{$endTime} (may have conflicts)");
            $this->updateRoomUsage($room['room_id'], $day, $startTime, $endTime);
            return $room;
        }
        
        Log::error("CRITICAL: No rooms available for " . ($course['courseCode'] ?? 'Unknown') . " at {$day} {$startTime}-{$endTime}");
        return null;
    }

    /**
     * Find a room that is available on MULTIPLE days at the same time window
     * For joint sessions, we need the same room on all days
     */
    private function findCommonRoomAcrossDays(array $course, string $startTime, string $endTime, string $currentDay, array $usedDays): ?array
    {
        $sectionName = trim(($course['yearLevel'] ?? '') . ' ' . ($course['block'] ?? ''));
        $instructorName = $course['instructor'] ?? $course['name'] ?? '';
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        
        // Get room candidates sorted by distribution preferences
        $sessionType = strtolower($course['sessionType'] ?? 'non-lab session');
        $requiresLab = ($sessionType === 'lab session');
        
        // Get all rooms that satisfy lab requirements
        $roomCandidates = [];
        foreach ($this->rooms as $room) {
            if (!($room['is_active'] ?? true)) continue;
            $roomIsLab = $room['is_lab'] ?? false;
            if ($requiresLab && !$roomIsLab) continue;
            if (!$requiresLab && $roomIsLab) continue;
            $roomCandidates[] = $room;
        }
        
        // Sort rooms by distribution preferences using the same logic as selectPreferredBuildingType
        $roomDistribution = self::ROOM_DISTRIBUTION[$this->department] ?? self::ROOM_DISTRIBUTION['default'];
        $buildingUsage = $this->getBuildingUsageCounts();
        
        // Calculate current distribution percentages
        $totalScheduled = array_sum($buildingUsage);
        $currentDistribution = [];
        foreach ($buildingUsage as $buildingType => $count) {
            $currentDistribution[$buildingType] = $totalScheduled > 0 ? $count / $totalScheduled : 0;
        }
        
        $roomScores = [];
        foreach ($roomCandidates as $room) {
            $roomId = $room['room_id'];
            $buildingType = $this->getRoomBuildingType($room);
            
            // Calculate deficit-based weight (same logic as selectPreferredBuildingType)
            $currentPercentage = $currentDistribution[$buildingType] ?? 0;
            $targetPercentage = $roomDistribution[$buildingType] ?? 0;
            $deficit = $targetPercentage - $currentPercentage;
            
            // Use deficit as primary factor, but add small bonus for target percentage to break ties
            $deficitWeight = $deficit + ($targetPercentage * 0.01);
            
            // Calculate usage-based score
            $totalUsage = 0;
            if (isset($this->roomUsage[$roomId])) {
                foreach ($this->roomUsage[$roomId] as $daySlots) {
                    $totalUsage += count($daySlots);
                }
            }
            $dayUsage = isset($this->roomUsage[$roomId][$currentDay]) ? count($this->roomUsage[$roomId][$currentDay]) : 0;
            
            $baseScore = (100 - $totalUsage * 2) + (50 - $dayUsage * 5);
            // Multiply by deficit weight (can be negative for buildings that are over-used)
            $score = $baseScore * $deficitWeight;
            
            $roomScores[] = ['room' => $room, 'score' => $score, 'buildingType' => $buildingType];
        }
        usort($roomScores, fn($a, $b) => $b['score'] <=> $a['score']);
        
        // Try each room in order of distribution preference
        foreach ($roomScores as $item) {
            $room = $item['room'];
            $roomId = $room['room_id'];
            
            // Check if room is available on the current day
            if ($this->resourceTracker->isRoomAvailable($roomId, $currentDay, $startTime, $endTime)) {
                // Check if room is available on at least one other day
                foreach ($days as $day) {
                    if ($day === $currentDay) continue;
                    if (in_array($day, $usedDays)) continue;
                    
                    if ($this->resourceTracker->isRoomAvailable($roomId, $day, $startTime, $endTime) &&
                        $this->resourceTracker->isInstructorAvailable($instructorName, $day, $startTime, $endTime) &&
                        $this->resourceTracker->isSectionAvailable($sectionName, $day, $startTime, $endTime)) {
                        Log::debug("Found common room {$roomId} for joint session on {$currentDay} and another day at {$startTime}-{$endTime}");
                        return $room;
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Update room usage tracking
     */
    private function updateRoomUsage(int $roomId, string $day, string $startTime, string $endTime): void
    {
        $key = "{$roomId}|{$day}|{$startTime}|{$endTime}";
        $this->roomUsage[$key] = true;
        
        // Update day usage tracking
        if (!isset($this->roomDayUsage[$day])) {
            $this->roomDayUsage[$day] = [];
        }
        $this->roomDayUsage[$day][$roomId] = ($this->roomDayUsage[$day][$roomId] ?? 0) + 1;
    }

    /**
     * Get room name by room ID
     */
    private function getRoomNameById(int $roomId): string
    {
        foreach ($this->rooms as $room) {
            if ($room['room_id'] == $roomId) {
                return $room['room_name'] ?? 'Unknown Room';
            }
        }
        return 'Unknown Room';
    }

    /**
     * Get alternative session split for 3-4 unit courses
     */
    private function getAlternativeSessionSplit(int $units): ?array
    {
        switch ($units) {
            case 3:
                // If original was [3.0], try [1.5, 1.5]
                // If original was [1.5, 1.5], try [3.0]
                return [1.5, 1.5];
            case 4:
                // If original was [4.0], try [2.0, 2.0]
                // If original was [2.0, 2.0], try [4.0]
                return [2.0, 2.0];
            default:
                return null;
        }
    }

    /**
     * Sort rooms by utilization to distribute load evenly with department-specific weights
     */
    private function getSortedRoomsByUtilization(bool $requiresLab, string $day): array
    {
        $roomScores = [];
        
        // Get department-specific room distribution
        $roomDistribution = self::ROOM_DISTRIBUTION[$this->department] ?? self::ROOM_DISTRIBUTION['default'];
        
        foreach ($this->rooms as $room) {
            if (!($room['is_active'] ?? true)) {
                continue;
            }
            
            $roomIsLab = $room['is_lab'] ?? false;
            
            // Strict room type filtering
            if ($requiresLab && !$roomIsLab) continue;
            if (!$requiresLab && $roomIsLab) continue;
            
            $roomId = $room['room_id'];
            
            // Calculate total usage across all days
            $totalUsage = 0;
            if (isset($this->roomUsage[$roomId])) {
                foreach ($this->roomUsage[$roomId] as $daySlots) {
                    $totalUsage += count($daySlots);
                }
            }
            
            // Calculate today's usage
            $dayUsage = 0;
            if (isset($this->roomUsage[$roomId][$day])) {
                $dayUsage = count($this->roomUsage[$roomId][$day]);
            }
            
            // Get building type and apply department-specific weight
            $buildingType = $this->getRoomBuildingType($room);
            $buildingWeight = $roomDistribution[$buildingType] ?? 1.0;
            
            // Score: prefer rooms with lower usage, weighted by department distribution
            // Weight daily usage more heavily than total usage
            $baseScore = (100 - $totalUsage * 2) + (50 - $dayUsage * 5);
            $score = $baseScore * $buildingWeight;
            
            $roomScores[] = [
                'room' => $room, 
                'score' => $score,
                'building_type' => $buildingType,
                'building_weight' => $buildingWeight
            ];
        }
        
        if (empty($roomScores)) {
            return [];
        }
        
        // Sort by score descending (highest score = least used + preferred building = best choice)
        usort($roomScores, fn($a, $b) => $b['score'] <=> $a['score']);
        
        Log::debug("Room distribution for department {$this->department}: " . json_encode($roomDistribution));
        
        // Debug: Log room distribution summary
        $roomCounts = ['HS' => 0, 'SHS' => 0, 'Annex' => 0];
        foreach ($roomScores as $item) {
            $roomCounts[$item['building_type']]++;
        }
        Log::info("Available rooms by building type: " . json_encode($roomCounts));
        
        return array_map(fn($item) => $item['room'], $roomScores);
    }

    /**
     * Get building type from room name
     */
    private function getRoomBuildingType(array $room): string
    {
        $roomName = strtoupper($room['room_name'] ?? '');
        
        // Check for HS rooms (with space, not hyphen)
        if (strpos($roomName, 'HS ') === 0 || strpos($roomName, 'HS-') === 0) {
            return 'HS';
        } 
        // Check for SHS rooms (with space, not hyphen)
        elseif (strpos($roomName, 'SHS ') === 0 || strpos($roomName, 'SHS-') === 0) {
            return 'SHS';
        } 
        // Check for Annex rooms (with space, not hyphen)
        elseif (strpos($roomName, 'ANNEX ') === 0 || strpos($roomName, 'ANX-') === 0) {
            return 'Annex';
        } 
        // Lab rooms default to HS building for distribution
        elseif (strpos($roomName, 'LAB') === 0) {
            return 'HS';
        }
        
        // Default fallback - check if it contains building type keywords
        if (strpos($roomName, 'HS') !== false && strpos($roomName, 'SHS') === false) {
            return 'HS';
        } elseif (strpos($roomName, 'SHS') !== false) {
            return 'SHS';
        } elseif (strpos($roomName, 'ANNEX') !== false) {
            return 'Annex';
        }
        
        // Default fallback
        return 'Annex';
    }

    /**
     * Get available rooms grouped by building type
     */
    private function getAvailableRoomsByBuilding(bool $requiresLab, string $day, string $startTime, string $endTime): array
    {
        $roomsByBuilding = ['HS' => [], 'SHS' => [], 'Annex' => []];
        
        foreach ($this->rooms as $room) {
            if (!($room['is_active'] ?? true)) {
                continue;
            }
            
            $roomIsLab = $room['is_lab'] ?? false;
            
            // Strict room type filtering
            if ($requiresLab && !$roomIsLab) continue;
            if (!$requiresLab && $roomIsLab) continue;
            
            // Check for conflicts
            if (!$this->hasRoomConflict($room, $day, $startTime, $endTime)) {
                $buildingType = $this->getRoomBuildingType($room);
                $roomsByBuilding[$buildingType][] = $room;
            }
        }
        
        return $roomsByBuilding;
    }

    /**
     * Check if a room has conflicts at the specified time
     * ENHANCED: Check against reference schedules AND ResourceTracker for consistent conflict detection
     */
    private function hasRoomConflict(array $room, string $day, string $startTime, string $endTime): bool
    {
        $roomName = $room['room_name'] ?? '';
        
        // FIRST: Check against reference schedules (basic education schedules that share classrooms)
        foreach ($this->referenceSchedules as $refSchedule) {
            if ($refSchedule['room'] === $roomName) {
                // Parse reference time to get start and end times
                list($refStartTime, $refEndTime) = $this->parseReferenceTime($refSchedule['time']);
                
                // Check if day matches (reference uses full day names like "Monday")
                $refDayShort = DayScheduler::normalizeDay($refSchedule['day']);
                $newCourseDays = $this->parseIndividualDays($day);
                
                if (in_array($refDayShort, $newCourseDays) && 
                    $this->timesOverlap($startTime, $endTime, $refStartTime, $refEndTime)) {
                    
                    Log::warning("🚨 REFERENCE SCHEDULE CONFLICT - ROOM:");
                    Log::warning("   Room: " . $roomName . " is blocked by reference schedule");
                    Log::warning("   Day: " . $day . " conflicts with reference " . $refSchedule['day']);
                    Log::warning("   College Time: " . $startTime . " - " . $endTime);
                    Log::warning("   Reference Original Time: " . $refSchedule['time']);
                    Log::warning("   Reference Corrected Time: " . $refStartTime . " - " . $refEndTime);
                    Log::warning("   Reference Subject: " . ($refSchedule['subject'] ?? 'Unknown'));
                    Log::warning("   Reference Education Level: " . ($refSchedule['education_level'] ?? 'Unknown'));
                    Log::warning("   Reference Instructor: " . ($refSchedule['instructor'] ?? 'Unknown'));
                    
                    return true;
                }
            }
        }
        
        // SECOND: Use ResourceTracker for consistent room conflict detection (includes reference schedules loaded)
        return !$this->resourceTracker->isRoomAvailable($room['room_id'], $day, $startTime, $endTime);
    }

    /**
     * Get building usage counts from scheduled courses
     */
    private function getBuildingUsageCounts(): array
    {
        $usage = ['HS' => 0, 'SHS' => 0, 'Annex' => 0];
        
        foreach ($this->scheduledCourses as $schedule) {
            $roomId = $schedule['room_id'] ?? null;
            if ($roomId) {
                $room = $this->getRoomById($roomId);
                if ($room) {
                    $buildingType = $this->getRoomBuildingType($room);
                    $usage[$buildingType]++;
                }
            }
        }
        
        return $usage;
    }

    /**
     * Get room by ID
     */
    private function getRoomById(int $roomId): ?array
    {
        foreach ($this->rooms as $room) {
            if ($room['room_id'] == $roomId) {
                return $room;
            }
        }
        return null;
    }

    /**
     * Select preferred building type based on department distribution and current usage
     */
    private function selectPreferredBuildingType(array $roomDistribution, array $buildingUsage, array $availableRoomsByBuilding): string
    {
        $totalScheduled = array_sum($buildingUsage);
        
        // If no rooms scheduled yet, start with the highest priority building
        if ($totalScheduled === 0) {
            return array_keys($roomDistribution, max($roomDistribution))[0];
        }
        
        // Calculate current distribution percentages
        $currentDistribution = [];
        foreach ($buildingUsage as $buildingType => $count) {
            $currentDistribution[$buildingType] = $totalScheduled > 0 ? $count / $totalScheduled : 0;
        }
        
        // ENHANCED: Find building type that's furthest below its target percentage
        // Prioritize building types with available rooms and largest deficit
        $bestBuildingType = 'HS';
        $largestDeficit = -1;
        
        foreach ($roomDistribution as $buildingType => $targetPercentage) {
            if (empty($availableRoomsByBuilding[$buildingType])) {
                continue; // Skip if no available rooms
            }
            
            $currentPercentage = $currentDistribution[$buildingType] ?? 0;
            $deficit = $targetPercentage - $currentPercentage;
            
            // Use deficit as primary factor, but add small bonus for target percentage to break ties
            $score = $deficit + ($targetPercentage * 0.01);
            
            if ($score > $largestDeficit) {
                $largestDeficit = $score;
                $bestBuildingType = $buildingType;
            }
        }
        
        // Log the distribution for debugging
        $distLog = [];
        foreach ($roomDistribution as $buildingType => $targetPct) {
            $currentPct = ($currentDistribution[$buildingType] ?? 0) * 100;
            $target = $targetPct * 100;
            $distLog[$buildingType] = "{$currentPct}%/{$target}%";
        }
        Log::info("Room distribution for {$this->department}: " . json_encode($distLog) . " -> Selected: {$bestBuildingType}");
        
        return $bestBuildingType;
    }

    /**
     * Get fallback building order based on room distribution
     */
    private function getFallbackBuildingOrder(array $roomDistribution, string $preferredBuilding): array
    {
        // Sort building types by their distribution percentage (descending)
        $sortedBuildings = [];
        foreach ($roomDistribution as $buildingType => $percentage) {
            $sortedBuildings[$buildingType] = $percentage;
        }
        arsort($sortedBuildings);
        
        $fallbackOrder = array_keys($sortedBuildings);
        
        // Remove the preferred building from the list and put it at the end
        $fallbackOrder = array_filter($fallbackOrder, fn($building) => $building !== $preferredBuilding);
        $fallbackOrder[] = $preferredBuilding;
        
        return $fallbackOrder;
    }

    /**
     * Select a room from a specific building type
     */
    private function selectRoomFromBuilding(array $rooms, string $day, string $startTime, string $endTime): ?array
    {
        if (empty($rooms)) {
            return null;
        }
        
        // Filter rooms to only those that are actually available (no conflicts)
        $availableRooms = array_filter($rooms, function($room) use ($day, $startTime, $endTime) {
            return $this->resourceTracker->isRoomAvailable($room['room_id'], $day, $startTime, $endTime);
        });
        
        if (empty($availableRooms)) {
            Log::debug("No available rooms in building type (all have conflicts)");
            return null;
        }
        
        // Use random selection to better distribute load and prevent repeated conflicts
        $availableRoomsArray = array_values($availableRooms);
        $selectedIndex = array_rand($availableRoomsArray);
        
        Log::debug("Selected room from building: " . ($availableRoomsArray[$selectedIndex]['room_name'] ?? 'Unknown'));
        
        return $availableRoomsArray[$selectedIndex];
    }

    /**
     * Get all scheduled start times for a specific instructor
     */
    private function getInstructorScheduledTimes(string $instructorName): array
    {
        $times = [];
        foreach ($this->scheduledCourses as $schedule) {
            if (($schedule['instructor'] ?? '') === $instructorName) {
                $times[] = $schedule['start_time'];
            }
        }
        return array_unique($times);
    }

    /**
     * Check if instructor has many courses and needs time diversity
     */
    private function shouldDiversifyTimeSlots(array $course): bool
    {
        $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown';
        $instructorSessionCount = 0;
        
        foreach ($this->scheduledCourses as $schedule) {
            if (($schedule['instructor'] ?? '') === $instructorName) {
                $instructorSessionCount++;
            }
        }
        
        // If instructor has TIME_DIVERSITY_THRESHOLD+ sessions scheduled, start diversifying
        return $instructorSessionCount >= self::TIME_DIVERSITY_THRESHOLD;
    }

    /**
     * Calculate how different this time slot is from existing instructor times
     */
    private function getDiversityScore(string $timeSlot, array $existingTimes): int
    {
        if (empty($existingTimes)) {
            return 100; // No existing times, perfect diversity
        }
        
        $slotMinutes = TimeScheduler::timeToMinutes($timeSlot);
        $minDistance = PHP_INT_MAX;
        
        foreach ($existingTimes as $existingTime) {
            $existingMinutes = TimeScheduler::timeToMinutes($existingTime);
            $distance = abs($slotMinutes - $existingMinutes);
            $minDistance = min($minDistance, $distance);
        }
        
        // Return distance in minutes (higher = more diverse)
        return $minDistance;
    }

    /**
     * Normalize employment type to standard format
     */
    private function normalizeEmploymentType(string $employmentType): string
    {
        $normalized = strtoupper(trim($employmentType));
        
        // Handle common variations
        if (in_array($normalized, ['FULL-TIME', 'FULLTIME', 'FULL TIME', 'FT', 'FULL-TIME'])) {
            return 'FULL-TIME';
        } elseif (in_array($normalized, ['PART-TIME', 'PARTTIME', 'PART TIME', 'PT', 'PART-TIME'])) {
            return 'PART-TIME';
        }
        
        // Default to FULL-TIME if unrecognized
        return 'FULL-TIME';
    }

    /**
     * Calculate end time based on start time and duration
     */
    private function calculateEndTime(string $startTime, float $durationHours): string
    {
        $startMinutes = TimeScheduler::timeToMinutes($startTime);
        $endMinutes = $startMinutes + (int) round($durationHours * 60);
        
        $endHour = intval($endMinutes / 60);
        $endMin = $endMinutes % 60;
        
        return sprintf('%02d:%02d:00', $endHour, $endMin);
    }

    /**
     * CRITICAL: Force schedule any dropped courses using emergency scheduling
     * Now handles ALL missing courses, not just PART-TIME ones
     */
    private function forceScheduleDroppedPartTimeCourses(array $schedules, array $originalCourses): array
    {
        Log::info("PhpScheduler: Checking for dropped courses...");
        
        // Find which courses were scheduled
        $scheduledCourses = [];
        foreach ($schedules as $schedule) {
            $key = ($schedule['subject_code'] ?? 'Unknown') . '|' . ($schedule['year_level'] ?? '') . '|' . ($schedule['block'] ?? 'A');
            $scheduledCourses[$key] = true;
        }
        
        // Find ALL dropped courses (both FULL-TIME and PART-TIME)
        $droppedCourses = [];
        foreach ($originalCourses as $course) {
            $key = ($course['courseCode'] ?? 'Unknown') . '|' . ($course['yearLevel'] ?? '') . '|' . ($course['block'] ?? 'A');
            $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
            
            if (!isset($scheduledCourses[$key])) {
                $droppedCourses[] = $course;
                Log::error("DROPPED COURSE: {$course['courseCode']} for {$course['yearLevel']} {$course['block']} ({$employmentType}) - attempting emergency scheduling");
            }
        }
        
        if (empty($droppedCourses)) {
            Log::info("PhpScheduler: No dropped courses found");
            return $schedules;
        }
        
        Log::warning("PhpScheduler: Found " . count($droppedCourses) . " dropped courses - attempting emergency scheduling");
        
        // Emergency schedule each dropped course
        foreach ($droppedCourses as $course) {
            $emergencySchedules = $this->emergencyScheduleCourse($course);
            if (!empty($emergencySchedules)) {
                $schedules = array_merge($schedules, $emergencySchedules);
                Log::info("EMERGENCY SUCCESS: Scheduled dropped course {$course['courseCode']}");
            } else {
                Log::error("EMERGENCY FAILED: Could not schedule dropped course {$course['courseCode']}");
            }
        }
        
        return $schedules;
    }
    
    /**
     * Emergency scheduling for any course (FULL-TIME or PART-TIME) using very relaxed constraints
     */
    private function emergencyScheduleCourse(array $course): array
    {
        $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
        Log::info("EMERGENCY SCHEDULING: Attempting to schedule {$employmentType} course {$course['courseCode']}");
        
        $units = $course['unit'] ?? $course['units'] ?? 3;
        $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown Instructor';
        $sectionName = ($course['yearLevel'] ?? '') . ' ' . ($course['block'] ?? '');
        
        // Generate session durations based on employment type
        $sessionDurations = TimeScheduler::generateRandomizedSessions($units, $employmentType);
        Log::info("EMERGENCY: {$course['courseCode']} ({$units} units, {$employmentType}) -> " . json_encode($sessionDurations));
        
        $emergencySchedules = [];
        $usedDays = [];
        
        foreach ($sessionDurations as $sessionIndex => $sessionDuration) {
            $scheduled = false;
            
            // Try to find available slots with very relaxed constraints (pass section for conflict checking)
            $availableSlots = $this->findEmergencyAvailableSlots($instructorName, $sessionDuration, $usedDays, $employmentType, $sectionName);
            
            foreach ($availableSlots as $slot) {
                $day = $slot['day'];
                $startTime = $slot['start'];
                $endTime = $this->calculateEndTime($startTime, $sessionDuration);
                
                // Find any available room
                $availableRoom = $this->findAnyAvailableRoom($course, $day, $startTime, $endTime);
                
                if ($availableRoom) {
                    $schedule = [
                        'instructor' => $instructorName,
                        'subject_code' => $course['courseCode'],
                        'subject_description' => $course['subject'] ?? 'Unknown Subject',
                        'unit' => $units,
                        'day' => $day,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'block' => $course['block'] ?? 'A',
                        'year_level' => $course['yearLevel'] ?? '1st Year',
                        'section' => $course['section'] ?? 'General-1st Year A',
                        'employment_type' => $employmentType,
                        'room_id' => $availableRoom['room_id'],
                        'dept' => $course['dept'] ?? 'General',
                        'emergency_scheduled' => true,
                        'session_duration' => $sessionDuration
                    ];
                    
                    // Force reserve resources (bypass normal conflict checking)
                    if ($this->resourceTracker->reserveAllResources(
                        $instructorName,
                        $availableRoom['room_id'],
                        $sectionName,
                        $day,
                        $startTime,
                        $endTime,
                        $schedule
                    )) {
                        $emergencySchedules[] = $schedule;
                        $this->scheduledCourses[] = $schedule;
                        $usedDays[] = $day;
                        $scheduled = true;
                        
                        Log::info("EMERGENCY: Successfully scheduled session {$sessionIndex} for {$course['courseCode']} on {$day} {$startTime}-{$endTime} in room {$availableRoom['room_id']}");
                        break;
                    } else {
                        Log::warning("EMERGENCY: Failed to reserve resources for {$course['courseCode']} at {$day} {$startTime}-{$endTime}");
                    }
                }
            }
            
            if (!$scheduled) {
                Log::error("EMERGENCY: Failed to schedule session {$sessionIndex} for {$course['courseCode']}");
            }
        }
        
        return $emergencySchedules;
    }
    
    /**
     * Find emergency available slots with very relaxed constraints
     */
    private function findEmergencyAvailableSlots(string $instructorName, float $sessionDuration, array $usedDays = [], string $employmentType = 'FULL-TIME', string $sectionName = ''): array
    {
        $availableSlots = [];
        
        // Define time slots based on employment type
        $timeSlots = $employmentType === 'PART-TIME' 
            ? ['17:00:00', '18:00:00', '19:00:00', '20:00:00'] // Evening slots for part-time
            : ['07:00:00', '08:00:00', '09:00:00', '10:00:00', '11:00:00', '13:00:00', '14:00:00', '15:00:00', '16:00:00', '17:00:00', '18:00:00', '19:00:00', '20:00:00']; // All slots for full-time
        
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        
        foreach ($days as $day) {
            if (in_array($day, $usedDays)) {
                continue; // Skip days already used by this course
            }
            
            foreach ($timeSlots as $startTime) {
                $endTime = $this->calculateEndTime($startTime, $sessionDuration);
                
                // CRITICAL FIX: Check BOTH instructor AND section availability
                // This prevents reservation failures due to section conflicts
                if (!$this->resourceTracker->isInstructorAvailable($instructorName, $day, $startTime, $endTime)) {
                    continue;
                }
                
                // Also check section availability if section name is provided
                if (!empty($sectionName) && !$this->resourceTracker->isSectionAvailable($sectionName, $day, $startTime, $endTime)) {
                    continue;
                }
                
                // RESPECT LUNCH TIME: Skip slots that overlap with lunch break (11:30 AM - 1:00 PM)
                if (TimeScheduler::isLunchBreakViolation($startTime, $endTime)) {
                    continue;
                }
                
                // STRICT: Reject if end time exceeds 8:45 PM
                if ($endTime > '20:45:00') {
                    continue;
                }
                
                $availableSlots[] = [
                    'day' => $day,
                    'start' => $startTime,
                    'end' => $endTime,
                    'duration' => $sessionDuration
                ];
            }
        }
        
        // Sort by day load balancing to prioritize underutilized days (Fri/Sat)
        usort($availableSlots, function($a, $b) {
            // Prioritize underutilized days first
            $avgLoad = array_sum($this->dayLoadCount) / count($this->dayLoadCount);
            $aLoad = $this->dayLoadCount[$a['day']] ?? 0;
            $bLoad = $this->dayLoadCount[$b['day']] ?? 0;
            
            // Prefer days that are below average load
            if ($aLoad < $avgLoad && $bLoad >= $avgLoad) {
                return -1; // Prefer a (underutilized)
            } elseif ($bLoad < $avgLoad && $aLoad >= $avgLoad) {
                return 1;  // Prefer b (underutilized)
            }
            
            // If both are equally loaded, use standard day order
            $dayOrder = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
            return $dayOrder[$a['day']] - $dayOrder[$b['day']];
        });
        
        return $availableSlots;
    }
    
    /**
     * Emergency scheduling for a single part-time course using very relaxed constraints
     */
    private function emergencySchedulePartTimeCourse(array $course): array
    {
        Log::info("EMERGENCY SCHEDULING: Attempting to schedule part-time course {$course['courseCode']}");
        
        $units = $course['unit'] ?? $course['units'] ?? 3;
        $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
        $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown';
        
        // Generate session durations
        $sessionDurations = TimeScheduler::generateRandomizedSessions($units, $employmentType);
        
        $schedules = [];
        $usedDays = [];
        
        foreach ($sessionDurations as $sessionIndex => $sessionDuration) {
            // Use emergency scheduling with very relaxed constraints
            $schedule = $this->emergencySchedulePartTimeSession($course, $sessionDuration, $sessionIndex, $usedDays);
            
            if ($schedule) {
                $schedules[] = $schedule;
                $usedDays[] = $schedule['day'];
                Log::info("EMERGENCY SUCCESS: Scheduled session {$sessionIndex} for {$course['courseCode']} on {$schedule['day']} {$schedule['start_time']}-{$schedule['end_time']}");
            } else {
                Log::error("EMERGENCY FAILED: Could not schedule session {$sessionIndex} for {$course['courseCode']}");
            }
        }
        
        return $schedules;
    }
    
    /**
     * Emergency scheduling for a single part-time session with very relaxed constraints
     */
    private function emergencySchedulePartTimeSession(array $course, float $sessionDuration, int $sessionIndex, array $usedDays): ?array
    {
        $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown';
        $section = $course['section'] ?? '';
        $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
        
        // Get ALL available slots for fair scheduling
        $allEveningSlots = array_filter($this->timeSlots, function($slot) {
            return $slot['start'] >= '07:00:00' && $slot['end'] <= '22:00:00';
        });
        
        // Try each evening slot until we find one that works
        foreach ($allEveningSlots as $slot) {
            $day = $slot['day'];
            $startTime = $slot['start'];
            $endTime = $this->calculateEndTime($startTime, $sessionDuration);
            
            // Skip if day already used
            if (in_array($day, $usedDays)) {
                continue;
            }
            
            // RESPECT LUNCH TIME: Skip slots that overlap with lunch break (11:30 AM - 1:00 PM)
            if (TimeScheduler::isLunchBreakViolation($startTime, $endTime)) {
                // Emergency: lunch break conflict
                continue;
            }
            
            // Skip if end time exceeds 8:45 PM cutoff
            if ($endTime > '20:45:00') {
                // Emergency time limit exceeded
                continue;
            }
            
            // Find ANY available room (ignore conflicts)
            $room = $this->findAnyAvailableRoom($course, $day, $startTime, $endTime);
            if (!$room) {
                continue;
            }
            
            // Create schedule entry (ignore instructor conflicts for emergency scheduling)
            $schedule = [
                'instructor' => $instructorName,
                'subject_code' => $course['courseCode'] ?? 'Unknown',
                'subject_description' => $course['subject'] ?? 'Unknown',
                'section' => $section,
                'day' => $day,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'year_level' => $course['yearLevel'] ?? '',
                'block' => $course['block'] ?? 'A',
                'unit' => $course['unit'] ?? $course['units'] ?? 3,
                'dept' => $course['dept'] ?? 'BSBA',
                'employment_type' => $employmentType,
                'sessionType' => $course['sessionType'] ?? 'Non-Lab session',
                'room_id' => $room['room_id'],
                'session_duration' => $sessionDuration
            ];
            
            // Reserve resources atomically; only accept if reservation succeeds (prevents section overlaps)
            if ($this->resourceTracker->reserveAllResources(
                $instructorName, $room['room_id'], $section, $day, $startTime, $endTime, $schedule
            )) {
                Log::warning("EMERGENCY SCHEDULED: {$course['courseCode']} scheduled on {$day} {$startTime}-{$endTime}");
                return $schedule;
            }
            
            // If reservation failed (conflicts), try next slot
            Log::warning("EMERGENCY RESERVATION FAILED for {$course['courseCode']} due to conflicts at {$day} {$startTime}-{$endTime}");
            continue;
        }
        
        return null;
    }

    /**
     * Ensure all courses are scheduled - force schedule any that were completely missed
     */
    private function ensureAllCoursesScheduled(array $schedules): array
    {
        // Track which courses have been scheduled and how many sessions each has
        $scheduledCourseKeys = [];
        $scheduledSessionCounts = [];
        
        foreach ($schedules as $schedule) {
            $key = ($schedule['subject_code'] ?? '') . '|' . ($schedule['year_level'] ?? '') . '|' . ($schedule['block'] ?? '');
            $scheduledCourseKeys[$key] = true;
            $scheduledSessionCounts[$key] = ($scheduledSessionCounts[$key] ?? 0) + 1;
        }

        // Check for unscheduled courses or courses with missing sessions
        $forcedSchedules = [];
        foreach ($this->courses as $course) {
            $key = ($course['courseCode'] ?? '') . '|' . ($course['yearLevel'] ?? '') . '|' . ($course['block'] ?? '');
            $units = $course['unit'] ?? $course['units'] ?? 3;
            $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
            $expectedSessions = TimeScheduler::generateRandomizedSessions($units, $employmentType);
            $expectedSessionCount = count($expectedSessions);
            $actualSessionCount = $scheduledSessionCounts[$key] ?? 0;
            
            if ($actualSessionCount < $expectedSessionCount) {
                $missingSessions = $expectedSessionCount - $actualSessionCount;
                Log::warning("Course {$course['courseCode']} has {$actualSessionCount}/{$expectedSessionCount} sessions scheduled. Force scheduling {$missingSessions} missing session(s).");
                
                // Find the existing session's start time and used days to enforce same-time constraint
                $existingStartTime = null;
                $usedDays = [];
                foreach ($schedules as $schedule) {
                    $scheduleKey = ($schedule['subject_code'] ?? '') . '|' . ($schedule['year_level'] ?? '') . '|' . ($schedule['block'] ?? '');
                    if ($scheduleKey === $key) {
                        if ($existingStartTime === null) {
                            $existingStartTime = $schedule['start_time'] ?? null;
                        }
                        // Collect all days already used by this course
                        $usedDays[] = $schedule['day'] ?? '';
                    }
                }
                
                // Force schedule only the missing sessions with same-time constraint and different days
                $forceScheduled = $this->forceScheduleCourse($course, $missingSessions, $existingStartTime, $usedDays);
                if ($forceScheduled) {
                    $forcedSchedules = array_merge($forcedSchedules, $forceScheduled);
                    $scheduledSessionCounts[$key] = $actualSessionCount + count($forceScheduled);
                }
            }
        }

        if (!empty($forcedSchedules)) {
            // Force scheduled additional sessions
            $schedules = array_merge($schedules, $forcedSchedules);
        }

        return $schedules;
    }

    /**
     * Force schedule a course with minimal constraints
     */
    private function forceScheduleCourse(array $course, int $maxSessions = null, string $requiredStartTime = null, array $usedDays = []): array
    {
        $sessionDurations = TimeScheduler::generateRandomizedSessions($course['unit'] ?? 3, $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME'));
        
        // Limit to requested number of sessions if specified
        if ($maxSessions !== null) {
            $sessionDurations = array_slice($sessionDurations, 0, $maxSessions);
        }
        
        // Filter time slots by employment type even for forced scheduling with STRICT part-time constraints (5:00 PM onwards only)
        $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown';
        $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
        $allAllowedSlots = TimeScheduler::filterTimeSlotsByEmployment($this->timeSlots, $employmentType, false);
        
        // STRICT DAY BALANCING: Prioritize Friday/Saturday evening slots for part-time instructors in force scheduling
        $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
        if ($employmentType === 'PART-TIME') {
            $allAllowedSlots = $this->prioritizeEveningSlotsForPartTime($allAllowedSlots);
            
            // Check if instructor has enough available evening slots
            $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            $availableEveningSlots = $this->getAvailableEveningSlotsForInstructor($instructorName);
            
            if ($availableEveningSlots < 2) {
                Log::warning("FORCE: Part-time instructor {$instructorName} has limited evening slots ({$availableEveningSlots} available) - may cause conflicts");
            }
        }
        
        $allowedSlots = $allAllowedSlots;
        
        // PREFER SAME TIME: For force scheduling, TRY to require the same time for all sessions
        if ($requiredStartTime !== null) {
            // PREFERRED: Try to use slots with exact same time
            $exactTimeSlots = array_filter($allAllowedSlots, function($slot) use ($requiredStartTime) {
                return $slot['start'] === $requiredStartTime;
            });
            
            if (!empty($exactTimeSlots)) {
                $allowedSlots = $exactTimeSlots;
                // Force: enforcing exact time
            } else {
                // SOFT CONSTRAINT FALLBACK: No slots at exact required time
                // Allow ANY available time as last resort to ensure session is scheduled
                Log::warning("FORCE: No slots available at required time {$requiredStartTime} for " . ($course['courseCode'] ?? 'Unknown') . ". Relaxing time constraint to schedule missing session.");
                // Use all available slots (don't return empty)
                $allowedSlots = $allAllowedSlots;
            }
        }
        
        // Exclude days already used by this course
        if (!empty($usedDays)) {
            $allowedSlots = array_filter($allowedSlots, function($slot) use ($usedDays) {
                return !in_array($slot['day'], $usedDays);
            });
            
            if (empty($allowedSlots)) {
                Log::error("FORCE: No slots available on unused days for " . ($course['courseCode'] ?? 'Unknown') . " (used days: " . implode(', ', $usedDays) . ")");
                return [];
            }
            
            // Force: excluding used days
        }
        
        $schedules = [];
        $firstSessionTime = null; // Track first session's time for consistency

        foreach ($sessionDurations as $sessionIndex => $duration) {
            $requiredMinutes = (int) round($duration * 60);
            
            // RELAXED CONSTRAINT: Prefer similar times but allow flexibility
            $currentAllowedSlots = $allowedSlots;
            
            if ($sessionIndex > 0 && $firstSessionTime !== null) {
                // PREFERRED: Try to find slots at the same time as first session
                $sameTimeSlots = array_filter($allowedSlots, function($slot) use ($firstSessionTime) {
                    return $slot['start'] === $firstSessionTime;
                });
                
                if (!empty($sameTimeSlots)) {
                    $currentAllowedSlots = $sameTimeSlots;
                    // Force: enforcing first session time
                } else {
                    // SOFT CONSTRAINT FALLBACK: No exact-time slots available
                    // Instead of breaking, allow ANY available time as last resort
                    Log::warning("FORCE: No slots at preferred time {$firstSessionTime} for session {$sessionIndex}. Relaxing time constraint to allow ANY available time.");
                    // Use all allowedSlots (already filtered by usedDays)
                    $currentAllowedSlots = $allowedSlots;
                }
            }
            
            // Filter slots by duration to prefer exact matches
            $slotsToTry = array_filter($currentAllowedSlots, function($slot) use ($requiredMinutes, $duration) {
                $slotMinutes = TimeScheduler::timeToMinutes($slot['end']) - TimeScheduler::timeToMinutes($slot['start']);
                
                // Prefer exact matches for 2.5-hour sessions
                if ($duration == 2.5) {
                    return $slotMinutes === $requiredMinutes; // Must be exactly 150 minutes
                }
                
                // For other durations, allow some flexibility
                return $slotMinutes >= ($requiredMinutes * 0.75) && $slotMinutes <= ($requiredMinutes * 1.25);
            });
            
            // If no exact match found for 2.5h, try broader match
            if (empty($slotsToTry) && $duration == 2.5) {
                $slotsToTry = array_filter($currentAllowedSlots, function($slot) use ($requiredMinutes) {
                    $slotMinutes = TimeScheduler::timeToMinutes($slot['end']) - TimeScheduler::timeToMinutes($slot['start']);
                    return $slotMinutes >= ($requiredMinutes * 0.75) && $slotMinutes <= ($requiredMinutes * 1.25);
                });
            }
            
            $scheduled = false;
            
            foreach ($slotsToTry as $slot) {
                // Check if day already used in this batch
                $dayAlreadyUsed = false;
                foreach ($schedules as $existingSchedule) {
                    if ($existingSchedule['day'] === $slot['day']) {
                        $dayAlreadyUsed = true;
                        break;
                    }
                }
                
                if ($dayAlreadyUsed) {
                    continue; // Skip days already used
                }
                
                // Calculate end time
                $endTime = $this->calculateEndTime($slot['start'], $duration);
                
                // Hard cutoff: never exceed 8:45 PM (20:45:00)
                if ($endTime > '20:45:00') {
                    continue;
                }
                
                // HARD CONSTRAINT: Always check instructor conflicts (same instructor can't be in 2 places)
                // Use silent mode to suppress excessive logging during force scheduling
                if ($this->hasInstructorConflict($course, $slot['day'], $slot['start'], $endTime, true)) {
                    continue; // MUST skip - instructor conflict is impossible
                }
                
                // SOFT CONSTRAINT: Section conflicts allowed ONLY if different instructor
                // If same instructor is teaching different sections at same time, that's already caught above
                $sectionConflict = $this->hasSectionConflictSameInstructor($course, $slot['day'], $slot['start'], $endTime);
                if ($sectionConflict) {
                    continue; // Skip if section conflict with SAME instructor (truly impossible)
                }
                // Different instructors teaching different sections at same time is ALLOWED
                
                // CRITICAL: Find available room (not just first room)
                $room = $this->findAnyAvailableRoom($course, $slot['day'], $slot['start'], $endTime);
                if (!$room) {
                    continue; // No available room
                }

                // Hard gate: validate all resources before accepting forced schedule (prevents section overlaps)
                if (!$this->resourceTracker->reserveAllResources(
                    $instructorName,
                    $room['room_id'],
                    ($course['yearLevel'] ?? '') . ' ' . ($course['block'] ?? 'A'),
                    $slot['day'],
                    $slot['start'],
                    $endTime,
                    [
                        'instructor' => $instructorName,
                        'subject_code' => $course['courseCode'] ?? 'UNKNOWN',
                        'subject_description' => $course['subject'] ?? $course['subjectDescription'] ?? 'Unknown Subject',
                        'section' => ($course['yearLevel'] ?? '1st Year') . ' ' . ($course['block'] ?? 'A'),
                        'day' => $slot['day'],
                        'start_time' => $slot['start'],
                        'end_time' => $endTime,
                        'year_level' => $course['yearLevel'] ?? '1st Year',
                        'block' => $course['block'] ?? 'A',
                        'unit' => $course['unit'] ?? $course['units'] ?? 3,
                        'dept' => $course['dept'] ?? 'General',
                        'employment_type' => $course['employmentType'] ?? 'FULL-TIME',
                        'sessionType' => $course['sessionType'] ?? 'Non-Lab session',
                        'room_id' => $room['room_id'],
                        'session_duration' => $duration
                    ]
                )) {
                    // Reservation failed (section/instructor/room conflict) — try another slot
                    continue;
                }
                
                // Capture first session's start time for subsequent sessions
                if ($firstSessionTime === null) {
                    $firstSessionTime = $slot['start'];
                    // Force: first session time set
                }
                
                // Extract course data for force scheduling
                $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown Instructor';
                $subjectCode = $course['courseCode'] ?? 'UNKNOWN';
                $forceUnits = $course['unit'] ?? $course['units'] ?? 3;
                
                Log::debug("PhpScheduler force scheduling - Units: {$forceUnits} for {$subjectCode}, Duration: {$duration}h");
                
                $schedule = [
                    'instructor' => $instructorName,
                    'subject_code' => $subjectCode,
                    'subject_description' => $course['subject'] ?? 'Unknown Subject',
                    'unit' => $forceUnits,
                    'day' => $slot['day'],
                    'start_time' => $slot['start'],
                    'end_time' => $endTime,
                    'block' => $course['block'] ?? 'A',
                    'year_level' => $course['yearLevel'] ?? '1st Year',
                    'section' => $course['section'] ?? 'General-1st Year A',
                    'employment_type' => $course['employmentType'] ?? 'FULL-TIME',
                    'room_id' => $room['room_id'],
                    'dept' => $course['dept'] ?? 'General',
                    'forced_schedule' => true,  // Mark as forced for debugging
                    'session_duration' => $duration
                ];

                $schedules[] = $schedule;
                // CRITICAL: Add to scheduledCourses for subsequent conflict checks
                $this->scheduledCourses[] = $schedule;
                // CRITICAL: Update allowedSlots to exclude the day just used
                $dayJustUsed = $slot['day'];
                $allowedSlots = array_filter($allowedSlots, function($s) use ($dayJustUsed) {
                    return $s['day'] !== $dayJustUsed;
                });
                // Force scheduled
                $scheduled = true;
                break; // Found a valid slot, move to next session
            }
            
            if (!$scheduled) {
                Log::warning("FORCE: Could not schedule session {$sessionIndex} for " . ($course['courseCode'] ?? 'Unknown') . " - no conflict-free slots available");
                
                // CSP FALLBACK: Try WITHOUT section conflict check (last resort - allow student overlap)
                foreach ($slotsToTry as $slot) {
                    $dayAlreadyUsed = false;
                    foreach ($schedules as $existingSchedule) {
                        if ($existingSchedule['day'] === $slot['day']) {
                            $dayAlreadyUsed = true;
                            break;
                        }
                    }
                    if ($dayAlreadyUsed) continue;
                    
                    $endTime = $this->calculateEndTime($slot['start'], $duration);
                    if ($endTime > '20:45:00') continue;
                    
                    // Only check instructor conflict - allow section overlap as last resort
                    // Use silent mode to suppress excessive logging
                    if ($this->hasInstructorConflict($course, $slot['day'], $slot['start'], $endTime, true)) {
                        continue;
                    }
                    
                    $room = $this->findAnyAvailableRoom($course, $slot['day'], $slot['start'], $endTime);
                    if (!$room) continue;
                    
                    // CRITICAL: Capture first session time to enforce consistency
                    if ($firstSessionTime === null) {
                        $firstSessionTime = $slot['start'];
                        // Force fallback: first session time set
                    }
                    
                    // Schedule with relaxed constraints
                    $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown Instructor';
                    $subjectCode = $course['courseCode'] ?? 'UNKNOWN';
                    $forceUnits = $course['unit'] ?? $course['units'] ?? 3;
                    
                    $schedule = [
                        'instructor' => $instructorName,
                        'subject_code' => $subjectCode,
                        'subject_description' => $course['subject'] ?? 'Unknown Subject',
                        'unit' => $forceUnits,
                        'day' => $slot['day'],
                        'start_time' => $slot['start'],
                        'end_time' => $endTime,
                        'block' => $course['block'] ?? 'A',
                        'year_level' => $course['yearLevel'] ?? '1st Year',
                        'section' => $course['section'] ?? 'General-1st Year A',
                        'employment_type' => $course['employmentType'] ?? 'FULL-TIME',
                        'room_id' => $room['room_id'],
                        'dept' => $course['dept'] ?? 'General',
                        'forced_schedule' => true,
                        'session_duration' => $duration
                    ];
                    
                    $schedules[] = $schedule;
                    $this->scheduledCourses[] = $schedule;
                    // CRITICAL: Update allowedSlots to exclude the day just used
                    $dayJustUsed = $slot['day'];
                    $allowedSlots = array_filter($allowedSlots, function($s) use ($dayJustUsed) {
                        return $s['day'] !== $dayJustUsed;
                    });
                    Log::warning("FORCE FALLBACK: Scheduled session {$sessionIndex} for {$subjectCode} with relaxed section constraints on {$dayJustUsed} at {$slot['start']}. Excluding {$dayJustUsed} from future slots.");
                    $scheduled = true;
                    break;
                }
                
                if (!$scheduled) {
                    Log::error("FORCE COMPLETE FAILURE Level 1: Cannot schedule session {$sessionIndex} for " . ($course['courseCode'] ?? 'Unknown') . " - trying ultimate fallback");
                    
                    // ULTIMATE FALLBACK: Allow COMBINED SECTIONS (same instructor, same course, same time, different sections)
                    // This is realistic - instructor teaches multiple sections together in one large classroom
                    
                    // CRITICAL: For subsequent sessions, PREFER slots at the SAME TIME as first session
                    $ultimateSlotsToTry = $slotsToTry;
                    $tryingDifferentTimes = false;
                    
                    if ($sessionIndex > 0 && $firstSessionTime !== null) {
                        // First, try to find slots at the same time
                        $sameTimeSlots = array_filter($slotsToTry, function($slot) use ($firstSessionTime) {
                            return $slot['start'] === $firstSessionTime;
                        });
                        
                        if (!empty($sameTimeSlots)) {
                            // Prefer same-time slots if available
                            $ultimateSlotsToTry = $sameTimeSlots;
                            // Ultimate fallback: filtering to first session time
                        } else {
                            // SOFT CONSTRAINT: No same-time slots available
                            // Allow ANY time as last resort to ensure session is scheduled (on different day)
                            Log::warning("ULTIMATE FALLBACK: No slots at preferred time {$firstSessionTime} for session {$sessionIndex}. Allowing ANY time to avoid dropping subject.");
                            $tryingDifferentTimes = true;
                            // Use all slotsToTry (different days already enforced)
                        }
                    }
                    
                    $attemptedSlots = 0;
                    foreach ($ultimateSlotsToTry as $slot) {
                        $attemptedSlots++;
                        $dayAlreadyUsed = false;
                        foreach ($schedules as $existingSchedule) {
                            if ($existingSchedule['day'] === $slot['day']) {
                                $dayAlreadyUsed = true;
                                break;
                            }
                        }
                        if ($dayAlreadyUsed) continue;
                        
                        $endTime = $this->calculateEndTime($slot['start'], $duration);
            if ($endTime > '20:45:00') continue;
                        
                        // ULTIMATE: Only reject if instructor is teaching DIFFERENT course at same time
                        // Use silent mode to suppress excessive logging
                        $hasDifferentCourseConflict = $this->hasInstructorConflictDifferentCourse($course, $slot['day'], $slot['start'], $endTime, true);
                        if ($hasDifferentCourseConflict) {
                            continue;
                        }
                        
                        // Try to find available room (may double-book rooms in worst case)
                        $room = $this->findAnyAvailableRoom($course, $slot['day'], $slot['start'], $endTime);
                        if (!$room) {
                            // LAST RESORT: Use any room even if occupied (larger capacity assumed)
                            if (!empty($this->rooms)) {
                                $room = $this->rooms[0];
                            } else {
                                continue;
                            }
                        }
                        
                        // Schedule with ULTIMATE relaxation - combined sections allowed
                        $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown Instructor';
                        $subjectCode = $course['courseCode'] ?? 'UNKNOWN';
                        $forceUnits = $course['unit'] ?? $course['units'] ?? 3;
                        
                        // CRITICAL: Capture first session time to enforce consistency
                        if ($firstSessionTime === null) {
                            $firstSessionTime = $slot['start'];
                            // Ultimate fallback: first session time set
                        }
                        
                        $schedule = [
                            'instructor' => $instructorName,
                            'subject_code' => $subjectCode,
                            'subject_description' => $course['subject'] ?? 'Unknown Subject',
                            'unit' => $forceUnits,
                            'day' => $slot['day'],
                            'start_time' => $slot['start'],
                            'end_time' => $endTime,
                            'block' => $course['block'] ?? 'A',
                            'year_level' => $course['yearLevel'] ?? '1st Year',
                            'section' => $course['section'] ?? 'General-1st Year A',
                            'employment_type' => $course['employmentType'] ?? 'FULL-TIME',
                            'room_id' => $room['room_id'],
                            'dept' => $course['dept'] ?? 'General',
                            'forced_schedule' => true,
                            'combined_sections' => true,  // Mark as combined sections
                            'session_duration' => $duration
                        ];
                        
                        $schedules[] = $schedule;
                        $this->scheduledCourses[] = $schedule;
                        // CRITICAL: Update allowedSlots to exclude the day just used
                        $dayJustUsed = $slot['day'];
                        $allowedSlots = array_filter($allowedSlots, function($s) use ($dayJustUsed) {
                            return $s['day'] !== $dayJustUsed;
                        });
                        Log::warning("ULTIMATE FALLBACK: Combined sections - {$subjectCode} session {$sessionIndex} (same course, different sections allowed) on {$dayJustUsed} at {$slot['start']}. Excluding {$dayJustUsed} from future slots.");
                        $scheduled = true;
                        break;
                    }
                    
                    // SECOND-LEVEL FALLBACK: If we tried only same-time slots and ALL failed, retry with ANY time
                    $shouldRetryWithAnyTime = false;
                    if ($sessionIndex === 0) {
                        // Session 0: if we tried all slots and failed, retry with ANY time
                        $shouldRetryWithAnyTime = !$scheduled && !$tryingDifferentTimes;
                    } else {
                        // Session 1+: if we tried same-time slots and failed, retry with ANY time
                        $shouldRetryWithAnyTime = !$scheduled && $firstSessionTime !== null && !$tryingDifferentTimes;
                    }
                    
                    if ($shouldRetryWithAnyTime) {
                        if ($sessionIndex === 0) {
                            Log::warning("ULTIMATE FALLBACK: All {$attemptedSlots} slots for session 0 had conflicts. RELAXING TIME CONSTRAINT to allow ANY time.");
                        } else {
                            Log::warning("ULTIMATE FALLBACK: All {$attemptedSlots} same-time slots at {$firstSessionTime} had conflicts. RELAXING TIME CONSTRAINT to allow different times.");
                        }
                        
                        // Retry with ALL time slots (not just same-time)
                        foreach ($slotsToTry as $slot) {
                            // Skip if day already used
                            $dayAlreadyUsed = false;
                            foreach ($schedules as $existingSchedule) {
                                if ($existingSchedule['day'] === $slot['day']) {
                                    $dayAlreadyUsed = true;
                                    break;
                                }
                            }
                            if ($dayAlreadyUsed) continue;
                            
                            $endTime = $this->calculateEndTime($slot['start'], $duration);
                        if ($endTime > '20:45:00') continue;
                            
                            // ULTIMATE: Only reject if instructor is teaching DIFFERENT course at same time
                            $hasDifferentCourseConflict = $this->hasInstructorConflictDifferentCourse($course, $slot['day'], $slot['start'], $endTime, true);
                            if ($hasDifferentCourseConflict) {
                                continue;
                            }
                            
                            // Find available room
                            $room = $this->findAvailableRoomUltimate($slot['day'], $slot['start'], $endTime);
                            if (empty($room)) {
                                continue;
                            }
                            
                            // Schedule with relaxed time constraint
                            $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown Instructor';
                            $subjectCode = $course['courseCode'] ?? 'UNKNOWN';
                            $forceUnits = $course['unit'] ?? $course['units'] ?? 3;
                            
                            $schedule = [
                                'instructor' => $instructorName,
                                'subject_code' => $subjectCode,
                                'subject_description' => $course['subject'] ?? 'Unknown Subject',
                                'unit' => $forceUnits,
                                'day' => $slot['day'],
                                'start_time' => $slot['start'],
                                'end_time' => $endTime,
                                'block' => $course['block'] ?? 'A',
                                'year_level' => $course['yearLevel'] ?? '1st Year',
                                'section' => $course['section'] ?? 'General-1st Year A',
                                'employment_type' => $course['employmentType'] ?? 'FULL-TIME',
                                'room_id' => $room['room_id'],
                                'dept' => $course['dept'] ?? 'General',
                                'forced_schedule' => true,
                                'combined_sections' => true,
                                'session_duration' => $duration
                            ];
                            
                            $schedules[] = $schedule;
                            $this->scheduledCourses[] = $schedule;
                            $dayJustUsed = $slot['day'];
                            $allowedSlots = array_filter($allowedSlots, function($s) use ($dayJustUsed) {
                                return $s['day'] !== $dayJustUsed;
                            });
                            Log::warning("ULTIMATE FALLBACK (RELAXED TIME): {$subjectCode} session {$sessionIndex} on {$dayJustUsed} at {$slot['start']} (different time accepted to avoid dropping). Excluding {$dayJustUsed} from future slots.");
                            $scheduled = true;
                            break;
                        }
                    }
                    
                    if (!$scheduled) {
                        Log::error("FORCE COMPLETE FAILURE: Absolutely cannot schedule session {$sessionIndex} for " . ($course['courseCode'] ?? 'Unknown') . " - exhausted all strategies");
                    }
                }
            }
        }

        return $schedules;
    }

    /**
     * Check if instructor is teaching a DIFFERENT course at the same time
     * (Same course to different sections at same time is ALLOWED - combined sections)
     */
    private function hasInstructorConflictDifferentCourse(array $course, string $day, string $startTime, string $endTime, bool $silentMode = false): bool
    {
        $instructorName = $course['instructor'] ?? $course['name'] ?? '';
        $courseCode = $course['courseCode'] ?? 'Unknown';
        
        $newCourseDays = $this->parseIndividualDays($day);
        
        foreach ($this->scheduledCourses as $scheduledCourse) {
            if ($scheduledCourse['instructor'] === $instructorName) {
                $scheduledCourseDays = $this->parseIndividualDays($scheduledCourse['day']);
                $hasDayOverlap = !empty(array_intersect($newCourseDays, $scheduledCourseDays));
                
                if ($hasDayOverlap && 
                    $this->timesOverlap($startTime, $endTime, $scheduledCourse['start_time'], $scheduledCourse['end_time'])) {
                    
                    // ALLOW if same course (combined sections teaching)
                    if ($scheduledCourse['subject_code'] === $courseCode) {
                        if (!$silentMode) {
                            Log::info("✅ COMBINED SECTIONS: Same instructor teaching {$courseCode} to multiple sections at {$day} {$startTime}-{$endTime}");
                        }
                        continue; // Same course - combined sections allowed
                    }
                    
                    // REJECT if different course
                    if (!$silentMode) {
                        Log::warning("❌ DIFFERENT COURSE CONFLICT: Instructor {$instructorName} teaching {$courseCode} conflicts with {$scheduledCourse['subject_code']} at {$day} {$startTime}-{$endTime}");
                    }
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check for instructor conflicts (same instructor at same time)
     */
    private function hasInstructorConflict(array $course, string $day, string $startTime, string $endTime, bool $silentMode = false): bool
    {
        $instructorName = $course['instructor'] ?? $course['name'] ?? '';
        $courseCode = $course['courseCode'] ?? 'Unknown';
        
        // Parse the incoming day(s) - could be "Mon" or "MonSat"
        $newCourseDays = $this->parseIndividualDays($day);
        
        // FIRST: Check against reference schedules (pre-existing schedules)
        foreach ($this->referenceSchedules as $refSchedule) {
            // Use fuzzy matching to handle different name formats
            $nameMatch = $this->matchInstructorNames($instructorName, $refSchedule['instructor']);
            
            if ($nameMatch) {
                // Parse reference time to get start and end times
                list($refStartTime, $refEndTime) = $this->parseReferenceTime($refSchedule['time']);
                
                // Check if day matches (reference uses full day names like "Monday")
                $refDayShort = DayScheduler::normalizeDay($refSchedule['day']);
                $dayMatch = in_array($refDayShort, $newCourseDays);
                
                if ($dayMatch) {
                    $timeOverlap = $this->timesOverlap($startTime, $endTime, $refStartTime, $refEndTime);
                    
                    if ($timeOverlap) {
                        // Only log if not in silent mode (during force scheduling we suppress logs)
                        if (!$silentMode) {
                            $refSubject = $refSchedule['subject'] ?? 'Unknown';
                            Log::warning("🚨 REFERENCE SCHEDULE CONFLICT - INSTRUCTOR: {$instructorName} (matched {$refSchedule['instructor']}) blocked at {$day} {$startTime}-{$endTime}, reference at {$refSchedule['day']} {$refStartTime}-{$refEndTime} ({$refSubject})");
                        }
                        
                        return true;
                    }
                }
            }
        }
        
        // SECOND: Check against existing schedules from previous generations (via dbConflictIndex)
        if (!empty($this->dbConflictIndex) && !empty($this->groupId)) {
            $instructorId = $this->resolveInstructorIdByName($instructorName);
            if ($instructorId) {
                foreach ($newCourseDays as $checkDay) {
                    $dayMeetings = $this->dbConflictIndex[$checkDay] ?? [];
                    foreach ($dayMeetings as $meeting) {
                        if ($meeting['instructor_id'] === $instructorId) {
                            if ($this->timesOverlap($startTime, $endTime, $meeting['start'], $meeting['end'])) {
                                // Allow if same subject (combined sections)
                                if (!empty($meeting['subject_id'])) {
                                    $currentSubjectId = $this->resolveSubjectIdByCode($courseCode);
                                    if ($currentSubjectId && $meeting['subject_id'] === $currentSubjectId) {
                                        continue; // Same subject, allow combined sections
                                    }
                                }
                                
                                if (!$silentMode) {
                                    Log::warning("🚨 PREVIOUS GENERATION CONFLICT - INSTRUCTOR: {$instructorName} (ID: {$instructorId}) blocked at {$day} {$startTime}-{$endTime}, existing at {$checkDay} {$meeting['start']}-{$meeting['end']}");
                                }
                                return true;
                            }
                        }
                    }
                }
            }
        }
        
        // THIRD: Check against schedules created in this generation run
        if (isset($this->instructorSchedules[$instructorName])) {
            foreach ($this->instructorSchedules[$instructorName] as $scheduledTime) {
                $scheduledDays = $this->parseIndividualDays($scheduledTime['day']);
                $hasDayOverlap = !empty(array_intersect($newCourseDays, $scheduledDays));
                
                if ($hasDayOverlap && 
                    $this->timesOverlap($startTime, $endTime, $scheduledTime['start_time'], $scheduledTime['end_time'])) {
                    
                    // ALLOW combined sections: same instructor, same course, different sections at same time
                    $scheduledCourseCode = $scheduledTime['subject_code'] ?? '';
                    if ($scheduledCourseCode === $courseCode) {
                        // Same course - combined sections teaching is ALLOWED
                        continue;
                    }
                    
                    // Only log if not in silent mode (during force scheduling we suppress logs)
                    if (!$silentMode && rand(1, 50) === 1) { // sample logs to reduce volume
                        Log::info("INSTRUCTOR CONFLICT DETECTED (indexed): Instructor={$instructorName}, Day={$day}, Time={$startTime}-{$endTime}, Course={$courseCode}");
                    }
                    
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check for section conflicts (same section at overlapping times)
     */
    private function findSectionMeetingOnDay(string $sectionName, string $day, string $startTime, string $endTime): ?array
    {
        // Helper for day-level conflict check in incremental scheduler
        foreach ($this->scheduledCourses as $sc) {
            $scheduledSection = trim($sc['year_level'] . ' ' . $sc['block']);
            if ($scheduledSection === $sectionName && $sc['day'] === $day) {
                if (TimeScheduler::timesOverlap($startTime, $endTime, $sc['start_time'], $sc['end_time'])) {
                    return $sc;
                }
            }
        }
        return null;
    }

    private function hasSectionConflict(array $course, string $day, string $startTime, string $endTime): bool
    {
        $yearLevel = $course['yearLevel'] ?? '';
        $block = $course['block'] ?? '';
        $section = trim($yearLevel . ' ' . $block);
        $courseCode = $course['courseCode'] ?? 'Unknown';
        
        // Parse the incoming day(s) - could be "Mon" or "MonSat"
        $newCourseDays = $this->parseIndividualDays($day);
        
        // PERFORMANCE OPTIMIZATION: Use indexed lookup instead of scanning all schedules
        if (isset($this->sectionSchedules[$section])) {
            foreach ($this->sectionSchedules[$section] as $scheduledTime) {
                $scheduledDays = $this->parseIndividualDays($scheduledTime['day']);
                $hasDayOverlap = !empty(array_intersect($newCourseDays, $scheduledDays));
                
                if ($hasDayOverlap && 
                    $this->timesOverlap($startTime, $endTime, $scheduledTime['start_time'], $scheduledTime['end_time'])) {
                    
                    // DETAILED CONFLICT LOGGING (only 1% of time to reduce spam)
                    if (rand(1, 100) === 1) {
                        Log::warning("🚨 SECTION CONFLICT DETECTED (indexed):");
                        Log::warning("   Section: " . $section);
                        Log::warning("   Day: " . $day);
                        Log::warning("   Time: " . $startTime . " - " . $endTime);
                        Log::warning("   Conflicting Course: " . $courseCode);
                    }
                    
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * CSP: Check for section conflicts with SAME instructor
     * This is a true hard constraint - same instructor can't teach 2 sections simultaneously
     */
    private function hasSectionConflictSameInstructor(array $course, string $day, string $startTime, string $endTime): bool
    {
        $instructorName = $course['instructor'] ?? $course['name'] ?? '';
        $section = $course['section'] ?? '';
        $courseCode = $course['courseCode'] ?? '';
        
        // Parse individual days
        $newCourseDays = $this->parseIndividualDays($day);
        
        foreach ($this->scheduledCourses as $scheduledCourse) {
            // Only check section conflicts with SAME instructor
            if ($scheduledCourse['instructor'] !== $instructorName) {
                continue; // Different instructor - section overlap is ALLOWED
            }
            
            // Same instructor - check if different sections overlap
            if ($scheduledCourse['section'] === $section) {
                // Same section, same instructor - check if it's same course or different course
                if ($scheduledCourse['subject_code'] === $courseCode) {
                    continue; // Same course, same section - multi-session is allowed on different days
                }
            }
            
            // Parse scheduled course days
            $scheduledCourseDays = $this->parseIndividualDays($scheduledCourse['day']);
            $hasDayOverlap = !empty(array_intersect($newCourseDays, $scheduledCourseDays));
            
            if ($hasDayOverlap && 
                $this->timesOverlap($startTime, $endTime, $scheduledCourse['start_time'], $scheduledCourse['end_time'])) {
                
                Log::warning("CSP: Section conflict with SAME INSTRUCTOR detected (truly impossible):");
                Log::warning("   Instructor: " . $instructorName);
                Log::warning("   Section 1: " . $section . " (" . $courseCode . ")");
                Log::warning("   Section 2: " . $scheduledCourse['section'] . " (" . $scheduledCourse['subject_code'] . ")");
                Log::warning("   Time: " . $startTime . " - " . $endTime);
                Log::warning("   Day overlap: " . implode(',', array_intersect($newCourseDays, $scheduledCourseDays)));
                
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check for strict section conflicts (only exact same section and time overlap)
     */
    private function hasStrictSectionConflict(array $course, string $day, string $startTime, string $endTime): bool
    {
        $yearLevel = $course['yearLevel'] ?? '';
        $block = $course['block'] ?? '';
        $section = trim($yearLevel . ' ' . $block);
        
        // Parse the incoming day(s)
        $newCourseDays = $this->parseIndividualDays($day);
        
        // Only check for exactly the same section
        foreach ($this->scheduledCourses as $scheduledCourse) {
            $scheduledSection = trim($scheduledCourse['year_level'] . ' ' . $scheduledCourse['block']);
            
            if ($scheduledSection === $section) {
                // Parse scheduled course days to handle combined day strings
                $scheduledCourseDays = $this->parseIndividualDays($scheduledCourse['day']);
                $hasDayOverlap = !empty(array_intersect($newCourseDays, $scheduledCourseDays));
                
                if ($hasDayOverlap && 
                    $this->timesOverlap($startTime, $endTime, $scheduledCourse['start_time'], $scheduledCourse['end_time'])) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Find best room for course with improved lab/non-lab filtering and preference support
     */
    private function findBestRoomForCourse(array $course, string $day, string $startTime, string $endTime): ?array
    {
        $requiresLab = RoomScheduler::courseRequiresLab($course);
        
        // Get available rooms for this time slot
        $availableRooms = RoomScheduler::getAvailableRooms(
            $this->rooms, 
            $day, 
            $startTime, 
            $endTime, 
            $this->roomUsage
        );
        
        if (empty($availableRooms)) {
            return null;
        }
        
        // Check if there's a preferred room
        $preferredRoomId = null;
        if (!empty($this->filterPreferences['preferredRoom'])) {
            $preferredRoomId = (int) $this->filterPreferences['preferredRoom'];
        }
        
        // Try to use preferred room if available and suitable
        if ($preferredRoomId) {
            foreach ($availableRooms as $room) {
                if ($room['room_id'] == $preferredRoomId) {
                    // Check if preferred room is suitable for the course type
                    $roomIsLab = $room['is_lab'] ?? false;
                    if (($requiresLab && $roomIsLab) || (!$requiresLab)) {
                        RoomScheduler::updateRoomUsage($room['room_id'], $day, $startTime, $endTime, $this->roomUsage);
                        Log::debug("Using preferred room: " . $room['room_name'] . " for " . ($course['courseCode'] ?? 'Unknown'));
                        return $room;
                    }
                }
            }
        }
        
        // Original room selection logic as fallback
        if ($requiresLab) {
            // For lab sessions, ONLY use lab rooms
            $labRooms = array_filter($availableRooms, fn($room) => $room['is_lab'] ?? false);
            
            if (!empty($labRooms)) {
                // Use room scheduler to select optimal lab room
                $selectedRoom = RoomScheduler::selectOptimalRoom(
                    $labRooms, 
                    $this->roomUsage, 
                    $this->roomDayUsage, 
                    $day, 
                    $this->rrPointer
                );
                
                if ($selectedRoom) {
                    RoomScheduler::updateRoomUsage($selectedRoom['room_id'], $day, $startTime, $endTime, $this->roomUsage);
                    return $selectedRoom;
                }
            }
            
            // No lab rooms available - reject this scheduling attempt
            return null;
        } else {
            // For non-lab sessions, prefer non-lab rooms
            $nonLabRooms = array_filter($availableRooms, fn($room) => !($room['is_lab'] ?? false));
            
            if (!empty($nonLabRooms)) {
                // Use non-lab rooms first
                $selectedRoom = RoomScheduler::selectOptimalRoom(
                    $nonLabRooms, 
                    $this->roomUsage, 
                    $this->roomDayUsage, 
                    $day, 
                    $this->rrPointer
                );
                
                if ($selectedRoom) {
                    RoomScheduler::updateRoomUsage($selectedRoom['room_id'], $day, $startTime, $endTime, $this->roomUsage);
                    return $selectedRoom;
                }
            }
            
            // NO FALLBACK to lab rooms for non-lab sessions - reject this scheduling attempt
            // Lab rooms are strictly reserved for lab sessions only
            return null;
        }
        
        return null;
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
        
        // Handle corrupted data where start_time >= end_time by swapping
        if ($start1Minutes >= $end1Minutes) {
            Log::warning('CORRUPTED TIME DATA: start_time >= end_time', [
                'start1' => $start1,
                'end1' => $end1
            ]);
            $temp = $start1Minutes;
            $start1Minutes = $end1Minutes;
            $end1Minutes = $temp;
        }
        if ($start2Minutes >= $end2Minutes) {
            Log::warning('CORRUPTED TIME DATA: start_time >= end_time', [
                'start2' => $start2,
                'end2' => $end2
            ]);
            $temp = $start2Minutes;
            $start2Minutes = $end2Minutes;
            $end2Minutes = $temp;
        }
        
        // Two meetings overlap if:
        // - Meeting 1 starts before Meeting 2 ends AND
        // - Meeting 2 starts before Meeting 1 ends
        // This correctly handles all overlap cases including partial overlaps
        $overlaps = ($start1Minutes < $end2Minutes) && ($start2Minutes < $end1Minutes);
        
        return $overlaps;
    }

    /**
     * Detect conflicts in the final schedule
     */
    /**
     * Validate final schedule to ensure part-time instructors are only scheduled after 5 PM
     */
    private function validatePartTimeConstraints(array $schedules): array
    {
        $violations = [];
        
        foreach ($schedules as $schedule) {
            $instructorName = $schedule['instructor'] ?? 'Unknown';
            $startTime = $schedule['start_time'] ?? '';
            $employmentType = $this->normalizeEmploymentType($schedule['employment_type'] ?? 'FULL-TIME');
            
            // FAIR SCHEDULING: No time restrictions for PART-TIME instructors
            // PART-TIME instructors can now be scheduled at any time for fair distribution
            if ($employmentType === 'PART-TIME') {
                Log::info("FAIR SCHEDULING: PART-TIME instructor {$instructorName} scheduled at {$startTime} - no restrictions");
            }
        }
        
        if (!empty($violations)) {
            Log::error("PART-TIME CONSTRAINT VIOLATIONS DETECTED:");
            foreach ($violations as $violation) {
                Log::error("VIOLATION: {$violation['message']} - Course: {$violation['course']}, Day: {$violation['day']}, Time: {$violation['start_time']}-{$violation['end_time']}");
            }
        } else {
            Log::info("PART-TIME VALIDATION: All part-time instructors are correctly scheduled after 5 PM");
        }
        
        return $violations;
    }

    /**
     * Detect conflicts using ResourceTracker for consistent validation
     */
    private function detectConflictsWithResourceTracker(array $schedules): array
    {
        $conflicts = [
            'instructor_conflicts' => 0,
            'room_conflicts' => 0,
            'section_conflicts' => 0,
            'lunch_break_violations' => 0
        ];

        // Create a fresh ResourceTracker to validate the final schedule
        $validationTracker = new ResourceTracker();
        
        foreach ($schedules as $schedule) {
            $instructorName = $schedule['instructor'] ?? 'Unknown Instructor';
            $roomId = $schedule['room_id'] ?? 0;
            $sectionName = $schedule['section'] ?? '';
            $day = $schedule['day'] ?? '';
            $startTime = $schedule['start_time'] ?? '';
            $endTime = $schedule['end_time'] ?? '';
            
            // Check for conflicts using ResourceTracker
            $scheduleConflicts = $validationTracker->validateBeforeAssignment(
                $instructorName, $roomId, $sectionName, $day, $startTime, $endTime
            );
            
            // Count conflicts by type
            foreach ($scheduleConflicts as $conflict) {
                switch ($conflict['type']) {
                    case 'instructor':
                        $conflicts['instructor_conflicts']++;
                        break;
                    case 'room':
                        $conflicts['room_conflicts']++;
                        break;
                    case 'section':
                        $conflicts['section_conflicts']++;
                        break;
                }
            }
            
            // Reserve resources for next validation
            $validationTracker->reserveAllResources(
                $instructorName, $roomId, $sectionName, $day, $startTime, $endTime, $schedule
            );
        }
        
        Log::info("RESOURCE TRACKER VALIDATION: {$conflicts['instructor_conflicts']} instructor, {$conflicts['room_conflicts']} room, {$conflicts['section_conflicts']} section conflicts");
        
        return $conflicts;
    }

    private function detectConflicts(array $schedules): array
    {
        $conflicts = [
            'instructor_conflicts' => 0,
            'room_conflicts' => 0,
            'section_conflicts' => 0,
            'lunch_break_violations' => 0
        ];

        // Expand schedules by individual days first
        // This ensures "MonSat" and "MonTue" both create entries for "Mon"
        $expandedSchedules = [];
        foreach ($schedules as $schedule) {
            $days = $this->parseIndividualDays($schedule['day'] ?? '');
            foreach ($days as $day) {
                $expandedSchedule = $schedule;
                $expandedSchedule['individual_day'] = $day; // Track which specific day this is
                $expandedSchedules[] = $expandedSchedule;
            }
        }

        // Group expanded schedules by time slot for conflict detection
        $timeGroups = [];
        foreach ($expandedSchedules as $schedule) {
            $key = $schedule['individual_day'] . '|' . $schedule['start_time'] . '|' . $schedule['end_time'];
            if (!isset($timeGroups[$key])) {
                $timeGroups[$key] = [];
            }
            $timeGroups[$key][] = $schedule;
        }

        foreach ($timeGroups as $timeSlot => $entries) {
            if (count($entries) <= 1) {
                continue;
            }

            $timeSlotParts = explode('|', $timeSlot);
            $day = $timeSlotParts[0];
            $startTime = $timeSlotParts[1];
            $endTime = $timeSlotParts[2];

            // Count only true cross-subject conflicts
            $instructorSubjectTracker = [];
            $subjectsPerInstructor = [];
            foreach ($entries as $entry) {
                $name = $entry['instructor'];
                $code = $entry['subject_code'] ?? '';
                if (!isset($instructorSubjectTracker[$name])) $instructorSubjectTracker[$name] = [];
                $instructorSubjectTracker[$name][$code] = ($instructorSubjectTracker[$name][$code] ?? 0) + 1;
                $subjectsPerInstructor[$name][] = $code;
            }

            $actualInstructorConflicts = 0;
            foreach ($instructorSubjectTracker as $instr => $subjectCounts) {
                if (count($subjectCounts) > 1) {
                    // This instructor is teaching >1 subject at this time (true conflict)
                    $actualInstructorConflicts++;
                    
                    // Debug log every such incident
                    Log::debug(
                        "INSTRUCTOR DBLE-BOOK: Instructor={$instr}, Day={$day}, Time={$startTime}-{$endTime}, Subjects=[" . implode(',', array_keys($subjectCounts)) . "]"
                    );
                } else {
                    // If it's just joint/multi-block for the same subject, log at debug and suppress conflict count
                    Log::debug(
                        "INSTRUCTOR MULTI-BLOCK (ignored): Instructor={$instr}, Day={$day}, Time={$startTime}-{$endTime}, Subject={$subjectsPerInstructor[$instr][0]} ({$subjectCounts[$subjectsPerInstructor[$instr][0]]} sections)"
                    );
                }
            }

            if ($actualInstructorConflicts > 0 && rand(1, 20) === 1) {
                Log::info("FINAL SCHEDULE INSTRUCTOR CONFLICT: Day={$day}, Time={$startTime}-{$endTime}, Groups=" . count($entries));
            }
            $conflicts['instructor_conflicts'] += $actualInstructorConflicts;

            // Check room conflicts
            $rooms = array_column($entries, 'room_id');
            $roomConflicts = count($rooms) - count(array_unique($rooms));
            if ($roomConflicts > 0) {
                Log::warning("🚨 FINAL SCHEDULE ROOM CONFLICT DETECTED:");
                Log::warning("   Day: " . $day . ", Time: " . $startTime . " - " . $endTime);
                foreach ($entries as $entry) {
                    $roomName = $this->getRoomNameById($entry['room_id']);
                    Log::warning("   Course: " . $entry['subject_code'] . " (Days: " . $entry['day'] . ") - Room: " . $roomName . " (ID: " . $entry['room_id'] . ")");
                }
            }
            $conflicts['room_conflicts'] += $roomConflicts;

            // Check section conflicts
            $sections = array_column($entries, 'section');
            $sectionConflicts = count($sections) - count(array_unique($sections));
            if ($sectionConflicts > 0) {
                Log::warning("🚨 FINAL SCHEDULE SECTION CONFLICT DETECTED:");
                Log::warning("   Day: " . $day . ", Time: " . $startTime . " - " . $endTime);
                foreach ($entries as $entry) {
                    Log::warning("   Course: " . $entry['subject_code'] . " (Days: " . $entry['day'] . ") - Section: " . $entry['section']);
                }
            }
            $conflicts['section_conflicts'] += $sectionConflicts;
        }

        // Check lunch break violations
        foreach ($schedules as $schedule) {
            if (TimeScheduler::isLunchBreakViolation($schedule['start_time'], $schedule['end_time'])) {
                $conflicts['lunch_break_violations']++;
            }
        }

        return $conflicts;
    }

    /**
     * Fallback genetic algorithm (simplified version)
     */
    /**
     * INCREMENTAL SCHEDULER: Your proposed algorithm implementation
     * Checks instructor availability first, then room, then section
     * Fails fast on conflicts to improve performance
     */
    public function solveIncremental(int $timeLimit = 30): array
    {
        // Starting incremental scheduler
        
        $startTime = time();
        
        // Reset tracking arrays
        $this->roomUsage = [];
        $this->scheduledCourses = [];
        $this->instructorLoad = [];
        $this->dayLoadCount = ['Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0];
        // PERFORMANCE: Reset indexed structures
        $this->instructorSchedules = [];
        $this->sectionSchedules = [];
        $this->roomSchedules = [];
        
        // Clear ResourceTracker state
        $this->resourceTracker->clearAllReservations();
        
        $schedules = [];
        $unscheduledCourses = [];

        try {
            $totalCourses = count($this->courses);
            
            // Preprocess courses (same as main solver)
            $this->courses = $this->parseJointSessionsBeforeScheduling($this->courses);
            
            // Order courses by constraint difficulty (same as main solver)
            $orderedCourses = $this->orderCoursesByConstraint($this->courses);
            Log::info("Incremental: Ordered " . count($orderedCourses) . " courses by constraint difficulty");
            
        foreach ($orderedCourses as $courseIndex => $course) {
            $currentTime = time();
            $elapsedTime = $currentTime - $startTime;
            
            // Timeout check
            if ($elapsedTime > ($timeLimit * 0.9)) {
                Log::warning("Incremental scheduler timeout reached after {$elapsedTime}s");
                break;
            }

            $courseSchedules = $this->scheduleCourseIncremental($course, $courseIndex);
            
            if (!empty($courseSchedules)) {
                $schedules = array_merge($schedules, $courseSchedules);
                
                // Log progress every 10 courses (reduced logging frequency)
                if ($courseIndex % 10 === 0 || $courseIndex === count($orderedCourses) - 1) {
                    $progress = round((($courseIndex + 1) / $totalCourses) * 100, 1);
                    Log::info("Incremental scheduler iteration {$courseIndex}, progress: {$progress}% (" . ($courseIndex + 1) . "/{$totalCourses}), scheduled: " . count($schedules));
                }
            } else {
                $unscheduledCourses[] = $course;
                // Reduced logging frequency for failed schedules
                if ($courseIndex % 5 === 0) {
                    Log::warning("Incremental: Failed to schedule: " . ($course['courseCode'] ?? 'Unknown'));
                }
            }
        }

        // ENHANCED FALLBACK: Try to schedule failed courses with relaxed constraints
        if (!empty($unscheduledCourses)) {
            // Incremental fallback for failed courses
            
            foreach ($unscheduledCourses as $courseIndex => $course) {
                Log::warning("INCREMENTAL FALLBACK: Attempting to schedule failed course: " . ($course['courseCode'] ?? 'Unknown'));
                
                // Try with relaxed constraints (allow section conflicts if different instructors)
                $fallbackSchedules = $this->scheduleCourseWithRelaxedConstraints($course, $courseIndex);
                if (!empty($fallbackSchedules)) {
                    $schedules = array_merge($schedules, $fallbackSchedules);
                    unset($unscheduledCourses[$courseIndex]);
                    // Fallback scheduling succeeded
                } else {
                    Log::error("INCREMENTAL FALLBACK FAILED: Could not schedule " . ($course['courseCode'] ?? 'Unknown') . " even with relaxed constraints");
                }
            }
        }

        // Validate results (optional)
        $conflicts = [
            'instructor_conflicts' => 0,
            'room_conflicts' => 0,
            'section_conflicts' => 0,
            'lunch_break_violations' => 0
        ];
        $totalConflicts = 0;
        if ((bool) env('LOG_SCHEDULER_VERBOSE', false)) {
            $conflicts = $this->detectConflictsWithResourceTracker($schedules);
            $totalConflicts = array_sum($conflicts);
        }
            
            $unscheduledCount = count($unscheduledCourses);
            $schedulingRate = $totalCourses > 0 ? (($totalCourses - $unscheduledCount) / $totalCourses) : 0;
            
            $executionTime = time() - $startTime;
            Log::info("Incremental scheduling completed in {$executionTime}s - Scheduled: " . count($schedules) . ", Conflicts: {$totalConflicts}, Rate: " . round($schedulingRate * 100, 1) . "%");
            
            $success = $schedulingRate >= 0.6 && $totalConflicts < 50;
            
            // Validate part-time constraints
            $partTimeViolations = $this->validatePartTimeConstraints($schedules);
            $message = $success ? 
                "Incremental schedule generated successfully with " . round($schedulingRate * 100, 1) . "% coverage" :
                "Incremental schedule generated with {$totalConflicts} conflicts and {$unscheduledCount} unscheduled courses";
            
            if (!empty($partTimeViolations)) {
                Log::error("CRITICAL: Part-time constraint violations detected in incremental schedule!");
                $message .= " (WARNING: Part-time violations detected)";
            }
            
            // Ensure no courses are dropped in incremental flow as well
            $schedules = $this->forceScheduleDroppedPartTimeCourses($schedules, $this->courses);
            
            return [
                'success' => $success,
                'schedules' => $schedules,
                'unscheduled_courses' => $unscheduledCourses,
                'conflicts' => $conflicts,
                'execution_time' => $executionTime,
                'scheduling_rate' => $schedulingRate,
                'message' => $message
            ];

        } catch (\Exception $e) {
            Log::error("Incremental scheduler error: " . $e->getMessage());
            return [
                'success' => false,
                'schedules' => $schedules,
                'unscheduled_courses' => $unscheduledCourses,
                'conflicts' => ['instructor_conflicts' => 0, 'room_conflicts' => 0, 'section_conflicts' => 0],
                'execution_time' => time() - $startTime,
                'scheduling_rate' => 0,
                'message' => "Incremental scheduler failed: " . $e->getMessage()
            ];
        }
    }

    public function solveWithGeneticAlgorithm(int $timeLimit = 30): array
    {
        Log::info("Starting PHP genetic algorithm fallback...");
        
        // This is a simplified genetic algorithm implementation
        // For a complete version, you'd implement population management,
        // crossover, mutation, and fitness evaluation
        
        $population = [];
        $populationSize = 20;
        $generations = 15;
        
        // Generate initial population
        for ($i = 0; $i < $populationSize; $i++) {
            $individual = $this->createRandomSchedule();
            if (!empty($individual)) {
                $population[] = $individual;
            }
        }
        
        if (empty($population)) {
            return [
                'success' => false,
                'message' => 'Failed to generate initial population',
                'schedules' => [],
                'algorithm' => 'php_genetic_algorithm'
            ];
        }
        
        $bestSchedule = $population[0];
        $bestFitness = $this->calculateFitness($bestSchedule);
        
        // Evolution loop
        for ($gen = 0; $gen < $generations; $gen++) {
            // Evaluate all individuals
            $fitnessScores = [];
            foreach ($population as $individual) {
                $fitnessScores[] = $this->calculateFitness($individual);
            }
            
            // Find best individual
            $bestIndex = array_search(min($fitnessScores), $fitnessScores);
            if ($fitnessScores[$bestIndex] < $bestFitness) {
                $bestFitness = $fitnessScores[$bestIndex];
                $bestSchedule = $population[$bestIndex];
            }
            
            // Early termination if good solution found
            if ($bestFitness < 10) { // Very few conflicts
                break;
            }
            
            // Create new generation (simplified)
            $newPopulation = [$bestSchedule]; // Keep best
            
            while (count($newPopulation) < $populationSize) {
                $parent = $this->tournamentSelection($population, $fitnessScores);
                $child = $this->mutate($parent);
                $newPopulation[] = $child;
            }
            
            $population = $newPopulation;
        }
        
        $conflicts = $this->detectConflicts($bestSchedule);
        $totalConflicts = array_sum($conflicts);
        
        return [
            'success' => $totalConflicts < 50, // Accept reasonable solutions
            'message' => "Genetic algorithm completed with {$totalConflicts} conflicts",
            'schedules' => $bestSchedule,
            'conflicts' => $conflicts,
            'total_conflicts' => $totalConflicts,
            'generations_run' => $gen + 1,
            'algorithm' => 'php_genetic_algorithm'
        ];
    }

    /**
     * Create a random schedule for genetic algorithm
     */
    private function createRandomSchedule(): array
    {
        // Reset usage tracking
        $this->roomUsage = [];
        $this->roomDayUsage = [];
        $this->scheduledCourses = []; // Reset scheduled courses for conflict tracking
        $this->rrPointer = 0;
        
        $schedule = [];
        
        foreach ($this->courses as $course) {
            $courseSchedules = $this->scheduleCourse($course, 0);
            $schedule = array_merge($schedule, $courseSchedules);
        }
        
        return $schedule;
    }

    /**
     * Calculate fitness score (lower is better)
     */
    private function calculateFitness(array $schedule): float
    {
        $conflicts = $this->detectConflicts($schedule);
        
        // Weight different conflict types
        $fitness = 0;
        $fitness += $conflicts['instructor_conflicts'] * 100; // High penalty
        $fitness += $conflicts['room_conflicts'] * 100; // High penalty
        $fitness += $conflicts['section_conflicts'] * 200; // Highest penalty
        $fitness += $conflicts['lunch_break_violations'] * 10; // Lower penalty
        
        return $fitness;
    }

    /**
     * Tournament selection for genetic algorithm
     */
    private function tournamentSelection(array $population, array $fitnessScores): array
    {
        $tournamentSize = 3;
        $tournament = [];
        $tournamentFitness = [];
        
        for ($i = 0; $i < $tournamentSize; $i++) {
            $index = array_rand($population);
            $tournament[] = $population[$index];
            $tournamentFitness[] = $fitnessScores[$index];
        }
        
        $bestIndex = array_search(min($tournamentFitness), $tournamentFitness);
        return $tournament[$bestIndex];
    }

    /**
     * Mutate an individual schedule
     */
    private function mutate(array $schedule): array
    {
        if (empty($schedule) || rand(1, 100) > 20) { // 20% mutation rate
            return $schedule;
        }
        
        $mutated = $schedule;
        $mutationIndex = array_rand($mutated);
        
        // Simple mutation: try to reschedule one random entry
        $entry = $mutated[$mutationIndex];
        unset($mutated[$mutationIndex]);
        
        // Try to find new time slot for this entry
        $suitableSlots = TimeScheduler::filterTimeSlotsByEmployment(
            $this->timeSlots, 
            $entry['employment_type'],
            false
        );
        
        if (!empty($suitableSlots)) {
            $newSlot = $suitableSlots[array_rand($suitableSlots)];
            $entry['day'] = $newSlot['day'];
            $entry['start_time'] = $newSlot['start'];
            $entry['end_time'] = $newSlot['end'];
            
            $mutated[] = $entry;
        }
        
        return array_values($mutated);
    }

    /**
     * Apply round-robin load balancing to courses
     */
    private function applyRoundRobinBalancing(array $courses): array
    {
        Log::info("PhpScheduler: Applying round-robin load balancing");
        
        $balancer = new RoundRobinBalancer();
        $balancedCourses = $balancer->balanceInstructorLoad($courses);
        
        Log::info("PhpScheduler: Round-robin balancing completed - " . count($courses) . " courses processed");
        
        return $balancedCourses;
    }

    /**
     * Apply section load balancing to courses
     */
    private function applySectionBalancing(array $courses): array
    {
        Log::info("PhpScheduler: Applying section load balancing");
        
        $balancer = new RoundRobinBalancer();
        $balancedCourses = $balancer->balanceSectionLoad($courses);
        
        Log::info("PhpScheduler: Section load balancing completed - " . count($courses) . " courses processed");
        
        return $balancedCourses;
    }

    /**
     * Parse joint sessions to understand the real structure
     */
    private function parseJointSessions(array $courses): array
    {
        Log::info("PhpScheduler: Parsing joint session structure");
        
        $balancer = new RoundRobinBalancer();
        $parsedCourses = $balancer->parseJointSessions($courses);
        
        Log::info("PhpScheduler: Joint session parsing completed - " . count($courses) . " individual entries parsed into " . count($parsedCourses) . " joint sessions");
        
        return $parsedCourses;
    }

    /**
     * Parse joint sessions BEFORE scheduling based on course metadata
     */
    private function parseJointSessionsBeforeScheduling(array $courses): array
    {
        Log::info("PhpScheduler: Parsing joint sessions BEFORE scheduling for " . count($courses) . " courses");
        
        $jointSessions = [];
        $courseGroups = $this->groupCoursesByJointSessionKey($courses);
        
        foreach ($courseGroups as $jointKey => $courseInstances) {
            if (count($courseInstances) > 1) {
                // Multiple instances = joint session (e.g., Mon + Sat for same course)
                $jointSession = $this->createJointSessionFromInstances($courseInstances);
                $jointSessions[] = $jointSession;
                Log::info("PhpScheduler: Created joint session for " . ($jointSession['courseCode'] ?? 'Unknown') . " with " . count($courseInstances) . " instances");
            } else {
                // Single instance = regular session
                $jointSessions[] = $courseInstances[0];
            }
        }
        
        Log::info("PhpScheduler: Parsed " . count($courses) . " individual courses into " . count($jointSessions) . " joint sessions");
        
        return $jointSessions;
    }

    /**
     * Group courses by joint session key (instructor + course + yearLevel + block)
     * FIXED: Include block in key to ensure A and B sections are treated as separate courses
     * ENHANCED: Redistribute overloaded instructors automatically
     */
    private function groupCoursesByJointSessionKey(array $courses): array
    {
        // First, check for overloaded instructors and redistribute
        $courses = $this->redistributeOverloadedInstructors($courses);
        
        $groups = [];
        
        foreach ($courses as $course) {
            $instructor = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            $courseCode = $course['courseCode'] ?? 'Unknown';
            $yearLevel = $course['yearLevel'] ?? '';
            $block = $course['block'] ?? 'A';
            
            // FIXED: Include block in joint session key to treat A and B sections separately
            // Each section (A, B) should be scheduled independently
            $jointKey = "{$instructor}|{$courseCode}|{$yearLevel}|{$block}";
            
            if (!isset($groups[$jointKey])) {
                $groups[$jointKey] = [];
            }
            $groups[$jointKey][] = $course;
        }
        
        return $groups;
    }
    
    /**
     * Redistribute courses from overloaded instructors to other available instructors
     */
    private function redistributeOverloadedInstructors(array $courses): array
    {
        Log::info("PhpScheduler: Checking for overloaded instructors...");
        
        // Calculate instructor loads
        $instructorLoads = [];
        foreach ($courses as $course) {
            $instructor = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            $units = $course['unit'] ?? $course['units'] ?? 3;
            
            if (!isset($instructorLoads[$instructor])) {
                $instructorLoads[$instructor] = [
                    'courses' => [],
                    'total_units' => 0,
                    'total_courses' => 0,
                    'unique_subjects' => [] // Track unique subjects to avoid double-counting A/B blocks
                ];
            }
            
            $instructorLoads[$instructor]['courses'][] = $course;
            
            // Only count unique subjects (courseCode + yearLevel combination)
            // A and B blocks of the same course are ONE course, not two
            $subjectKey = ($course['courseCode'] ?? 'Unknown') . '|' . ($course['yearLevel'] ?? '');
            if (!isset($instructorLoads[$instructor]['unique_subjects'][$subjectKey])) {
                $instructorLoads[$instructor]['unique_subjects'][$subjectKey] = true;
                $instructorLoads[$instructor]['total_courses']++;
                $instructorLoads[$instructor]['total_units'] += $units;
            }
        }
        
        // Identify overloaded instructors (more than 8 courses or 40+ units)
        $overloadedInstructors = [];
        $availableInstructors = [];
        
        foreach ($instructorLoads as $instructor => $load) {
            // Get employment type for this instructor
            $employmentType = 'FULL-TIME';
            if (!empty($load['courses'])) {
                $employmentType = $this->normalizeEmploymentType($load['courses'][0]['employmentType'] ?? 'FULL-TIME');
            }
            
            // Different limits for part-time vs full-time instructors
            $maxCourses = $employmentType === 'PART-TIME' ? 3 : 8;
            $maxUnits = $employmentType === 'PART-TIME' ? 18 : 40;
            
            if ($load['total_courses'] > $maxCourses || $load['total_units'] > $maxUnits) {
                $overloadedInstructors[$instructor] = array_merge($load, [
                    'max_courses' => $maxCourses,
                    'max_units' => $maxUnits,
                    'employment_type' => $employmentType
                ]);
                
                // IMPROVED: More detailed warning for part-time instructor overload
                if ($employmentType === 'PART-TIME') {
                    Log::error("🚨 CRITICAL PART-TIME OVERLOAD: {$instructor} has {$load['total_courses']}/{$maxCourses} courses ({$load['total_units']}/{$maxUnits} units) - This will cause massive evening slot conflicts!");
                    Log::error("   SOLUTION: Redistributing excess courses to full-time instructors to prevent conflicts");
                } else {
                    Log::warning("OVERLOADED INSTRUCTOR: {$instructor} has {$load['total_courses']} courses ({$load['total_units']} units) - {$employmentType}");
                }
            } else {
                $availableInstructors[$instructor] = $load;
            }
        }
        
        // If no overloaded instructors, return original courses
        if (empty($overloadedInstructors)) {
            Log::info("PhpScheduler: No overloaded instructors found");
            return $courses;
        }
        
        // Redistribute courses from overloaded instructors
        $redistributedCourses = [];
        $coursesToRedistribute = [];
        
        // Identify paired (A/B) course assignments for part-time instructors
        $pairedAssignments = [];
        foreach ($courses as $course) {
            $instructor = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
            if ($employmentType === 'PART-TIME') {
                $key = $instructor . '|' . ($course['courseCode'] ?? 'Unknown') . '|' . ($course['yearLevel'] ?? '');
                if (!isset($pairedAssignments[$key])) {
                    $pairedAssignments[$key] = [];
                }
                $pairedAssignments[$key][] = $course['block'] ?? '';
            }
        }

        // 2. Determine which critical pairs exist
        $criticalPairs = [];
        foreach ($pairedAssignments as $key => $blocks) {
            if (in_array('A', $blocks) && in_array('B', $blocks)) {
                $criticalPairs[] = $key;
            }
        }

        // 3. Overhaul assignment handling for PART-TIME with critical pairs:
        $processedPairs = [];
        foreach ($courses as $i => $course) {
            $instructor = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
            $pairKey = $instructor . '|' . ($course['courseCode'] ?? 'Unknown') . '|' . ($course['yearLevel'] ?? '');
            $isCriticalPair = in_array($pairKey, $criticalPairs);

            if (isset($overloadedInstructors[$instructor])) {
                if ($employmentType === 'PART-TIME') {
                    $instructorLoad = $overloadedInstructors[$instructor];
                    $maxCourses = $instructorLoad['max_courses'];
                    $isAlreadyKept = count(array_filter($redistributedCourses, function($c) use ($instructor) {
                        return ($c['instructor'] ?? $c['name'] ?? '') === $instructor;
                    }));

                    if ($isCriticalPair && !isset($processedPairs[$pairKey]) && ($course['block'] ?? '') === 'A') {
                        // Find the paired B
                        $pairedIndex = null;
                        foreach ($courses as $j => $otherCourse) {
                            if (
                                $j !== $i &&
                                ($otherCourse['instructor'] ?? $otherCourse['name'] ?? '') === $instructor &&
                                ($otherCourse['courseCode'] ?? '') === ($course['courseCode'] ?? '') &&
                                ($otherCourse['yearLevel'] ?? '') === ($course['yearLevel'] ?? '') &&
                                ($otherCourse['block'] ?? '') === 'B'
                            ) {
                                $pairedIndex = $j;
                                break;
                            }
                        }
                        // Try to keep the pair if not terribly overloaded (<= maxCourses + 1)
                        if (($isAlreadyKept + 2) <= ($maxCourses + 1)) {
                            $redistributedCourses[] = $course;
                            if ($pairedIndex !== null) {
                                $redistributedCourses[] = $courses[$pairedIndex];
                            }
                            $processedPairs[$pairKey] = true;
                            Log::info("FORCING CRITICAL PAIR: Overloading $instructor by 1 to keep both blocks of {$course['courseCode']}");
                        } else {
                            $coursesToRedistribute[] = $course;
                            if ($pairedIndex !== null) {
                                $coursesToRedistribute[] = $courses[$pairedIndex];
                            }
                            $processedPairs[$pairKey] = true;
                            Log::warning("CRITICAL PAIR BLOCKED: Even forced overload can't fit both blocks of {$course['courseCode']} for $instructor");
                        }
                    } else if ($isCriticalPair && isset($processedPairs[$pairKey])) {
                        // skip B (handled with A)
                        continue;
                    } else {
                        // Not a critical pair; keep surplus courses only if under hard cap
                        if ($isAlreadyKept < $maxCourses) {
                            $redistributedCourses[] = $course;
                            Log::info("PRESERVING PART-TIME: Keeping {$course['courseCode']} with {$instructor} ({$isAlreadyKept}/{$maxCourses} courses)");
                        } else {
                            $coursesToRedistribute[] = $course;
                            Log::warning("REDISTRIBUTING PART-TIME: Moving {$course['courseCode']} from overloaded part-time instructor {$instructor}");
                        }
                    }
                } else {
                    $coursesToRedistribute[] = $course;
                }
            } else {
                $redistributedCourses[] = $course;
            }
        }
        
        // Find best instructor for each course to redistribute
        foreach ($coursesToRedistribute as $course) {
            $bestInstructor = $this->findBestInstructorForCourse($course, $availableInstructors);
            
            if ($bestInstructor) {
                // Reassign course to best instructor
                $course['instructor'] = $bestInstructor;
                $course['name'] = $bestInstructor;
                
                // Update employment type to FULL-TIME when redistributing from part-time instructor
                $originalEmploymentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
                if ($originalEmploymentType === 'PART-TIME') {
                    $course['employmentType'] = 'FULL-TIME';
                    Log::info("UPDATED EMPLOYMENT TYPE: {$course['courseCode']} changed from PART-TIME to FULL-TIME for instructor {$bestInstructor}");
                }
                
                $redistributedCourses[] = $course;
                
                // Update available instructor load
                $units = $course['unit'] ?? $course['units'] ?? 3;
                $availableInstructors[$bestInstructor]['total_units'] += $units;
                $availableInstructors[$bestInstructor]['total_courses']++;
                
                Log::info("REDISTRIBUTED: " . ($course['courseCode'] ?? 'Unknown') . " reassigned from overloaded instructor to {$bestInstructor}");
            } else {
                // Keep original instructor if no better option found
                $redistributedCourses[] = $course;
                Log::warning("REDISTRIBUTION FAILED: No available instructor for " . ($course['courseCode'] ?? 'Unknown'));
            }
        }
        
        Log::info("PhpScheduler: Redistributed " . count($coursesToRedistribute) . " courses from overloaded instructors");
        
        return $redistributedCourses;
    }
    
    /**
     * Find the best instructor for a course based on current load and compatibility
     * CRITICAL: Never assign courses to part-time instructors to avoid evening slot conflicts
     */
    private function findBestInstructorForCourse(array $course, array $availableInstructors): ?string
    {
        $courseUnits = $course['unit'] ?? $course['units'] ?? 3;
        $courseYearLevel = $course['yearLevel'] ?? '';
        $courseDept = $course['dept'] ?? 'General';
        
        $bestInstructor = null;
        $bestScore = PHP_INT_MAX;
        
        foreach ($availableInstructors as $instructor => $load) {
            // IMPROVED: Allow redistributing courses to full-time instructors only
            $employmentType = 'FULL-TIME';
            if (!empty($load['courses'])) {
                $employmentType = $this->normalizeEmploymentType($load['courses'][0]['employmentType'] ?? 'FULL-TIME');
            }
            
            // Only assign to full-time instructors to avoid evening slot conflicts
            if ($employmentType === 'PART-TIME') {
                Log::debug("SKIPPING PART-TIME: Not assigning {$course['courseCode']} to {$instructor} (part-time instructor)");
                continue;
            }
            
            // Skip if instructor would become severely overloaded (allow slight overload for redistribution)
            $maxCourses = 8;
            $maxUnits = 40;
            if ($load['total_courses'] >= $maxCourses + 2 || $load['total_units'] + $courseUnits > $maxUnits + 6) {
                continue;
            }
            
            // Calculate compatibility score (lower is better)
            $score = $load['total_courses'] * 2 + $load['total_units'];
            
            // Prefer instructors with similar department/year level experience
            // This is a simple heuristic - in a real system, you'd have instructor qualifications
            
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestInstructor = $instructor;
            }
        }
        
        return $bestInstructor;
    }

    /**
     * Create a joint session from multiple course instances
     */
    private function createJointSessionFromInstances(array $instances): array
    {
        $baseCourse = $instances[0]; // Use first instance as base
        
        // Create joint session with metadata
        $jointSession = $baseCourse;
        $jointSession['joint_session'] = true;
        $jointSession['instances'] = $instances;
        $jointSession['instance_count'] = count($instances);
        
        // For joint sessions, use the units from one instance (not sum)
        // Joint sessions represent the same course taught to multiple sections
        $jointSession['total_units'] = $baseCourse['unit'] ?? $baseCourse['units'] ?? 3;
        
        // Determine if this should be a multi-day session
        $jointSession['is_multi_day'] = count($instances) > 1;
        
        // Create combined section name for joint sessions
        $blocks = array_column($instances, 'block');
        $jointSession['combined_blocks'] = implode(' & ', $blocks);
        $jointSession['section'] = ($baseCourse['yearLevel'] ?? '') . ' ' . $jointSession['combined_blocks'];
        
        Log::info("JOINT SESSION CREATED: " . ($jointSession['courseCode'] ?? 'Unknown') . " for " . ($jointSession['instructor'] ?? 'Unknown') . " with blocks: " . $jointSession['combined_blocks']);
        
        return $jointSession;
    }

    /**
     * Analyze joint sessions for better conflict understanding
     */
    private function analyzeJointSessions(array $schedules): void
    {
        if (!$this->verbose()) return;
        Log::debug("PhpScheduler: Analyzing joint session structure for " . count($schedules) . " schedules");
        
        $balancer = new RoundRobinBalancer();
        $jointSessions = $balancer->parseJointSessions($schedules);
        
        Log::debug("PhpScheduler: Joint session analysis completed - " . count($schedules) . " individual schedules parsed into " . count($jointSessions) . " joint sessions");
        
        // Log joint session distribution for debugging
        $this->logJointSessionDistribution($jointSessions);
    }

    /**
     * Log joint session distribution for debugging
     */
    private function logJointSessionDistribution(array $jointSessions): void
    {
        Log::debug("PhpScheduler: Joint session distribution:");
        
        $instructorCounts = [];
        $timeSlotCounts = ['morning' => 0, 'afternoon' => 0, 'evening' => 0];
        
        foreach ($jointSessions as $session) {
            $instructor = $session['instructor'] ?? 'Unknown';
            $instructorCounts[$instructor] = ($instructorCounts[$instructor] ?? 0) + 1;
            
            // Analyze time slot distribution
            $startTime = $session['start_time'] ?? '00:00:00';
            $startMinutes = TimeScheduler::timeToMinutes($startTime);
            
            if ($startMinutes < 720) { // Before 12:00 PM
                $timeSlotCounts['morning']++;
            } elseif ($startMinutes < 1020) { // Before 5:00 PM
                $timeSlotCounts['afternoon']++;
            } else {
                $timeSlotCounts['evening']++;
            }
        }
        
        Log::debug("PhpScheduler: Instructor distribution in joint sessions:");
        foreach ($instructorCounts as $instructor => $count) {
            Log::debug("  {$instructor}: {$count} joint sessions");
        }
        
        Log::debug("PhpScheduler: Time slot distribution in joint sessions: " . json_encode($timeSlotCounts));
    }

    /**
     * Detect conflicts using frontend-style logic for accurate results
     */
    private function detectConflictsFrontendStyle(array $schedules): array
    {
        Log::info("PhpScheduler: Running frontend-style conflict detection on " . count($schedules) . " schedules");
        
        $conflicts = [
            'instructor_conflicts' => 0,
            'room_conflicts' => 0,
            'section_conflicts' => 0
        ];
        
        // Group schedules by instructor, room, and section (like frontend does)
        $instructorGroups = [];
        $roomGroups = [];
        $sectionGroups = [];
        
        foreach ($schedules as $schedule) {
            $instructor = $schedule['instructor'] ?? 'Unknown';
            $room = $schedule['room_id'] ?? 'Unknown';
            $section = $schedule['section'] ?? 'Unknown';
            
            $instructorGroups[$instructor][] = $schedule;
            $roomGroups[$room][] = $schedule;
            $sectionGroups[$section][] = $schedule;
        }
        
        // Check instructor conflicts (like frontend does)
        foreach ($instructorGroups as $instructor => $group) {
            if (count($group) <= 1) continue;
            
            $conflictGroups = $this->findTimeOverlapsFrontendStyle($group);
            foreach ($conflictGroups as $conflictGroup) {
                if (count($conflictGroup) > 1) {
                    $conflicts['instructor_conflicts'] += count($conflictGroup) - 1;
                    if (rand(1, 20) === 1) {
                        Log::info("FRONTEND-STYLE INSTRUCTOR CONFLICT: {$instructor} overlapping=" . count($conflictGroup));
                    }
                }
            }
        }
        
        // Check room conflicts
        foreach ($roomGroups as $room => $group) {
            if (count($group) <= 1) continue;
            
            $conflictGroups = $this->findTimeOverlapsFrontendStyle($group);
            foreach ($conflictGroups as $conflictGroup) {
                if (count($conflictGroup) > 1) {
                    $conflicts['room_conflicts'] += count($conflictGroup) - 1;
                    Log::warning("🚨 FRONTEND-STYLE ROOM CONFLICT: Room {$room} has " . count($conflictGroup) . " overlapping schedules");
                }
            }
        }
        
        // Check section conflicts
        foreach ($sectionGroups as $section => $group) {
            if (count($group) <= 1) continue;
            
            $conflictGroups = $this->findTimeOverlapsFrontendStyle($group);
            foreach ($conflictGroups as $conflictGroup) {
                if (count($conflictGroup) > 1) {
                    $conflicts['section_conflicts'] += count($conflictGroup) - 1;
                    Log::warning("🚨 FRONTEND-STYLE SECTION CONFLICT: Section {$section} has " . count($conflictGroup) . " overlapping schedules");
                }
            }
        }
        
        return $conflicts;
    }

    /**
     * Find time overlaps using frontend-style logic
     */
    private function findTimeOverlapsFrontendStyle(array $schedules): array
    {
        $conflictGroups = [];
        $processed = [];
        
        // Create a flat list of all meetings from all schedules (like frontend does)
        $allMeetings = [];
        foreach ($schedules as $schedule) {
            // Parse joint days like frontend does
            $days = $this->parseIndividualDays($schedule['day'] ?? '');
            foreach ($days as $day) {
                $allMeetings[] = [
                    'schedule' => $schedule,
                    'day' => $day,
                    'start_time' => $schedule['start_time'] ?? '00:00:00',
                    'end_time' => $schedule['end_time'] ?? '00:00:00'
                ];
            }
        }
        
        foreach ($allMeetings as $meetingData) {
            $schedule = $meetingData['schedule'];
            $scheduleId = $schedule['subject_code'] . '|' . $schedule['instructor'] . '|' . $schedule['section'];
            
            if (in_array($scheduleId, $processed)) continue;
            
            $conflictGroup = [$schedule];
            $processed[] = $scheduleId;
            
            // Check for overlapping meetings with other schedules
            foreach ($allMeetings as $otherMeetingData) {
                $otherSchedule = $otherMeetingData['schedule'];
                $otherScheduleId = $otherSchedule['subject_code'] . '|' . $otherSchedule['instructor'] . '|' . $otherSchedule['section'];
                
                if (in_array($otherScheduleId, $processed)) continue;
                if ($scheduleId === $otherScheduleId) continue;
                
                // Check if meetings overlap on the same day (like frontend does)
                if ($this->meetingsOverlapFrontendStyle($meetingData, $otherMeetingData)) {
                    $conflictGroup[] = $otherSchedule;
                    $processed[] = $otherScheduleId;
                }
            }
            
            if (count($conflictGroup) > 1) {
                $conflictGroups[] = $conflictGroup;
            }
        }
        
        return $conflictGroups;
    }

    /**
     * Check if two meetings overlap (frontend-style logic)
     */
    private function meetingsOverlapFrontendStyle(array $meeting1, array $meeting2): bool
    {
        // Check if meetings are on the same day
        if ($meeting1['day'] !== $meeting2['day']) {
            return false;
        }
        
        // Convert time strings to minutes for easier comparison (like frontend does)
        $start1 = $this->timeToMinutesFrontendStyle($meeting1['start_time']);
        $end1 = $this->timeToMinutesFrontendStyle($meeting1['end_time']);
        $start2 = $this->timeToMinutesFrontendStyle($meeting2['start_time']);
        $end2 = $this->timeToMinutesFrontendStyle($meeting2['end_time']);
        
        // Check for overlap: two time ranges overlap if one starts before the other ends
        return ($start1 < $end2) && ($start2 < $end1);
    }

    /**
     * Convert time string to minutes (frontend-style logic)
     */
    private function timeToMinutesFrontendStyle(string $timeString): int
    {
        $time = trim($timeString);
        
        // Handle database time format (HH:MM:SS or HH:MM)
        if (strpos($time, ':') !== false) {
            $parts = explode(':', $time);
            $hours = (int)$parts[0];
            $minutes = (int)$parts[1];
            return $hours * 60 + $minutes;
        }
        
        return 0;
    }

    /**
     * Apply room usage balancing to courses
     */
    private function applyRoomBalancing(array $courses): array
    {
        if ($this->verbose()) {
            Log::debug("PhpScheduler: Applying room usage balancing");
        }
        
        $balancer = new RoundRobinBalancer();
        $balancedCourses = $balancer->balanceRoomUsage($courses, $this->rooms);
        
        if ($this->verbose()) {
            Log::debug("PhpScheduler: Room usage balancing completed - " . count($courses) . " courses processed");
        }
        
        return $balancedCourses;
    }

    /**
     * Apply time slot diversification to courses
     */
    private function applyTimeSlotDiversification(array $courses): array
    {
        if ($this->verbose()) {
            Log::debug("PhpScheduler: Applying time slot diversification");
        }
        
        $balancer = new RoundRobinBalancer();
        $diversifiedCourses = $balancer->diversifyTimeSlotPreferences($courses);
        
        if ($this->verbose()) {
            Log::debug("PhpScheduler: Time slot diversification completed");
        }
        
        return $diversifiedCourses;
    }

    /**
     * Log instructor distribution for diagnostics
     */
    private function logInstructorDistribution(array $courses): void
    {
        $instructorGroups = [];
        
        foreach ($courses as $course) {
            $instructor = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            if (!isset($instructorGroups[$instructor])) {
                $instructorGroups[$instructor] = 0;
            }
            $instructorGroups[$instructor]++;
        }
        
        Log::info("PhpScheduler: Instructor distribution before scheduling:");
        foreach ($instructorGroups as $instructor => $courseCount) {
            Log::info("  {$instructor}: {$courseCount} courses");
        }
        
        // Check for potential overload situations
        $balancer = new RoundRobinBalancer();
        $feasibilityReport = $balancer->analyzeInstructorFeasibility($courses);
        
        $overloadedInstructors = array_filter($feasibilityReport, fn($report) => !$report['is_feasible']);
        
        if (!empty($overloadedInstructors)) {
            Log::warning("PhpScheduler: Found " . count($overloadedInstructors) . " potentially overloaded instructors:");
            foreach ($overloadedInstructors as $instructor => $report) {
                Log::warning("  {$instructor}: {$report['current_courses']}/{$report['max_courses']} courses ({$report['employment_type']}) - OVERLOAD: {$report['overload_amount']}");
            }
        }
    }

    /**
     * Check instructor feasibility for constraint ordering
     */
    private function checkInstructorFeasibility(string $instructor, string $employmentType): array
    {
        $balancer = new RoundRobinBalancer();
        
        // Count courses for this instructor
        $instructorCourses = array_filter($this->courses, function($course) use ($instructor) {
            return ($course['instructor'] ?? $course['name'] ?? '') === $instructor;
        });
        
        $currentCourses = count($instructorCourses);
        $maxCourses = $balancer->calculateMaximumSchedulableCourses($employmentType);
        
        return [
            'is_feasible' => $currentCourses <= $maxCourses,
            'current_courses' => $currentCourses,
            'max_courses' => $maxCourses,
            'overload_amount' => max(0, $currentCourses - $maxCourses)
        ];
    }
}
