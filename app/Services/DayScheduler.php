<?php

namespace App\Services;

class DayScheduler
{
    /**
     * Normalize day names to standard abbreviated format
     */
    public static function normalizeDay(string $day): string
    {
        $map = [
            'monday' => 'Mon',
            'tuesday' => 'Tue', 
            'wednesday' => 'Wed',
            'thursday' => 'Thu',
            'friday' => 'Fri',
            'saturday' => 'Sat',
            'sunday' => 'Sun',
            'mon' => 'Mon',
            'tue' => 'Tue',
            'wed' => 'Wed', 
            'thu' => 'Thu',
            'fri' => 'Fri',
            'sat' => 'Sat',
            'sun' => 'Sun',
            // Add capitalized versions for Python output
            'Monday' => 'Mon',
            'Tuesday' => 'Tue',
            'Wednesday' => 'Wed',
            'Thursday' => 'Thu',
            'Friday' => 'Fri',
            'Saturday' => 'Sat',
            'Sunday' => 'Sun'
        ];
        
        $key = trim($day);
        return $map[$key] ?? $day; // Return original day instead of defaulting to 'Mon'
    }

    /**
     * Combine multiple days into a single string (e.g., "MonTue", "MonWedFri")
     */
    public static function combineDays($days): string
    {
        $dayMap = [
            'Mon' => 'Mon',
            'Tue' => 'Tue', 
            'Wed' => 'Wed',
            'Thu' => 'Thu',
            'Fri' => 'Fri',
            'Sat' => 'Sat',
            'Sun' => 'Sun'
        ];
        
        $normalizedDays = [];
        foreach ($days as $day) {
            $normalized = $dayMap[$day] ?? $day;
            if (!in_array($normalized, $normalizedDays)) {
                $normalizedDays[] = $normalized;
            }
        }
        
        return implode('', $normalizedDays);
    }

    /**
     * Parse combined day strings into an array of individual days.
     * Accepts inputs like "MonThu", "MonWedFri" or already-single day.
     * Falls back to scanning for known day tokens if splitCombinedDays returns empty.
     */
    public static function parseCombinedDays(string $dayString): array
    {
        $dayString = trim($dayString ?? '');
        if ($dayString === '') {
            return [];
        }

        $split = self::splitCombinedDays($dayString);
        if (!empty($split)) {
            return $split;
        }

        // Heuristic scan for 3-letter tokens (case-insensitive)
        $tokens = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        $found = [];
        foreach ($tokens as $tok) {
            if (stripos($dayString, $tok) !== false) {
                $found[] = $tok;
            }
        }
        return self::sortDaysInWeeklyOrder(array_values(array_unique($found)));
    }

    /**
     * Expand a meeting-like structure that may contain a combined day string
     * into multiple per-day meeting arrays. The input should include keys:
     * - day, start_time, end_time, room_id, meeting_type (others will be carried over)
     */
    public static function expandMeetings(array $meetingLike): array
    {
        $days = self::parseCombinedDays((string)($meetingLike['day'] ?? ''));
        if (empty($days)) {
            return [$meetingLike];
        }

        $expanded = [];
        foreach ($days as $d) {
            $copy = $meetingLike;
            $copy['day'] = $d;
            $expanded[] = $copy;
        }
        return $expanded;
    }

    /**
     * Get standard day mapping
     */
    public static function getDayMapping(): array
    {
        return [
            'monday' => 'Mon',
            'tuesday' => 'Tue', 
            'wednesday' => 'Wed',
            'thursday' => 'Thu',
            'friday' => 'Fri',
            'saturday' => 'Sat',
            'sunday' => 'Sun',
            'mon' => 'Mon',
            'tue' => 'Tue',
            'wed' => 'Wed', 
            'thu' => 'Thu',
            'fri' => 'Fri',
            'sat' => 'Sat',
            'sun' => 'Sun',
            'Monday' => 'Mon',
            'Tuesday' => 'Tue',
            'Wednesday' => 'Wed',
            'Thursday' => 'Thu',
            'Friday' => 'Fri',
            'Saturday' => 'Sat',
            'Sunday' => 'Sun'
        ];
    }

    /**
     * Get all valid day abbreviations
     */
    public static function getAllDayAbbreviations(): array
    {
        return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    }

    /**
     * Check if a day string is valid
     */
    public static function isValidDay(string $day): bool
    {
        $dayMap = self::getDayMapping();
        return isset($dayMap[trim($day)]) || in_array(trim($day), self::getAllDayAbbreviations());
    }

    /**
     * Convert day abbreviation to full name
     */
    public static function dayAbbreviationToFull(string $dayAbbr): string
    {
        $map = [
            'Mon' => 'Monday',
            'Tue' => 'Tuesday',
            'Wed' => 'Wednesday',
            'Thu' => 'Thursday',
            'Fri' => 'Friday',
            'Sat' => 'Saturday',
            'Sun' => 'Sunday'
        ];
        
        return $map[$dayAbbr] ?? $dayAbbr;
    }

    /**
     * Split combined days back into individual days
     */
    public static function splitCombinedDays(string $combinedDays): array
    {
        $combinedDays = trim((string)$combinedDays);
        if ($combinedDays === '') {
            return [];
        }

        // Extract all day tokens in order of appearance (case-insensitive)
        // Supports concatenated forms like "MonWedFri" and delimited forms like "Mon, Wed / Fri"
        $matches = [];
        preg_match_all('/(Mon|Tue|Wed|Thu|Fri|Sat|Sun)/i', $combinedDays, $matches);
        $tokens = $matches[1] ?? [];

        if (empty($tokens)) {
            return [];
        }

        // Normalize tokens to canonical abbreviations and de-duplicate preserving order
        $map = self::getDayMapping();
        $normalized = [];
        foreach ($tokens as $tok) {
            $abbr = $map[$tok] ?? ($map[strtolower($tok)] ?? null);
            if ($abbr && !in_array($abbr, $normalized, true)) {
                $normalized[] = $abbr;
            }
        }

        // Return sorted in weekly order for consistency
        return self::sortDaysInWeeklyOrder($normalized);
    }

    /**
     * Sort days in weekly order (Mon, Tue, Wed, Thu, Fri, Sat)
     */
    public static function sortDaysInWeeklyOrder(array $days): array
    {
        $dayOrder = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
        
        usort($days, function($a, $b) use ($dayOrder) {
            $orderA = $dayOrder[$a] ?? 999;
            $orderB = $dayOrder[$b] ?? 999;
            return $orderA - $orderB;
        });
        
        return $days;
    }
}
