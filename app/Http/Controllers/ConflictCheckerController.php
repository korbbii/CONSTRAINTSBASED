<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ScheduleEntry;
use App\Models\ScheduleMeeting;
use App\Models\ScheduleGroup;
use Illuminate\Support\Facades\DB;

class ConflictCheckerController extends Controller
{
    public function index()
    {
        // Get all schedule groups to check conflicts across all schedules
        $allGroups = ScheduleGroup::orderBy('created_at', 'desc')->get();
        
        if ($allGroups->isEmpty()) {
            return view('Conflict-checker', [
                'instructorConflicts' => collect(),
                'roomConflicts' => collect(), 
                'sectionConflicts' => collect(),
                'allGroups' => collect()
            ]);
        }
        
        // Get the most recent group for display purposes
        $latestGroup = $allGroups->first();
        
        // Get instructor conflicts within each group (separated by group_id)
        $instructorConflicts = $this->getInstructorConflicts(null);
        
        // Get room conflicts within each group (separated by group_id)
        $roomConflicts = $this->getRoomConflicts(null);
        
        // Get section conflicts within each group (separated by group_id)
        $sectionConflicts = $this->getSectionConflicts(null);
        
        return view('Conflict-checker', compact('instructorConflicts', 'roomConflicts', 'sectionConflicts', 'latestGroup', 'allGroups'));
    }
    
    private function getInstructorConflicts($groupContext = null)
    {
        $query = ScheduleEntry::with([
            'subject',
            'instructor', 
            'section',
            'scheduleGroup',
            'meetings'
        ])
        ->whereHas('meetings');
        
        // Scope strictly to the provided group's id to avoid cross-group false positives
        if ($groupContext instanceof \App\Models\ScheduleGroup) {
            $query->where('group_id', $groupContext->group_id);
        } elseif (!is_null($groupContext)) {
            // Backward compatibility: if a numeric group id is passed
            $query->where('group_id', $groupContext);
        }
        
        $entries = $query->get();
        

        $conflicts = collect();
        
        // Build flat groups by meeting-level instructor within the same group
        // CRITICAL: Include group_id to separate different schedule versions
        $byInstructor = [];
        foreach ($entries as $entry) {
            foreach ($entry->meetings as $meeting) {
                $instrId = $meeting->instructor_id;
                if (is_null($instrId)) { continue; }
                // Group by instructor AND group_id to avoid cross-group conflicts
                $key = $instrId . '|' . $entry->group_id;
                if (!isset($byInstructor[$key])) { $byInstructor[$key] = []; }
                $byInstructor[$key][] = ['entry' => $entry, 'meeting' => $meeting];
            }
        }

        \Log::info("DEBUG: Grouped into " . count($byInstructor) . " instructor/group groups (meeting-level)");

        foreach ($byInstructor as $key => $items) {
            if (count($items) <= 1) { continue; }

            // Detect overlaps among meetings for this instructor (room not required)
            $processed = [];
            for ($i = 0; $i < count($items); $i++) {
                if (in_array($i, $processed, true)) { continue; }
                $groupItems = [ $items[$i] ];
                $processed[] = $i;
                for ($j = $i + 1; $j < count($items); $j++) {
                    if (in_array($j, $processed, true)) { continue; }
                    if ($this->meetingsOverlap($items[$i]['meeting'], $items[$j]['meeting'])) {
                        $groupItems[] = $items[$j];
                        $processed[] = $j;
                    }
                }
                if (count($groupItems) > 1) {
                    $conflicts->push(collect($groupItems)->map(function($item) {
                        $entry = $item['entry'];
                        $meeting = $item['meeting'];
                        return [
                            'subject_code' => $entry->subject_code,
                            'department' => $entry->department,
                            'instructor_name' => $entry->instructor_name,
                            'day' => $meeting ? $meeting->day : null,
                            'start_time' => $meeting ? $this->formatTime12Hour($meeting->start_time) : null,
                            'end_time' => $meeting ? $this->formatTime12Hour($meeting->end_time) : null,
                            'school_year' => $entry->scheduleGroup->school_year,
                            'semester' => $entry->scheduleGroup->semester,
                            'section_code' => $entry->section->code ?? null,
                            'room_name' => $meeting && $meeting->room ? $meeting->room->room_name : 'No Room',
                        ];
                    }));
                }
            }
        }

        return $conflicts;
    }
    
    private function getRoomConflicts($groupId = null)
    {
        $query = ScheduleEntry::with([
            'subject',
            'instructor',
            'section', 
            'scheduleGroup',
            'meetings'
        ])
        ->whereHas('meetings');
        
        // Filter by specific group if provided
        if ($groupId) {
            $query->where('group_id', $groupId);
        }
        
        $entries = $query->get();

        $conflicts = collect();
        
        // Group entries by room AND group_id to separate different schedule versions
        $groupedEntries = $entries->groupBy(function($entry) {
            $firstMeeting = $entry->meetings->first();
            $roomId = $firstMeeting ? $firstMeeting->room_id : '0';
            return $roomId . '|' . $entry->group_id;
        });

        foreach ($groupedEntries as $group) {
            if ($group->count() <= 1) continue;
            
            // For room conflicts, require same room AND overlapping times
            $conflictGroups = $this->findTimeOverlapsAllMeetings($group, requireSameRoom: true);
            foreach ($conflictGroups as $conflictGroup) {
                if ($conflictGroup->count() > 1) {
                    $conflicts->push($conflictGroup);
                }
            }
        }

        return $conflicts;
    }

    private function getSectionConflicts($groupContext = null)
    {
        $query = ScheduleEntry::with([
            'subject',
            'instructor',
            'section', 
            'scheduleGroup',
            'meetings'
        ])
        ->whereHas('meetings');
        
        // Scope strictly to the provided group's id to avoid cross-group false positives
        if ($groupContext instanceof \App\Models\ScheduleGroup) {
            $query->where('group_id', $groupContext->group_id);
        } elseif (!is_null($groupContext)) {
            // Backward compatibility: if a numeric group id is passed
            $query->where('group_id', $groupContext);
        }
        
        $entries = $query->get();

        $conflicts = collect();
        
        // Group entries by section CODE AND group_id to separate different schedule versions
        $groupedEntries = $entries->groupBy(function($entry) {
            $sectionCode = optional($entry->section)->code ?? 'UNKNOWN';
            return $sectionCode . '|' . $entry->group_id;
        });

        \Log::info("SECTION DEBUG: Grouped into " . $groupedEntries->count() . " section/group groups");

        foreach ($groupedEntries as $groupKey => $group) {
            if ($group->count() <= 1) continue;
            
            // For section conflicts, don't require same room - sections can't have overlapping times regardless
            $conflictGroups = $this->findTimeOverlapsAllMeetings($group, requireSameRoom: false);
            \Log::info("SECTION DEBUG: Group " . $groupKey . " has " . $group->count() . " entries, conflicts found: " . $conflictGroups->count());
            foreach ($conflictGroups as $conflictGroup) {
                if ($conflictGroup->count() > 1) {
                    $conflicts->push($conflictGroup);
                }
            }
        }

        return $conflicts;
    }

    private function findTimeOverlapsAllMeetings($entries, bool $requireSameRoom = false)
    {
        $conflictGroups = collect();
        $processed = collect();

        // Create a flat list of all meetings from all entries
        $allMeetings = collect();
        foreach ($entries as $entry) {
            foreach ($entry->meetings as $meeting) {
                $allMeetings->push([
                    'entry' => $entry,
                    'meeting' => $meeting
                ]);
            }
        }

        foreach ($allMeetings as $index => $meetingData) {
            $entry = $meetingData['entry'];
            $meeting = $meetingData['meeting'];
            
            // Track processed meetings by index to handle entries with multiple meetings
            if ($processed->contains($index)) continue;
            
            $conflictGroup = collect([['entry' => $entry, 'meeting' => $meeting]]);
            $processed->push($index);

            // Check for overlapping meetings with other entries
            foreach ($allMeetings as $otherIndex => $otherMeetingData) {
                $otherEntry = $otherMeetingData['entry'];
                $otherMeeting = $otherMeetingData['meeting'];
                
                if ($processed->contains($otherIndex)) continue;
                if ($index === $otherIndex) continue;

                // Check if meetings overlap on the same day
                $timeOverlaps = $this->meetingsOverlap($meeting, $otherMeeting);
                
                // If requireSameRoom is true (for room conflicts), also check room_id
                // If requireSameRoom is false (for instructor/section conflicts), only check time overlap
                if ($timeOverlaps && (!$requireSameRoom || $meeting->room_id === $otherMeeting->room_id)) {
                    $conflictGroup->push(['entry' => $otherEntry, 'meeting' => $otherMeeting]);
                    $processed->push($otherIndex);
                }
            }

            if ($conflictGroup->count() > 1) {
                // Return the structure expected by views: a collection of flat items
                $conflictGroups->push($conflictGroup->map(function($item) {
                    $entry = $item['entry'];
                    $meeting = $item['meeting'];
                    return [
                        'subject_code' => $entry->subject_code,
                        'department' => $entry->department,
                        'instructor_name' => $entry->instructor_name,
                        'day' => $meeting ? $meeting->day : null,
                        'start_time' => $meeting ? $this->formatTime12Hour($meeting->start_time) : null,
                        'end_time' => $meeting ? $this->formatTime12Hour($meeting->end_time) : null,
                        'school_year' => $entry->scheduleGroup->school_year,
                        'semester' => $entry->scheduleGroup->semester,
                        'section_code' => $entry->section->code ?? null,
                        'room_name' => $meeting && $meeting->room ? $meeting->room->room_name : 'No Room',
                    ];
                }));
            }
        }

        return $conflictGroups;
    }

    private function findTimeOverlaps($entries)
    {
        $conflictGroups = collect();
        $processed = collect();

        foreach ($entries as $entry) {
            if ($processed->contains($entry->entry_id)) continue;
            
            $meeting = $entry->meetings->first();
            if (!$meeting) continue;

            $conflictGroup = collect([$entry]);
            $processed->push($entry->entry_id);

            // Check for overlapping meetings
            foreach ($entries as $otherEntry) {
                if ($processed->contains($otherEntry->entry_id)) continue;
                
                $otherMeeting = $otherEntry->meetings->first();
                if (!$otherMeeting) continue;

                // Check if meetings overlap on the same day
                if ($this->meetingsOverlap($meeting, $otherMeeting)) {
                    $conflictGroup->push($otherEntry);
                    $processed->push($otherEntry->entry_id);
                }
            }

            if ($conflictGroup->count() > 1) {
                $conflictGroups->push($conflictGroup->map(function($entry) {
                    $meeting = $entry->meetings->first();
                    return [
                        'subject_code' => $entry->subject_code,
                        'department' => $entry->department,
                        'instructor_name' => $entry->instructor_name,
                        'day' => $meeting ? $meeting->day : null,
                        'start_time' => $meeting ? $meeting->start_time : null,
                        'end_time' => $meeting ? $meeting->end_time : null,
                        'school_year' => $entry->scheduleGroup->school_year,
                        'semester' => $entry->scheduleGroup->semester,
                        'section_code' => $entry->section->code ?? null,
                        'room_name' => $meeting && $meeting->room ? $meeting->room->room_name : null,
                    ];
                }));
            }
        }

        return $conflictGroups;
    }

    private function meetingsOverlap($meeting1, $meeting2)
    {
        // Robustly parse combined day strings, handling concatenated and delimited forms
        $days1 = \App\Services\DayScheduler::parseCombinedDays($meeting1->day ?? '');
        $days2 = \App\Services\DayScheduler::parseCombinedDays($meeting2->day ?? '');
        
        // Check if meetings share any common days
        $commonDays = array_intersect($days1, $days2);
        \Log::debug('OVERLAP DEBUG: comparing days', ['m1' => $days1, 'm2' => $days2, 'common' => $commonDays]);
        if (empty($commonDays)) {
            return false;
        }

        // If they share at least one day, check if times overlap
        $start1 = $this->timeToMinutes($meeting1->start_time);
        $end1 = $this->timeToMinutes($meeting1->end_time);
        $start2 = $this->timeToMinutes($meeting2->start_time);
        $end2 = $this->timeToMinutes($meeting2->end_time);

        // Handle corrupted data where start_time >= end_time by swapping
        if ($start1 >= $end1) {
            \Log::warning('CORRUPTED TIME DATA: start_time >= end_time', [
                'meeting_id' => optional($meeting1)->meeting_id,
                'start' => $meeting1->start_time,
                'end' => $meeting1->end_time
            ]);
            $temp = $start1;
            $start1 = $end1;
            $end1 = $temp;
        }
        if ($start2 >= $end2) {
            \Log::warning('CORRUPTED TIME DATA: start_time >= end_time', [
                'meeting_id' => optional($meeting2)->meeting_id,
                'start' => $meeting2->start_time,
                'end' => $meeting2->end_time
            ]);
            $temp = $start2;
            $start2 = $end2;
            $end2 = $temp;
        }

        // Check for overlap: two time ranges overlap if one starts before the other ends
        $overlap = ($start1 < $end2) && ($start2 < $end1);
        return $overlap;
    }

    private function timeToMinutes($timeString)
    {
        // Handle database time format (HH:MM:SS or HH:MM)
        $time = trim($timeString);
        
        // If it contains a dash, take the first part
        if (strpos($time, '–') !== false) {
            $time = trim(explode('–', $time)[0]);
        }
        
        // Handle AM/PM format (in case it's stored that way)
        if (stripos($time, 'AM') !== false || stripos($time, 'PM') !== false) {
            $time = date('H:i', strtotime($time));
        }
        
        // Convert to minutes - handle both HH:MM:SS and HH:MM formats
        $parts = explode(':', $time);
        $hours = (int)$parts[0];
        $minutes = (int)($parts[1] ?? 0);
        
        return ($hours * 60) + $minutes;
    }

    private function formatTime12Hour($timeString)
    {
        // Handle database time format (HH:MM:SS or HH:MM)
        $time = trim($timeString);
        
        // If it contains a dash, take the first part
        if (strpos($time, '–') !== false) {
            $time = trim(explode('–', $time)[0]);
        }
        
        // Convert to 12-hour format
        return date('g:i A', strtotime($time));
    }

    private function getConflictingDays($conflictGroup)
    {
        // Get all days from all meetings in the conflict group
        $allDays = [];
        foreach ($conflictGroup as $item) {
            $meeting = $item['meeting'];
            if ($meeting && $meeting->day) {
                $days = \App\Services\DayScheduler::splitCombinedDays($meeting->day);
                $allDays = array_merge($allDays, $days);
            }
        }
        
        // Count occurrences of each day
        $dayCounts = array_count_values($allDays);
        
        // Return days that appear more than once (indicating conflict)
        return array_keys(array_filter($dayCounts, function($count) {
            return $count > 1;
        }));
    }
}
