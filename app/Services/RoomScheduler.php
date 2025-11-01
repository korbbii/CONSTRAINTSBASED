<?php

namespace App\Services;

use App\Services\DayScheduler;
use App\Services\ResourceTracker;

class RoomScheduler
{
    /**
     * ENHANCED room availability check with ResourceTracker integration
     */
    public static function isRoomAvailableWithTracker(
        int $roomId, 
        string $day, 
        string $startTime, 
        string $endTime, 
        ResourceTracker $resourceTracker
    ): bool {
        return $resourceTracker->isRoomAvailable($roomId, $day, $startTime, $endTime);
    }

    /**
     * Get room availability score for load balancing
     */
    public static function getRoomAvailabilityScore(int $roomId, ResourceTracker $resourceTracker): int
    {
        $load = $resourceTracker->getRoomLoad($roomId);
        // Lower load = higher score (prefer less used rooms)
        return max(0, 100 - ($load * 10));
    }

    /**
     * Get room capacity with fallback
     */
    public static function getRoomCapacity(array $room): int
    {
        return $room['capacity'] ?? 30;
    }

    /**
     * Check if room is suitable for course requirements
     */
    public static function isRoomSuitableForCourse(array $room, array $course): bool
    {
        // Check if room is active
        if (!($room['is_active'] ?? true)) {
            return false;
        }

        // Check lab requirement - STRICT: Lab rooms ONLY for lab sessions
        $requiresLab = self::courseRequiresLab($course);
        $roomIsLab = $room['is_lab'] ?? false;
        
        // Lab courses MUST use lab rooms
        if ($requiresLab && !$roomIsLab) {
            return false;
        }

        // Non-lab courses MUST NOT use lab rooms (strict rule - no fallback)
        if (!$requiresLab && $roomIsLab) {
            return false;
        }

        // Check capacity requirement
        $estimatedStudents = self::estimateStudentCount($course);
        $minCapacity = max(20, $estimatedStudents * 1.2); // 20% buffer
        $roomCapacity = self::getRoomCapacity($room);
        
        if ($roomCapacity < $minCapacity) {
            return false;
        }

        return true;
    }

    /**
     * Check if course requires a lab room
     */
    public static function courseRequiresLab(array $course): bool
    {
        $sessionType = strtolower(trim($course['sessionType'] ?? 'non-lab session'));
        return $sessionType === 'lab session';
    }

    /**
     * Estimate student count based on course data
     */
    public static function estimateStudentCount(array $course): int
    {
        $units = $course['unit'] ?? $course['units'] ?? 3;
        return min(50, max(20, $units * 10));
    }

    /**
     * Select optimal room using dynamic distribution algorithm
     */
    public static function selectOptimalRoom(
        array $suitableRooms, 
        array $roomUsage, 
        array $roomDayUsage, 
        string $day,
        int &$rrPointer = 0
    ): ?array {
        if (empty($suitableRooms)) {
            return null;
        }

        if (count($suitableRooms) === 1) {
            return array_values($suitableRooms)[0];
        }

        // Calculate room scores based on usage and capacity
        $roomScores = [];
        foreach ($suitableRooms as $room) {
            $roomId = $room['room_id'];
            
            // Calculate total usage across all days
            $totalUsage = 0;
            if (isset($roomUsage[$roomId])) {
                foreach ($roomUsage[$roomId] as $daySlots) {
                    $totalUsage += count($daySlots);
                }
            }
            
            // Calculate today's usage
            $dayUsage = $roomDayUsage[$day][$roomId] ?? 0;
            
            // Score: lower usage = higher score (prefer less used rooms)
            // Also consider capacity efficiency
            $capacity = self::getRoomCapacity($room);
            $efficiencyScore = min(1.0, $capacity / 50); // Prefer rooms closer to optimal size
            
            $score = (100 - $totalUsage) + (50 - $dayUsage) + ($efficiencyScore * 20);
            
            $roomScores[] = [
                'room' => $room,
                'score' => $score
            ];
        }

        // Sort by score (highest first)
        usort($roomScores, fn($a, $b) => $b['score'] <=> $a['score']);

        // Use round-robin among top 3 rooms to ensure some distribution
        $topRooms = array_slice($roomScores, 0, min(3, count($roomScores)));
        $selectedIndex = $rrPointer % count($topRooms);
        $rrPointer++;

        return $topRooms[$selectedIndex]['room'];
    }

    /**
     * Get suitable rooms for a course
     */
    public static function getSuitableRooms(array $rooms, array $course): array
    {
        return array_filter($rooms, fn($room) => self::isRoomSuitableForCourse($room, $course));
    }

    /**
     * Get available rooms (not conflicting with existing usage)
     */
    public static function getAvailableRooms(
        array $rooms, 
        string $day, 
        string $startTime, 
        string $endTime, 
        array $roomUsage
    ): array {
        return array_filter($rooms, function($room) use ($day, $startTime, $endTime, $roomUsage) {
            return self::isRoomAvailable($room['room_id'], $day, $startTime, $endTime, $roomUsage);
        });
    }

    /**
     * Check if a specific room is available at a given time
     */
    public static function isRoomAvailable(
        int $roomId, 
        string $day, 
        string $startTime, 
        string $endTime, 
        array $roomUsage
    ): bool {
        // Split combined day strings (e.g., "MonSat" -> ["Mon", "Sat"])
        $individualDays = DayScheduler::splitCombinedDays($day);
        
        // Check each individual day for conflicts
        foreach ($individualDays as $singleDay) {
            $normalizedDay = DayScheduler::normalizeDay($singleDay);
            
            // Check for conflicts with already scheduled meetings on this day
            if (isset($roomUsage[$roomId][$normalizedDay])) {
                foreach ($roomUsage[$roomId][$normalizedDay] as $existingSlot) {
                    if (TimeScheduler::timesOverlap(
                        $startTime, 
                        $endTime, 
                        $existingSlot['start_time'], 
                        $existingSlot['end_time']
                    )) {
                        return false; // Conflict found on this day
                    }
                }
            }
        }

        // No conflicts found on any day
        return true;
    }

    /**
     * Update room usage tracking
     */
    public static function updateRoomUsage(
        int $roomId, 
        string $day, 
        string $startTime, 
        string $endTime, 
        array &$roomUsage
    ): void {
        // Split combined day strings (e.g., "MonSat" -> ["Mon", "Sat"])
        $individualDays = DayScheduler::splitCombinedDays($day);
        
        // Update usage for each individual day
        foreach ($individualDays as $singleDay) {
            $normalizedDay = DayScheduler::normalizeDay($singleDay);
            
            if (!isset($roomUsage[$roomId])) {
                $roomUsage[$roomId] = [];
            }
            if (!isset($roomUsage[$roomId][$normalizedDay])) {
                $roomUsage[$roomId][$normalizedDay] = [];
            }
            
            $roomUsage[$roomId][$normalizedDay][] = [
                'start_time' => $startTime,
                'end_time' => $endTime
            ];
        }
    }

    /**
     * Get rooms suitable and available for a course at a specific time
     */
    public static function getSuitableAvailableRooms(
        array $rooms,
        array $course,
        string $day,
        string $startTime,
        string $endTime,
        array $roomUsage
    ): array {
        return array_filter($rooms, function($room) use ($course, $day, $startTime, $endTime, $roomUsage) {
            return self::isRoomSuitableForCourse($room, $course) && 
                   self::isRoomAvailable($room['room_id'], $day, $startTime, $endTime, $roomUsage);
        });
    }

    /**
     * Find best room for a course at a specific time slot with fallback logic
     */
    public static function findBestRoom(
        array $rooms,
        array $course,
        string $day,
        string $startTime,
        string $endTime,
        array &$roomUsage,
        array &$roomDayUsage,
        int &$rrPointer = 0
    ): ?array {
        // Try to get suitable and available rooms first
        $suitableRooms = self::getSuitableAvailableRooms($rooms, $course, $day, $startTime, $endTime, $roomUsage);
        
        if (!empty($suitableRooms)) {
            $selectedRoom = self::selectOptimalRoom($suitableRooms, $roomUsage, $roomDayUsage, $day, $rrPointer);
            if ($selectedRoom) {
                self::updateRoomUsage($selectedRoom['room_id'], $day, $startTime, $endTime, $roomUsage);
                self::updateDayUsage($selectedRoom['room_id'], $day, $roomDayUsage);
                return $selectedRoom;
            }
        }

        // Fallback logic for when no suitable rooms are available
        $requiresLab = self::courseRequiresLab($course);
        
        if ($requiresLab) {
            // For lab sessions, only fallback to lab rooms
            $labRooms = self::getAvailableRooms($rooms, $day, $startTime, $endTime, $roomUsage);
            $labRooms = array_filter($labRooms, fn($room) => $room['is_lab'] ?? false);
            
            if (!empty($labRooms)) {
                $selectedRoom = array_values($labRooms)[0];
                self::updateRoomUsage($selectedRoom['room_id'], $day, $startTime, $endTime, $roomUsage);
                self::updateDayUsage($selectedRoom['room_id'], $day, $roomDayUsage);
                return $selectedRoom;
            }
        } else {
            // For non-lab sessions, fallback to any available NON-LAB room
            $availableRooms = self::getAvailableRooms($rooms, $day, $startTime, $endTime, $roomUsage);
            $nonLabRooms = array_filter($availableRooms, fn($room) => !($room['is_lab'] ?? false));
            
            if (!empty($nonLabRooms)) {
                $selectedRoom = array_values($nonLabRooms)[0];
                self::updateRoomUsage($selectedRoom['room_id'], $day, $startTime, $endTime, $roomUsage);
                self::updateDayUsage($selectedRoom['room_id'], $day, $roomDayUsage);
                return $selectedRoom;
            }
        }

        return null; // No suitable room found
    }

    /**
     * Update day usage tracking
     */
    private static function updateDayUsage(int $roomId, string $day, array &$roomDayUsage): void
    {
        // Split combined day strings (e.g., "MonSat" -> ["Mon", "Sat"])
        $individualDays = DayScheduler::splitCombinedDays($day);
        
        // Update usage count for each individual day
        foreach ($individualDays as $singleDay) {
            $normalizedDay = DayScheduler::normalizeDay($singleDay);
            
            if (!isset($roomDayUsage[$normalizedDay])) {
                $roomDayUsage[$normalizedDay] = [];
            }
            
            $roomDayUsage[$normalizedDay][$roomId] = ($roomDayUsage[$normalizedDay][$roomId] ?? 0) + 1;
        }
    }
}
