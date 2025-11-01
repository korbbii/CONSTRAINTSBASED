<?php

namespace App\Services;

class DayMapper
{
    /**
     * Day to bitmask mapping for efficient conflict detection
     */
    private static array $dayBitmasks = [
        'Mon' => 1,    // 0000001
        'Tue' => 2,    // 0000010
        'Wed' => 4,    // 0000100
        'Thu' => 8,    // 0001000
        'Fri' => 16,   // 0010000
        'Sat' => 32,   // 0100000
        'Sun' => 64    // 1000000
    ];

    /**
     * Get bitmask for a single day string
     */
    public static function getBitmaskForDayString(string $day): int
    {
        $normalizedDay = DayScheduler::normalizeDay($day);
        return self::$dayBitmasks[$normalizedDay] ?? 0;
    }

    /**
     * Get bitmask for combined days string (e.g., "MonWedFri")
     */
    public static function getBitmaskForCombinedDays(string $combinedDays): int
    {
        $individualDays = DayScheduler::splitCombinedDays($combinedDays);
        $bitmask = 0;
        
        foreach ($individualDays as $day) {
            $bitmask |= self::getBitmaskForDayString($day);
        }
        
        return $bitmask;
    }

    /**
     * Check if two day bitmasks overlap
     */
    public static function daysOverlap(int $bitmask1, int $bitmask2): bool
    {
        return ($bitmask1 & $bitmask2) !== 0;
    }

    /**
     * Get individual days from bitmask
     */
    public static function getDaysFromBitmask(int $bitmask): array
    {
        $days = [];
        
        foreach (self::$dayBitmasks as $day => $mask) {
            if ($bitmask & $mask) {
                $days[] = $day;
            }
        }
        
        return $days;
    }

    /**
     * Convert bitmask back to combined days string
     */
    public static function getCombinedDaysFromBitmask(int $bitmask): string
    {
        $days = self::getDaysFromBitmask($bitmask);
        return DayScheduler::combineDays($days);
    }

    /**
     * Get all day bitmask mappings
     */
    public static function getAllDayBitmasks(): array
    {
        return self::$dayBitmasks;
    }
}
