<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Models\ScheduleEntry;
use App\Models\ScheduleMeeting;

class ScheduleEditController extends Controller
{
    /**
     * Validate a proposed schedule edit for conflicts in real time.
     * Accepts either meeting_id or entry_id plus proposed fields.
     * Returns { ok: bool, conflicts: [], details: {...} }
     */
    public function validateEdit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id'    => 'nullable|integer|exists:schedule_groups,group_id',
            'meeting_id' => 'nullable|integer|exists:schedule_meetings,meeting_id',
            'entry_id'   => 'nullable|integer|exists:schedule_entries,entry_id',
            'instructor_id' => 'nullable|integer',
            'room_id'       => 'nullable|integer',
            'room_name'     => 'nullable|string', // For resolving room_id from room name
            'section_id'    => 'nullable|integer',
            'day'           => 'required|string',
            'start_time'    => 'required|date_format:H:i:s',
            'end_time'      => 'required|date_format:H:i:s',
        ]);

        // Resolve context (group_id, subject_id, section_id) from entry if not given
        $entry = null;
        if (!empty($validated['meeting_id'])) {
            $meeting = ScheduleMeeting::find($validated['meeting_id']);
            $entry = $meeting ? $meeting->entry : null;
        }
        if (!$entry && !empty($validated['entry_id'])) {
            $entry = ScheduleEntry::find($validated['entry_id']);
        }
        if (!$entry && !empty($validated['group_id'])) {
            // Fall back to group-level validation
            $groupId = (int)$validated['group_id'];
        } else {
            if (!$entry) {
                return response()->json(['ok' => false, 'message' => 'Entry not found for validation'], 422);
            }
            $groupId   = $entry->group_id;
        }

        $subjectId = $entry ? $entry->subject_id : null;
        $sectionId = $validated['section_id'] ?? ($entry ? $entry->section_id : null);

        $instructorId = $validated['instructor_id'] ?? ($entry && $entry->instructor ? $entry->instructor->instructor_id : null);
        $roomId       = $validated['room_id'] ?? null; // room is on meeting
        if ($roomId === null && !empty($validated['meeting_id'])) {
            $roomId = ScheduleMeeting::where('meeting_id', $validated['meeting_id'])->value('room_id');
        }
        // If room_id is still null and room_name is provided, resolve it
        if ($roomId === null && !empty($validated['room_name'])) {
            $roomId = \App\Models\Room::where('room_name', $validated['room_name'])->value('room_id');
        }

        $day   = \App\Services\DayScheduler::normalizeDay($validated['day']);
        $start = $validated['start_time'];
        $end   = $validated['end_time'];

        // Load entry with subject and meetings relationships to get units and detect joint sessions
        if ($entry && !$entry->relationLoaded('subject')) {
            $entry->load('subject');
        }
        if ($entry && !$entry->relationLoaded('meetings')) {
            $entry->load('meetings');
        }
        if ($entry && $entry->instructor && !$entry->relationLoaded('instructor')) {
            $entry->load('instructor');
        }

        // Clone core conflict-detector logic from ConflictCheckerController
        $entries = ScheduleEntry::with(['meetings','instructor','section','scheduleGroup'])
            ->where('group_id', $groupId)
            ->whereHas('meetings')
            ->get();

        $proposed = (object) [
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'room_id' => $roomId,
        ];

        Log::debug('validateEdit: Checking conflicts', [
            'group_id' => $groupId,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'instructor_id' => $instructorId,
            'room_id' => $roomId,
            'meeting_id' => $validated['meeting_id'] ?? null,
            'section_id' => $sectionId,
            'entries_count' => $entries->count(),
            'day_parsed' => \App\Services\DayScheduler::parseCombinedDays($day)
        ]);

        // Collect all time-based conflicts (don't return early - check all)
        $timeConflicts = [];
        $timeConflictDetails = [];
        
        // RESPECT CLASS START TIME: Check if start time is before 7:00 AM (07:00:00)
        // Invalid ranges: 12:00 AM (00:00:00) to 6:59 AM (06:59:59)
        if ($start < '07:00:00') {
            Log::debug('validateEdit: Class start time violation detected', [
                'start' => $start,
                'end' => $end
            ]);
            $timeConflicts[] = 'start_time';
            $timeConflictDetails['start_time'] = [
                [
                    'message' => 'Class must start at 7:00 AM or later',
                    'start' => $start,
                    'end' => $end
                ]
            ];
        }
        
        // RESPECT LUNCH TIME: Check if proposed time violates lunch break (12:00 PM - 12:59 PM)
        if (\App\Services\TimeScheduler::isLunchBreakViolation($start, $end)) {
            Log::debug('validateEdit: Lunch break violation detected', [
                'start' => $start,
                'end' => $end
            ]);
            $timeConflicts[] = 'lunch';
            $timeConflictDetails['lunch'] = [
                [
                    'message' => 'Time slot overlaps with lunch break (12:00 PM - 12:59 PM)',
                    'start' => $start,
                    'end' => $end
                ]
            ];
        }
        
        // RESPECT CLASS CUTOFF: Check if time ends at or after 8:45 PM (20:45:00)
        // Invalid ranges: 8:45 PM (20:45:00) to 11:59 PM (23:59:59)
        if ($end >= '20:45:00') {
            Log::debug('validateEdit: Class cutoff violation detected', [
                'start' => $start,
                'end' => $end
            ]);
            $timeConflicts[] = 'cutoff';
            $timeConflictDetails['cutoff'] = [
                [
                    'message' => 'Time slot ends at or after class cutoff (8:45 PM)',
                    'start' => $start,
                    'end' => $end
                ]
            ];
        }
        
        // VALIDATE DURATION MATCHES UNITS: Check if time duration matches expected duration based on units
        // For joint sessions (same time on multiple days), validate per-session duration
        // For single-session meetings, validate total units duration
        if ($entry && $entry->subject) {
            $units = $entry->subject->units ?? null;
            if ($units && $units > 0) {
                // Get employment type (needed for session calculation)
                $employmentType = 'FULL-TIME'; // Default
                if ($entry->instructor && $entry->instructor->employment_type) {
                    $employmentType = $entry->instructor->employment_type;
                } elseif (!empty($validated['employment_type'])) {
                    $employmentType = $validated['employment_type'];
                }
                
                // Calculate expected session durations based on units
                $expectedDurations = \App\Services\TimeScheduler::generateRandomizedSessions((int)$units, $employmentType);
                
                // Detect if this is a joint session (multiple meetings with same time)
                $isJointSession = false;
                $jointSessionMeetings = [];
                if ($entry->meetings && $entry->meetings->count() > 1) {
                    // Check if multiple meetings share the same time (joint session indicator)
                    $timeGroups = [];
                    foreach ($entry->meetings as $meeting) {
                        // Skip the current meeting being edited (use proposed time)
                        if (!empty($validated['meeting_id']) && (int)$meeting->meeting_id === (int)$validated['meeting_id']) {
                            $timeKey = $start . '|' . $end;
                        } else {
                            $timeKey = $meeting->start_time . '|' . $meeting->end_time;
                        }
                        if (!isset($timeGroups[$timeKey])) {
                            $timeGroups[$timeKey] = [];
                        }
                        $timeGroups[$timeKey][] = $meeting;
                    }
                    
                    // Check if there's a time group with multiple meetings (joint session)
                    foreach ($timeGroups as $timeKey => $meetings) {
                        if (count($meetings) > 1) {
                            $isJointSession = true;
                            $jointSessionMeetings = $meetings;
                            break;
                        }
                    }
                }
                
                // For joint sessions, validate against per-session duration (each meeting should match one session duration)
                // For single sessions, validate against total units or single session duration
                if ($isJointSession && count($expectedDurations) > 1) {
                    // Joint session: each meeting should match one of the session durations (e.g., 1.5 hours for 3-unit course split into [1.5, 1.5])
                    // Use individual session durations, not total
                    $expectedDurationsForValidation = $expectedDurations;
                } else {
                    // Single session: validate against total units (if single session) or appropriate session duration
                    // If it's a single-session course, expectedDurations will have one value matching units
                    // If it's a multi-session course being edited as a single meeting, use the first session duration
                    $expectedDurationsForValidation = $expectedDurations;
                }
                
                // Calculate actual duration in minutes
                $startMinutes = \App\Services\TimeScheduler::timeToMinutes($start);
                $endMinutes = \App\Services\TimeScheduler::timeToMinutes($end);
                $actualDurationMinutes = $endMinutes - $startMinutes;
                
                // Convert expected durations to minutes
                $expectedDurationsMinutes = array_map(function($hours) {
                    return (int)round($hours * 60);
                }, $expectedDurationsForValidation);
                
                // Check if actual duration matches any expected session duration (within 1 minute tolerance)
                $matches = false;
                foreach ($expectedDurationsMinutes as $expectedMinutes) {
                    if (abs($actualDurationMinutes - $expectedMinutes) <= 1) {
                        $matches = true;
                        break;
                    }
                }
                
                if (!$matches) {
                    Log::debug('validateEdit: Duration mismatch detected', [
                        'units' => $units,
                        'employment_type' => $employmentType,
                        'is_joint_session' => $isJointSession,
                        'joint_session_meeting_count' => $isJointSession ? count($jointSessionMeetings) : 0,
                        'expected_durations' => $expectedDurations,
                        'expected_durations_for_validation' => $expectedDurationsForValidation,
                        'expected_durations_minutes' => $expectedDurationsMinutes,
                        'actual_duration_minutes' => $actualDurationMinutes,
                        'start' => $start,
                        'end' => $end
                    ]);
                    $timeConflicts[] = 'duration';
                    $expectedDurationsDisplay = array_map(function($hours) {
                        return number_format($hours, 1) . ' hour' . ($hours != 1 ? 's' : '');
                    }, $expectedDurationsForValidation);
                    // Remove duplicates from display array to avoid "1.5 hours or 1.5 hours"
                    $expectedDurationsDisplay = array_unique($expectedDurationsDisplay);
                    $message = 'Time duration does not match expected duration';
                    if ($isJointSession) {
                        $message .= ' for joint session';
                    }
                    $message .= ' (' . $units . ' unit' . ($units != 1 ? 's' : '') . '). Expected: ' . implode(' or ', $expectedDurationsDisplay);
                    $timeConflictDetails['duration'] = [
                        [
                            'message' => $message,
                            'units' => $units,
                            'is_joint_session' => $isJointSession,
                            'expected_durations' => $expectedDurationsForValidation,
                            'actual_duration_minutes' => $actualDurationMinutes,
                            'start' => $start,
                            'end' => $end
                        ]
                    ];
                }
            }
        }
        
        // If there are time-based conflicts, return them immediately (before checking other conflicts)
        if (!empty($timeConflicts)) {
            return response()->json([
                'ok' => false,
                'conflicts' => $timeConflicts,
                'details' => $timeConflictDetails,
            ]);
        }

        // Get the entry_id of the current meeting being edited (to exclude all meetings from same entry)
        $currentEntryIdForValidation = null;
        if (!empty($validated['entry_id'])) {
            $currentEntryIdForValidation = (int)$validated['entry_id'];
        } elseif (!empty($validated['meeting_id'])) {
            $currentMeeting = ScheduleMeeting::find($validated['meeting_id']);
            if ($currentMeeting) {
                $currentEntryIdForValidation = (int)$currentMeeting->entry_id;
            }
        }

        $conflictTypes = [];
        foreach ($entries as $e) {
            // Skip the entire entry if it's the one being edited (exclude all its meetings)
            // This ensures that when editing time for one meeting, we don't conflict with other meetings of the same course
            if ($currentEntryIdForValidation && (int)$e->entry_id === (int)$currentEntryIdForValidation) {
                continue;
            }
            
            foreach ($e->meetings as $m) {
                // Skip comparing against the same meeting (when editing), allow move within same one
                if (!empty($validated['meeting_id']) && (int)$m->meeting_id === (int)$validated['meeting_id']) {
                    continue;
                }
                
                // Use same overlap rule as ConflictCheckerController
                if ($this->meetingsOverlapSimple($proposed, $m)) {
                    Log::debug('validateEdit: Found overlap', [
                        'proposed_day' => $day,
                        'existing_day' => $m->day,
                        'proposed_time' => "$start - $end",
                        'existing_time' => "{$m->start_time} - {$m->end_time}",
                        'meeting_id' => $m->meeting_id
                    ]);
                    
                    if ($instructorId && $m->instructor_id && (int)$m->instructor_id === (int)$instructorId) {
                        Log::debug('validateEdit: Instructor conflict detected', [
                            'instructor_id' => $instructorId,
                            'conflicting_entry' => $e->entry_id,
                            'conflicting_meeting' => $m->meeting_id
                        ]);
                        $conflictTypes['instructor'][] = ['entry' => $e, 'meeting' => $m];
                    }
                    if ($roomId && $m->room_id && (int)$m->room_id === (int)$roomId) {
                        Log::debug('validateEdit: Room conflict detected', [
                            'room_id' => $roomId,
                            'conflicting_entry' => $e->entry_id,
                            'conflicting_meeting' => $m->meeting_id
                        ]);
                        $conflictTypes['room'][] = ['entry' => $e, 'meeting' => $m];
                    }
                    if ($sectionId && (int)$e->section_id === (int)$sectionId) {
                        Log::debug('validateEdit: Section conflict detected', [
                            'section_id' => $sectionId,
                            'conflicting_entry' => $e->entry_id,
                            'conflicting_meeting' => $m->meeting_id
                        ]);
                        $conflictTypes['section'][] = ['entry' => $e, 'meeting' => $m];
                    }
                }
            }
        }

        Log::debug('validateEdit: Validation result', [
            'ok' => empty($conflictTypes),
            'conflicts' => array_keys($conflictTypes),
            'conflict_count' => array_sum(array_map('count', $conflictTypes))
        ]);

        return response()->json([
            'ok' => empty($conflictTypes),
            'conflicts' => array_keys($conflictTypes),
            'details' => $conflictTypes,
        ]);
    }

    /**
     * Persist an edit (if client decides to save) with a final conflict guard.
     */
    public function updateMeeting(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'meeting_id'   => 'required|integer|exists:schedule_meetings,meeting_id',
            'instructor_id' => 'nullable|integer',
            'room_id'       => 'nullable|integer',
            'day'           => 'required|string',
            'start_time'    => 'required|date_format:H:i:s',
            'end_time'      => 'required|date_format:H:i:s',
        ]);

        $meeting = ScheduleMeeting::findOrFail($validated['meeting_id']);
        $entry   = $meeting->entry;

        // Final guard
        $hasConflict = ScheduleMeeting::hasConflict(
            (int)$entry->group_id,
            $validated['instructor_id'] ?? $meeting->instructor_id,
            $validated['room_id'] ?? $meeting->room_id,
            (int)$entry->section_id,
            $validated['day'],
            $validated['start_time'],
            $validated['end_time'],
            (int)$entry->subject_id
        );
        if ($hasConflict) {
            return response()->json(['ok' => false, 'message' => 'Conflict detected. Changes not saved.'], 409);
        }

        // Apply changes
        $meeting->day = $validated['day'];
        $meeting->start_time = $validated['start_time'];
        $meeting->end_time = $validated['end_time'];
        if (array_key_exists('room_id', $validated)) {
            $meeting->room_id = $validated['room_id'];
        }
        if (array_key_exists('instructor_id', $validated)) {
            $meeting->instructor_id = $validated['instructor_id'];
        }
        $meeting->save();

        return response()->json(['ok' => true, 'meeting' => $meeting]);
    }

    /**
     * Locate entry and meeting by group + subject_code + instructor_name + section code + time.
     * Returns instructor_id, room_id, meeting_id, and entry_id for validation.
     */
    public function locateEntry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => 'required|integer|exists:schedule_groups,group_id',
            'subject_code' => 'required|string',
            'instructor_name' => 'required|string',
            'section_code' => 'required|string',
            'day' => 'nullable|string',
            'start_time' => 'nullable|date_format:H:i:s',
            'end_time' => 'nullable|date_format:H:i:s',
        ]);

        // Normalize section code - might be "1st Year A" or "BSBA-1st Year A"
        $sectionCode = $validated['section_code'];
        // If section code doesn't start with department prefix, try both formats
        $sectionCodeVariants = [$sectionCode];
        if (!preg_match('/^[A-Z]+-/', $sectionCode)) {
            // Try with common department prefixes
            $sectionCodeVariants[] = 'BSBA-' . $sectionCode;
            $sectionCodeVariants[] = 'CRIM-' . $sectionCode;
            $sectionCodeVariants[] = 'EDUC-' . $sectionCode;
        }

        // Find entry by group + subject + instructor + section
        // Note: instructor_id is on meetings, not entries, so we need to filter through meetings
        $entry = ScheduleEntry::with(['meetings'])
            ->where('group_id', $validated['group_id'])
            ->whereHas('subject', function($q) use ($validated){ 
                $q->where('code', $validated['subject_code']); 
            })
            ->whereHas('meetings', function($q) use ($validated) {
                $q->whereHas('instructor', function($q2) use ($validated) {
                    $q2->where('name', $validated['instructor_name']);
                });
            })
            ->whereHas('section', function($q) use ($sectionCodeVariants){ 
                $q->whereIn('code', $sectionCodeVariants); 
            })
            ->first();

        if (!$entry) {
            Log::warning('locateEntry: Entry not found', [
                'group_id' => $validated['group_id'],
                'subject_code' => $validated['subject_code'],
                'instructor_name' => $validated['instructor_name'],
                'section_code' => $validated['section_code']
            ]);
            return response()->json([
                'ok' => false, 
                'message' => 'Entry not found by locator',
                'instructor_id' => null,
                'room_id' => null,
                'meeting_id' => null,
                'entry_id' => null
            ], 404);
        }

        // Load meetings relationship if not already loaded
        if (!$entry->relationLoaded('meetings')) {
            $entry->load('meetings');
        }

        // Find the meeting matching the day/time if provided
        $meeting = null;
        if (!empty($validated['day']) && !empty($validated['start_time']) && !empty($validated['end_time'])) {
            $normalizedDay = \App\Services\DayScheduler::normalizeDay($validated['day']);
            // Try exact match first
            $meeting = $entry->meetings->first(function($m) use ($normalizedDay, $validated) {
                return $m->day === $normalizedDay && 
                       $m->start_time === $validated['start_time'] && 
                       $m->end_time === $validated['end_time'];
            });
            
            // If not found, try without exact time match (might be combined days)
            if (!$meeting) {
                $meeting = $entry->meetings->first(function($m) use ($normalizedDay, $validated) {
                    $meetingDays = \App\Services\DayScheduler::parseCombinedDays($m->day) ?: [$m->day];
                    return in_array($normalizedDay, $meetingDays);
                });
            }
        }

        // If no specific meeting found, get the first meeting from the entry
        if (!$meeting && $entry->meetings && $entry->meetings->count() > 0) {
            $meeting = $entry->meetings->first();
        }

        // Get instructor_id - prioritize meeting's instructor_id (instructors are on meetings now)
        $instructorId = $meeting ? $meeting->instructor_id : ($entry->instructor_id ?? null);
        if (!$instructorId && $entry->instructor) {
            $instructorId = $entry->instructor->instructor_id;
        }

        return response()->json([
            'ok' => true,
            'instructor_id' => $instructorId,
            'room_id' => $meeting ? $meeting->room_id : null,
            'meeting_id' => $meeting ? $meeting->meeting_id : null,
            'entry_id' => $entry->entry_id,
            // Include original meeting time to help frontend identify the exact meeting
            'original_start_time' => $meeting ? $meeting->start_time : null,
            'original_end_time' => $meeting ? $meeting->end_time : null,
            'original_day' => $meeting ? $meeting->day : null,
            // Also include current time as fallback
            'start_time' => $meeting ? $meeting->start_time : null,
            'end_time' => $meeting ? $meeting->end_time : null,
            'day' => $meeting ? $meeting->day : null
        ]);
    }

    /**
     * Persist by locating meeting via group + subject_code + instructor_name + section code + original time.
     * This supports the history view which renders human-readable rows.
     */
    public function updateByLocator(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => 'required|integer|exists:schedule_groups,group_id',
            'subject_code' => 'required|string',
            'instructor_name' => 'required|string',
            'section_code' => 'required|string',
            'orig_day' => 'required|string',
            'orig_start_time' => 'required|date_format:H:i:s',
            'orig_end_time' => 'required|date_format:H:i:s',
            'new_day' => 'required|string',
            'new_start_time' => 'required|date_format:H:i:s',
            'new_end_time' => 'required|date_format:H:i:s',
            'new_room_name' => 'nullable|string'
        ]);

        // Find entry by group + subject + section
        // Note: instructor_id is on schedule_meetings, not schedule_entries, so filter through meetings relationship
        Log::info('updateByLocator: Searching for entry', [
            'group_id' => $validated['group_id'],
            'subject_code' => $validated['subject_code'],
            'instructor_name' => $validated['instructor_name'],
            'section_code' => $validated['section_code']
        ]);
        
        // Section code matching: frontend sends "1st Year A" but database has "BSBA-1st Year A"
        // So we need to match using LIKE pattern that matches the suffix
        $sectionCodePattern = '%' . $validated['section_code'];
        
        $entry = ScheduleEntry::where('group_id', $validated['group_id'])
            ->whereHas('subject', function($q) use ($validated){ $q->where('code', $validated['subject_code']); })
            ->whereHas('meetings', function($q) use ($validated) {
                $q->whereHas('instructor', function($q2) use ($validated) {
                    $q2->where('name', $validated['instructor_name']);
                });
            })
            ->whereHas('section', function($q) use ($sectionCodePattern){ 
                $q->where('code', 'LIKE', $sectionCodePattern);
            })
            ->first();
            
        if (!$entry) {
            // Debug: Try to find what's missing
            $entryByGroupSubject = ScheduleEntry::where('group_id', $validated['group_id'])
                ->whereHas('subject', function($q) use ($validated){ $q->where('code', $validated['subject_code']); })
                ->with(['subject', 'section', 'meetings.instructor'])
                ->get();
                
            $entryByGroupSection = ScheduleEntry::where('group_id', $validated['group_id'])
                ->whereHas('section', function($q) use ($sectionCodePattern){ 
                    $q->where('code', 'LIKE', $sectionCodePattern);
                })
                ->with(['subject', 'section', 'meetings.instructor'])
                ->get();
                
            $entryByInstructor = ScheduleEntry::where('group_id', $validated['group_id'])
                ->whereHas('meetings', function($q) use ($validated) {
                    $q->whereHas('instructor', function($q2) use ($validated) {
                        $q2->where('name', $validated['instructor_name']);
                    });
                })
                ->get(['entry_id', 'group_id', 'subject_id', 'section_id']);
            
            Log::error('updateByLocator: Entry not found by locator', [
                'requested' => [
                    'group_id' => $validated['group_id'],
                    'subject_code' => $validated['subject_code'],
                    'instructor_name' => $validated['instructor_name'],
                    'section_code' => $validated['section_code']
                ],
                'found_by_group_subject' => $entryByGroupSubject->count(),
                'found_by_group_section' => $entryByGroupSection->count(),
                'found_by_instructor' => $entryByInstructor->count(),
                'sample_entries_by_group_subject' => $entryByGroupSubject->take(3)->toArray(),
                'sample_entries_by_group_section' => $entryByGroupSection->take(3)->toArray(),
                'sample_entries_by_instructor' => $entryByInstructor->take(3)->toArray()
            ]);
            
            return response()->json([
                'ok' => false, 
                'message' => 'Entry not found by locator',
                'debug' => [
                    'requested' => [
                        'group_id' => $validated['group_id'],
                        'subject_code' => $validated['subject_code'],
                        'instructor_name' => $validated['instructor_name'],
                        'section_code' => $validated['section_code']
                    ],
                    'found_by_group_subject' => $entryByGroupSubject->count(),
                    'found_by_group_section' => $entryByGroupSection->count(),
                    'found_by_instructor' => $entryByInstructor->count()
                ]
            ], 404);
        }
        
        Log::info('updateByLocator: Entry found', [
            'entry_id' => $entry->entry_id,
            'group_id' => $entry->group_id,
            'subject_id' => $entry->subject_id,
            'section_id' => $entry->section_id
        ]);

        // Map room name to id if provided
        $newRoomId = null;
        if (!empty($validated['new_room_name'])) {
            $newRoomId = \App\Models\Room::where('room_name', $validated['new_room_name'])->value('room_id');
        }

        // Find the meeting matching the original day/time
        Log::info('updateByLocator: Searching for meeting', [
            'entry_id' => $entry->entry_id,
            'orig_day' => $validated['orig_day'],
            'orig_start_time' => $validated['orig_start_time'],
            'orig_end_time' => $validated['orig_end_time']
        ]);
        
        // Get all meetings for this entry for debugging
        $allEntryMeetings = ScheduleMeeting::where('entry_id', $entry->entry_id)
            ->with('instructor')
            ->get(['meeting_id', 'entry_id', 'instructor_id', 'day', 'start_time', 'end_time', 'room_id']);
        
        Log::debug('updateByLocator: All meetings for entry', [
            'entry_id' => $entry->entry_id,
            'total_meetings' => $allEntryMeetings->count(),
            'meetings' => $allEntryMeetings->map(function($m) {
                return [
                    'meeting_id' => $m->meeting_id,
                    'day' => $m->day,
                    'start_time' => $m->start_time,
                    'end_time' => $m->end_time,
                    'instructor_id' => $m->instructor_id,
                    'instructor_name' => $m->instructor ? $m->instructor->name : 'N/A'
                ];
            })->toArray()
        ]);
        
        // Try exact match first
        $normalizedOrigDay = \App\Services\DayScheduler::normalizeDay($validated['orig_day']);
        Log::debug('updateByLocator: Trying exact match', [
            'normalized_day' => $normalizedOrigDay,
            'start_time' => $validated['orig_start_time'],
            'end_time' => $validated['orig_end_time']
        ]);
        
        $meeting = ScheduleMeeting::where('entry_id', $entry->entry_id)
            ->where('day', $normalizedOrigDay)
            ->where('start_time', $validated['orig_start_time'])
            ->where('end_time', $validated['orig_end_time'])
            ->first();
        
        if ($meeting) {
            Log::info('updateByLocator: Meeting found with exact match', [
                'meeting_id' => $meeting->meeting_id,
                'day' => $meeting->day,
                'start_time' => $meeting->start_time,
                'end_time' => $meeting->end_time
            ]);
        }
        
        // If not found with exact match, try matching by parsing combined days
        if (!$meeting) {
            Log::debug('updateByLocator: Exact match failed, trying combined days');
            $origDays = \App\Services\DayScheduler::parseCombinedDays($validated['orig_day']);
            if (count($origDays) > 1) {
                // Try matching any of the days in the combined day string
                $meeting = ScheduleMeeting::where('entry_id', $entry->entry_id)
                    ->where(function($q) use ($origDays) {
                        foreach ($origDays as $day) {
                            $q->orWhere('day', 'LIKE', '%' . \App\Services\DayScheduler::normalizeDay($day) . '%');
                        }
                    })
                    ->where('start_time', $validated['orig_start_time'])
                    ->where('end_time', $validated['orig_end_time'])
                    ->first();
                
                if ($meeting) {
                    Log::info('updateByLocator: Meeting found with combined days match', [
                        'meeting_id' => $meeting->meeting_id,
                        'day' => $meeting->day,
                        'start_time' => $meeting->start_time,
                        'end_time' => $meeting->end_time,
                        'parsed_days' => $origDays
                    ]);
                }
            }
        }
        
        // If still not found, try matching just by entry_id and time (might be day format mismatch)
        if (!$meeting) {
            Log::debug('updateByLocator: Combined days match failed, trying time-only match');
            $meeting = ScheduleMeeting::where('entry_id', $entry->entry_id)
                ->where('start_time', $validated['orig_start_time'])
                ->where('end_time', $validated['orig_end_time'])
                ->first();
                
            if ($meeting) {
                Log::info('updateByLocator: Meeting found with time-only match', [
                    'meeting_id' => $meeting->meeting_id,
                    'day' => $meeting->day,
                    'start_time' => $meeting->start_time,
                    'end_time' => $meeting->end_time,
                    'requested_day' => $validated['orig_day'],
                    'normalized_day' => $normalizedOrigDay
                ]);
            }
        }
        
        if (!$meeting) {
            Log::warning('updateByLocator: Meeting not found with any method', [
                'entry_id' => $entry->entry_id,
                'orig_day' => $validated['orig_day'],
                'normalized_day' => $normalizedOrigDay,
                'orig_start_time' => $validated['orig_start_time'],
                'orig_end_time' => $validated['orig_end_time'],
                'available_meetings' => $allEntryMeetings->map(function($m) {
                    return [
                        'meeting_id' => $m->meeting_id,
                        'day' => $m->day,
                        'start_time' => $m->start_time,
                        'end_time' => $m->end_time
                    ];
                })->toArray()
            ]);
            
            // If still not found, try to get any meeting from this entry (fallback - use first meeting)
            $firstMeeting = ScheduleMeeting::where('entry_id', $entry->entry_id)->first();
            if ($firstMeeting) {
                Log::info('updateByLocator: Using first meeting as fallback', [
                    'entry_id' => $entry->entry_id,
                    'meeting_id' => $firstMeeting->meeting_id,
                    'day' => $firstMeeting->day,
                    'start_time' => $firstMeeting->start_time,
                    'end_time' => $firstMeeting->end_time,
                    'requested_orig_day' => $validated['orig_day'],
                    'requested_orig_start_time' => $validated['orig_start_time'],
                    'requested_orig_end_time' => $validated['orig_end_time']
                ]);
                $meeting = $firstMeeting;
                // Note: We're using the first meeting, but we should still validate the new time
                // The original time match is just for locating the meeting - the new time will be validated
            } else {
                Log::error('updateByLocator: No meetings found for entry', [
                    'entry_id' => $entry->entry_id,
                    'group_id' => $validated['group_id'],
                    'subject_code' => $validated['subject_code'],
                    'section_code' => $validated['section_code']
                ]);
                return response()->json([
                    'ok' => false, 
                    'message' => 'Meeting not found for original slot',
                    'debug' => [
                        'entry_id' => $entry->entry_id,
                        'requested' => [
                            'orig_day' => $validated['orig_day'],
                            'orig_start_time' => $validated['orig_start_time'],
                            'orig_end_time' => $validated['orig_end_time']
                        ],
                        'available_meetings_count' => $allEntryMeetings->count()
                    ]
                ], 404);
            }
        }

        // Final guard using validator
        $request2 = new Request([
            'group_id' => $validated['group_id'],
            'day' => $validated['new_day'],
            'start_time' => $validated['new_start_time'],
            'end_time' => $validated['new_end_time'],
            'instructor_id' => $meeting->instructor_id,
            'room_id' => $newRoomId ?? $meeting->room_id,
            'meeting_id' => $meeting->meeting_id,
        ]);
        $validationResponse = $this->validateEdit($request2)->getData(true);
        if (!($validationResponse['ok'] ?? false)) {
            return response()->json(['ok' => false, 'message' => 'Conflict detected', 'details' => $validationResponse], 409);
        }

        // IMPORTANT: Joint sessions are stored as SEPARATE meeting records in the database
        // e.g., entry_id 1 might have meeting_id 1 (day='Sat') and meeting_id 2 (day='Fri')
        // both with the same start_time and end_time (13:00:00 - 14:30:00)
        // When updating time, we need to update ALL meetings that share the same original time
        
        // Check if the original meeting is part of a joint session
        // A joint session is when multiple meetings share the same entry_id and same time
        $meetingsWithSameTime = ScheduleMeeting::where('entry_id', $entry->entry_id)
            ->where('start_time', $validated['orig_start_time'])
            ->where('end_time', $validated['orig_end_time'])
            ->get();
        
        $isJointSession = $meetingsWithSameTime->count() > 1;
        
        if ($isJointSession) {
            // For joint sessions, update ALL meetings that share the same original time
            // These are the separate meeting records (e.g., one for Sat, one for Fri)
            $allMeetingsToUpdate = $meetingsWithSameTime;
            
            // Check if day is being changed
            $normalizedOrigDay = \App\Services\DayScheduler::normalizeDay($validated['orig_day']);
            $normalizedNewDay = \App\Services\DayScheduler::normalizeDay($validated['new_day']);
            $isDayChanged = $normalizedOrigDay !== $normalizedNewDay;
            
            // Parse new days if day is being changed
            $newDays = [];
            if ($isDayChanged) {
                $newDays = \App\Services\DayScheduler::parseCombinedDays($validated['new_day']);
            }
            
            Log::info('updateByLocator: Updating joint session (separate meeting records)', [
                'entry_id' => $entry->entry_id,
                'meetings_to_update_count' => $allMeetingsToUpdate->count(),
                'original_meetings' => $allMeetingsToUpdate->map(function($m) {
                    return [
                        'meeting_id' => $m->meeting_id,
                        'day' => $m->day,
                        'start_time' => $m->start_time,
                        'end_time' => $m->end_time
                    ];
                })->toArray(),
                'is_day_changed' => $isDayChanged,
                'new_days' => $newDays,
                'new_start_time' => $validated['new_start_time'],
                'new_end_time' => $validated['new_end_time']
            ]);
            
            // Update all meetings that share the same time
            $savedCount = 0;
            foreach ($allMeetingsToUpdate as $index => $m) {
                // Store old values for logging
                $oldStartTime = $m->start_time;
                $oldEndTime = $m->end_time;
                $oldDay = $m->day;
                $oldRoomId = $m->room_id;
                
                // Use update() method with array to ensure proper saving
                $updateData = [
                    'start_time' => $validated['new_start_time'],
                    'end_time' => $validated['new_end_time']
                ];
                
                // If day is being changed and we have matching new days, update the day
                // Assign new days to meetings in order (e.g., first meeting gets first day, second gets second)
                if ($isDayChanged && !empty($newDays) && isset($newDays[$index])) {
                    $updateData['day'] = \App\Services\DayScheduler::normalizeDay($newDays[$index]);
                }
                
                if ($newRoomId) {
                    $updateData['room_id'] = $newRoomId;
                }
                
                // Use update() instead of direct assignment + save()
                $saved = $m->update($updateData);
                $m->refresh(); // Reload to verify changes
                
                $timeMatches = $m->start_time === $validated['new_start_time'] && $m->end_time === $validated['new_end_time'];
                $dayMatches = !$isDayChanged || (isset($updateData['day']) && $m->day === $updateData['day']);
                
                if ($saved && $timeMatches && $dayMatches) {
                    $savedCount++;
                } else {
                    Log::error('updateByLocator: Failed to save joint session meeting', [
                        'meeting_id' => $m->meeting_id,
                        'saved' => $saved,
                        'expected_start' => $validated['new_start_time'],
                        'actual_start' => $m->start_time,
                        'expected_end' => $validated['new_end_time'],
                        'actual_end' => $m->end_time,
                        'expected_day' => $updateData['day'] ?? $oldDay,
                        'actual_day' => $m->day,
                        'time_matches' => $timeMatches,
                        'day_matches' => $dayMatches
                    ]);
                }
            }
            
            Log::info('updateByLocator: Joint session update complete', [
                'total_meetings' => $allMeetingsToUpdate->count(),
                'saved_count' => $savedCount,
                'is_day_changed' => $isDayChanged,
                'new_start_time' => $validated['new_start_time'],
                'new_end_time' => $validated['new_end_time']
            ]);
            
            // Note: For joint sessions:
            // - Time updates: All meetings get the same new time, each keeps its individual day
            // - Day updates: Each meeting gets assigned a day from the parsed new_day string
            // - The frontend displays them as "MonSat" by combining the individual days
            
            return response()->json([
                'ok' => true, 
                'meeting' => $allMeetingsToUpdate->first(),
                'updated_count' => $allMeetingsToUpdate->count(),
                'is_joint_session' => true,
                'debug' => [
                    'total_meetings' => $allMeetingsToUpdate->count(),
                    'saved_count' => $savedCount,
                    'is_day_changed' => $isDayChanged
                ]
            ]);
        } else {
            // Single day session - update just this meeting
            // Only update day if it's actually different (to support time-only edits)
            $normalizedNewDay = \App\Services\DayScheduler::normalizeDay($validated['new_day']);
            $currentDay = $meeting->day;
            
            // Store old values for logging
            $oldStartTime = $meeting->start_time;
            $oldEndTime = $meeting->end_time;
            $oldDay = $meeting->day;
            $oldRoomId = $meeting->room_id;
            
            // Use update() method with array to ensure proper saving
            $updateData = [
                'start_time' => $validated['new_start_time'],
                'end_time' => $validated['new_end_time']
            ];
            
            // Only update day if it changed (not a time-only edit)
            if ($normalizedNewDay !== $currentDay) {
                $updateData['day'] = $normalizedNewDay;
            }
            
            if ($newRoomId) { 
                $updateData['room_id'] = $newRoomId; 
            }
            
            // Use update() method instead of direct assignment + save()
            // This ensures mass assignment works correctly
            $updated = $meeting->update($updateData);
            
            // Reload to verify changes were persisted
            $meeting->refresh();
            
            // Check if update was successful
            $saved = $updated;
            
            Log::info('updateByLocator: Meeting saved', [
                'meeting_id' => $meeting->meeting_id,
                'entry_id' => $entry->entry_id,
                'saved' => $saved,
                'old_values' => [
                    'day' => $oldDay,
                    'start_time' => $oldStartTime,
                    'end_time' => $oldEndTime,
                    'room_id' => $oldRoomId
                ],
                'new_values' => [
                    'day' => $meeting->day,
                    'start_time' => $meeting->start_time,
                    'end_time' => $meeting->end_time,
                    'room_id' => $meeting->room_id
                ],
                'requested_new_day' => $validated['new_day'],
                'normalized_new_day' => $normalizedNewDay,
                'day_was_changed' => $normalizedNewDay !== $currentDay
            ]);
            
            // Verify the save actually worked
            if (!$saved || $meeting->start_time !== $validated['new_start_time'] || $meeting->end_time !== $validated['new_end_time']) {
                Log::error('updateByLocator: Meeting save failed or values not persisted', [
                    'meeting_id' => $meeting->meeting_id,
                    'saved' => $saved,
                    'expected_start_time' => $validated['new_start_time'],
                    'actual_start_time' => $meeting->start_time,
                    'expected_end_time' => $validated['new_end_time'],
                    'actual_end_time' => $meeting->end_time
                ]);
                return response()->json([
                    'ok' => false, 
                    'message' => 'Failed to save meeting changes',
                    'debug' => [
                        'saved' => $saved,
                        'expected_start' => $validated['new_start_time'],
                        'actual_start' => $meeting->start_time,
                        'expected_end' => $validated['new_end_time'],
                        'actual_end' => $meeting->end_time
                    ]
                ], 500);
            }

            return response()->json([
                'ok' => true, 
                'meeting' => $meeting,
                'debug' => [
                    'old_start_time' => $oldStartTime,
                    'old_end_time' => $oldEndTime,
                    'new_start_time' => $meeting->start_time,
                    'new_end_time' => $meeting->end_time
                ]
            ]);
        }
    }

    /**
     * Suggest alternative slots when conflict is detected
     * Returns available day/time/room combinations that don't conflict
     */
    public function suggestAlternatives(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => 'required|integer|exists:schedule_groups,group_id',
            'instructor_id' => 'nullable|integer',
            'room_id' => 'nullable|integer',
            'section_id' => 'nullable|integer',
            'preferred_day' => 'nullable|string',
            'duration_minutes' => 'required|integer', // duration in minutes
            'meeting_id' => 'nullable|integer', // Exclude current meeting from suggestions
            'edit_type' => 'nullable|string|in:day,time,room', // What is being edited
            'original_day' => 'nullable|string', // Original day for day editing exclusion
        ]);

        $groupId = $validated['group_id'];
        $instructorId = $validated['instructor_id'] ?? null;
        $preferredRoomId = $validated['room_id'] ?? null;
        $sectionId = $validated['section_id'] ?? null;
        $preferredDay = $validated['preferred_day'] ?? null;
        $duration = (int)$validated['duration_minutes'];
        $meetingId = $validated['meeting_id'] ?? null; // Exclude current meeting from suggestions
        $editType = $validated['edit_type'] ?? null; // What is being edited
        $originalDayForExclusion = $validated['original_day'] ?? null; // Original day for day editing exclusion

        Log::info('SuggestAlternatives called', [
            'group_id' => $groupId,
            'instructor_id' => $instructorId,
            'room_id' => $preferredRoomId,
            'section_id' => $sectionId,
            'meeting_id' => $meetingId,
            'preferred_day' => $preferredDay,
            'duration_minutes' => $duration,
            'edit_type' => $editType,
            'original_day' => $originalDayForExclusion
        ]);

        // Get all existing meetings in this group
        // If section_id is provided, only query meetings from that section for more relevant suggestions
        $entriesQuery = ScheduleEntry::with(['meetings'])
            ->where('group_id', $groupId)
            ->whereHas('meetings');
        
        // Filter by section if section_id is provided (for section-specific suggestions)
        if ($sectionId) {
            $entriesQuery->where('section_id', $sectionId);
        }
        
        $entries = $entriesQuery->get();

        $allMeetings = collect();
        $currentEntryId = null;
        $originalMeetingDay = null;
        $originalMeetingStartTime = null;
        $originalMeetingEndTime = null;
        foreach ($entries as $e) {
            foreach ($e->meetings as $m) {
                $allMeetings->push($m);
                // Track the entry_id and original time of the current meeting being edited (to exclude all meetings from same entry and original time)
                if ($meetingId && (int)$m->meeting_id === (int)$meetingId) {
                    $currentEntryId = $e->entry_id;
                    $originalMeetingDay = $m->day;
                    $originalMeetingStartTime = $m->start_time;
                    $originalMeetingEndTime = $m->end_time;
                }
            }
        }
        
        // Also need to check conflicts against ALL meetings (not just same section) for room/instructor conflicts
        // But for section conflicts, we only need to check same section
        $allMeetingsForConflictCheck = collect();
        if (!$sectionId) {
            // If no section_id, use all meetings
            $allMeetingsForConflictCheck = $allMeetings;
        } else {
            // If section_id provided, get all meetings for conflict checking (room/instructor)
            // but section conflicts will be filtered by section_id in the conflict check logic
            $allEntriesForConflict = ScheduleEntry::with(['meetings'])
                ->where('group_id', $groupId)
                ->whereHas('meetings')
                ->get();
            foreach ($allEntriesForConflict as $e) {
                foreach ($e->meetings as $m) {
                    $allMeetingsForConflictCheck->push($m);
                }
            }
        }

        // Generate candidate slots
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $commonSlots = [
            ['start' => '07:00:00', 'end' => '08:30:00'],
            ['start' => '08:30:00', 'end' => '10:00:00'],
            ['start' => '10:00:00', 'end' => '11:30:00'],
            ['start' => '13:00:00', 'end' => '14:30:00'],
            ['start' => '14:30:00', 'end' => '16:00:00'],
            ['start' => '16:00:00', 'end' => '17:30:00'],
            ['start' => '17:30:00', 'end' => '19:00:00'],
            ['start' => '19:00:00', 'end' => '20:30:00'],
        ];

        // Calculate duration-based slots
        $timeSlots = [];
        foreach ($days as $day) {
            for ($hour = 7; $hour <= 20; $hour++) {
                $start = sprintf('%02d:00:00', $hour);
                $endMin = $hour * 60 + $duration;
                $endHour = floor($endMin / 60);
                $endMinRem = $endMin % 60;
                if ($endHour > 22) continue; // Don't go past 10 PM
                $end = sprintf('%02d:%02d:00', $endHour, $endMinRem);
                $timeSlots[] = ['day' => $day, 'start' => $start, 'end' => $end];
            }
            // Add 30-minute offset slots
            for ($hour = 7; $hour < 20; $hour++) {
                $start = sprintf('%02d:30:00', $hour);
                $endMin = ($hour + 0.5) * 60 + $duration;
                $endHour = floor($endMin / 60);
                $endMinRem = (int)($endMin % 60);
                if ($endHour > 22) continue;
                $end = sprintf('%02d:%02d:00', $endHour, $endMinRem);
                $timeSlots[] = ['day' => $day, 'start' => $start, 'end' => $end];
            }
        }

        // Get available rooms
        $rooms = \App\Models\Room::where('is_active', true)->get();

        $suggestions = [];
        $count = 0;
        $maxSuggestions = 10;

        // Handle combined days (e.g., "ThuSat" -> ["Thu", "Sat"])
        $preferredDaysArray = [];
        $isJointSession = false;
        if ($preferredDay) {
            $preferredDaysArray = \App\Services\DayScheduler::parseCombinedDays($preferredDay);
            // Normalize day names (e.g., "Thursday" -> "Thu")
            $dayMap = ['Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed', 
                      'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat'];
            $preferredDaysArray = array_map(function($d) use ($dayMap) {
                return $dayMap[$d] ?? $d;
            }, $preferredDaysArray);
            // If no valid days found, try using the raw preferredDay
            if (empty($preferredDaysArray)) {
                $preferredDaysArray = [$preferredDay];
            }
            // Check if this is a joint session (multiple days)
            $isJointSession = count($preferredDaysArray) > 1;
        }
        
        // For joint sessions, we need more suggestions to find matching time slots
        if ($isJointSession) {
            $maxSuggestions = 30; // Increase to find more matches
        }
        
        // When editing TIME for a joint session, we must ensure the time works for ALL days in the joint session
        // Parse the preferred_day to check if it's a joint session (for time editing)
        $isJointSessionForTime = false;
        $jointDaysForTime = [];
        if ($preferredDay && $instructorId && $preferredRoomId) {
            // This is likely time editing - check if preferredDay is a joint session
            $parsedPreferredDays = \App\Services\DayScheduler::parseCombinedDays($preferredDay);
            if (count($parsedPreferredDays) > 1) {
                $isJointSessionForTime = true;
                $jointDaysForTime = $parsedPreferredDays;
            }
        }
        
        // Prioritize preferred day(s) if provided
        $daysToTry = !empty($preferredDaysArray) 
            ? array_unique(array_merge($preferredDaysArray, array_diff($days, $preferredDaysArray))) 
            : $days;

        // For day editing, filter time slots to only include those matching the original time
        // For time/room editing, use all time slots
        if ($editType === 'day' && $originalMeetingStartTime && $originalMeetingEndTime) {
            // Filter timeSlots to only include slots matching the original time
            $timeSlots = array_filter($timeSlots, function($slot) use ($originalMeetingStartTime, $originalMeetingEndTime) {
                return $slot['start'] === $originalMeetingStartTime && $slot['end'] === $originalMeetingEndTime;
            });
        }
        
        // For joint sessions, first collect all valid suggestions, then group by time slot
        $allSuggestions = [];
            
        foreach ($daysToTry as $day) {
            foreach ($timeSlots as $slot) {
                if ($slot['day'] !== $day) continue;

                // EXCLUDE ORIGINAL MEETING: Skip suggestions that match the original
                // For day editing: exclude if day matches (we've already filtered to original time)
                // For time editing: exclude if both day AND time match
                // For room editing: exclude if day AND time match (room is what's being changed)
                if ($editType === 'day' && $originalDayForExclusion) {
                    // For day editing: exclude any suggestion that matches the original DAY
                    $slotDays = \App\Services\DayScheduler::parseCombinedDays($slot['day']) ?: [$slot['day']];
                    $originalDays = \App\Services\DayScheduler::parseCombinedDays($originalDayForExclusion) ?: [$originalDayForExclusion];
                    
                    // Check if any day in the slot matches any day in the original meeting
                    $dayMatches = !empty(array_intersect($slotDays, $originalDays));
                    
                    // Skip if day matches (regardless of time) - we're editing the day, so exclude original day
                    if ($dayMatches) {
                        continue;
                    }
                } elseif ($originalMeetingDay && $originalMeetingStartTime && $originalMeetingEndTime) {
                    // For time/room editing: exclude if both day AND time match
                    $slotDays = \App\Services\DayScheduler::parseCombinedDays($slot['day']) ?: [$slot['day']];
                    $originalDays = \App\Services\DayScheduler::parseCombinedDays($originalMeetingDay) ?: [$originalMeetingDay];
                    
                    // Check if any day in the slot matches any day in the original meeting
                    $dayMatches = !empty(array_intersect($slotDays, $originalDays));
                    
                    // Check if time matches
                    $timeMatches = ($slot['start'] === $originalMeetingStartTime && $slot['end'] === $originalMeetingEndTime);
                    
                    // If both day and time match, skip this suggestion (it's the original time)
                    if ($dayMatches && $timeMatches) {
                        continue;
                    }
                }

                // RESPECT CLASS START TIME: Skip slots that start before 7:00 AM (07:00:00)
                // Invalid ranges: 12:00 AM (00:00:00) to 6:59 AM (06:59:59)
                if ($slot['start'] < '07:00:00') {
                    continue;
                }

                // RESPECT LUNCH TIME: Skip slots that overlap with lunch break (12:00 PM - 12:59 PM)
                if (\App\Services\TimeScheduler::isLunchBreakViolation($slot['start'], $slot['end'])) {
                    continue;
                }
                
                // RESPECT CLASS CUTOFF: Skip slots that end at or after 8:45 PM (20:45:00)
                // Invalid ranges: 8:45 PM (20:45:00) to 11:59 PM (23:59:59)
                if ($slot['end'] >= '20:45:00') {
                    continue;
                }

                // Check if this slot conflicts with any existing meeting
                // IMPORTANT: When suggesting for time editing, we must validate with the CURRENT room/instructor/section
                // to ensure the suggestion will pass validation when manually entered
                $conflicts = false;
                $testMeeting = (object)[
                    'day' => $slot['day'],
                    'start_time' => $slot['start'],
                    'end_time' => $slot['end'],
                    // Use the CURRENT room_id if provided (for time editing)
                    'room_id' => $preferredRoomId,
                ];

                // Use allMeetingsForConflictCheck for conflict checking (includes all meetings for room/instructor conflicts)
                foreach ($allMeetingsForConflictCheck as $existing) {
                    // Skip the current meeting being edited (same as validation)
                    if ($meetingId && (int)$existing->meeting_id === (int)$meetingId) {
                        continue;
                    }
                    // Also skip all meetings from the same entry (if we're editing a meeting)
                    if ($currentEntryId && $existing->entry_id && (int)$existing->entry_id === (int)$currentEntryId) {
                        continue;
                    }
                    
                    if ($this->meetingsOverlapSimple($testMeeting, $existing)) {
                        // Check specific conflicts - use the CURRENT context
                        if ($instructorId && $existing->instructor_id && (int)$existing->instructor_id === (int)$instructorId) {
                            $conflicts = true;
                            break;
                        }
                        if ($preferredRoomId && $existing->room_id && (int)$existing->room_id === (int)$preferredRoomId) {
                            $conflicts = true;
                            break;
                        }
                        if ($sectionId) {
                            $existingEntry = ScheduleEntry::whereHas('meetings', function($q) use ($existing) {
                                $q->where('meeting_id', $existing->meeting_id);
                            })->where('section_id', $sectionId)->first();
                            if ($existingEntry) {
                                $conflicts = true;
                                break;
                            }
                        }
                    }
                }

                // Validate suggestion using the same rules as validateEdit (time-based conflicts)
                $hasTimeConflict = false;
                // Check class start time
                if ($slot['start'] < '07:00:00') {
                    $hasTimeConflict = true;
                }
                // Check lunch break
                if (\App\Services\TimeScheduler::isLunchBreakViolation($slot['start'], $slot['end'])) {
                    $hasTimeConflict = true;
                }
                // Check class cutoff
                if ($slot['end'] >= '20:45:00') {
                    $hasTimeConflict = true;
                }
                
                if ($hasTimeConflict) {
                    continue; // Skip this suggestion
                }
                
                if (!$conflicts && !$hasTimeConflict) {
                    // If this is time editing for a joint session, we must verify the time works on ALL joint days
                    if ($isJointSessionForTime && !in_array($slot['day'], $jointDaysForTime)) {
                        // This slot is not one of the joint days, skip it
                        continue;
                    }
                    
                    // If time editing for joint session, verify this time slot works on ALL joint days
                    if ($isJointSessionForTime && in_array($slot['day'], $jointDaysForTime)) {
                        // Check if this time slot works on ALL joint days, not just this one
                        $validForAllJointDays = true;
                        foreach ($jointDaysForTime as $jointDay) {
                            $jointTestMeeting = (object)[
                                'day' => $jointDay,
                                'start_time' => $slot['start'],
                                'end_time' => $slot['end'],
                                'room_id' => $preferredRoomId,
                            ];
                            
                            // Use allMeetingsForConflictCheck for conflict checking
                            foreach ($allMeetingsForConflictCheck as $existing) {
                                if ($meetingId && (int)$existing->meeting_id === (int)$meetingId) {
                                    continue;
                                }
                                // Also skip all meetings from the same entry
                                if ($currentEntryId && $existing->entry_id && (int)$existing->entry_id === (int)$currentEntryId) {
                                    continue;
                                }
                                
                                if ($this->meetingsOverlapSimple($jointTestMeeting, $existing)) {
                                    // Check specific conflicts
                                    if ($instructorId && $existing->instructor_id && (int)$existing->instructor_id === (int)$instructorId) {
                                        $validForAllJointDays = false;
                                        break 2;
                                    }
                                    if ($preferredRoomId && $existing->room_id && (int)$existing->room_id === (int)$preferredRoomId) {
                                        $validForAllJointDays = false;
                                        break 2;
                                    }
                                    if ($sectionId) {
                                        $existingEntry = ScheduleEntry::whereHas('meetings', function($q) use ($existing) {
                                            $q->where('meeting_id', $existing->meeting_id);
                                        })->where('section_id', $sectionId)->first();
                                        if ($existingEntry) {
                                            $validForAllJointDays = false;
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                        
                        if (!$validForAllJointDays) {
                            // This time slot doesn't work for all joint days, skip it
                            continue;
                        }
                        
                        // If we get here, the time slot works for all joint days
                        // Use the combined day format (e.g., "MonSat") for the suggestion
                        $preferredRoom = $rooms->firstWhere('room_id', $preferredRoomId);
                        $combinedDay = \App\Services\DayScheduler::combineDays($jointDaysForTime);
                        $allSuggestions[] = [
                            'day' => $combinedDay, // Use combined day format for joint sessions
                            'start_time' => $slot['start'],
                            'end_time' => $slot['end'],
                            'room_id' => $preferredRoomId,
                            'room_name' => $preferredRoom ? $preferredRoom->room_name : 'Unknown',
                            'is_joint' => true,
                        ];
                        continue; // Skip the normal room assignment logic for joint sessions
                    }
                    
                    // Try preferred room first (if provided), then try all other available rooms
                    // This ensures we suggest slots from ALL available rooms, not just the current one
                    $roomsToTry = [];
                    if ($preferredRoomId) {
                        // Prioritize preferred room first
                        $preferredRoom = $rooms->firstWhere('room_id', $preferredRoomId);
                        if ($preferredRoom) {
                            $roomsToTry[] = $preferredRoom;
                        }
                        // Then add all other rooms
                    foreach ($rooms as $room) {
                            if ((int)$room->room_id !== (int)$preferredRoomId) {
                                $roomsToTry[] = $room;
                            }
                        }
                    } else {
                        // No preferred room - try all rooms
                        $roomsToTry = $rooms->all();
                    }
                    
                    // Try each room until we find one that works
                    foreach ($roomsToTry as $room) {
                        $roomConflicts = false;
                        $roomTestMeeting = (object)[
                            'day' => $slot['day'],
                            'start_time' => $slot['start'],
                            'end_time' => $slot['end'],
                            'room_id' => $room->room_id,
                        ];
                        
                        // Use allMeetingsForConflictCheck for conflict checking
                        foreach ($allMeetingsForConflictCheck as $existing) {
                            // Skip the current meeting being edited
                            if ($meetingId && (int)$existing->meeting_id === (int)$meetingId) {
                                continue;
                            }
                            // Also skip all meetings from the same entry
                            if ($currentEntryId && $existing->entry_id && (int)$existing->entry_id === (int)$currentEntryId) {
                                continue;
                            }
                            
                            if ($this->meetingsOverlapSimple($roomTestMeeting, $existing)) {
                                // Check if this is a room conflict (same room, overlapping time)
                                if ((int)$existing->room_id === (int)$room->room_id) {
                                $roomConflicts = true;
                                break;
                            }
                                // Check if this is an instructor conflict (same instructor, overlapping time)
                                if ($instructorId && $existing->instructor_id && (int)$existing->instructor_id === (int)$instructorId) {
                                    $roomConflicts = true;
                                    break;
                                }
                                // Check if this is a section conflict
                                if ($sectionId) {
                                    $existingEntry = ScheduleEntry::whereHas('meetings', function($q) use ($existing) {
                                        $q->where('meeting_id', $existing->meeting_id);
                                    })->where('section_id', $sectionId)->first();
                                    if ($existingEntry) {
                                        $roomConflicts = true;
                                        break;
                                    }
                                }
                            }
                        }
                        
                        if (!$roomConflicts) {
                            $allSuggestions[] = [
                                'day' => $slot['day'],
                                'start_time' => $slot['start'],
                                'end_time' => $slot['end'],
                                'room_id' => $room->room_id,
                                'room_name' => $room->room_name,
                            ];
                            break; // Found one room for this slot, move on
                        }
                    }
                }
            }
        }

        // For joint sessions, prioritize suggestions where same time slot is available on multiple days
        if ($isJointSession && count($allSuggestions) > 0) {
            // Group suggestions by time slot and room
            $groupedByTime = [];
            foreach ($allSuggestions as $suggestion) {
                $timeKey = $suggestion['start_time'] . '-' . $suggestion['end_time'] . '-' . $suggestion['room_id'];
                if (!isset($groupedByTime[$timeKey])) {
                    $groupedByTime[$timeKey] = [
                        'days' => [],
                        'start_time' => $suggestion['start_time'],
                        'end_time' => $suggestion['end_time'],
                        'room_id' => $suggestion['room_id'],
                        'room_name' => $suggestion['room_name'],
                    ];
                }
                $groupedByTime[$timeKey]['days'][] = $suggestion['day'];
            }
            
            // First, add joint session suggestions (same time slot on 2+ days)
            foreach ($groupedByTime as $timeKey => $slot) {
                $uniqueDays = array_unique($slot['days']);
                if (count($uniqueDays) >= 2) {
                    // Sort days
                    $dayOrder = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
                    usort($uniqueDays, function($a, $b) use ($dayOrder) {
                        return ($dayOrder[$a] ?? 999) - ($dayOrder[$b] ?? 999);
                    });
                    
                    // Take first 2 days for joint session
                    $combinedDays = \App\Services\DayScheduler::combineDays(array_slice($uniqueDays, 0, 2));
                    
                    $suggestions[] = [
                        'day' => $combinedDays,
                        'start_time' => $slot['start_time'],
                        'end_time' => $slot['end_time'],
                        'room_id' => $slot['room_id'],
                        'room_name' => $slot['room_name'],
                        'is_joint' => true,
                    ];
                    $count++;
                    
                    if ($count >= $maxSuggestions) break;
                }
            }
            
            // Then add single-day suggestions if we haven't reached max
            if ($count < $maxSuggestions) {
                foreach ($allSuggestions as $suggestion) {
                    if ($count >= $maxSuggestions) break;
                    // Skip if we already included this in a joint suggestion
                    $alreadyIncluded = false;
                    foreach ($suggestions as $existing) {
                        if (isset($existing['is_joint']) && $existing['is_joint'] &&
                            $existing['start_time'] === $suggestion['start_time'] &&
                            $existing['end_time'] === $suggestion['end_time'] &&
                            $existing['room_id'] === $suggestion['room_id']) {
                            $existingDays = \App\Services\DayScheduler::parseCombinedDays($existing['day']);
                            if (in_array($suggestion['day'], $existingDays)) {
                                $alreadyIncluded = true;
                                break;
                            }
                        }
                    }
                    if (!$alreadyIncluded) {
                        $suggestions[] = $suggestion;
                        $count++;
                    }
                }
            }
        } else {
            // For single-day sessions, deduplicate suggestions by time slot and day
            // For day editing: deduplicate by day only (same day, different times = duplicates)
            // For time/room editing: deduplicate by day+time+room
            $seen = [];
            foreach ($allSuggestions as $suggestion) {
                if ($count >= $maxSuggestions) break;
                
                // Create a unique key for deduplication
                if ($editType === 'day') {
                    // For day editing: deduplicate by day only (we're suggesting different days)
                    $key = $suggestion['day'];
                } else {
                    // For time/room editing: deduplicate by day+time+room
                    $key = $suggestion['day'] . '|' . $suggestion['start_time'] . '|' . $suggestion['end_time'] . '|' . ($suggestion['room_id'] ?? '');
                }
                
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $suggestions[] = $suggestion;
                    $count++;
                }
            }
        }

        // Final deduplication pass to ensure no duplicates
        $finalSuggestions = [];
        $finalSeen = [];
        foreach ($suggestions as $suggestion) {
            // Use same deduplication logic as above based on edit type
            if ($editType === 'day') {
                // For day editing: deduplicate by day only
                $key = $suggestion['day'] ?? '';
            } else {
                // For time/room editing: deduplicate by day+time+room
                $key = ($suggestion['day'] ?? '') . '|' . ($suggestion['start_time'] ?? '') . '|' . ($suggestion['end_time'] ?? '') . '|' . ($suggestion['room_id'] ?? '');
            }
            
            if (!isset($finalSeen[$key])) {
                $finalSeen[$key] = true;
                $finalSuggestions[] = $suggestion;
            }
        }
        
        // Final validation: Re-validate each suggestion using validateEdit logic to ensure no conflicts
        // Also exclude any suggestions that match the original meeting
        $validatedSuggestions = [];
        foreach ($finalSuggestions as $suggestion) {
            // Skip if this suggestion matches the original
            // For day editing: exclude if day matches (regardless of time)
            // For time/room editing: exclude if both day AND time match
            if ($editType === 'day' && $originalDayForExclusion) {
                // For day editing: exclude if day matches
                $suggestionDays = \App\Services\DayScheduler::parseCombinedDays($suggestion['day']) ?: [$suggestion['day']];
                $originalDays = \App\Services\DayScheduler::parseCombinedDays($originalDayForExclusion) ?: [$originalDayForExclusion];
                
                $dayMatches = !empty(array_intersect($suggestionDays, $originalDays));
                
                if ($dayMatches) {
                    Log::debug('Skipping suggestion - matches original day (day editing)', [
                        'suggestion' => $suggestion,
                        'original_day' => $originalDayForExclusion
                    ]);
                    continue; // Skip this suggestion - it's the original day
                }
            } elseif ($originalMeetingDay && $originalMeetingStartTime && $originalMeetingEndTime) {
                // For time/room editing: exclude if both day AND time match
                $suggestionDays = \App\Services\DayScheduler::parseCombinedDays($suggestion['day']) ?: [$suggestion['day']];
                $originalDays = \App\Services\DayScheduler::parseCombinedDays($originalMeetingDay) ?: [$originalMeetingDay];
                
                $dayMatches = !empty(array_intersect($suggestionDays, $originalDays));
                $timeMatches = ($suggestion['start_time'] === $originalMeetingStartTime && $suggestion['end_time'] === $originalMeetingEndTime);
                
                if ($dayMatches && $timeMatches) {
                    Log::debug('Skipping suggestion - matches original meeting time', [
                        'suggestion' => $suggestion,
                        'original' => [
                            'day' => $originalMeetingDay,
                            'start_time' => $originalMeetingStartTime,
                            'end_time' => $originalMeetingEndTime
                        ]
                    ]);
                    continue; // Skip this suggestion - it's the original time
                }
            }
            
            // Create a validation request
            $validationRequest = new Request([
                'group_id' => $groupId,
                'day' => $suggestion['day'],
                'start_time' => $suggestion['start_time'],
                'end_time' => $suggestion['end_time'],
                'instructor_id' => $instructorId,
                'room_id' => $suggestion['room_id'] ?? $preferredRoomId,
                'meeting_id' => $meetingId,
                'entry_id' => $currentEntryId,
                'section_id' => $sectionId,
            ]);
            
            // Validate using the same logic as validateEdit
            try {
                $validationResponse = $this->validateEdit($validationRequest);
                $validationData = $validationResponse->getData(true);
                
                // Only include suggestions that pass validation (ok = true)
                if ($validationData['ok'] ?? false) {
                    $validatedSuggestions[] = $suggestion;
                    if (count($validatedSuggestions) >= $maxSuggestions) {
                        break;
                    }
                } else {
                    Log::debug('Suggestion failed validation', [
                        'suggestion' => $suggestion,
                        'conflicts' => $validationData['conflicts'] ?? []
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Error validating suggestion', [
                    'suggestion' => $suggestion,
                    'error' => $e->getMessage()
                ]);
                // Skip this suggestion if validation fails
                continue;
            }
        }
        
        $finalSuggestions = $validatedSuggestions;
        
        Log::info('SuggestAlternatives result', [
            'group_id' => $groupId,
            'suggestions_count' => count($finalSuggestions),
            'all_meetings_count' => $allMeetings->count(),
            'rooms_count' => $rooms->count(),
            'is_joint_session' => $isJointSession,
            'is_joint_session_for_time' => $isJointSessionForTime ?? false,
            'preferred_day' => $preferredDay,
            'meeting_id' => $meetingId,
            'edit_type' => $editType,
            'original_day' => $originalDayForExclusion
        ]);
        
        // Log first few suggestions for debugging
        if (count($finalSuggestions) > 0) {
            Log::info('SuggestAlternatives first suggestion', [
                'day' => $finalSuggestions[0]['day'] ?? null,
                'start_time' => $finalSuggestions[0]['start_time'] ?? null,
                'end_time' => $finalSuggestions[0]['end_time'] ?? null,
                'room_id' => $finalSuggestions[0]['room_id'] ?? null,
                'is_joint' => $finalSuggestions[0]['is_joint'] ?? false
            ]);
        }
        
        return response()->json([
            'suggestions' => $finalSuggestions,
            'count' => count($finalSuggestions)
        ]);
    }

    /**
     * Helper: Check if two meetings overlap (same logic as ConflictChecker)
     */
    private function meetingsOverlapSimple($meeting1, $meeting2): bool
    {
        $days1 = \App\Services\DayScheduler::parseCombinedDays(is_object($meeting1) ? ($meeting1->day ?? '') : ($meeting1['day'] ?? ''));
        $days2 = \App\Services\DayScheduler::parseCombinedDays(is_object($meeting2) ? ($meeting2->day ?? '') : ($meeting2['day'] ?? ''));
        $commonDays = array_intersect($days1, $days2);
        if (empty($commonDays)) return false;
        $start1 = $this->timeToMinutesSimple(is_object($meeting1) ? ($meeting1->start_time ?? '') : ($meeting1['start_time'] ?? ''));
        $end1   = $this->timeToMinutesSimple(is_object($meeting1) ? ($meeting1->end_time ?? '') : ($meeting1['end_time'] ?? ''));
        $start2 = $this->timeToMinutesSimple(is_object($meeting2) ? ($meeting2->start_time ?? '') : ($meeting2['start_time'] ?? ''));
        $end2   = $this->timeToMinutesSimple(is_object($meeting2) ? ($meeting2->end_time ?? '') : ($meeting2['end_time'] ?? ''));
        
        // Handle corrupted data where start_time >= end_time by swapping
        if ($start1 >= $end1) {
            Log::warning('CORRUPTED TIME DATA: start_time >= end_time', [
                'meeting_id' => is_object($meeting1) ? optional($meeting1)->meeting_id : null,
                'start' => is_object($meeting1) ? $meeting1->start_time : $meeting1['start_time'],
                'end' => is_object($meeting1) ? $meeting1->end_time : $meeting1['end_time']
            ]);
            $temp = $start1;
            $start1 = $end1;
            $end1 = $temp;
        }
        if ($start2 >= $end2) {
            Log::warning('CORRUPTED TIME DATA: start_time >= end_time', [
                'meeting_id' => is_object($meeting2) ? optional($meeting2)->meeting_id : null,
                'start' => is_object($meeting2) ? $meeting2->start_time : $meeting2['start_time'],
                'end' => is_object($meeting2) ? $meeting2->end_time : $meeting2['end_time']
            ]);
            $temp = $start2;
            $start2 = $end2;
            $end2 = $temp;
        }
        
        return ($start1 < $end2) && ($start2 < $end1);
    }

    /**
     * Helper: Convert time string to minutes
     */
    private function timeToMinutesSimple($timeString): int
    {
        $time = trim((string)$timeString);
        if (strpos($time, '–') !== false) { $time = trim(explode('–', $time)[0]); }
        if (stripos($time, 'AM') !== false || stripos($time, 'PM') !== false) {
            $time = date('H:i', strtotime($time));
        }
        $parts = explode(':', $time);
        $hours = (int)($parts[0] ?? 0);
        $minutes = (int)($parts[1] ?? 0);
        return ($hours * 60) + $minutes;
    }
}


