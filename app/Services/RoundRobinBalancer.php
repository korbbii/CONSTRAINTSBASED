<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class RoundRobinBalancer
{
    private array $instructorLoads = [];
    private array $sectionLoads = [];
    private array $timeSlotPreferences = ['morning', 'afternoon', 'evening'];
    private int $preferenceIndex = 0;

    /**
     * Parse courses to understand joint session structure
     */
    public function parseJointSessions(array $courses): array
    {
        Log::info("RoundRobinBalancer: Parsing joint session structure for " . count($courses) . " courses");
        
        $parsedCourses = [];
        $courseGroups = $this->groupCoursesByCourseCode($courses);
        
        foreach ($courseGroups as $courseCode => $courseInstances) {
            // Group by instructor, section, and time to identify joint sessions
            $jointSessions = $this->identifyJointSessions($courseInstances);
            
            foreach ($jointSessions as $session) {
                $parsedCourses[] = $session;
            }
        }
        
        Log::info("RoundRobinBalancer: Parsed " . count($courses) . " individual entries into " . count($parsedCourses) . " joint sessions");
        
        return $parsedCourses;
    }

    /**
     * Group courses by course code
     */
    private function groupCoursesByCourseCode(array $courses): array
    {
        $groups = [];
        
        foreach ($courses as $course) {
            $courseCode = $course['courseCode'] ?? $course['subject_code'] ?? 'Unknown';
            if (!isset($groups[$courseCode])) {
                $groups[$courseCode] = [];
            }
            $groups[$courseCode][] = $course;
        }
        
        return $groups;
    }

    /**
     * Identify joint sessions from course instances
     */
    private function identifyJointSessions(array $courseInstances): array
    {
        $jointSessions = [];
        
        // Group by instructor, section, and time slot
        $sessionGroups = [];
        
        foreach ($courseInstances as $course) {
            $instructor = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            $section = $course['section'] ?? '';
            $day = $course['day'] ?? '';
            $startTime = $course['startTime'] ?? '';
            $endTime = $course['endTime'] ?? '';
            $room = $course['room'] ?? '';
            
            // FIXED: Group by instructor + section + time + room (not individual day)
            // This ensures Mon and Sat entries for same course get grouped together
            $sessionKey = "{$instructor}|{$section}|{$startTime}|{$endTime}|{$room}";
            
            if (!isset($sessionGroups[$sessionKey])) {
                $sessionGroups[$sessionKey] = [
                    'course' => $course,
                    'days' => [],
                    'individual_days' => []
                ];
            }
            
            $sessionGroups[$sessionKey]['days'][] = $day;
            $sessionGroups[$sessionKey]['individual_days'][] = $day;
        }
        
        // Convert to joint session format
        foreach ($sessionGroups as $sessionKey => $sessionData) {
            $course = $sessionData['course'];
            $days = $sessionData['days'];
            $individualDays = $sessionData['individual_days'];
            
            // Create joint session representation with CORRECT field names
            $jointSession = $course;
            $jointSession['joint_session'] = true;
            $jointSession['day'] = implode('', array_unique($days)); // e.g., "MonSat" - CORRECT FIELD NAME
            $jointSession['startTime'] = $course['startTime'] ?? ''; // CORRECT FIELD NAME
            $jointSession['endTime'] = $course['endTime'] ?? ''; // CORRECT FIELD NAME
            $jointSession['individual_days'] = array_unique($individualDays);
            $jointSession['meeting_count'] = count($individualDays);
            
            $jointSessions[] = $jointSession;
        }
        
        return $jointSessions;
    }

    /**
     * Balance instructor load using round-robin distribution
     */
    public function balanceInstructorLoad(array $courses): array
    {
        Log::info("RoundRobinBalancer: Starting instructor load balancing for " . count($courses) . " courses");
        
        // Group courses by instructor (now working with joint sessions)
        $instructorGroups = $this->groupCoursesByInstructor($courses);
        
        // Calculate current loads (counting joint sessions as single courses)
        $this->calculateInstructorLoads($instructorGroups);
        
        // Log current distribution
        $this->logCurrentDistribution($instructorGroups);
        
        // Check for overloaded instructors
        $overloadedInstructors = $this->identifyOverloadedInstructors($instructorGroups);
        
        if (empty($overloadedInstructors)) {
            Log::info("RoundRobinBalancer: No overloaded instructors found - no balancing needed");
            return $courses;
        }
        
        // Only redistribute if there's significant overload (more than 1 course over limit)
        $significantOverload = array_filter($overloadedInstructors, function($overload) {
            return $overload['overload'] > 1;
        });
        
        if (empty($significantOverload)) {
            Log::info("RoundRobinBalancer: Only minor overloads detected - skipping redistribution to preserve academic structure");
            return $courses;
        }
        
        // Redistribute courses from overloaded instructors
        $balancedCourses = $this->redistributeCourses($courses, $instructorGroups, $overloadedInstructors);
        
        Log::info("RoundRobinBalancer: Load balancing completed");
        return $balancedCourses;
    }

    /**
     * Calculate maximum schedulable courses for employment type
     */
    public function calculateMaximumSchedulableCourses(string $employmentType, int $avgUnits = 6): int
    {
        if ($employmentType === 'PART-TIME') {
            // PART-TIME: Only evening slots (17:00-21:00) available
            // Limited to 3.75 hours per day (5:00 PM - 8:45 PM)
            // Each 6-unit course needs 2 sessions × 3 hours = 6 hours
            // Maximum 2 courses per day × 6 days = 12 sessions per week
            // Realistically: 2-3 courses maximum (12-18 units)
            return 3;
        } else {
            // FULL-TIME: Morning, afternoon, and evening slots available
            // More flexible scheduling - allow up to 18 courses (increased from 15)
            return 18;
        }
    }

    /**
     * Diversify time slot preferences using smart round-robin
     */
    public function diversifyTimeSlotPreferences(array $courses): array
    {
        Log::info("RoundRobinBalancer: Diversifying time slot preferences for " . count($courses) . " courses");
        
        // Analyze current time slot usage patterns (working with joint sessions)
        $timeSlotUsage = $this->analyzeTimeSlotUsage($courses);
        
        $diversifiedCourses = [];
        
        foreach ($courses as $course) {
            $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
            
            // Assign time preference based on employment type and current usage
            if ($employmentType === 'PART-TIME') {
                // PART-TIME courses must use evening slots
                $course['time_preference'] = 'evening';
            } else {
                // FULL-TIME courses: choose least used time period
                $course['time_preference'] = $this->selectLeastUsedTimePeriod($timeSlotUsage);
                $timeSlotUsage[$course['time_preference']]++;
            }
            
            $diversifiedCourses[] = $course;
        }
        
        // Log preference distribution
        $this->logPreferenceDistribution($diversifiedCourses);
        
        return $diversifiedCourses;
    }

    /**
     * Analyze current time slot usage patterns
     */
    private function analyzeTimeSlotUsage(array $courses): array
    {
        $usage = ['morning' => 0, 'afternoon' => 0, 'evening' => 0];
        
        foreach ($courses as $course) {
            $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
            
            if ($employmentType === 'PART-TIME') {
                $usage['evening']++;
            } else {
                // For FULL-TIME, estimate based on course units
                $units = $course['unit'] ?? $course['units'] ?? 3;
                if ($units >= 6) {
                    $usage['morning']++; // Large courses prefer morning
                } else {
                    $usage['afternoon']++; // Smaller courses prefer afternoon
                }
            }
        }
        
        return $usage;
    }

    /**
     * Select the least used time period
     */
    private function selectLeastUsedTimePeriod(array $usage): string
    {
        $minUsage = min($usage);
        $leastUsed = array_keys($usage, $minUsage);
        
        // If multiple periods have same usage, prefer morning > afternoon > evening
        $preferenceOrder = ['morning', 'afternoon', 'evening'];
        
        foreach ($preferenceOrder as $period) {
            if (in_array($period, $leastUsed)) {
                return $period;
            }
        }
        
        return 'morning'; // Fallback
    }

    /**
     * Balance room usage to prevent room conflicts
     */
    public function balanceRoomUsage(array $courses, array $rooms): array
    {
        Log::info("RoundRobinBalancer: Starting room usage balancing for " . count($courses) . " courses");
        
        // Analyze room capacity and usage patterns
        $roomCapacity = $this->analyzeRoomCapacity($rooms);
        $roomUsage = $this->analyzeRoomUsage($courses);
        
        // Log current room distribution
        $this->logCurrentRoomDistribution($roomUsage, $roomCapacity);
        
        // Assign room preferences to courses to spread usage
        $balancedCourses = $this->assignRoomPreferences($courses, $roomCapacity, $roomUsage);
        
        Log::info("RoundRobinBalancer: Room usage balancing completed");
        return $balancedCourses;
    }

    /**
     * Analyze room capacity
     */
    private function analyzeRoomCapacity(array $rooms): array
    {
        $capacity = [];
        
        foreach ($rooms as $room) {
            $roomId = $room['room_id'] ?? 'unknown';
            $capacity[$roomId] = [
                'name' => $room['name'] ?? 'Unknown Room',
                'capacity' => $room['capacity'] ?? 30,
                'building' => $this->extractBuilding($room['name'] ?? ''),
                'usage_count' => 0
            ];
        }
        
        return $capacity;
    }

    /**
     * Analyze current room usage
     */
    private function analyzeRoomUsage(array $courses): array
    {
        $usage = [];
        
        foreach ($courses as $course) {
            // Estimate room preference based on course type
            $units = $course['unit'] ?? $course['units'] ?? 3;
            $sessionType = strtolower($course['sessionType'] ?? 'non-lab session');
            
            if ($sessionType === 'lab session') {
                $usage['lab_rooms'] = ($usage['lab_rooms'] ?? 0) + 1;
            } else {
                if ($units >= 6) {
                    $usage['large_rooms'] = ($usage['large_rooms'] ?? 0) + 1;
                } else {
                    $usage['small_rooms'] = ($usage['small_rooms'] ?? 0) + 1;
                }
            }
        }
        
        return $usage;
    }

    /**
     * Assign room preferences to courses
     */
    private function assignRoomPreferences(array $courses, array $roomCapacity, array $roomUsage): array
    {
        $balancedCourses = [];
        
        foreach ($courses as $course) {
            $units = $course['unit'] ?? $course['units'] ?? 3;
            $sessionType = strtolower($course['sessionType'] ?? 'non-lab session');
            
            // Assign room preference based on course requirements
            if ($sessionType === 'lab session') {
                $course['room_preference'] = 'lab';
            } elseif ($units >= 6) {
                $course['room_preference'] = 'large';
            } else {
                $course['room_preference'] = 'small';
            }
            
            $balancedCourses[] = $course;
        }
        
        return $balancedCourses;
    }

    /**
     * Extract building from room name
     */
    private function extractBuilding(string $roomName): string
    {
        if (strpos($roomName, 'HS') !== false) {
            return 'HS';
        } elseif (strpos($roomName, 'SHS') !== false) {
            return 'SHS';
        } elseif (strpos($roomName, 'ANNEX') !== false) {
            return 'ANNEX';
        }
        
        return 'UNKNOWN';
    }

    /**
     * Log current room distribution
     */
    private function logCurrentRoomDistribution(array $roomUsage, array $roomCapacity): void
    {
        Log::info("RoundRobinBalancer: Current room usage analysis:");
        Log::info("  Lab rooms needed: " . ($roomUsage['lab_rooms'] ?? 0));
        Log::info("  Large rooms needed: " . ($roomUsage['large_rooms'] ?? 0));
        Log::info("  Small rooms needed: " . ($roomUsage['small_rooms'] ?? 0));
        
        Log::info("RoundRobinBalancer: Available room capacity:");
        foreach ($roomCapacity as $roomId => $info) {
            Log::info("  {$info['name']}: {$info['capacity']} capacity ({$info['building']})");
        }
    }

    /**
     * Balance section load to prevent student conflicts
     */
    public function balanceSectionLoad(array $courses): array
    {
        Log::info("RoundRobinBalancer: Starting section load balancing for " . count($courses) . " courses");
        
        // Group courses by section
        $sectionGroups = $this->groupCoursesBySection($courses);
        
        // Calculate section loads
        $this->calculateSectionLoads($sectionGroups);
        
        // Log current section distribution
        $this->logCurrentSectionDistribution($sectionGroups);
        
        // Check for overloaded sections
        $overloadedSections = $this->identifyOverloadedSections($sectionGroups);
        
        if (empty($overloadedSections)) {
            Log::info("RoundRobinBalancer: No overloaded sections found - no balancing needed");
            return $courses;
        }
        
        // Redistribute courses from overloaded sections
        $balancedCourses = $this->redistributeSectionCourses($courses, $sectionGroups, $overloadedSections);
        
        Log::info("RoundRobinBalancer: Section load balancing completed");
        return $balancedCourses;
    }

    /**
     * Calculate maximum schedulable courses per section
     */
    public function calculateMaximumSchedulableCoursesPerSection(string $yearLevel): int
    {
        // Each section can have maximum 6 courses per week (one per day Mon-Sat)
        // But we need to account for different time slots per day
        // Conservative estimate: 4 courses per section to avoid conflicts
        return 4;
    }

    /**
     * Analyze instructor feasibility
     */
    public function analyzeInstructorFeasibility(array $courses): array
    {
        $instructorGroups = $this->groupCoursesByInstructor($courses);
        $feasibilityReport = [];
        
        foreach ($instructorGroups as $instructor => $instructorCourses) {
            $employmentType = $this->getInstructorEmploymentType($instructorCourses);
            $maxCourses = $this->calculateMaximumSchedulableCourses($employmentType);
            $currentCourses = count($instructorCourses);
            
            $feasibilityReport[$instructor] = [
                'current_courses' => $currentCourses,
                'max_courses' => $maxCourses,
                'employment_type' => $employmentType,
                'is_feasible' => $currentCourses <= $maxCourses,
                'overload_amount' => max(0, $currentCourses - $maxCourses)
            ];
        }
        
        return $feasibilityReport;
    }

    /**
     * Group courses by instructor
     */
    private function groupCoursesByInstructor(array $courses): array
    {
        $groups = [];
        
        foreach ($courses as $course) {
            $instructor = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            if (!isset($groups[$instructor])) {
                $groups[$instructor] = [];
            }
            $groups[$instructor][] = $course;
        }
        
        return $groups;
    }

    /**
     * Calculate instructor loads
     */
    private function calculateInstructorLoads(array $instructorGroups): void
    {
        foreach ($instructorGroups as $instructor => $courses) {
            $this->instructorLoads[$instructor] = count($courses);
        }
    }

    /**
     * Identify overloaded instructors
     */
    private function identifyOverloadedInstructors(array $instructorGroups): array
    {
        $overloaded = [];
        
        foreach ($instructorGroups as $instructor => $courses) {
            $employmentType = $this->getInstructorEmploymentType($courses);
            $maxCourses = $this->calculateMaximumSchedulableCourses($employmentType);
            $currentCourses = count($courses);
            
            if ($currentCourses > $maxCourses) {
                $overloaded[$instructor] = [
                    'current' => $currentCourses,
                    'max' => $maxCourses,
                    'overload' => $currentCourses - $maxCourses,
                    'employment_type' => $employmentType
                ];
            }
        }
        
        return $overloaded;
    }

    /**
     * Redistribute courses from overloaded instructors
     */
    private function redistributeCourses(array $courses, array $instructorGroups, array $overloadedInstructors): array
    {
        Log::info("RoundRobinBalancer: Redistributing courses from " . count($overloadedInstructors) . " overloaded instructors");
        
        $redistributedCourses = [];
        $coursesToRedistribute = [];
        
        // Collect courses from overloaded instructors
        foreach ($overloadedInstructors as $instructor => $overloadInfo) {
            $instructorCourses = $instructorGroups[$instructor];
            $maxCourses = $overloadInfo['max'];
            
            // Keep the first N courses, redistribute the rest
            $keepCourses = array_slice($instructorCourses, 0, $maxCourses);
            $redistributeCourses = array_slice($instructorCourses, $maxCourses);
            
            $redistributedCourses = array_merge($redistributedCourses, $keepCourses);
            $coursesToRedistribute = array_merge($coursesToRedistribute, $redistributeCourses);
            
            Log::info("RoundRobinBalancer: Keeping {$maxCourses} courses for {$instructor}, redistributing " . count($redistributeCourses) . " courses");
        }
        
        // Add courses from non-overloaded instructors
        foreach ($instructorGroups as $instructor => $instructorCourses) {
            if (!isset($overloadedInstructors[$instructor])) {
                $redistributedCourses = array_merge($redistributedCourses, $instructorCourses);
            }
        }
        
        // Find instructors with capacity to take additional courses
        $availableInstructors = $this->findAvailableInstructors($instructorGroups, $overloadedInstructors);
        
        if (empty($availableInstructors)) {
            Log::warning("RoundRobinBalancer: No available instructors found for redistribution - some courses may remain unscheduled");
            return array_merge($redistributedCourses, $coursesToRedistribute);
        }
        
        // Distribute courses using round-robin among available instructors
        $instructorIndex = 0;
        foreach ($coursesToRedistribute as $course) {
            $targetInstructor = $availableInstructors[$instructorIndex % count($availableInstructors)];
            
            // Only change instructor if the course is significantly overloaded
            $originalInstructor = $course['instructor'] ?? $course['name'] ?? 'Unknown';
            if ($originalInstructor !== $targetInstructor) {
                Log::info("RoundRobinBalancer: Moving course " . ($course['courseCode'] ?? 'Unknown') . " from {$originalInstructor} to {$targetInstructor}");
                $course['instructor'] = $targetInstructor;
                $course['name'] = $targetInstructor;
            }
            
            $redistributedCourses[] = $course;
            $instructorIndex++;
            
            Log::debug("RoundRobinBalancer: Redistributed course " . ($course['courseCode'] ?? 'Unknown') . " to {$targetInstructor}");
        }
        
        return $redistributedCourses;
    }

    /**
     * Find instructors with capacity for additional courses
     */
    private function findAvailableInstructors(array $instructorGroups, array $overloadedInstructors): array
    {
        $available = [];
        
        foreach ($instructorGroups as $instructor => $courses) {
            if (isset($overloadedInstructors[$instructor])) {
                continue; // Skip overloaded instructors
            }
            
            $employmentType = $this->getInstructorEmploymentType($courses);
            $maxCourses = $this->calculateMaximumSchedulableCourses($employmentType);
            $currentCourses = count($courses);
            
            if ($currentCourses < $maxCourses) {
                $available[] = $instructor;
            }
        }
        
        return $available;
    }

    /**
     * Get employment type for instructor's courses
     */
    private function getInstructorEmploymentType(array $courses): string
    {
        if (empty($courses)) {
            return 'FULL-TIME';
        }
        
        // Use the employment type from the first course
        return $this->normalizeEmploymentType($courses[0]['employmentType'] ?? 'FULL-TIME');
    }

    /**
     * Normalize employment type
     */
    private function normalizeEmploymentType(string $employmentType): string
    {
        $normalized = strtoupper(trim($employmentType));
        
        if (in_array($normalized, ['FULL-TIME', 'FULLTIME', 'FULL TIME', 'FT'])) {
            return 'FULL-TIME';
        } elseif (in_array($normalized, ['PART-TIME', 'PARTTIME', 'PART TIME', 'PT'])) {
            return 'PART-TIME';
        }
        
        return 'FULL-TIME';
    }

    /**
     * Log current instructor distribution
     */
    private function logCurrentDistribution(array $instructorGroups): void
    {
        Log::info("RoundRobinBalancer: Current instructor distribution:");
        
        foreach ($instructorGroups as $instructor => $courses) {
            $employmentType = $this->getInstructorEmploymentType($courses);
            $maxCourses = $this->calculateMaximumSchedulableCourses($employmentType);
            $currentCourses = count($courses);
            
            Log::info("  {$instructor}: {$currentCourses}/{$maxCourses} courses ({$employmentType})");
        }
    }

    /**
     * Log time preference distribution
     */
    private function logPreferenceDistribution(array $courses): void
    {
        $preferences = ['morning' => 0, 'afternoon' => 0, 'evening' => 0];
        
        foreach ($courses as $course) {
            $preference = $course['time_preference'] ?? 'unknown';
            if (isset($preferences[$preference])) {
                $preferences[$preference]++;
            }
        }
        
        Log::info("RoundRobinBalancer: Time preference distribution: " . json_encode($preferences));
    }

    /**
     * Group courses by section
     */
    private function groupCoursesBySection(array $courses): array
    {
        $groups = [];
        
        foreach ($courses as $course) {
            $section = $course['section'] ?? '';
            if (!isset($groups[$section])) {
                $groups[$section] = [];
            }
            $groups[$section][] = $course;
        }
        
        return $groups;
    }

    /**
     * Calculate section loads
     */
    private function calculateSectionLoads(array $sectionGroups): void
    {
        foreach ($sectionGroups as $section => $courses) {
            $this->sectionLoads[$section] = count($courses);
        }
    }

    /**
     * Identify overloaded sections
     */
    private function identifyOverloadedSections(array $sectionGroups): array
    {
        $overloaded = [];
        
        foreach ($sectionGroups as $section => $courses) {
            $yearLevel = $this->extractYearLevel($section);
            $maxCourses = $this->calculateMaximumSchedulableCoursesPerSection($yearLevel);
            $currentCourses = count($courses);
            
            if ($currentCourses > $maxCourses) {
                $overloaded[$section] = [
                    'current' => $currentCourses,
                    'max' => $maxCourses,
                    'overload' => $currentCourses - $maxCourses,
                    'year_level' => $yearLevel
                ];
            }
        }
        
        return $overloaded;
    }

    /**
     * Redistribute courses from overloaded sections
     */
    private function redistributeSectionCourses(array $courses, array $sectionGroups, array $overloadedSections): array
    {
        Log::info("RoundRobinBalancer: Redistributing courses from " . count($overloadedSections) . " overloaded sections");
        
        $redistributedCourses = [];
        $coursesToRedistribute = [];
        
        // Collect courses from overloaded sections
        foreach ($overloadedSections as $section => $overloadInfo) {
            $sectionCourses = $sectionGroups[$section];
            $maxCourses = $overloadInfo['max'];
            
            // Keep the first N courses, redistribute the rest
            $keepCourses = array_slice($sectionCourses, 0, $maxCourses);
            $redistributeCourses = array_slice($sectionCourses, $maxCourses);
            
            $redistributedCourses = array_merge($redistributedCourses, $keepCourses);
            $coursesToRedistribute = array_merge($coursesToRedistribute, $redistributeCourses);
            
            Log::info("RoundRobinBalancer: Keeping {$maxCourses} courses for {$section}, redistributing " . count($redistributeCourses) . " courses");
        }
        
        // Add courses from non-overloaded sections
        foreach ($sectionGroups as $section => $sectionCourses) {
            if (!isset($overloadedSections[$section])) {
                $redistributedCourses = array_merge($redistributedCourses, $sectionCourses);
            }
        }
        
        // Find sections with capacity to take additional courses
        $availableSections = $this->findAvailableSections($sectionGroups, $overloadedSections);
        
        if (empty($availableSections)) {
            Log::warning("RoundRobinBalancer: No available sections found for redistribution - some courses may remain unscheduled");
            return array_merge($redistributedCourses, $coursesToRedistribute);
        }
        
        // Distribute courses using round-robin among available sections
        $sectionIndex = 0;
        foreach ($coursesToRedistribute as $course) {
            $targetSection = $availableSections[$sectionIndex % count($availableSections)];
            $course['section'] = $targetSection;
            
            // Update year level and block based on target section
            $this->updateCourseSectionInfo($course, $targetSection);
            
            $redistributedCourses[] = $course;
            $sectionIndex++;
            
            Log::debug("RoundRobinBalancer: Redistributed course " . ($course['courseCode'] ?? 'Unknown') . " to {$targetSection}");
        }
        
        return $redistributedCourses;
    }

    /**
     * Find sections with capacity for additional courses
     */
    private function findAvailableSections(array $sectionGroups, array $overloadedSections): array
    {
        $available = [];
        
        foreach ($sectionGroups as $section => $courses) {
            if (isset($overloadedSections[$section])) {
                continue; // Skip overloaded sections
            }
            
            $yearLevel = $this->extractYearLevel($section);
            $maxCourses = $this->calculateMaximumSchedulableCoursesPerSection($yearLevel);
            $currentCourses = count($courses);
            
            if ($currentCourses < $maxCourses) {
                $available[] = $section;
            }
        }
        
        return $available;
    }

    /**
     * Extract year level from section string
     */
    private function extractYearLevel(string $section): string
    {
        if (preg_match('/(\d+(?:st|nd|rd|th)\s+Year)/', $section, $matches)) {
            return $matches[1];
        }
        return '1st Year'; // Default fallback
    }

    /**
     * Update course section information
     */
    private function updateCourseSectionInfo(array &$course, string $targetSection): void
    {
        // Extract year level and block from target section
        if (preg_match('/(\d+(?:st|nd|rd|th)\s+Year)\s+([A-Z])/', $targetSection, $matches)) {
            $course['yearLevel'] = $matches[1];
            $course['block'] = $matches[2];
        }
    }

    /**
     * Log current section distribution
     */
    private function logCurrentSectionDistribution(array $sectionGroups): void
    {
        Log::info("RoundRobinBalancer: Current section distribution:");
        
        foreach ($sectionGroups as $section => $courses) {
            $yearLevel = $this->extractYearLevel($section);
            $maxCourses = $this->calculateMaximumSchedulableCoursesPerSection($yearLevel);
            $currentCourses = count($courses);
            
            Log::info("  {$section}: {$currentCourses}/{$maxCourses} courses ({$yearLevel})");
        }
    }
}
