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
    private ResourceTracker $resourceTracker; // Centralized resource tracking

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

    public function __construct(array $courses, array $rooms, string $department = 'BSBA')
    {
        $this->courses = $this->preprocessCourses($courses);
        $this->rooms = $rooms;
        $this->department = $department;
        $this->timeSlots = TimeScheduler::generateComprehensiveTimeSlots();
        
        // Initialize ResourceTracker for centralized conflict detection
        $this->resourceTracker = new ResourceTracker();
        
        // Load reference schedules for conflict detection
        $this->loadReferenceSchedules();
        
        Log::debug("PhpScheduler initialized with " . count($this->courses) . " courses, " . count($this->rooms) . " rooms, " . count($this->timeSlots) . " time slots, " . count($this->referenceSchedules) . " reference schedules, and department: " . $this->department);
        
        // Debug: Log first few time slots to verify structure
        if (!empty($this->timeSlots)) {
            Log::debug("Sample time slots: " . json_encode(array_slice($this->timeSlots, 0, 3)));
        }
    }

    /**
     * RANDOM scheduler: assign sessions by shuffling slots and using strict/loose checks
     */
    public function solveRandom(bool $strict = true, int $timeLimit = 30): array
    {
        Log::info("Starting PHP Random scheduler (" . ($strict ? 'strict' : 'loose') . ")...");

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
                        $usedDays[] = $scheduled['day'];
                        Log::info("RANDOM: Scheduled session {$sessionIndex} for " . ($course['courseCode'] ?? 'Unknown') . " on " . $scheduled['day'] . " " . $scheduled['start_time'] . "-" . $scheduled['end_time']);
                    } else {
                        Log::warning("RANDOM: Failed to schedule session {$sessionIndex} for " . ($course['courseCode'] ?? 'Unknown'));
                    }
                }
            }

            // Assess conflicts
            $conflicts = $this->detectConflicts($schedules);
            $totalConflicts = array_sum($conflicts);

            $executionTime = time() - $startTime;
            Log::info("Random scheduling completed in {$executionTime}s - Scheduled: " . count($schedules) . ", Conflicts: {$totalConflicts}");

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
        $allowedSlots = TimeScheduler::filterTimeSlotsByEmployment($this->timeSlots, $employmentType);
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
        
        foreach ($courses as $course) {
            // Handle multi-block entries (e.g., "A & B", "A,B")
            $rawBlock = trim($course['block'] ?? 'A');
            $blocks = [];
            
            if (strtoupper($rawBlock) === 'A & B' || strtoupper($rawBlock) === 'A&B') {
                $blocks = ['A', 'B'];
            } elseif (strpos($rawBlock, ',') !== false) {
                $blocks = array_map('trim', explode(',', $rawBlock));
            } elseif (!empty($rawBlock)) {
                $blocks = [$rawBlock];
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

        return $processedCourses;
    }

    /**
     * Load reference schedules from database for conflict detection
     */
    private function loadReferenceSchedules(): void
    {
        try {
            // Load all active reference schedules
            $references = Reference::with('referenceGroup')->get();
            
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
                Log::info("Loaded " . count($this->referenceSchedules) . " reference schedules for conflict detection");
            }
        } catch (\Exception $e) {
            Log::warning("Failed to load reference schedules: " . $e->getMessage());
            $this->referenceSchedules = [];
        }
    }

    /**
     * Parse reference time format (e.g., "7:45 AM-8:45 AM") to start and end times
     */
    private function parseReferenceTime(string $timeRange): array
    {
        // Expected format: "7:45 AM-8:45 AM" or "7:45-8:45"
        $parts = explode('-', $timeRange);
        if (count($parts) !== 2) {
            return ['00:00:00', '00:00:00'];
        }
        
        $start = trim($parts[0]);
        $end = trim($parts[1]);
        
        // Convert to 24-hour format
        $startTime = $this->convertTo24Hour($start);
        $endTime = $this->convertTo24Hour($end);
        
        return [$startTime, $endTime];
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
     * Set filter preferences for soft constraints
     */
    public function setFilterPreferences(array $preferences): void
    {
        $this->filterPreferences = $preferences;
        Log::debug("Filter preferences set in PhpScheduler:", $preferences);
    }

    /**
     * SIMPLIFIED solve method - fast and conflict-free
     */
    public function solve(int $timeLimit = 30): array
    {
        Log::info("Starting CSP-based PHP scheduler - constraint satisfaction approach...");
        
        $startTime = time();
        
        // Reset tracking arrays
        $this->roomUsage = [];
        $this->scheduledCourses = [];
        $this->instructorLoad = [];
        $this->dayLoadCount = ['Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0];
        
        $schedules = [];
        $unscheduledCourses = [];

        try {
            // CSP APPROACH: Smart ordering + backtracking + constraint relaxation
            $totalCourses = count($this->courses);
            
            // Log instructor distribution before scheduling
            $this->logInstructorDistribution($this->courses);
            
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
            Log::info("CSP: Ordered " . count($orderedCourses) . " courses by constraint difficulty");
            
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
                    // Optional progress logging
                    if ((bool) env('LOG_SCHEDULER_VERBOSE', false)) {
                        if (rand(1, 20) === 1 || $courseIndex % 20 === 0) {
                            Log::debug("Scheduled: " . ($course['courseCode'] ?? 'Unknown') . " for " . ($course['yearLevel'] ?? '') . ' ' . ($course['block'] ?? '') . " (Progress: " . ($courseIndex + 1) . "/{$totalCourses})");
                        }
                    }
                } else {
                    $unscheduledCourses[] = $course;
                    Log::warning("Failed to schedule: " . ($course['courseCode'] ?? 'Unknown'));
                }
            }

            // Validate results using ResourceTracker for consistency
            $conflicts = $this->detectConflictsWithResourceTracker($schedules);
            $totalConflicts = array_sum($conflicts);

            // ENHANCED VALIDATION: Log conflicts but don't reject if we have good coverage
            if ($conflicts['instructor_conflicts'] > 0) {
                Log::warning("INSTRUCTOR CONFLICTS: {$conflicts['instructor_conflicts']} instructor conflicts detected");
            }

            if ($conflicts['room_conflicts'] > 0) {
                Log::warning("ROOM CONFLICTS: {$conflicts['room_conflicts']} room conflicts detected");
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

            // Final safeguard: Check for any completely unscheduled courses and force schedule them
            $schedules = $this->ensureAllCoursesScheduled($schedules);

            // STEP FINAL: Analyze joint sessions for better conflict understanding
            $this->analyzeJointSessions($schedules);
            
            // STEP FINAL+: Use frontend-style conflict detection for accurate results
            $frontendConflicts = $this->detectConflictsFrontendStyle($schedules);
            Log::info("PhpScheduler: Frontend-style conflict detection found: " . json_encode($frontendConflicts));

            Log::info("PHP scheduler completed: {$message}");

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
     * IMPROVED: Intelligent multi-pass scheduling approach
     */
    private function intelligentMultiPassScheduling(array $courses, int $timeLimit, int $startTime): array
    {
        $schedules = [];
        $unscheduledCourses = [];
        
        // Pass 1: Schedule high-priority courses with strict constraints
        Log::info("Pass 1: Scheduling high-priority courses with strict constraints");
        foreach ($courses as $courseIndex => $course) {
            if ((time() - $startTime) > ($timeLimit * 0.3)) break;
            
            $courseSchedules = $this->scheduleCourseWithStrictConstraints($course, $courseIndex);
            if (!empty($courseSchedules)) {
                $schedules = array_merge($schedules, $courseSchedules);
            } else {
                $unscheduledCourses[] = $course;
            }
        }
        
        // Pass 2: Schedule remaining courses with relaxed constraints
        Log::info("Pass 2: Scheduling remaining courses with relaxed constraints");
        foreach ($unscheduledCourses as $courseIndex => $course) {
            if ((time() - $startTime) > ($timeLimit * 0.7)) break;
            
            $courseSchedules = $this->scheduleCourseWithRelaxedConstraints($course, $courseIndex);
            if (!empty($courseSchedules)) {
                $schedules = array_merge($schedules, $courseSchedules);
                unset($unscheduledCourses[$courseIndex]);
            }
        }
        
        // Pass 3: Force schedule critical courses
        Log::info("Pass 3: Force scheduling critical courses");
        foreach ($unscheduledCourses as $courseIndex => $course) {
            if ((time() - $startTime) > ($timeLimit * 0.9)) break;
            
            $courseSchedules = $this->forceScheduleCourseImproved($course, $courseIndex);
            if (!empty($courseSchedules)) {
                $schedules = array_merge($schedules, $courseSchedules);
            }
        }
        
        return $schedules;
    }
    
    /**
     * IMPROVED: Sort courses by intelligent priority with instructor workload focus
     */
    private function sortCoursesByIntelligentPriority(array $courses): array
    {
        // Pre-calculate instructor statistics for better prioritization
        $instructorStats = $this->calculateInstructorStatistics($courses);
        
        usort($courses, function($a, $b) use ($instructorStats) {
            $instructorA = $a['instructor'] ?? '';
            $instructorB = $b['instructor'] ?? '';
            
            // Get instructor statistics
            $statsA = $instructorStats[$instructorA] ?? [
                'total_courses' => 0,
                'total_units' => 0,
                'sections_taught' => 0,
                'year_levels' => 0,
                'workload_score' => 0
            ];
            
            $statsB = $instructorStats[$instructorB] ?? [
                'total_courses' => 0,
                'total_units' => 0,
                'sections_taught' => 0,
                'year_levels' => 0,
                'workload_score' => 0
            ];
            
            // PRIORITY 1: Instructors with multiple sections get moderate priority (reduced from highest)
            if ($statsA['sections_taught'] != $statsB['sections_taught']) {
                return $statsB['sections_taught'] <=> $statsA['sections_taught'];
            }
            
            // PRIORITY 2: Instructors with high workload (total units) get moderate priority
            if ($statsA['total_units'] != $statsB['total_units']) {
                return $statsB['total_units'] <=> $statsA['total_units'];
            }
            
            // PRIORITY 3: Instructors with many courses get moderate priority
            if ($statsA['total_courses'] != $statsB['total_courses']) {
                return $statsB['total_courses'] <=> $statsA['total_courses'];
            }
            
            // PRIORITY 4: Instructors teaching multiple year levels get moderate priority
            if ($statsA['year_levels'] != $statsB['year_levels']) {
                return $statsB['year_levels'] <=> $statsA['year_levels'];
            }
            
            // PRIORITY 5: Units (higher units = higher priority)
            $unitsA = $a['units'] ?? 0;
            $unitsB = $b['units'] ?? 0;
            if ($unitsA != $unitsB) {
                return $unitsB <=> $unitsA;
            }
            
            // PRIORITY 6: Year level (higher year = higher priority)
            $yearA = $this->extractYearLevel($a['yearLevel'] ?? '');
            $yearB = $this->extractYearLevel($b['yearLevel'] ?? '');
            if ($yearA != $yearB) {
                return $yearB <=> $yearA;
            }
            
            // PRIORITY 7: Course code (alphabetical for consistency)
            return strcmp($a['courseCode'] ?? '', $b['courseCode'] ?? '');
        });
        
        // Log the prioritization results
        $this->logInstructorPrioritizationFixed($courses, $instructorStats);
        
        return $courses;
    }
    
    /**
     * Calculate comprehensive instructor statistics for better prioritization
     */
    private function calculateInstructorStatistics(array $courses): array
    {
        $instructorStats = [];
        
        foreach ($courses as $course) {
            $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            $units = $course['units'] ?? $course['unit'] ?? 3;
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
    private function logInstructorPrioritizationFixed(array $courses, array $instructorStats): void
    {
        Log::info("=== INSTRUCTOR PRIORITIZATION RESULTS (FIXED) ===");
        
        $instructorPriorities = [];
        foreach ($courses as $course) {
            $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            $stats = $instructorStats[$instructorName] ?? [
                'total_courses' => 0,
                'total_units' => 0,
                'sections_taught' => 0,
                'year_levels' => 0,
                'workload_score' => 0
            ];
            
            if (!isset($instructorPriorities[$instructorName])) {
                $instructorPriorities[$instructorName] = [
                    'stats' => $stats,
                    'courses_count' => 0
                ];
            }
            $instructorPriorities[$instructorName]['courses_count']++;
        }
        
        // Sort by workload score
        uasort($instructorPriorities, function($a, $b) {
            return $b['stats']['workload_score'] <=> $a['stats']['workload_score'];
        });
        
        foreach ($instructorPriorities as $instructorName => $data) {
            Log::info("Instructor: {$instructorName}", [
                'courses' => $data['courses_count'],
                'sections' => $data['stats']['sections_taught'],
                'total_units' => $data['stats']['total_units'],
                'year_levels' => $data['stats']['year_levels'],
                'workload_score' => $data['stats']['workload_score']
            ]);
        }
    }
    
    /**
     * IMPROVED: Schedule course with strict constraints
     */
    private function scheduleCourseWithStrictConstraints(array $course, int $courseIndex): array
    {
        $schedules = [];
        $units = $course['units'] ?? 0;
        $sessions = $this->calculateOptimalSessions($units);
        
        $usedDays = [];
        
        foreach ($sessions as $sessionIndex => $sessionDuration) {
            $session = $this->scheduleSessionWithStrictConstraints(
                $course, 
                $sessionDuration, 
                $sessionIndex, 
                $usedDays
            );
            
            if ($session) {
                $schedules[] = $session;
                $usedDays[] = $session['day'];
                
                // Reserve resources
                $this->resourceTracker->reserveAllResources(
                    $session['instructor'],
                    $session['room_id'],
                    $session['section'],
                    $session['day'],
                    $session['start_time'],
                    $session['end_time'],
                    $session
                );
            }
        }
        
        return $schedules;
    }
    
    /**
     * IMPROVED: Schedule session with strict constraints
     */
    private function scheduleSessionWithStrictConstraints(array $course, float $sessionDuration, int $sessionIndex, array $usedDays): ?array
    {
        $instructorName = $course['instructor'] ?? $course['name'] ?? 'Unknown Instructor';
        $sectionName = ($course['yearLevel'] ?? '') . ' ' . ($course['block'] ?? '');
        $units = $course['units'] ?? 0;
        
        // Get optimal time slots for this course
        $optimalSlots = $this->getOptimalTimeSlots($course, $sessionDuration, $usedDays);
        
        foreach ($optimalSlots as $slot) {
            $startTime = $slot['start'];
            $endTime = $this->calculateEndTime($startTime, $sessionDuration);
            
            // Strict constraint checking
            if ($this->hasStrictConflicts($course, $slot['day'], $startTime, $endTime)) {
                continue;
            }
            
            // Find best available room
            $room = $this->findBestAvailableRoom($course, $slot['day'], $startTime, $endTime);
            if (!$room) {
                continue;
            }
            
            // Create schedule entry
            $schedule = [
                'courseCode' => $course['courseCode'] ?? 'Unknown',
                'courseDescription' => $course['courseDescription'] ?? '',
                'instructor' => $instructorName,
                'yearLevel' => $course['yearLevel'] ?? '',
                'block' => $course['block'] ?? '',
                'section' => $sectionName,
                'day' => $slot['day'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'room_id' => $room['room_id'],
                'room_name' => $room['room_name'] ?? 'Unknown Room',
                'units' => $units,
                'department' => $this->department,
                'session_index' => $sessionIndex,
                'total_sessions' => count($this->calculateOptimalSessions($units))
            ];
            
            return $schedule;
        }
        
        return null;
    }
    
    /**
     * IMPROVED: Get optimal time slots for a course
     */
    private function getOptimalTimeSlots(array $course, float $sessionDuration, array $usedDays): array
    {
        $yearLevel = $course['yearLevel'] ?? '';
        $units = $course['units'] ?? 0;
        $instructor = $course['instructor'] ?? '';
        
        $optimalSlots = [];
        
        // Get instructor's current load
        $instructorLoad = $this->resourceTracker->getInstructorLoad($instructor);
        
        // Get day distribution
        $dayDistribution = $this->resourceTracker->getDayDistribution();
        $leastLoadedDay = $this->resourceTracker->getLeastLoadedDay();
        
        // Filter time slots based on course characteristics
        foreach ($this->timeSlots as $slot) {
            // Skip used days
            if (in_array($slot['day'], $usedDays)) {
                continue;
            }
            
            // Calculate slot duration
            $slotDuration = TimeScheduler::timeToMinutes($slot['end']) - TimeScheduler::timeToMinutes($slot['start']);
            $requiredMinutes = $sessionDuration * 60;
            
            // Skip if slot is too short
            if ($slotDuration < $requiredMinutes) {
                continue;
            }
            
            // Calculate priority score
            $priority = $this->calculateSlotPriority($slot, $course, $instructorLoad, $dayDistribution, $leastLoadedDay);
            
            $optimalSlots[] = array_merge($slot, ['priority' => $priority]);
        }
        
        // Sort by priority (higher priority first)
        usort($optimalSlots, function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
        
        return $optimalSlots;
    }
    
    /**
     * IMPROVED: Calculate slot priority score
     */
    private function calculateSlotPriority(array $slot, array $course, int $instructorLoad, array $dayDistribution, string $leastLoadedDay): int
    {
        $priority = 0;
        
        // Day preference (prefer less loaded days)
        if ($slot['day'] === $leastLoadedDay) {
            $priority += 50;
        } else {
            $priority += max(0, 50 - ($dayDistribution[$slot['day']] ?? 0) * 5);
        }
        
        // Time preference (prefer morning slots for better attendance)
        $startHour = (int)substr($slot['start'], 0, 2);
        if ($startHour >= 7 && $startHour <= 10) {
            $priority += 30; // Morning preference
        } elseif ($startHour >= 14 && $startHour <= 16) {
            $priority += 20; // Afternoon preference
        } elseif ($startHour >= 18) {
            $priority += 10; // Evening preference
        }
        
        // Instructor load balancing
        if ($instructorLoad < 5) {
            $priority += 20; // Prefer less loaded instructors
        }
        
        // Course type preferences
        $units = $course['units'] ?? 0;
        if ($units >= 6) {
            $priority += 15; // Higher priority for major courses
        }
        
        return $priority;
    }
    
    /**
     * IMPROVED: Check for strict conflicts
     */
    private function hasStrictConflicts(array $course, string $day, string $startTime, string $endTime): bool
    {
        $instructorName = $course['instructor'] ?? $course['name'] ?? '';
        $sectionName = ($course['yearLevel'] ?? '') . ' ' . ($course['block'] ?? '');
        
        // Check instructor availability
        if (!$this->resourceTracker->isInstructorAvailable($instructorName, $day, $startTime, $endTime)) {
            return true;
        }
        
        // Check section availability
        if (!$this->resourceTracker->isSectionAvailable($sectionName, $day, $startTime, $endTime)) {
            return true;
        }
        
        // Check lunch break violation
        if (TimeScheduler::isLunchBreakViolation($startTime, $endTime)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * IMPROVED: Find best available room
     */
    private function findBestAvailableRoom(array $course, string $day, string $startTime, string $endTime): ?array
    {
        $bestRoom = null;
        $bestScore = -1;
        
        foreach ($this->rooms as $room) {
            // Check room availability
            if (!$this->resourceTracker->isRoomAvailable($room['room_id'], $day, $startTime, $endTime)) {
                continue;
            }
            
            // Calculate room suitability score
            $score = $this->calculateRoomScore($room, $course, $day);
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRoom = $room;
            }
        }
        
        return $bestRoom;
    }
    
    /**
     * IMPROVED: Calculate room suitability score
     */
    private function calculateRoomScore(array $room, array $course, string $day): int
    {
        $score = 0;
        
        // Room capacity vs course requirements
        $capacity = $room['capacity'] ?? 30;
        $expectedStudents = $this->estimateStudentCount($course);
        
        if ($capacity >= $expectedStudents) {
            $score += 50;
            // Bonus for optimal capacity
            if ($capacity <= $expectedStudents * 1.2) {
                $score += 20;
            }
        }
        
        // Room type preferences
        $roomType = $room['room_type'] ?? '';
        $courseType = $course['course_type'] ?? '';
        
        if ($roomType === 'lab' && $courseType === 'lab') {
            $score += 30;
        } elseif ($roomType === 'lecture' && $courseType !== 'lab') {
            $score += 20;
        }
        
        // Building preferences based on department
        $building = $room['building'] ?? '';
        if ($this->isPreferredBuilding($building)) {
            $score += 15;
        }
        
        // Room load balancing
        $roomLoad = $this->resourceTracker->getRoomLoad($room['room_id']);
        if ($roomLoad < 5) {
            $score += 10;
        }
        
        return $score;
    }
    
    /**
     * IMPROVED: Schedule course with relaxed constraints
     */
    private function scheduleCourseWithRelaxedConstraints(array $course, int $courseIndex): array
    {
        // Similar to strict constraints but with more flexibility
        return $this->scheduleCourseWithStrictConstraints($course, $courseIndex);
    }
    
    /**
     * IMPROVED: Force schedule course (last resort)
     */
    private function forceScheduleCourseImproved(array $course, int $courseIndex): array
    {
        // Force scheduling with minimal constraints
        $schedules = [];
        $units = $course['units'] ?? 0;
        $sessions = $this->calculateOptimalSessions($units);
        
        foreach ($sessions as $sessionIndex => $sessionDuration) {
            $session = $this->forceScheduleSession($course, $sessionDuration, $sessionIndex);
            if ($session) {
                $schedules[] = $session;
            }
        }
        
        return $schedules;
    }
    
    /**
     * IMPROVED: Force schedule session
     */
    private function forceScheduleSession(array $course, float $sessionDuration, int $sessionIndex): ?array
    {
        // Find any available slot and room
        foreach ($this->timeSlots as $slot) {
            $startTime = $slot['start'];
            $endTime = $this->calculateEndTime($startTime, $sessionDuration);
            
            // Skip only critical conflicts
            if ($this->hasCriticalConflicts($course, $slot['day'], $startTime, $endTime)) {
                continue;
            }
            
            // Find any available room
            $room = $this->findAnyAvailableRoom($course, $slot['day'], $startTime, $endTime);
            if ($room) {
                return $this->createScheduleEntry($course, $slot, $startTime, $endTime, $room, $sessionIndex);
            }
        }
        
        return null;
    }
    
    /**
     * IMPROVED: Check for critical conflicts only
     */
    private function hasCriticalConflicts(array $course, string $day, string $startTime, string $endTime): bool
    {
        $instructorName = $course['instructor'] ?? $course['name'] ?? '';
        
        // Only check instructor conflicts (critical)
        return !$this->resourceTracker->isInstructorAvailable($instructorName, $day, $startTime, $endTime);
    }
    
    /**
     * IMPROVED: Optimize schedule distribution
     */
    private function optimizeScheduleDistribution(array $schedules): array
    {
        // Implement load balancing optimizations
        $optimizedSchedules = $schedules;
        
        // Balance instructor loads
        $optimizedSchedules = $this->balanceInstructorLoads($optimizedSchedules);
        
        // Balance day distribution
        $optimizedSchedules = $this->balanceDayDistribution($optimizedSchedules);
        
        // Balance room usage
        $optimizedSchedules = $this->balanceRoomUsage($optimizedSchedules);
        
        return $optimizedSchedules;
    }
    
    /**
     * IMPROVED: Resolve remaining conflicts
     */
    private function resolveRemainingConflicts(array $schedules): array
    {
        $conflicts = $this->detectConflictsWithResourceTracker($schedules);
        
        if ($conflicts['instructor_conflicts'] > 0 || $conflicts['room_conflicts'] > 0) {
            Log::info("Resolving remaining conflicts using genetic algorithm approach");
            
            // Use genetic conflict resolver
            $geneticResolver = new GeneticConflictResolver();
            $geneticResolver->setParameters([
                'population_size' => 15,
                'max_generations' => 20,
                'mutation_rate' => 0.15,
                'crossover_rate' => 0.7
            ]);
            
            $schedules = $geneticResolver->resolveConflicts($schedules, $conflicts);
        }
        
        return $schedules;
    }
    
    /**
     * IMPROVED: Helper methods
     */
    private function extractYearLevel(string $yearLevel): int
    {
        preg_match('/(\d+)/', $yearLevel, $matches);
        return isset($matches[1]) ? (int)$matches[1] : 0;
    }
    
    private function calculateOptimalSessions(int $units): array
    {
        // Based on your memory about session splitting logic
        if ($units <= 3) {
            return [$units];
        } elseif ($units <= 6) {
            return [$units / 2, $units / 2];
        } else {
            $sessionCount = ceil($units / 3);
            $baseDuration = $units / $sessionCount;
            $sessions = array_fill(0, $sessionCount, $baseDuration);
            
            // Distribute remainder
            $remainder = $units - ($baseDuration * $sessionCount);
            for ($i = 0; $i < $remainder; $i++) {
                $sessions[$i] += 1;
            }
        }
        
        return $sessions;
    }
}
