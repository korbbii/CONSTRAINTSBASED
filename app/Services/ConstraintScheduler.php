<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ConstraintScheduler
{
    /**
     * Detect conflicts using constraint satisfaction principles
     */
    public static function detectConflicts(array $schedules): array
    {
        $conflicts = [];
        $constraints = [
            'instructor' => [],
            'room' => [],
            'section' => []
        ];
        
        Log::debug("ConstraintScheduler: Analyzing " . count($schedules) . " schedules for conflicts");
        
        foreach ($schedules as $index => $schedule) {
            // Parse joint days
            $days = DayScheduler::splitCombinedDays($schedule['day'] ?? '');
            
            foreach ($days as $day) {
                $timeSlot = $day . '|' . ($schedule['start_time'] ?? '00:00:00') . '|' . ($schedule['end_time'] ?? '00:00:00');
                
                // Check instructor constraints
                $instructorKey = ($schedule['instructor'] ?? 'unknown') . '|' . $timeSlot;
                if (isset($constraints['instructor'][$instructorKey])) {
                    $conflicts[] = [
                        'type' => 'instructor',
                        'instructor' => $schedule['instructor'] ?? 'unknown',
                        'day' => $day,
                        'time_slot' => $timeSlot,
                        'conflicting_schedules' => [
                            $constraints['instructor'][$instructorKey],
                            $schedule
                        ],
                        'severity' => 'high'
                    ];
                    
                    Log::debug("INSTRUCTOR CONFLICT: " . ($schedule['instructor'] ?? 'unknown') .
                               " already scheduled at {$day} " . ($schedule['start_time'] ?? '00:00:00'));
                } else {
                    $constraints['instructor'][$instructorKey] = $schedule;
                }
                
                // Check room constraints
                $roomKey = ($schedule['room_id'] ?? 'unknown') . '|' . $timeSlot;
                if (isset($constraints['room'][$roomKey])) {
                    $conflicts[] = [
                        'type' => 'room',
                        'room_id' => $schedule['room_id'] ?? 'unknown',
                        'day' => $day,
                        'time_slot' => $timeSlot,
                        'conflicting_schedules' => [
                            $constraints['room'][$roomKey],
                            $schedule
                        ],
                        'severity' => 'high'
                    ];
                    
                    Log::warning("ROOM CONFLICT: Room " . ($schedule['room_id'] ?? 'unknown') . 
                               " already scheduled at {$day} " . ($schedule['start_time'] ?? '00:00:00'));
                } else {
                    $constraints['room'][$roomKey] = $schedule;
                }
                
                // Check section constraints
                $sectionKey = ($schedule['section'] ?? 'unknown') . '|' . $timeSlot;
                if (isset($constraints['section'][$sectionKey])) {
                    $conflicts[] = [
                        'type' => 'section',
                        'section' => $schedule['section'] ?? 'unknown',
                        'day' => $day,
                        'time_slot' => $timeSlot,
                        'conflicting_schedules' => [
                            $constraints['section'][$sectionKey],
                            $schedule
                        ],
                        'severity' => 'critical'
                    ];
                    
                    Log::warning("SECTION CONFLICT: Section " . ($schedule['section'] ?? 'unknown') . 
                               " already scheduled at {$day} " . ($schedule['start_time'] ?? '00:00:00'));
                } else {
                    $constraints['section'][$sectionKey] = $schedule;
                }
            }
        }
        
        Log::info("ConstraintScheduler: Found " . count($conflicts) . " conflicts");
        return $conflicts;
    }

    /**
     * Check if a specific time slot conflicts with existing schedules
     */
    public static function checkTimeSlotConflict(array $newSchedule, array $existingSchedules): ?array
    {
        $days = DayScheduler::splitCombinedDays($newSchedule['day'] ?? '');
        
        foreach ($days as $day) {
            $timeSlot = $day . '|' . ($newSchedule['start_time'] ?? '00:00:00') . '|' . ($newSchedule['end_time'] ?? '00:00:00');
            
            foreach ($existingSchedules as $existing) {
                $existingDays = DayScheduler::splitCombinedDays($existing['day'] ?? '');
                
                if (in_array($day, $existingDays) && 
                    self::timesOverlap(
                        $newSchedule['start_time'] ?? '00:00:00',
                        $newSchedule['end_time'] ?? '00:00:00',
                        $existing['start_time'] ?? '00:00:00',
                        $existing['end_time'] ?? '00:00:00'
                    )) {
                    
                    // Check for instructor conflict
                    if (($newSchedule['instructor'] ?? '') === ($existing['instructor'] ?? '')) {
                        return [
                            'type' => 'instructor',
                            'conflict_with' => $existing,
                            'day' => $day,
                            'time_slot' => $timeSlot
                        ];
                    }
                    
                    // Check for room conflict
                    if (($newSchedule['room_id'] ?? null) === ($existing['room_id'] ?? null)) {
                        return [
                            'type' => 'room',
                            'conflict_with' => $existing,
                            'day' => $day,
                            'time_slot' => $timeSlot
                        ];
                    }
                    
                    // Check for section conflict
                    if (($newSchedule['section'] ?? '') === ($existing['section'] ?? '')) {
                        return [
                            'type' => 'section',
                            'conflict_with' => $existing,
                            'day' => $day,
                            'time_slot' => $timeSlot
                        ];
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Find alternative time slots for a conflicting schedule
     */
    public static function findAlternativeSlots(array $conflictingSchedule, array $availableSlots, array $existingSchedules): array
    {
        $alternatives = [];
        $days = DayScheduler::splitCombinedDays($conflictingSchedule['day'] ?? '');
        
        foreach ($availableSlots as $slot) {
            $slotDays = DayScheduler::splitCombinedDays($slot['day'] ?? '');
            
            // Check if this slot can accommodate all required days
            $canAccommodate = true;
            foreach ($days as $day) {
                if (!in_array($day, $slotDays)) {
                    $canAccommodate = false;
                    break;
                }
            }
            
            if (!$canAccommodate) {
                continue;
            }
            
            // Check for conflicts with existing schedules
            $testSchedule = array_merge($conflictingSchedule, [
                'day' => $slot['day'],
                'start_time' => $slot['start'],
                'end_time' => $slot['end']
            ]);
            
            $conflict = self::checkTimeSlotConflict($testSchedule, $existingSchedules);
            if (!$conflict) {
                $alternatives[] = $slot;
            }
        }
        
        Log::debug("Found " . count($alternatives) . " alternative slots for conflicting schedule");
        return $alternatives;
    }

    /**
     * Check if two time ranges overlap
     */
    private static function timesOverlap(string $start1, string $end1, string $start2, string $end2): bool
    {
        $start1Time = TimeScheduler::timeToMinutes($start1);
        $end1Time = TimeScheduler::timeToMinutes($end1);
        $start2Time = TimeScheduler::timeToMinutes($start2);
        $end2Time = TimeScheduler::timeToMinutes($end2);
        
        return !($end1Time <= $start2Time || $end2Time <= $start1Time);
    }

    /**
     * Get conflict statistics
     */
    public static function getConflictStatistics(array $conflicts): array
    {
        $stats = [
            'total' => count($conflicts),
            'by_type' => [],
            'by_severity' => [],
            'critical_count' => 0,
            'high_count' => 0,
            'medium_count' => 0,
            'low_count' => 0
        ];
        
        foreach ($conflicts as $conflict) {
            $type = $conflict['type'] ?? 'unknown';
            $severity = $conflict['severity'] ?? 'medium';
            
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
            $stats['by_severity'][$severity] = ($stats['by_severity'][$severity] ?? 0) + 1;
            
            switch ($severity) {
                case 'critical':
                    $stats['critical_count']++;
                    break;
                case 'high':
                    $stats['high_count']++;
                    break;
                case 'medium':
                    $stats['medium_count']++;
                    break;
                case 'low':
                    $stats['low_count']++;
                    break;
            }
        }
        
        return $stats;
    }

    /**
     * Validate schedule constraints
     */
    public static function validateScheduleConstraints(array $schedule): array
    {
        $errors = [];
        
        // Check required fields
        if (empty($schedule['instructor'])) {
            $errors[] = 'Missing instructor';
        }
        
        if (empty($schedule['day'])) {
            $errors[] = 'Missing day';
        }
        
        if (empty($schedule['start_time']) || empty($schedule['end_time'])) {
            $errors[] = 'Missing time information';
        }
        
        // Validate day format
        $days = DayScheduler::splitCombinedDays($schedule['day'] ?? '');
        foreach ($days as $day) {
            if (!DayScheduler::isValidDay($day)) {
                $errors[] = "Invalid day: {$day}";
            }
        }
        
        // Validate time format
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $schedule['start_time'] ?? '')) {
            $errors[] = 'Invalid start_time format';
        }
        
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $schedule['end_time'] ?? '')) {
            $errors[] = 'Invalid end_time format';
        }
        
        // Check for lunch break violation
        if (TimeScheduler::isLunchBreakViolation($schedule['start_time'] ?? '', $schedule['end_time'] ?? '')) {
            $errors[] = 'Schedule violates lunch break (12:00-13:00)';
        }
        
        return $errors;
    }

    /**
     * Validate before assignment - comprehensive resource checking
     */
    public static function validateBeforeAssignment(
        ResourceTracker $resourceTracker,
        string $instructorName,
        int $roomId,
        string $sectionName,
        string $day,
        string $startTime,
        string $endTime
    ): array {
        $conflicts = [];
        
        // Use ResourceTracker for comprehensive validation
        $resourceConflicts = $resourceTracker->validateBeforeAssignment(
            $instructorName, $roomId, $sectionName, $day, $startTime, $endTime
        );
        
        if (!empty($resourceConflicts)) {
            $conflicts = array_merge($conflicts, $resourceConflicts);
        }
        
        // Additional constraint validations
        $scheduleData = [
            'instructor' => $instructorName,
            'room_id' => $roomId,
            'section' => $sectionName,
            'day' => $day,
            'start_time' => $startTime,
            'end_time' => $endTime
        ];
        
        $constraintErrors = self::validateScheduleConstraints($scheduleData);
        foreach ($constraintErrors as $error) {
            $conflicts[] = [
                'type' => 'constraint',
                'message' => $error
            ];
        }
        
        return $conflicts;
    }

    /**
     * Check if there's sufficient availability gap between sessions
     */
    public static function hasAvailabilityGap(
        string $startTime1,
        string $endTime1,
        string $startTime2,
        string $endTime2,
        int $minGapMinutes = 15
    ): bool {
        $start1Minutes = TimeScheduler::timeToMinutes($startTime1);
        $end1Minutes = TimeScheduler::timeToMinutes($endTime1);
        $start2Minutes = TimeScheduler::timeToMinutes($startTime2);
        $end2Minutes = TimeScheduler::timeToMinutes($endTime2);
        
        // Check if sessions are on different days (no gap needed)
        if ($startTime1 !== $startTime2) {
            return true;
        }
        
        // Check gap between end of first and start of second
        $gap1 = $start2Minutes - $end1Minutes;
        $gap2 = $start1Minutes - $end2Minutes;
        
        return $gap1 >= $minGapMinutes || $gap2 >= $minGapMinutes;
    }

    /**
     * Enhanced time overlap detection with buffer consideration
     */
    public static function timesOverlapWithBuffer(
        string $start1,
        string $end1,
        string $start2,
        string $end2,
        int $bufferMinutes = 0
    ): bool {
        $start1Time = TimeScheduler::timeToMinutes($start1);
        $end1Time = TimeScheduler::timeToMinutes($end1);
        $start2Time = TimeScheduler::timeToMinutes($start2);
        $end2Time = TimeScheduler::timeToMinutes($end2);
        
        // Add buffer to times
        $start1Time -= $bufferMinutes;
        $end1Time += $bufferMinutes;
        $start2Time -= $bufferMinutes;
        $end2Time += $bufferMinutes;
        
        return !($end1Time <= $start2Time || $end2Time <= $start1Time);
    }

    /**
     * Get comprehensive conflict statistics with ResourceTracker integration
     */
    public static function getComprehensiveConflictStatistics(array $conflicts, ResourceTracker $resourceTracker): array
    {
        $stats = self::getConflictStatistics($conflicts);
        
        // Add resource usage statistics
        $resourceStats = $resourceTracker->getResourceStatistics();
        
        $stats['resource_usage'] = $resourceStats;
        $stats['load_balance_score'] = self::calculateLoadBalanceScore($resourceStats);
        
        return $stats;
    }

    /**
     * Calculate load balance score (0-100, higher is better)
     */
    private static function calculateLoadBalanceScore(array $resourceStats): int
    {
        $scores = [];
        
        // Instructor load balance
        $instructorLoads = array_values($resourceStats['instructor_loads']);
        if (!empty($instructorLoads)) {
            $maxInstructorLoad = max($instructorLoads);
            $avgInstructorLoad = array_sum($instructorLoads) / count($instructorLoads);
            $instructorScore = $maxInstructorLoad > 0 ? min(100, ($avgInstructorLoad / $maxInstructorLoad) * 100) : 100;
            $scores[] = $instructorScore;
        }
        
        // Room load balance
        $roomLoads = array_values($resourceStats['room_loads']);
        if (!empty($roomLoads)) {
            $maxRoomLoad = max($roomLoads);
            $avgRoomLoad = array_sum($roomLoads) / count($roomLoads);
            $roomScore = $maxRoomLoad > 0 ? min(100, ($avgRoomLoad / $maxRoomLoad) * 100) : 100;
            $scores[] = $roomScore;
        }
        
        // Day distribution balance
        $dayLoads = array_values($resourceStats['day_distribution']);
        if (!empty($dayLoads)) {
            $maxDayLoad = max($dayLoads);
            $avgDayLoad = array_sum($dayLoads) / count($dayLoads);
            $dayScore = $maxDayLoad > 0 ? min(100, ($avgDayLoad / $maxDayLoad) * 100) : 100;
            $scores[] = $dayScore;
        }
        
        return !empty($scores) ? (int) round(array_sum($scores) / count($scores)) : 100;
    }
}
