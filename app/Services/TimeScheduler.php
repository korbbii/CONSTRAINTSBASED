<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TimeScheduler
{
    private const DAYS = [
        'Mon',
        'Tue', 
        'Wed',
        'Thu',
        'Fri',
        'Sat'
        // Sunday excluded as it is a rest day
    ];

    /**
     * Generate comprehensive time slots for the week with lunch break constraint
     * Enhanced with 70% continuous meetings and 30% gap variation for more time slots
     */
    public static function generateComprehensiveTimeSlots(): array
    {
        $timeSlots = [];
        
        // Generate base time slots with traditional gaps
        $baseSlots = self::generateBaseTimeSlots();
        
        // Generate continuous time slots (70% of total)
        $continuousSlots = self::generateContinuousTimeSlots();
        
        // Combine both approaches
        $allSlots = array_merge($baseSlots, $continuousSlots);
        
        // Create time slots for each day
        $finalSlots = [];
        foreach (self::DAYS as $day) {
            foreach ($allSlots as $slot) {
                $finalSlots[] = array_merge(['day' => $day], $slot);
            }
        }

        // Shuffle to randomize order
        shuffle($finalSlots);
        
        // Reorganize to ensure better day distribution
        $dayGroups = [];
        foreach ($finalSlots as $slot) {
            $day = $slot['day'];
            if (!isset($dayGroups[$day])) {
                $dayGroups[$day] = [];
            }
            $dayGroups[$day][] = $slot;
        }

        // Interleave slots from different days for better distribution
        $maxSlotsPerDay = max(array_map('count', $dayGroups));
        
        // Randomize day order to prevent Monday bias and ensure fair distribution
        $randomizedDays = self::DAYS;
        shuffle($randomizedDays);
        
        // FAIR DISTRIBUTION: All days treated equally without prioritization
        $prioritizedDays = $randomizedDays;
        
        for ($i = 0; $i < $maxSlotsPerDay; $i++) {
            foreach ($prioritizedDays as $day) {
                if (isset($dayGroups[$day][$i])) {
                    $timeSlots[] = $dayGroups[$day][$i];
                }
            }
        }

        return $timeSlots;
    }

    /**
     * Generate base time slots with traditional 15-minute gaps
     */
    private static function generateBaseTimeSlots(): array
    {
        return [
            // MORNING SLOTS: Start at 7:00 AM for ALL courses
            // 1.5-hour slots
            ['start' => '07:00:00', 'end' => '08:30:00', 'period' => 'morning_1_5h'],
            ['start' => '08:30:00', 'end' => '10:00:00', 'period' => 'morning_1_5h'],
            ['start' => '10:00:00', 'end' => '11:30:00', 'period' => 'morning_1_5h'],
            ['start' => '11:30:00', 'end' => '13:00:00', 'period' => 'morning_1_5h_safe'],
            ['start' => '07:15:00', 'end' => '08:45:00', 'period' => 'morning_1_5h_alt'],
            ['start' => '08:45:00', 'end' => '10:15:00', 'period' => 'morning_1_5h_alt'],
            ['start' => '10:15:00', 'end' => '11:45:00', 'period' => 'morning_1_5h_alt'],
            
            // 2-hour slots
            ['start' => '07:00:00', 'end' => '09:00:00', 'period' => 'morning_2h'],
            ['start' => '09:00:00', 'end' => '11:00:00', 'period' => 'morning_2h'],
            ['start' => '11:00:00', 'end' => '13:00:00', 'period' => 'morning_2h'],
            ['start' => '07:30:00', 'end' => '09:30:00', 'period' => 'morning_2h_alt'],
            ['start' => '09:30:00', 'end' => '11:30:00', 'period' => 'morning_2h_alt'],
            ['start' => '11:30:00', 'end' => '13:30:00', 'period' => 'morning_2h_alt'],
            
            // 2.5-hour slots
            ['start' => '07:00:00', 'end' => '09:30:00', 'period' => 'morning_2_5h'],
            ['start' => '09:30:00', 'end' => '12:00:00', 'period' => 'morning_2_5h'],
            
            // 3-hour slots
            ['start' => '07:00:00', 'end' => '10:00:00', 'period' => 'morning_3h'],
            ['start' => '13:00:00', 'end' => '15:30:00', 'period' => 'afternoon_3h'],
            ['start' => '08:00:00', 'end' => '11:00:00', 'period' => 'morning_3h_alt'],
            ['start' => '14:00:00', 'end' => '16:30:00', 'period' => 'afternoon_3h_alt'],
            ['start' => '09:00:00', 'end' => '12:00:00', 'period' => 'morning_3h_late'],
            ['start' => '15:00:00', 'end' => '17:30:00', 'period' => 'afternoon_3h_late'],
            
            // 3.5-hour slots
            ['start' => '07:00:00', 'end' => '10:30:00', 'period' => 'morning_3_5h'],
            ['start' => '13:00:00', 'end' => '16:00:00', 'period' => 'afternoon_3_5h'],
            
            // 4-hour slots
            ['start' => '07:00:00', 'end' => '11:00:00', 'period' => 'morning_4h'],
            ['start' => '13:00:00', 'end' => '16:30:00', 'period' => 'afternoon_4h'],
            
            // 4.5-hour slots
            ['start' => '07:30:00', 'end' => '12:00:00', 'period' => 'morning_4_5h'],
            
            // 5-hour slots
            ['start' => '07:00:00', 'end' => '12:00:00', 'period' => 'morning_5h'],
            ['start' => '07:00:00', 'end' => '12:00:00', 'period' => 'morning_5h_alt'],

            // AFTERNOON SLOTS: Start at 1:00 PM
            // 1.5-hour slots
            ['start' => '13:00:00', 'end' => '14:00:00', 'period' => 'afternoon_1_5h'],
            ['start' => '14:00:00', 'end' => '15:30:00', 'period' => 'afternoon_1_5h'],
            ['start' => '15:30:00', 'end' => '17:00:00', 'period' => 'afternoon_1_5h'],
            ['start' => '13:00:00', 'end' => '14:00:00', 'period' => 'afternoon_1_5h_alt'],
            
            // 2-hour slots
            ['start' => '13:00:00', 'end' => '14:30:00', 'period' => 'afternoon_2h'],
            ['start' => '14:30:00', 'end' => '16:30:00', 'period' => 'afternoon_2h'],
            ['start' => '13:00:00', 'end' => '14:30:00', 'period' => 'afternoon_2h_alt'],
            
            // 2.5-hour slots
            ['start' => '13:00:00', 'end' => '15:00:00', 'period' => 'afternoon_2_5h'],
            ['start' => '15:00:00', 'end' => '17:30:00', 'period' => 'afternoon_2_5h'],
            
            // 3-hour slots
            ['start' => '13:00:00', 'end' => '15:30:00', 'period' => 'afternoon_3h'],
            ['start' => '15:30:00', 'end' => '18:30:00', 'period' => 'afternoon_3h'],
            ['start' => '13:00:00', 'end' => '15:30:00', 'period' => 'afternoon_3h_alt'],
            
            // 3.5-hour slots
            ['start' => '13:00:00', 'end' => '16:00:00', 'period' => 'afternoon_3_5h'],
            ['start' => '16:00:00', 'end' => '19:30:00', 'period' => 'afternoon_3_5h'],
            ['start' => '13:00:00', 'end' => '16:00:00', 'period' => 'afternoon_3_5h_alt'],
            
            // 4-hour slots
            ['start' => '13:00:00', 'end' => '16:30:00', 'period' => 'afternoon_4h'],
            ['start' => '16:30:00', 'end' => '20:30:00', 'period' => 'afternoon_4h'],
            ['start' => '13:00:00', 'end' => '16:30:00', 'period' => 'afternoon_4h_alt'],
            
            // 4.5-hour slots
            ['start' => '13:00:00', 'end' => '17:00:00', 'period' => 'afternoon_4_5h'],
            
            // 5-hour slots
            ['start' => '13:00:00', 'end' => '18:00:00', 'period' => 'afternoon_5h'],
            ['start' => '13:00:00', 'end' => '18:00:00', 'period' => 'afternoon_5h_alt'],
            ['start' => '13:00:00', 'end' => '18:00:00', 'period' => 'afternoon_5h_explicit'],

            // EVENING SLOTS: Start at 4:00 PM, End at 8:45 PM
            // 1.5-hour slots
            ['start' => '16:00:00', 'end' => '17:30:00', 'period' => 'evening_1_5h'],
            ['start' => '17:00:00', 'end' => '18:30:00', 'period' => 'evening_1_5h'],
            ['start' => '18:30:00', 'end' => '20:45:00', 'period' => 'evening_1_5h'],
            ['start' => '18:30:00', 'end' => '20:45:00', 'period' => 'evening_1_5h_alt'],
            
            // 2-hour slots
            ['start' => '16:00:00', 'end' => '18:00:00', 'period' => 'evening_2h'],
            ['start' => '17:00:00', 'end' => '19:00:00', 'period' => 'evening_2h'],
            ['start' => '19:00:00', 'end' => '20:45:00', 'period' => 'evening_2h'],
            ['start' => '17:00:00', 'end' => '19:00:00', 'period' => 'evening_2h_alt'],
            ['start' => '16:30:00', 'end' => '18:30:00', 'period' => 'evening_2h_alt2'],
            ['start' => '18:30:00', 'end' => '20:30:00', 'period' => 'evening_2h_alt2'],
            
            // 2.5-hour slots
            ['start' => '17:00:00', 'end' => '19:30:00', 'period' => 'evening_2_5h'],
            ['start' => '19:30:00', 'end' => '20:45:00', 'period' => 'evening_2_5h'],
            ['start' => '17:00:00', 'end' => '19:30:00', 'period' => 'evening_2_5h_alt'],
            
            // 3-hour slots (most common for part-time)
            ['start' => '16:00:00', 'end' => '19:00:00', 'period' => 'evening_3h'],
            ['start' => '17:00:00', 'end' => '20:45:00', 'period' => 'evening_3h'],
            ['start' => '18:00:00', 'end' => '20:45:00', 'period' => 'evening_3h'],
            ['start' => '17:00:00', 'end' => '20:45:00', 'period' => 'evening_3h_alt'],
            ['start' => '18:00:00', 'end' => '20:45:00', 'period' => 'evening_3h_mid'],
            ['start' => '16:30:00', 'end' => '19:30:00', 'period' => 'evening_3h_early'],
            ['start' => '17:30:00', 'end' => '20:30:00', 'period' => 'evening_3h_late'],
            
            // Additional evening slots for part-time instructors
            ['start' => '18:30:00', 'end' => '20:45:00', 'period' => 'evening_3h_very_late'],
            ['start' => '19:00:00', 'end' => '20:45:00', 'period' => 'evening_3h_night'],
            
            // 3.5-hour slots
            ['start' => '17:00:00', 'end' => '20:30:00', 'period' => 'evening_3_5h'],
            ['start' => '18:00:00', 'end' => '20:45:00', 'period' => 'evening_3_5h_mid'],
            
            // Additional 3.5-hour slots
            ['start' => '17:30:00', 'end' => '20:45:00', 'period' => 'evening_3_5h_late'],
            ['start' => '19:00:00', 'end' => '20:45:00', 'period' => 'evening_3_5h_night'],
            
            // Additional evening slots for overloaded part-time instructors
            ['start' => '17:15:00', 'end' => '20:45:00', 'period' => 'evening_3h_early'],
            ['start' => '18:15:00', 'end' => '20:45:00', 'period' => 'evening_3h_mid_early'],
            ['start' => '19:00:00', 'end' => '20:45:00', 'period' => 'evening_3h_late_night'],
            ['start' => '20:15:00', 'end' => '20:45:00', 'period' => 'evening_2_25h'],
            
            // 4-hour slots
            ['start' => '17:00:00', 'end' => '20:45:00', 'period' => 'evening_4h'],
            ['start' => '18:00:00', 'end' => '20:45:00', 'period' => 'evening_4h_mid'],
            
            // 4.5-hour slots
            ['start' => '17:00:00', 'end' => '20:45:00', 'period' => 'evening_4_5h'],
            
            // ENHANCED EVENING SLOTS starting at 4:00 PM for part-time instructors
            // 3-hour slots starting at 4:00 PM
            ['start' => '16:00:00', 'end' => '19:00:00', 'period' => 'evening_3h_4pm'],
            ['start' => '17:00:00', 'end' => '20:45:00', 'period' => 'evening_3h_5pm'],
            ['start' => '17:30:00', 'end' => '20:30:00', 'period' => 'evening_3h_5_30pm'],
            ['start' => '18:00:00', 'end' => '20:45:00', 'period' => 'evening_3h_6pm'],
            
            // 2-hour slots starting at 4:00 PM
            ['start' => '16:00:00', 'end' => '18:00:00', 'period' => 'evening_2h_4pm'],
            ['start' => '17:00:00', 'end' => '19:00:00', 'period' => 'evening_2h_5pm'],
            ['start' => '17:30:00', 'end' => '19:30:00', 'period' => 'evening_2h_5_30pm'],
            ['start' => '18:00:00', 'end' => '20:45:00', 'period' => 'evening_2h_6pm'],
            ['start' => '18:30:00', 'end' => '20:30:00', 'period' => 'evening_2h_6_30pm'],
            ['start' => '19:00:00', 'end' => '20:45:00', 'period' => 'evening_2h_7pm'],
            
            // 1.5-hour slots starting at 4:00 PM
            ['start' => '16:00:00', 'end' => '17:30:00', 'period' => 'evening_1_5h_4pm'],
            ['start' => '17:00:00', 'end' => '18:30:00', 'period' => 'evening_1_5h_5pm'],
            ['start' => '17:30:00', 'end' => '19:00:00', 'period' => 'evening_1_5h_5_30pm'],
            ['start' => '18:00:00', 'end' => '19:30:00', 'period' => 'evening_1_5h_6pm'],
            ['start' => '18:30:00', 'end' => '20:45:00', 'period' => 'evening_1_5h_6_30pm'],
            ['start' => '19:00:00', 'end' => '20:45:00', 'period' => 'evening_1_5h_7pm'],
            ['start' => '19:30:00', 'end' => '20:45:00', 'period' => 'evening_1_5h_7_30pm'],
            
            // ENHANCED FRIDAY/SATURDAY SLOTS for better part-time instructor utilization
            // Friday/Saturday specific slots (these days are underutilized - only 8% usage currently)
            
            // FRIDAY/SATURDAY MORNING SLOTS (7:00 AM - 12:30 PM)
            ['start' => '07:00:00', 'end' => '10:00:00', 'period' => 'fri_sat_morning_3h'],
            ['start' => '07:30:00', 'end' => '10:30:00', 'period' => 'fri_sat_morning_3h_30'],
            ['start' => '08:00:00', 'end' => '11:00:00', 'period' => 'fri_sat_morning_3h_8am'],
            ['start' => '09:00:00', 'end' => '12:00:00', 'period' => 'fri_sat_morning_3h_9am'],
            ['start' => '07:00:00', 'end' => '09:00:00', 'period' => 'fri_sat_morning_2h'],
            ['start' => '09:00:00', 'end' => '11:00:00', 'period' => 'fri_sat_morning_2h_9am'],
            ['start' => '10:00:00', 'end' => '12:00:00', 'period' => 'fri_sat_morning_2h_10am'],
            
            // FRIDAY/SATURDAY AFTERNOON SLOTS (12:30 PM - 5:00 PM) - Currently UNUSED
            ['start' => '13:00:00', 'end' => '15:30:00', 'period' => 'fri_sat_afternoon_3h'],
            ['start' => '13:00:00', 'end' => '16:00:00', 'period' => 'fri_sat_afternoon_3h_1pm'],
            ['start' => '13:30:00', 'end' => '16:30:00', 'period' => 'fri_sat_afternoon_3h_1_30pm'],
            ['start' => '14:00:00', 'end' => '17:00:00', 'period' => 'fri_sat_afternoon_3h_2pm'],
            ['start' => '13:00:00', 'end' => '14:30:00', 'period' => 'fri_sat_afternoon_2h'],
            ['start' => '14:30:00', 'end' => '16:30:00', 'period' => 'fri_sat_afternoon_2h_2_30pm'],
            ['start' => '15:00:00', 'end' => '17:00:00', 'period' => 'fri_sat_afternoon_2h_3pm'],
            
            // FRIDAY/SATURDAY EVENING SLOTS (5:00 PM - 9:00 PM) - Currently UNUSED
            ['start' => '17:00:00', 'end' => '20:45:00', 'period' => 'fri_sat_evening_3h'],
            ['start' => '17:30:00', 'end' => '20:30:00', 'period' => 'fri_sat_evening_3h_30'],
            ['start' => '18:00:00', 'end' => '20:45:00', 'period' => 'fri_sat_evening_3h_6pm'],
            ['start' => '16:00:00', 'end' => '19:00:00', 'period' => 'fri_sat_evening_3h_early'],
            ['start' => '17:00:00', 'end' => '19:00:00', 'period' => 'fri_sat_evening_2h'],
            ['start' => '17:30:00', 'end' => '19:30:00', 'period' => 'fri_sat_evening_2h_30'],
            ['start' => '18:00:00', 'end' => '20:45:00', 'period' => 'fri_sat_evening_2h_6pm'],
            ['start' => '18:30:00', 'end' => '20:30:00', 'period' => 'fri_sat_evening_2h_6_30pm'],
            ['start' => '19:00:00', 'end' => '20:45:00', 'period' => 'fri_sat_evening_2h_7pm'],
            
            // FRIDAY/SATURDAY LONG SLOTS for 6+ unit courses
            ['start' => '07:00:00', 'end' => '12:00:00', 'period' => 'fri_sat_long_5h'],
            ['start' => '13:00:00', 'end' => '17:30:00', 'period' => 'fri_sat_long_5h_afternoon'],
            ['start' => '13:00:00', 'end' => '17:00:00', 'period' => 'fri_sat_long_4h'],
            ['start' => '14:00:00', 'end' => '18:00:00', 'period' => 'fri_sat_long_4h_2pm'],
            
            // ADDITIONAL EVENING SLOTS for better part-time instructor availability
            // 1.5-hour evening slots
            ['start' => '16:00:00', 'end' => '17:30:00', 'period' => 'evening_1_5h_early'],
            ['start' => '16:30:00', 'end' => '18:00:00', 'period' => 'evening_1_5h_early'],
            ['start' => '17:30:00', 'end' => '19:00:00', 'period' => 'evening_1_5h'],
            ['start' => '18:00:00', 'end' => '19:30:00', 'period' => 'evening_1_5h'],
            ['start' => '19:00:00', 'end' => '20:30:00', 'period' => 'evening_1_5h'],
            ['start' => '19:30:00', 'end' => '20:45:00', 'period' => 'evening_1_5h'],
            
            // 2-hour evening slots
            ['start' => '16:00:00', 'end' => '18:00:00', 'period' => 'evening_2h_early'],
            ['start' => '16:30:00', 'end' => '18:30:00', 'period' => 'evening_2h_early'],
            ['start' => '17:00:00', 'end' => '19:00:00', 'period' => 'evening_2h'],
            ['start' => '17:30:00', 'end' => '19:30:00', 'period' => 'evening_2h'],
            ['start' => '18:00:00', 'end' => '20:45:00', 'period' => 'evening_2h'],
            ['start' => '18:30:00', 'end' => '20:30:00', 'period' => 'evening_2h'],
            ['start' => '19:00:00', 'end' => '20:45:00', 'period' => 'evening_2h'],
            
            // 2.5-hour evening slots
            ['start' => '16:00:00', 'end' => '18:30:00', 'period' => 'evening_2_5h_early'],
            ['start' => '16:30:00', 'end' => '19:00:00', 'period' => 'evening_2_5h_early'],
            ['start' => '17:00:00', 'end' => '19:30:00', 'period' => 'evening_2_5h'],
            ['start' => '17:30:00', 'end' => '20:45:00', 'period' => 'evening_2_5h'],
            ['start' => '18:00:00', 'end' => '20:30:00', 'period' => 'evening_2_5h'],
            ['start' => '18:30:00', 'end' => '20:45:00', 'period' => 'evening_2_5h'],
            
            // 3-hour evening slots
            ['start' => '16:00:00', 'end' => '19:00:00', 'period' => 'evening_3h_early'],
            ['start' => '16:30:00', 'end' => '19:30:00', 'period' => 'evening_3h_early'],
            ['start' => '17:00:00', 'end' => '20:45:00', 'period' => 'evening_3h'],
            ['start' => '17:30:00', 'end' => '20:30:00', 'period' => 'evening_3h'],
            ['start' => '18:00:00', 'end' => '20:45:00', 'period' => 'evening_3h'],
            
            // 3.5-hour evening slots
            ['start' => '16:00:00', 'end' => '19:30:00', 'period' => 'evening_3_5h_early'],
            ['start' => '16:30:00', 'end' => '20:45:00', 'period' => 'evening_3_5h_early'],
            ['start' => '17:00:00', 'end' => '20:30:00', 'period' => 'evening_3_5h'],
            ['start' => '17:30:00', 'end' => '20:45:00', 'period' => 'evening_3_5h'],
            
            // 4-hour evening slots
            ['start' => '16:00:00', 'end' => '20:45:00', 'period' => 'evening_4h_early'],
            ['start' => '16:30:00', 'end' => '20:30:00', 'period' => 'evening_4h_early'],
            ['start' => '17:00:00', 'end' => '20:45:00', 'period' => 'evening_4h'],
        ];
    }

    /**
     * Generate continuous time slots (70% of total) with minimal gaps
     * This creates more time slots by allowing meetings to start immediately after others end
     */
    private static function generateContinuousTimeSlots(): array
    {
        $continuousSlots = [];
        
        // Define session durations we need to support
        $durations = [1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 4.5, 5.0];
        
        // Generate continuous slots for different time periods
        $timePeriods = [
            ['start' => '07:00', 'end' => '12:00', 'name' => 'morning'],  // Extended to 12:00 PM for 5-hour slots
            ['start' => '13:00', 'end' => '18:00', 'name' => 'afternoon'], // Start at 1:00 PM, end at 18:00 for 5-hour slots
            ['start' => '16:00', 'end' => '20:45', 'name' => 'evening']   // Start at 4:00 PM, End at 8:45 PM
        ];
        
        foreach ($timePeriods as $period) {
            $startMinutes = self::timeToMinutes($period['start'] . ':00');
            $endMinutes = self::timeToMinutes($period['end'] . ':00');
            
            foreach ($durations as $duration) {
                $durationMinutes = (int)($duration * 60);
                
                // Generate continuous slots within this period
                for ($currentStart = $startMinutes; $currentStart + $durationMinutes <= $endMinutes; $currentStart += 30) {
                    // Skip lunch break (12:00-13:00)
                    $currentEnd = $currentStart + $durationMinutes;
                    if ($currentStart < 720 && $currentEnd > 720) { // 720 = 12:00 in minutes
                        continue;
                    }
                    
                    $startTime = self::minutesToTime($currentStart);
                    $endTime = self::minutesToTime($currentEnd);
                    
                    $continuousSlots[] = [
                        'start' => $startTime,
                        'end' => $endTime,
                        'period' => $period['name'] . '_continuous_' . $duration . 'h'
                    ];
                }
                
                // Add explicit 5-hour slots for 10-unit courses
                if ($duration == 5.0) {
                    if ($period['name'] == 'morning') {
                        $continuousSlots[] = [
                            'start' => '07:00:00',
                            'end' => '12:00:00',
                            'period' => 'morning_explicit_5h'
                        ];
                    } elseif ($period['name'] == 'afternoon') {
                        $continuousSlots[] = [
                            'start' => '13:00:00',
                            'end' => '18:00:00',
                            'period' => 'afternoon_explicit_5h'
                        ];
                    }
                }
            }
        }
        
        return $continuousSlots;
    }

    /**
     * Convert minutes since midnight to time string
     */
    private static function minutesToTime(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%02d:%02d:00', $hours, $mins);
    }

    /**
     * Filter time slots based on employment type - FAIR SCHEDULING FOR ALL
     * Both PART-TIME and FULL-TIME instructors can be scheduled across all time periods
     * RESPECTS LUNCH TIME: No scheduling during 12:00 PM - 12:59 PM
     */
    public static function filterTimeSlotsByEmployment(array $timeSlots, string $employmentType, bool $allowFlexibleScheduling = false): array
    {
        // FAIR SCHEDULING: Both PART-TIME and FULL-TIME can use all available time slots
        // RESPECT LUNCH TIME: Exclude slots that overlap with lunch break (12:00 PM - 12:59 PM)
        return array_filter($timeSlots, function($slot) {
            // Check if slot overlaps with lunch break (12:00 PM - 12:59 PM)
            if (self::isLunchBreakViolation($slot['start'], $slot['end'])) {
                Log::info("LUNCH RESPECT: Excluding slot {$slot['start']}-{$slot['end']} - overlaps with lunch break (12:00 PM - 12:59 PM)");
                return false;
            }
            
            // Allow 6:30 AM to 10:00 PM for all instructors (morning, afternoon, evening)
            return $slot['start'] >= '06:30:00' && $slot['end'] <= '22:00:00';
        });
    }

    /**
     * Generate session durations with explicit FULL-TIME splits for 6-10 units
     * Now includes randomized session generation for 3-4 unit courses
     */
    public static function generateRandomizedSessions(int $units, string $employmentType): array
    {
        if ($units <= 0) {
            return [];
        }

        // PART-TIME: maximum 2 sessions, prefer longer sessions for evening scheduling
        if ($employmentType === 'PART-TIME') {
            if ($units <= 2) {
                return [(float) $units]; // Single session for very low units
            } elseif ($units == 3) {
                // Split 3-unit PART-TIME courses into two 1.5-hour sessions for day consolidation
                return [1.5, 1.5];
            } elseif ($units == 5) {
                // ALWAYS split into two 2.5-hour sessions (no random)
                return [2.5, 2.5]; // Two 2.5-hour sessions
            } else {
                // Split into 2 sessions maximum for other units
                $firstSession = round($units / 2.0, 1);
                $secondSession = round($units - $firstSession, 1);
                return [$firstSession, $secondSession];
            }
        }

        // FULL-TIME rules
        $maxPerSession = 5.0;
        $u = (float) $units;

        // SIMPLIFIED: Use predictable session splits for 3-4 unit courses
        if ($u == 3.0) {
            Log::info("SIMPLE: 3-unit course split into 2 sessions [1.5, 1.5]");
            return [1.5, 1.5];
        }

        if ($u == 4.0) {
            Log::info("SIMPLE: 4-unit course split into 2 sessions [2.0, 2.0]");
            return [2.0, 2.0];
        }

        // For other low units (1-2), use single session
        if ($u <= 2.0) {
            return [$u];
        }
        
        // For 3-4 units, use the existing logic
        if ($u == 3.0) {
            Log::info("SIMPLE: 3-unit course split into 2 sessions [1.5, 1.5]");
            return [1.5, 1.5];
        }

        if ($u == 4.0) {
            Log::info("SIMPLE: 4-unit course split into 2 sessions [2.0, 2.0]");
            return [2.0, 2.0];
        }

        // Modified splits - MAXIMUM 2 sessions only, with better edge case handling
        // IMPORTANT: These durations must match available time slots exactly
        $ftMap = [
            6 => [3.0, 3.0],     // 2 sessions of 3 hours each (8:00-11:00, 13:00-16:00)
            7 => [3.5, 3.5],     // 2 sessions of 3.5 hours each (7:30-11:00, 13:00-16:30)
            8 => [4.0, 4.0],     // 2 sessions of 4 hours each (8:00-12:00, 13:00-17:00)
            9 => [4.5, 4.5],     // 2 sessions of 4.5 hours each (7:00-11:30, 13:00-17:30)
            10 => [4.5, 4.5],    // 2 sessions of 4.5 hours each (7:00-11:30, 13:00-17:30) - FIXED: was 5.0,5.0
            11 => [4.5, 4.5],    // 2 sessions of 4.5 hours each (capped) - FIXED: was 5.0,5.0
            12 => [4.5, 4.5],    // 2 sessions of 4.5 hours each (capped) - FIXED: was 5.0,5.0
        ];
        
        if (abs($u - round($u)) < 1e-9 && isset($ftMap[(int) round($u)])) {
            return $ftMap[(int) round($u)];
        }

        // For 5 units: ALWAYS split into two 2.5-hour sessions (no random)
        if (abs($u - 5.0) < 1e-9) {
            return [2.5, 2.5]; // Two 2.5-hour sessions
        }

        // >10 units: force into 2 sessions maximum (even if sessions become longer than 5h)
        if ($u > 10.0) {
            $firstSession = round($u / 2.0, 1);
            $secondSession = round($u - $firstSession, 1);
            return [$firstSession, $secondSession];
        }

        // Default for non-explicit values: create optimal two-way split
        if ($u <= $maxPerSession) {
            // Single session for values up to max per session
            return [round($u, 1)];
        } else {
            // Two-way split, try to make sessions as equal as possible while staying under max
            $halfUnits = $u / 2.0;
            if ($halfUnits <= $maxPerSession) {
                // Even split works
                $a = round($halfUnits, 1);
                $b = round($u - $a, 1);
            } else {
                // Use max session size for first, remainder for second
                $a = $maxPerSession;
                $b = round($u - $a, 1);
            }
            return [round($a, 1), round($b, 1)];
        }
    }

    /**
     * Check if a slot overlaps with lunch break (12:00 PM - 12:59 PM)
     */
    public static function isLunchBreakViolation(string $startTime, string $endTime): bool
    {
        $startHour = (int) explode(':', $startTime)[0];
        $startMin = (int) explode(':', $startTime)[1];
        $endHour = (int) explode(':', $endTime)[0];
        $endMin = (int) explode(':', $endTime)[1];
        
        $startMinutes = $startHour * 60 + $startMin;
        $endMinutes = $endHour * 60 + $endMin;
        $lunchStart = 12 * 60; // 720 minutes (12:00 PM / 12:00:00)
        $lunchEnd = 12 * 60 + 59 + 1; // 780 minutes (13:00:00 - exclusive, includes up to 12:59:59)
        
        // Check if the time slot overlaps with lunch break (12:00 PM - 12:59 PM)
        // Overlaps if: slot starts before lunch ends AND slot ends after lunch starts
        return !($endMinutes <= $lunchStart || $startMinutes >= $lunchEnd);
    }

    /**
     * Check if two time ranges overlap
     */
    public static function timesOverlap(string $start1, string $end1, string $start2, string $end2): bool
    {
        $s1 = self::timeToMinutes($start1);
        $e1 = self::timeToMinutes($end1);
        $s2 = self::timeToMinutes($start2);
        $e2 = self::timeToMinutes($end2);
        
        return !($e1 <= $s2 || $e2 <= $s1);
    }

    /**
     * Convert time string to minutes since midnight
     */
    public static function timeToMinutes(string $timeStr): int
    {
        $parts = explode(':', $timeStr);
        return (int) $parts[0] * 60 + (int) $parts[1];
    }

    /**
     * Convert 24-hour time to 12-hour with AM/PM
     */
    public static function formatTime12Hour(string $time24): string
    {
        try {
            return Carbon::createFromFormat('H:i:s', $time24)->format('g:i A');
        } catch (\Exception $e) {
            return $time24;
        }
    }

    /**
     * Calculate required sessions based on units and employment type
     */
    public static function calculateRequiredSlots(int $units, string $employmentType): int
    {
        $sessions = self::generateRandomizedSessions($units, $employmentType);
        return count($sessions);
    }

    /**
     * Get all canonical days
     */
    public static function getAllDays(): array
    {
        return self::DAYS;
    }

    /**
     * Normalize day name to canonical form (delegated to DayScheduler)
     */
    public static function normalizeDay(string $day): string
    {
        return \App\Services\DayScheduler::normalizeDay($day);
    }

    /**
     * Sort schedules by day then time
     */
    public static function sortByDayThenTime(array $schedules): array
    {
        usort($schedules, function ($a, $b) {
            $dayA = array_search($a['day'] ?? '', self::DAYS);
            $dayB = array_search($b['day'] ?? '', self::DAYS);
            
            if ($dayA === false) $dayA = 999;
            if ($dayB === false) $dayB = 999;
            
            if ($dayA !== $dayB) {
                return $dayA <=> $dayB;
            }
            
            return strcmp($a['start_time'] ?? '00:00:00', $b['start_time'] ?? '00:00:00');
        });
        
        return $schedules;
    }
}

