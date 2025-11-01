<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Room;
use App\Models\ScheduleEntry;
use App\Models\ScheduleGroup;
use App\Models\Instructor;
use App\Models\Subject;
use App\Models\Section;
use App\Models\ScheduleMeeting;
use App\Services\PhpScheduler;
use App\Services\TimeScheduler;
use App\Services\RoomScheduler;
use App\Services\DayScheduler;
use App\Services\ConstraintScheduler;
use App\Services\ResourceTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use DateTime;
use Exception;

class AutomateScheduleController extends Controller
{
    // Simple per-request caches to avoid repeated DB lookups during bulk saves
    private array $cacheInstructorIds = [];
    private array $cacheSubjectIds = [];
    private array $cacheSectionIds = [];
    private function resolveInstructorId(string $name, string $employmentType = 'FULL-TIME'): int
    {
        $normalizedEmploymentType = $this->normalizeEmploymentType($employmentType);
        
        // Cache hit
        if (isset($this->cacheInstructorIds[$name])) {
            return $this->cacheInstructorIds[$name];
        }

        // First, try to find existing instructor by name
        $instructor = Instructor::where('name', $name)->first();
        
        if ($instructor) {
            // Update employment type if it's different (in case of data correction)
            if ($instructor->employment_type !== $normalizedEmploymentType) {
                $instructor->employment_type = $normalizedEmploymentType;
                $instructor->save();
                Log::info("Updated employment type for instructor {$name} from {$instructor->employment_type} to {$normalizedEmploymentType}");
            }
            $this->cacheInstructorIds[$name] = $instructor->instructor_id;
            return $this->cacheInstructorIds[$name];
        }
        
        // Create new instructor with employment type
        $instructor = Instructor::create([
            'name' => $name,
            'employment_type' => $normalizedEmploymentType,
            'is_active' => true
        ]);
        
        Log::debug("Created new instructor {$name} with employment type {$normalizedEmploymentType}");
        $this->cacheInstructorIds[$name] = $instructor->instructor_id;
        return $this->cacheInstructorIds[$name];
    }

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

    private function resolveSubjectId(string $code, string $description = null, int $units = null): int
    {
        if (isset($this->cacheSubjectIds[$code])) {
            return $this->cacheSubjectIds[$code];
        }
        $attributes = ['description' => $description ?? $code, 'units' => $units ?? 0];
        $id = Subject::updateOrCreate(['code' => $code], $attributes)->subject_id;
        $this->cacheSubjectIds[$code] = $id;
        return $id;
    }

    private function resolveSectionId(string $department, string $yearLevel, string $block): int
    {
        // Backwards-compat: if a caller still passes year+block, keep working
        $section = trim($yearLevel . ' ' . $block);
        return $this->resolveSectionIdBySection($department, $section);
    }

    private function resolveSectionIdBySection(string $department, string $section): int
    {
        $normalizedSection = trim(preg_replace('/\s+/', ' ', $section));
        $code = trim($department) . '-' . $normalizedSection;

        // Try to extract numeric year level for the column
        $yearLevelInt = 0;
        
        // Handle "1st Year", "2nd Year", "3rd Year", "4th Year" format
        if (preg_match('/(\d+)(?:st|nd|rd|th)\s+Year/i', $normalizedSection, $m)) {
            $yearLevelInt = (int)$m[1];
        }
        // Handle Roman numerals
        else if (preg_match('/\b(I{1,3}|IV|V)\b/i', $normalizedSection, $m)) {
            $roman = strtoupper($m[1]);
            $map = ['I'=>1,'II'=>2,'III'=>3,'IV'=>4,'V'=>5];
            $yearLevelInt = $map[$roman] ?? 0;
        }
        // Handle plain numbers
        else if (preg_match('/(\d+)/', $normalizedSection, $m)) {
            $yearLevelInt = (int)$m[1];
        }

        // Cache key by resolved code
        if (isset($this->cacheSectionIds[$code])) {
            return $this->cacheSectionIds[$code];
        }

        $section = Section::firstOrCreate([
            'code' => $code
        ], [
            'year_level' => $yearLevelInt,
            'department' => $department,
        ]);
        
        Log::debug("Section resolved: {$code} -> year_level: {$yearLevelInt}, department: {$department}");
        
        $this->cacheSectionIds[$code] = $section->section_id;
        return $this->cacheSectionIds[$code];
    }

    /**
     * Parse a combined section string and return [yearLevel, block].
     * Accepts variants like "BSBA-4th Year A", "4th Year A", or "3rd Year".
     */
    private function parseSectionParts(?string $section): array
    {
        $section = trim((string)($section ?? ''));
        if ($section === '') {
            return ['1st Year', 'A']; // Default values
        }

        // Remove optional department prefix before '-'
        $parts = explode('-', $section, 2);
        $candidate = trim(end($parts));

        // Match "4th Year A" or "IV Year B" - check for most specific patterns first
        if (preg_match('/^(?P<year>(?:\d+(?:st|nd|rd|th)|I{1,3}|IV|V)\s+Year)\s*(?P<block>[A-Z])?$/i', $candidate, $m)) {
            $yearLevel = $m['year'];
            $block = isset($m['block']) ? strtoupper($m['block']) : 'A';
            return [$yearLevel, $block];
        }

        // Match "4th Year" without block
        if (preg_match('/^(?P<year>(?:\d+(?:st|nd|rd|th)|I{1,3}|IV|V)\s+Year)$/i', $candidate, $m)) {
            $yearLevel = $m['year'];
            return [$yearLevel, 'A']; // Default to block A
        }

        // Match Roman numerals like "IV", "III", "II", "I" - check longest first
        if (preg_match('/^(IV|III|II|I)$/i', $candidate, $m)) {
            $roman = strtoupper($m[1]);
            $yearMap = ['I' => '1st Year', 'II' => '2nd Year', 'III' => '3rd Year', 'IV' => '4th Year'];
            $yearLevel = $yearMap[$roman] ?? '1st Year';
            return [$yearLevel, 'A']; // Default to block A
        }

        // Match numeric year like "4", "3", "2", "1"
        if (preg_match('/^(\d+)$/', $candidate, $m)) {
            $num = (int)$m[1];
            $yearLevel = $num . ($num === 1 ? 'st' : ($num === 2 ? 'nd' : ($num === 3 ? 'rd' : 'th'))) . ' Year';
            return [$yearLevel, 'A']; // Default to block A
        }

        // Match patterns like "4 A", "III B", etc.
        if (preg_match('/^(\d+|IV|III|II|I)\s+([A-Z])$/i', $candidate, $m)) {
            $yearPart = $m[1];
            $block = strtoupper($m[2]);
            
            // Convert year part to full year level
            if (is_numeric($yearPart)) {
                $num = (int)$yearPart;
                $yearLevel = $num . ($num === 1 ? 'st' : ($num === 2 ? 'nd' : ($num === 3 ? 'rd' : 'th'))) . ' Year';
            } else {
                $roman = strtoupper($yearPart);
                $yearMap = ['I' => '1st Year', 'II' => '2nd Year', 'III' => '3rd Year', 'IV' => '4th Year'];
                $yearLevel = $yearMap[$roman] ?? '1st Year';
            }
            
            return [$yearLevel, $block];
        }

        // If it already looks like just a block letter, no year present
        if (preg_match('/^[A-Z]$/', $candidate)) {
            return ['1st Year', strtoupper($candidate)]; // Default to 1st Year
        }

        // Default fallback
        return ['1st Year', 'A'];
    }

    /**
     * Pick an available room ID for a given time slot with dynamic room distribution
     * Considers room capacity, lab requirements, unavailability, and balances usage
     */
    private function pickAvailableRoomId(int $groupId, string $day, string $startTime, string $endTime, array $rooms, $preferredRoomId = null, array &$roomUsage = [], array &$roomDayUsage = [], int &$rrIndex = 0, array $courseRequirements = []): int
    {
        // Normalize day and times to match how meetings are stored
        $normalizedDay = DayScheduler::normalizeDay($day);
        
        // Get course requirements for intelligent room matching
        $requiresLab = $courseRequirements['requires_lab'] ?? false;
        $estimatedStudents = $courseRequirements['estimated_students'] ?? 30;
        $minCapacity = max(20, $estimatedStudents * 1.2); // 20% buffer
        
        // Check if preferred room is available and suitable
        if ($preferredRoomId && isset($rooms[$preferredRoomId])) {
            $room = $rooms[$preferredRoomId];
            if ($this->isRoomSuitableForCourse($room, $courseRequirements) && 
                $this->isRoomAvailable($room['room_id'], $normalizedDay, $startTime, $endTime, $roomUsage)) {
                $this->updateRoomUsage($room['room_id'], $normalizedDay, $startTime, $endTime, $roomUsage);
                return $preferredRoomId;
            }
        }
        
        // Get all suitable and available rooms
        $suitableRooms = $this->getSuitableAvailableRooms($rooms, $normalizedDay, $startTime, $endTime, $roomUsage, $courseRequirements);
        
        if (empty($suitableRooms)) {
            // For lab sessions, only fallback to lab rooms if no suitable lab rooms found
            if ($requiresLab) {
                $labRooms = array_filter($rooms, function($room) use ($normalizedDay, $startTime, $endTime, $roomUsage) {
                    return ($room['is_lab'] ?? false) && 
                           $this->isRoomAvailable($room['room_id'], $normalizedDay, $startTime, $endTime, $roomUsage);
                });
                if (!empty($labRooms)) {
                    $suitableRooms = $labRooms;
                } else {
                    Log::warning("No lab rooms available for lab session - skipping assignment");
                    return 0; // Return 0 to indicate no suitable room found
                }
            } else {
                // For non-lab sessions, fallback to any available NON-LAB room
                $availableRooms = $this->getAvailableRooms($rooms, $normalizedDay, $startTime, $endTime, $roomUsage);
                // Filter out lab rooms for non-lab sessions
                $nonLabRooms = array_filter($availableRooms, function($room) {
                    return !($room['is_lab'] ?? false);
                });
                
                if (!empty($nonLabRooms)) {
                    $suitableRooms = $nonLabRooms;
                } else {
                    // Last resort: use first non-lab room
                    $firstNonLabRoom = array_values(array_filter($rooms, function($room) {
                        return !($room['is_lab'] ?? false);
                    }))[0] ?? null;
                    
                    if ($firstNonLabRoom) {
                        return $firstNonLabRoom['room_id'];
                    } else {
                        Log::warning("No non-lab rooms available for non-lab session - skipping assignment");
                        return 0; // Return 0 to indicate no suitable room found
                    }
                }
            }
        }
        
        // Select room using intelligent distribution algorithm
        $selectedRoom = $this->selectOptimalRoom($suitableRooms, $roomUsage, $roomDayUsage, $rrIndex);
        
        // Update room usage tracking
        $this->updateRoomUsage($selectedRoom['room_id'], $normalizedDay, $startTime, $endTime, $roomUsage);
        
        return $selectedRoom['room_id'];
    }
    
    /**
     * Check if a room is suitable for a course based on requirements
     */
    private function isRoomSuitableForCourse(array $room, array $courseRequirements): bool
    {
        $requiresLab = $courseRequirements['requires_lab'] ?? false;
        $estimatedStudents = $courseRequirements['estimated_students'] ?? 30;
        $minCapacity = max(20, $estimatedStudents * 1.2);
        
        // Check if room is active
        if (!($room['is_active'] ?? true)) {
            return false;
        }
        
        // Check lab requirement
        if ($requiresLab && !($room['is_lab'] ?? false)) {
            return false;
        }
        
        // Check capacity requirement
        $roomCapacity = $room['capacity'] ?? 30;
        if ($roomCapacity < $minCapacity) {
            return false;
        }
        
                return true;
            }
            
    /**
     * Check if a room is available at a specific time (no conflicts)
     */
    private function isRoomAvailable(int $roomId, string $day, string $startTime, string $endTime, array $roomUsage): bool
    {
        $roomKey = $roomId;
        $dayKey = $day;
        
        // Check for conflicts with already scheduled meetings
        if (isset($roomUsage[$roomKey][$dayKey])) {
            foreach ($roomUsage[$roomKey][$dayKey] as $existingSlot) {
                if ($this->timesOverlap($startTime, $endTime, $existingSlot['start_time'], $existingSlot['end_time'])) {
                    return false;
                }
            }
        }
        
        // All rooms are available if no conflicts found
        return true;
    }
    
    /**
     * Get rooms that are both suitable and available
     */
    private function getSuitableAvailableRooms(array $rooms, string $day, string $startTime, string $endTime, array $roomUsage, array $courseRequirements): array
    {
        return array_filter($rooms, function($room) use ($day, $startTime, $endTime, $roomUsage, $courseRequirements) {
            return $this->isRoomSuitableForCourse($room, $courseRequirements) && 
                   $this->isRoomAvailable($room['room_id'], $day, $startTime, $endTime, $roomUsage);
        });
    }
    
    /**
     * Get rooms that are available (regardless of suitability)
     */
    private function getAvailableRooms(array $rooms, string $day, string $startTime, string $endTime, array $roomUsage): array
    {
        return array_filter($rooms, function($room) use ($day, $startTime, $endTime, $roomUsage) {
            return $this->isRoomAvailable($room['room_id'], $day, $startTime, $endTime, $roomUsage);
        });
    }
    
    /**
     * Select optimal room using intelligent distribution algorithm
     */
    private function selectOptimalRoom(array $suitableRooms, array $roomUsage, array $roomDayUsage, int &$rrIndex): array
    {
        if (count($suitableRooms) === 1) {
            return array_values($suitableRooms)[0];
        }
        
        // Calculate room scores based on usage and capacity
        $roomScores = [];
        foreach ($suitableRooms as $room) {
            $roomId = $room['room_id'];
            $totalUsage = 0;
            $dayUsage = 0;
            
            // Calculate total usage across all days
            foreach ($roomUsage as $day => $daySlots) {
                if (isset($daySlots[$roomId])) {
                    $totalUsage += count($daySlots[$roomId]);
                }
            }
            
            // Calculate today's usage
            foreach ($roomDayUsage as $day => $dayUsage) {
                if (isset($dayUsage[$roomId])) {
                    $dayUsage += $dayUsage[$roomId];
                }
            }
            
            // Score: lower usage = higher score (prefer less used rooms)
            // Also consider capacity efficiency
            $capacity = $room['capacity'] ?? 30;
            $efficiencyScore = min(1.0, $capacity / 50); // Prefer rooms closer to optimal size
            
            $roomScores[$roomId] = [
                'room' => $room,
                'score' => (100 - $totalUsage) + (50 - $dayUsage) + ($efficiencyScore * 20)
            ];
        }
        
        // Sort by score (highest first)
        uasort($roomScores, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // Use round-robin among top 3 rooms to ensure some distribution
        $topRooms = array_slice($roomScores, 0, min(3, count($roomScores)), true);
        $topRoomIds = array_keys($topRooms);
        
        $selectedIndex = $rrIndex % count($topRoomIds);
        $rrIndex++;
        
        return $topRooms[$topRoomIds[$selectedIndex]]['room'];
    }
    
    /**
     * Update room usage tracking
     */
    private function updateRoomUsage(int $roomId, string $day, string $startTime, string $endTime, array &$roomUsage): void
    {
        $roomKey = $roomId;
        $dayKey = $day;
        
        if (!isset($roomUsage[$roomKey][$dayKey])) {
            $roomUsage[$roomKey][$dayKey] = [];
        }
        
        $roomUsage[$roomKey][$dayKey][] = [
            'start_time' => $startTime,
            'end_time' => $endTime
        ];
    }

    private function expandSchedulesToBothBlocks(array $schedules): array
    {
        $expandedSchedules = [];
        
        foreach ($schedules as $schedule) {
            // Add original schedule
            $expandedSchedules[] = $schedule;
            
            // Create copy for other block
            $otherBlockSchedule = $schedule;
            $otherBlockSchedule['block'] = ($schedule['block'] === 'A') ? 'B' : 'A';
            
            // Update section_id for the other block
            $otherBlockSchedule['section_id'] = $this->resolveSectionId(
                $schedule['dept'],
                $schedule['year_level'],
                $otherBlockSchedule['block']
            );
            
            $expandedSchedules[] = $otherBlockSchedule;
        }
        
        return $expandedSchedules;
    }

    private function createEntryAndMeeting(int $groupId, ?int $roomId, string $instructorName, string $subjectCode, string $subjectDescription, int $units, string $day, string $startTime, string $endTime, string $department, string $yearLevel, string $block)
    {
        try {
                $instructorId = $this->resolveInstructorId($instructorName, 'FULL-TIME');
                $subjectId = $this->resolveSubjectId($subjectCode, $subjectDescription, $units);
            $sectionId = $this->resolveSectionId($department, $yearLevel, $block);
            
            // Normalize day to abbreviated format for database
            $normalizedDay = DayScheduler::normalizeDay($day);

            // Handle null room_id by assigning a default room
            if ($roomId === null) {
                $defaultRoom = Room::where('is_active', true)->first();
                $roomId = $defaultRoom ? $defaultRoom->room_id : 1; // Fallback to room_id 1
            }

			// CONSOLIDATION: Reuse an existing entry for same course/section and time range
			// This avoids creating a new parent entry per day when times match.
			// Normalize incoming times to HH:MM:SS to match DB storage
			$normStart = strlen($startTime) === 5 ? ($startTime . ':00') : $startTime;
			$normEnd = strlen($endTime) === 5 ? ($endTime . ':00') : $endTime;
            // Entry is now keyed without instructor (instructor moved to meetings)
            $existingEntry = ScheduleEntry::where([
                'group_id' => $groupId,
                'subject_id' => $subjectId,
                'section_id' => $sectionId
            ])
			->whereHas('meetings', function ($q) use ($normStart, $normEnd) {
				$q->where('start_time', $normStart)
					->where('end_time', $normEnd);
			})
			->first();

            if ($existingEntry) {
				$entry = $existingEntry;
			} else {
				// Create ScheduleEntry with only the normalized columns
				$entry = ScheduleEntry::create([
                        'group_id' => $groupId,
                        'subject_id' => $subjectId,
                        'section_id' => $sectionId,
                        'status' => 'confirmed'
				]);
			}
            
            // Reduced logging to prevent pipe overflow
            if (rand(1, 20) === 1) { // Only log 5% of entries
                Log::info("Created schedule entry: subject={$subjectCode}, year_level={$yearLevel}, block={$block}, section_id={$sectionId}");
            }

			// Prevent DB-level conflicts across shared days before creating the meeting
            $days = \App\Services\DayScheduler::parseCombinedDays($normalizedDay);
            if (empty($days)) { $days = [$normalizedDay]; }
            $createdMeetings = 0;
			// If reusing an existing entry, determine canonical time/room from its first meeting
			$canonicalMeeting = null;
			if (isset($existingEntry) && $existingEntry) {
				$canonicalMeeting = \App\Models\ScheduleMeeting::where('entry_id', $existingEntry->entry_id)
					->orderBy('meeting_id', 'asc')
					->first();
			}

            // Lock room across joint sessions (all days under the same entry)
            $lockedRoomId = $canonicalMeeting ? (int)$canonicalMeeting->room_id : null;

            foreach ($days as $d) {
                $targetDay = $d;
				$targetStart = $normStart;
				$targetEnd = $normEnd;
                $targetRoom = (int)$roomId;

				// INHERIT: If parent already has a meeting, inherit its time and room
				if ($canonicalMeeting) {
					$targetStart = $canonicalMeeting->start_time;
					$targetEnd = $canonicalMeeting->end_time;
					$targetRoom = (int)$canonicalMeeting->room_id;
				}

                // Enforce locked room if available
                if ($lockedRoomId !== null) {
                    $targetRoom = $lockedRoomId;
                }

                // Prevent inserting overlapping meetings: check DB
                if ($this->hasDbOverlap($groupId, $instructorId, $targetRoom, $sectionId, $targetDay, $targetStart, $targetEnd, $subjectId)) {
                    // Attempt a same-time alternative (different day/room)
                    // Prefer locked room if set
                    $preferredRoom = $lockedRoomId !== null ? $lockedRoomId : $targetRoom;
                    $alt = $this->findSameTimeAlternative($groupId, $instructorId, $sectionId, $targetDay, $targetStart, $targetEnd, $preferredRoom, $subjectId);
                    if (is_array($alt)) {
                        [$targetDay, $targetStart, $targetEnd, $targetRoom] = $alt;
                    } else {
                        // Try next available slot with same duration across allowed days/rooms
                        $nextPreferredRoom = $lockedRoomId !== null ? $lockedRoomId : $targetRoom;
                        $next = $this->findNextAvailableSlot($groupId, $instructorId, $sectionId, $nextPreferredRoom, $targetStart, $targetEnd, $subjectId);
                        if (is_array($next)) {
                            [$targetDay, $targetStart, $targetEnd, $targetRoom] = $next;
                        } else {
                            // Skip this meeting if no alternative slot is available
                            \Log::warning("SKIP MEETING (no-alt): {$subjectCode} {$yearLevel} {$block} {$d} {$targetStart}-{$targetEnd}");
                            continue;
                        }
                    }
                }

                ScheduleMeeting::create([
                    'entry_id' => $entry->entry_id,
                    'instructor_id' => $instructorId,
                    'room_id' => $targetRoom,
                    'day' => $targetDay,
                    'start_time' => $targetStart,
                    'end_time' => $targetEnd
                ]);
                // Lock room for subsequent days once first meeting is created
                if ($lockedRoomId === null) { $lockedRoomId = (int)$targetRoom; }
                $createdMeetings++;
            }
            // If no meetings were created (all conflicted), remove the empty entry and return null
            if ($createdMeetings === 0) {
                try { $entry->delete(); } catch (\Throwable $t) { /* ignore */ }
                return null;
            }

                return $entry;
        } catch (Exception $e) {
            Log::error("Error creating entry for {$subjectCode} ({$yearLevel} {$block}): " . $e->getMessage());
            Log::error("Exception details: " . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Check DB for any overlap on shared days for instructor, room, or section.
     */
    private function hasDbOverlap(int $groupId, int $instructorId, int $roomId, int $sectionId, string $day, string $start, string $end, ?int $subjectId = null): bool
    {
        // Support combined day strings like "WedSat" by expanding to per-day checks
        $expandedDays = \App\Services\DayScheduler::parseCombinedDays($day);
        if (empty($expandedDays)) {
            $expandedDays = [\App\Services\DayScheduler::normalizeDay($day)];
        }

        // Normalize incoming times to DB format (H:i:s) in case caller passes AM/PM
        $normalizeToDbTime = function (string $t): string {
            $trimmed = trim($t);
            // Handle ranges like "5:30 PM–7:00 PM" defensively by taking left/right parts when seen
            if (strpos($trimmed, '–') !== false) {
                $parts = explode('–', $trimmed);
                // Prefer first part for start, second part for end; actual assignment happens below
                $trimmed = trim($parts[0]);
            }
            // If AM/PM present or non-24h format, convert via strtotime
            if (stripos($trimmed, 'AM') !== false || stripos($trimmed, 'PM') !== false) {
                return date('H:i:s', strtotime($trimmed));
            }
            // If already H:i or H:i:s, standardize to H:i:s
            $colonCount = substr_count($trimmed, ':');
            if ($colonCount === 1) {
                return $trimmed . ':00';
            }
            return $trimmed;
        };

        // Detect if caller accidentally passed a range string into $start/$end and correct
        $rawStart = trim($start);
        $rawEnd = trim($end);
        if (strpos($rawStart, '–') !== false && strpos($rawEnd, '–') === false) {
            $parts = array_map('trim', explode('–', $rawStart));
            $start = $parts[0] ?? $start;
            $end = $parts[1] ?? $end;
        }
        if (strpos($rawEnd, '–') !== false && strpos($rawStart, '–') === false) {
            $parts = array_map('trim', explode('–', $rawEnd));
            $start = $parts[0] ?? $start;
            $end = $parts[1] ?? $end;
        }

        $start = $normalizeToDbTime($start);
        $end = $normalizeToDbTime($end);
        // Overlap predicate (STRICT): two intervals overlap iff start < other_end AND end > other_start
        $overlap = function($q) use ($start, $end) {
            $q->where('start_time', '<', $end)
              ->where('end_time', '>', $start);
        };

        foreach ($expandedDays as $singleDay) {
            // Instructor conflict (meeting-level instructor)
            $instr = \App\Models\ScheduleMeeting::where('day', $singleDay)
                ->where('instructor_id', $instructorId)
                ->whereHas('entry', function($q) use ($groupId, $subjectId) {
                    $q->where('group_id', $groupId);
                    // Allow overlaps for the SAME subject (joint session sharing the same time)
                    if (!is_null($subjectId)) {
                        $q->where('subject_id', '!=', $subjectId);
                    }
                })
                ->where($overlap)
                ->exists();

            if ($instr) {
                if (config('app.scheduler_debug_overlap')) {
                    \Log::debug("DB-OVERLAP instructor", [
                        'group_id' => $groupId, 'instructor_id' => $instructorId, 'section_id' => $sectionId,
                        'day' => $singleDay, 'start' => $start, 'end' => $end, 'room_id' => $roomId,
                        'subject_id' => $subjectId
                    ]);
                }
                return true;
            }

            // Room conflict
            $room = \App\Models\ScheduleMeeting::where('day', $singleDay)
                ->where('room_id', $roomId)
                ->whereHas('entry', function($q) use ($groupId, $subjectId) {
                    $q->where('group_id', $groupId);
                    if (!is_null($subjectId)) {
                        $q->where('subject_id', '!=', $subjectId);
                    }
                })
                ->where($overlap)
                ->exists();

            if ($room) {
                if (config('app.scheduler_debug_overlap')) {
                    \Log::debug("DB-OVERLAP room", [
                        'group_id' => $groupId, 'room_id' => $roomId, 'section_id' => $sectionId,
                        'day' => $singleDay, 'start' => $start, 'end' => $end,
                        'subject_id' => $subjectId
                    ]);
                }
                return true;
            }

            // Section conflict
            $sect = \App\Models\ScheduleMeeting::where('day', $singleDay)
                ->whereHas('entry', function($q) use ($groupId, $sectionId) {
                    $q->where('group_id', $groupId)->where('section_id', $sectionId);
                })
                ->where($overlap)
                ->exists();

            if ($sect) {
                if (config('app.scheduler_debug_overlap')) {
                    \Log::debug("DB-OVERLAP section", [
                        'group_id' => $groupId, 'section_id' => $sectionId,
                        'day' => $singleDay, 'start' => $start, 'end' => $end, 'room_id' => $roomId,
                        'subject_id' => $subjectId
                    ]);
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Try to find the next available meeting slot with the same duration.
     * Returns [day, start_time, end_time, room_id] or null if none found.
     */
    private function findNextAvailableSlot(int $groupId, int $instructorId, int $sectionId, ?int $preferredRoomId, string $start, string $end, ?int $subjectId = null): ?array
    {
        $durationMinutes = \App\Services\TimeScheduler::timeToMinutes($end) - \App\Services\TimeScheduler::timeToMinutes($start);
        if ($durationMinutes <= 0) { return null; }

        // Candidate time slots across all days with same duration
        $allSlots = \App\Services\TimeScheduler::generateComprehensiveTimeSlots();
        $sameDuration = array_values(array_filter($allSlots, function($slot) use ($durationMinutes) {
            $d = \App\Services\TimeScheduler::timeToMinutes($slot['end']) - \App\Services\TimeScheduler::timeToMinutes($slot['start']);
            return $d === $durationMinutes;
        }));

        // Prefer same-day first by reordering slots (not strictly necessary, but helpful)
        $dayOrder = ['Mon','Tue','Wed','Thu','Fri','Sat'];

        // Rooms: try preferred first, then any active room
        $rooms = [];
        if ($preferredRoomId) { $rooms[] = (int)$preferredRoomId; }
        $otherRooms = \App\Models\Room::where('is_active', true)
            ->when($preferredRoomId !== null, function($q) use ($preferredRoomId) { $q->where('room_id', '!=', $preferredRoomId); })
            ->pluck('room_id')->all();
        $rooms = array_merge($rooms, $otherRooms);

        // Iterate by days then by time slots to find the first non-overlapping option
        foreach ($dayOrder as $day) {
            foreach ($sameDuration as $slot) {
                $slotStart = $slot['start'];
                $slotEnd = $slot['end'];

                foreach ($rooms as $roomId) {
                    if (!$this->hasDbOverlap($groupId, $instructorId, (int)$roomId, $sectionId, $day, $slotStart, $slotEnd, $subjectId)) {
                        return [$day, $slotStart, $slotEnd, (int)$roomId];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Try to find the same start/end on a different day/room (do not change time window).
     * Returns [day, start_time, end_time, room_id] or null if none found.
     */
    private function findSameTimeAlternative(int $groupId, int $instructorId, int $sectionId, string $currentDay, string $start, string $end, ?int $preferredRoomId, ?int $subjectId = null): ?array
    {
        $dayOrder = ['Mon','Tue','Wed','Thu','Fri','Sat'];
        // Try current day first with other rooms, then other days
        $daysToTry = array_values($dayOrder);
        // Rotate so current day is first
        $currentIdx = array_search(\App\Services\DayScheduler::normalizeDay($currentDay), $dayOrder, true);
        if ($currentIdx !== false) {
            $daysToTry = array_merge(array_slice($dayOrder, $currentIdx, null, true), array_slice($dayOrder, 0, $currentIdx, true));
        }

        // Build room preference list
        $rooms = [];
        if ($preferredRoomId) { $rooms[] = (int)$preferredRoomId; }
        $otherRooms = \App\Models\Room::where('is_active', true)
            ->when($preferredRoomId !== null, function($q) use ($preferredRoomId) { $q->where('room_id', '!=', $preferredRoomId); })
            ->pluck('room_id')->all();
        $rooms = array_merge($rooms, $otherRooms);

        foreach ($daysToTry as $day) {
            foreach ($rooms as $roomId) {
                if (!$this->hasDbOverlap($groupId, $instructorId, (int)$roomId, $sectionId, $day, $start, $end, $subjectId)) {
                    return [$day, $start, $end, (int)$roomId];
                }
            }
        }

        return null;
    }


    /**
     * Log file upload data in organized format
     */
    private function logFileUploadData(array $rawInstructorData, string $semester, string $schoolYear): void
    {
        try {
            // Transform the raw data to get organized information
            $instructorData = $this->transformInstructorData($rawInstructorData);
            
            // Extract department from the first valid entry
            $department = 'BSBA'; // Default
            if (!empty($instructorData)) {
                foreach ($instructorData as $entry) {
                    if (!empty($entry['dept'])) {
                        $department = $entry['dept'];
                        break;
                    }
                }
            }
            
            // Count instructors
            $instructors = array_unique(array_column($instructorData, 'name'));
            $totalInstructors = count($instructors);
            
            // Count employment types
            $employmentTypes = array_count_values(array_column($instructorData, 'employmentType'));
            $fullTimeCount = $employmentTypes['Full-time'] ?? $employmentTypes['FULL-TIME'] ?? 0;
            $partTimeCount = $employmentTypes['Part-time'] ?? $employmentTypes['PART-TIME'] ?? 0;
            
            // Count year levels
            $yearLevels = array_unique(array_column($instructorData, 'yearLevel'));
            $totalYearLevels = count($yearLevels);
            
            // Count subjects per year level
            $subjectsPerYearLevel = [];
            foreach ($instructorData as $entry) {
                $yearLevel = $entry['yearLevel'];
                $subjectCode = $entry['courseCode'];
                if (!isset($subjectsPerYearLevel[$yearLevel])) {
                    $subjectsPerYearLevel[$yearLevel] = [];
                }
                $subjectsPerYearLevel[$yearLevel][$subjectCode] = true;
            }
            
            // Count sections (unique combinations of year level and block)
            $sections = [];
            foreach ($instructorData as $entry) {
                $sectionKey = $entry['yearLevel'] . ' ' . $entry['block'];
                $sections[$sectionKey] = true;
            }
            $totalSections = count($sections);
            
            // Log the organized data
            Log::info('=== FILE UPLOAD DATA SUMMARY ===', [
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'department' => $department,
                'semester' => $semester,
                'school_year' => $schoolYear,
                'total_instructors' => $totalInstructors,
                'instructors' => $instructors,
                'employment_types' => [
                    'full_time' => $fullTimeCount,
                    'part_time' => $partTimeCount
                ],
                'total_year_levels' => $totalYearLevels,
                'year_levels' => $yearLevels,
                'subjects_per_year_level' => array_map('count', $subjectsPerYearLevel),
                'total_sections' => $totalSections,
                'sections' => array_keys($sections),
                'total_courses' => count($instructorData)
            ]);
            
            // Log detailed course information
            Log::info('=== DETAILED COURSE INFORMATION ===');
            foreach ($instructorData as $index => $course) {
                Log::info("Course " . ($index + 1) . ": " . $course['courseCode'] . " - " . $course['subject'], [
                    'instructor' => $course['name'],
                    'year_level' => $course['yearLevel'],
                    'block' => $course['block'],
                    'units' => $course['unit'],
                    'employment_type' => $course['employmentType'],
                    'session_type' => $course['sessionType'],
                    'department' => $course['dept']
                ]);
            }
            
        } catch (Exception $e) {
            Log::error('Error logging file upload data: ' . $e->getMessage());
        }
    }

    /**
     * MAIN SCHEDULER: Generate schedule using Python algorithms
     * 
     * ARCHITECTURE:
     * - Python (OR-Tools/Genetic) = Main scheduler (handles all logic, constraints, conflicts)
     * - Laravel = Translator only (converts data format, saves to database)
     * 
     * Flow: Request → Python Algorithm → Database → Response
     */
    public function generateSchedule(Request $request): JsonResponse
    {
        // Increase execution time limit for complex scheduling algorithms
        set_time_limit(900); // 15 minutes (increased from 10)
        ini_set('memory_limit', '512M'); // Increase memory limit (increased from 5 to prevent timeout)
        
        try {
            $rawInstructorData = $request->input('instructorData', []);
            $semester = $request->input('semester', '1st Semester');
            $schoolYear = $request->input('schoolYear', '2024-2025');
            $filterPreferences = $request->input('filterPreferences', []);

            if (empty($rawInstructorData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No instructor data provided'
                ], 400);
            }

            // Log filter preferences for debugging
            if (!empty($filterPreferences)) {
                Log::info("Filter preferences received:", $filterPreferences);
            }

            // Log the file upload data in organized format
            $this->logFileUploadData($rawInstructorData, $semester, $schoolYear);

            // Transform the raw data array to the expected format
            $instructorData = $this->transformInstructorData($rawInstructorData);
            Log::info("Transformed " . count($rawInstructorData) . " raw entries to " . count($instructorData) . " valid entries");

            // Extract department from the first valid entry
            $department = 'BSBA'; // Default
            if (!empty($instructorData)) {
                foreach ($instructorData as $entry) {
                    if (!empty($entry['dept'])) {
                        $department = $entry['dept'];
                        break;
                    }
                }
            }
            
            // Allow multiple schedule versions for same department/semester/year
            // Users can generate and compare different schedules
            $scheduleGroup = ScheduleGroup::create([
                'department' => $department,
                'school_year' => $schoolYear,
                'semester' => $semester
            ]);

            // Proactively ensure both A and B sections exist for every year level present in the data
            $yearLevels = array_unique(array_column($instructorData, 'yearLevel'));
            
            // Use year levels from the data (no detection needed)
            
            // Ensure we have at least 1st through 4th year sections
            $requiredYearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
            $yearLevels = array_unique(array_merge($yearLevels, $requiredYearLevels));
            
            foreach ($yearLevels as $yearLevel) {
                $this->resolveSectionId($department, $yearLevel, 'A');
                $this->resolveSectionId($department, $yearLevel, 'B');
            }

            // Build rooms list for Python with all required fields
            $rooms = Room::all(['room_id','room_name','capacity','is_lab','is_active'])->map(function ($r) {
                return [
                    'room_id' => $r->room_id,
                    'room_name' => $r->room_name,
                    'capacity' => $r->capacity ?? 30, // Default capacity if null
                    'is_lab' => $r->is_lab ?? false, // Default to false if null
                    'is_active' => $r->is_active ?? true, // Default to true if null
                ];
            })->values()->toArray();
            Log::info("Available rooms: " . count($rooms));

            // Optional: random scheduling mode toggle
            $mode = $request->input('mode', env('SCHEDULER_DEFAULT_MODE', 'php'));
            if ($mode === 'random') {
                Log::info('Starting PHP Random Scheduler with ' . count($instructorData) . ' courses...');
                // Synchronize subjects across blocks to ensure both A and B sections are created
                $synchronizedData = $this->synchronizeSubjectsAcrossBlocks($instructorData);
                // Determine education level based on department (college departments = "College")
                $educationLevel = 'College'; // Default to College for college departments
                $phpScheduler = new \App\Services\PhpScheduler($synchronizedData, $rooms, $department, $educationLevel);
                if (!empty($filterPreferences)) {
                    $phpScheduler->setFilterPreferences($filterPreferences);
                }
                $strict = filter_var($request->input('randomStrict', 'true'), FILTER_VALIDATE_BOOLEAN);
                $randResult = $phpScheduler->solveRandom($strict, 45);
                if (!empty($randResult['schedules'])) {
                    // Pre-filter randomized output for conflicts before saving
                    $filteredRand = $this->filterSchedulesByConflicts($randResult['schedules']);
                    // Save schedules to database - group by course to avoid duplicates
                    $savedSchedules = $this->saveSchedulesToDatabase($filteredRand, $scheduleGroup->group_id);
                    // Post-process to resolve any residual section/day overlaps safely
                    $this->resolveSectionConflictsForGroup($scheduleGroup->group_id);
                    try {
                        $this->logGeneratedSchedule($savedSchedules, $randResult['algorithm'] ?? 'php_random', $scheduleGroup->group_id);
                    } catch (\Exception $e) {
                        Log::warning('Could not log generated schedule (random) due to pipe overflow: ' . $e->getMessage());
                    }
                    return response()->json([
                        'success' => true,
                        'message' => $randResult['message'] ?? 'Random schedule generated',
                        'data' => [
                            'success' => true,
                            'message' => $randResult['message'] ?? '',
                            'schedules' => $savedSchedules,
                            'total_conflicts' => $randResult['total_conflicts'] ?? 0,
                            'algorithm' => $randResult['algorithm'] ?? 'php_random'
                        ],
                        'group_id' => $scheduleGroup->group_id,
                        'department' => $department,
                        'school_year' => $schoolYear,
                        'semester' => $semester,
                        'algorithm' => $randResult['algorithm'] ?? 'php_random'
                    ]);
                }
                // If random produced nothing, continue to normal flow
                Log::warning('Random scheduler produced no entries, continuing to primary schedulers...');
            }

            // Try PHP Scheduler first (NEW PRIMARY ALGORITHM)
            Log::info('Starting PHP Scheduler Algorithm with ' . count($instructorData) . ' courses...');
            $phpResult = ['success' => false, 'message' => 'PHP scheduler not attempted'];
            try {
                $phpResult = $this->runPhpScheduler($instructorData, $rooms, $scheduleGroup->group_id, $filterPreferences, $department);
            } catch (\Throwable $e) {
                Log::error('PHP scheduler threw exception: ' . $e->getMessage());
                $phpResult = ['success' => false, 'message' => 'PHP scheduler exception: ' . $e->getMessage()];
            } catch (\Error $e) {
                Log::error('PHP scheduler fatal error: ' . $e->getMessage());
                $phpResult = ['success' => false, 'message' => 'PHP scheduler fatal error: ' . $e->getMessage()];
            }
            
            if ($phpResult['success']) {
                Log::info('PHP scheduler succeeded with ' . count($phpResult['schedules']) . ' entries');
                
                // Log the generated schedule with error handling to prevent pipe overflow
                try {
                    $this->logGeneratedSchedule($phpResult['schedules'], 'php_primary', $scheduleGroup->group_id);
                } catch (\Exception $e) {
                    Log::warning('Could not log generated schedule due to pipe overflow: ' . $e->getMessage());
                }
                
                return response()->json([
                    'success' => true,
                    'message' => 'Schedule generated using PHP constraint satisfaction algorithm',
                    'data' => $phpResult,
                    'group_id' => $scheduleGroup->group_id,
                    'department' => $department,
                    'school_year' => $schoolYear,
                    'semester' => $semester,
                    'algorithm' => 'php_primary'
                ]);
            }
            
            // Check if we got a reasonable schedule even if not "perfect"
            $scheduledCount = count($phpResult['schedules'] ?? []);
            $totalCourses = count($instructorData);
            $coverageRate = $totalCourses > 0 ? ($scheduledCount / $totalCourses) : 0;
            
            if ($coverageRate >= 0.5) { // If we scheduled at least 50% of courses
                Log::info("PHP scheduler provided partial solution (" . round($coverageRate * 100, 1) . "% coverage), using it instead of falling back");
                
                // Log the generated schedule
                try {
                    $this->logGeneratedSchedule($phpResult['schedules'], 'php_primary_partial', $scheduleGroup->group_id);
                } catch (\Exception $e) {
                    Log::warning('Could not log generated schedule due to pipe overflow: ' . $e->getMessage());
                }
                
                return response()->json([
                    'success' => true,
                    'message' => 'Schedule generated using PHP algorithm (partial solution)',
                    'data' => $phpResult,
                    'group_id' => $scheduleGroup->group_id,
                    'department' => $department,
                    'school_year' => $schoolYear,
                    'semester' => $semester,
                    'algorithm' => 'php_primary_partial'
                ]);
            }
            
            // Fallback to Python OR-Tools Algorithm
            Log::info('PHP scheduler provided insufficient coverage, trying Python OR-Tools Algorithm with ' . count($instructorData) . ' courses...');
            $ortoolsResult = ['success' => false, 'message' => 'OR-Tools algorithm not attempted'];
            try {
                $ortoolsResult = $this->runOrtoolsAlgorithm($instructorData, $rooms, $scheduleGroup->group_id, 'A');
            } catch (\Throwable $e) {
                Log::error('OR-Tools algorithm threw exception: ' . $e->getMessage());
                $ortoolsResult = ['success' => false, 'message' => 'OR-Tools algorithm exception: ' . $e->getMessage()];
            } catch (\Error $e) {
                Log::error('OR-Tools algorithm fatal error: ' . $e->getMessage());
                $ortoolsResult = ['success' => false, 'message' => 'OR-Tools algorithm fatal error: ' . $e->getMessage()];
            }
            
            if ($ortoolsResult['success']) {
                Log::info('OR-Tools algorithm succeeded with ' . count($ortoolsResult['schedules']) . ' entries');
                
                // Log the generated schedule with error handling to prevent pipe overflow
                try {
                    $this->logGeneratedSchedule($ortoolsResult['schedules'], 'ortools_fallback', $scheduleGroup->group_id);
                } catch (\Exception $e) {
                    Log::warning('Could not log generated schedule due to pipe overflow: ' . $e->getMessage());
                }
                
                return response()->json([
                    'success' => true,
                    'message' => 'Schedule generated using Python OR-Tools algorithm (fallback)',
                    'data' => $ortoolsResult,
                    'group_id' => $scheduleGroup->group_id,
                    'department' => $department,
                    'school_year' => $schoolYear,
                    'semester' => $semester,
                    'algorithm' => 'ortools_fallback'
                ]);
            }
            
            // Fallback to Python Genetic Algorithm
            Log::info('OR-Tools failed, trying Python Genetic Algorithm with ' . count($instructorData) . ' courses...');
            $geneticResult = ['success' => false, 'message' => 'Genetic algorithm not attempted'];
            try {
                $geneticResult = $this->runGeneticAlgorithm($instructorData, $rooms, $scheduleGroup->group_id);
            } catch (\Throwable $e) {
                Log::error('Genetic algorithm threw exception: ' . $e->getMessage());
                $geneticResult = ['success' => false, 'message' => 'Genetic algorithm exception: ' . $e->getMessage()];
            } catch (\Error $e) {
                Log::error('Genetic algorithm fatal error: ' . $e->getMessage());
                $geneticResult = ['success' => false, 'message' => 'Genetic algorithm fatal error: ' . $e->getMessage()];
            }
            
            if ($geneticResult['success']) {
                Log::info('Genetic algorithm succeeded with ' . count($geneticResult['schedules']) . ' entries');
                
                // Log the generated schedule with error handling to prevent pipe overflow
                try {
                    $this->logGeneratedSchedule($geneticResult['schedules'], 'genetic', $scheduleGroup->group_id);
                } catch (\Exception $e) {
                    Log::warning('Could not log generated schedule due to pipe overflow: ' . $e->getMessage());
                }
            
            return response()->json([
                'success' => true,
                    'message' => 'Schedule generated using Python Genetic Algorithm',
                    'data' => $geneticResult,
                'group_id' => $scheduleGroup->group_id,
                'department' => $department,
                'school_year' => $schoolYear,
                'semester' => $semester,
                    'algorithm' => 'genetic'
                ]);
            }
            
            // If all algorithms fail, this is now impossible since PHP is primary
            Log::info('All algorithms have failed (this should not happen with new architecture)');
            $phpResult = ['success' => false, 'message' => 'All algorithms failed'];
            
            if ($phpResult['success']) {
                Log::info('PHP fallback algorithm succeeded with ' . count($phpResult['schedules']) . ' entries');
                
                // Log the generated schedule with error handling to prevent pipe overflow
                try {
                    $this->logGeneratedSchedule($phpResult['schedules'], 'php_fallback', $scheduleGroup->group_id);
                } catch (\Exception $e) {
                    Log::warning('Could not log generated schedule due to pipe overflow: ' . $e->getMessage());
                }
                
                return response()->json([
                    'success' => true,
                    'message' => 'Schedule generated using PHP fallback algorithm',
                    'data' => $phpResult,
                    'group_id' => $scheduleGroup->group_id,
                    'department' => $department,
                    'school_year' => $schoolYear,
                    'semester' => $semester,
                    'algorithm' => 'php_fallback'
                ]);
            }
            
            // If all algorithms fail, return error
            Log::error('All scheduling algorithms failed.');
            return response()->json([
                'success' => false,
                'message' => 'All scheduling algorithms failed. The problem may be infeasible with current constraints.',
                'errors' => [
                    'ortools' => $ortoolsResult['message'] ?? 'Unknown error',
                    'genetic' => $geneticResult['message'] ?? 'Unknown error',
                    'php_fallback' => $phpResult['message'] ?? 'Unknown error'
                ]
            ], 500);

        } catch (Exception $e) {
            Log::error('Schedule generation error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Schedule generation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    private function transformInstructorData(array $rawData): array
    {
        $transformedData = [];
        
        foreach ($rawData as $row) {
            // Skip empty rows
            if (empty($row['name']) || empty($row['courseCode'])) {
                continue;
            }
            
            $yearLevel = $row['yearLevel'] ?? '1st Year';
            $department = $row['dept'] ?? 'BSBA';
            $rawBlock = trim((string)($row['block'] ?? 'A'));
            
            // Normalize blocks: handle formats like "A & B & C" or "A,B,C"
            $blockTokens = preg_split('/[,&]+/u', $rawBlock);
            $normalizedBlocks = [];
            foreach ($blockTokens as $bt) {
                $b = strtoupper(trim($bt));
                if ($b !== '') { $normalizedBlocks[] = $b; }
            }
            if (empty($normalizedBlocks)) { $normalizedBlocks = ['A']; }
            
            // Calculate units per section - divide by number of sections for units 4 and above
            $originalUnits = (int) ($row['unit'] ?? 0);
            $unitsPerSection = $originalUnits;
            if (count($normalizedBlocks) > 1 && $originalUnits >= 4) {
                $unitsPerSection = intval($originalUnits / count($normalizedBlocks));
                Log::info("Dividing units for {$row['courseCode']}: {$originalUnits} units ÷ " . count($normalizedBlocks) . " sections = {$unitsPerSection} units per section");
            } elseif (count($normalizedBlocks) > 1 && $originalUnits < 4) {
                Log::info("Keeping original units for {$row['courseCode']}: {$originalUnits} units (less than 4, no division needed)");
            }

            foreach ($normalizedBlocks as $block) {
                $transformedData[] = [
                    'name' => trim($row['name']),
                    'courseCode' => trim($row['courseCode']),
                    'subject' => trim($row['subject'] ?? $row['courseCode']),
                    'unit' => $unitsPerSection,
                    'employmentType' => $this->normalizeEmploymentType($row['employmentType'] ?? 'FULL-TIME'),
                    'sessionType' => $row['sessionType'] ?? 'Non-Lab session',
                    'dept' => $department,
                    'yearLevel' => $yearLevel,
                    'block' => $block,
                    'section' => trim($row['section'] ?? '')
                ];
            }
        }
        
        return $transformedData;
    }

    private function synchronizeSubjectsAcrossBlocks(array $instructorData): array
    {
        // CONSERVATIVE APPROACH: Only ensure subjects have proper block structure
        // without aggressive duplication that could interfere with conflict-free algorithm
        
        $synchronizedData = [];
        $processedKeys = [];
        $subjectTracker = []; // Track subjects by courseCode|yearLevel

        // First pass: collect all unique subjects and their blocks
        foreach ($instructorData as $entry) {
            $subjectKey = $entry['courseCode'] . '|' . $entry['yearLevel'];
            $block = $entry['block'] ?? 'A';
            
            if (!isset($subjectTracker[$subjectKey])) {
                $subjectTracker[$subjectKey] = [
                    'subject' => $entry,
                    'blocks' => [],
                    'processed' => false
                ];
            }
            
            if (!in_array($block, $subjectTracker[$subjectKey]['blocks'])) {
                $subjectTracker[$subjectKey]['blocks'][] = $block;
            }
        }

        // Second pass: ensure each subject exists across ALL blocks found for its year level (A/B/C/...)
        // Build all blocks per year level first
        $allBlocksPerYear = [];
        foreach ($subjectTracker as $subjectKey => $dataTmp) {
            $yl = $dataTmp['subject']['yearLevel'] ?? '1st Year';
            foreach ($dataTmp['blocks'] as $bTmp) {
                if (!isset($allBlocksPerYear[$yl])) { $allBlocksPerYear[$yl] = []; }
                if (!in_array($bTmp, $allBlocksPerYear[$yl])) { $allBlocksPerYear[$yl][] = $bTmp; }
            }
        }
        foreach ($allBlocksPerYear as $yl => $blocksList) { sort($allBlocksPerYear[$yl]); }

        foreach ($subjectTracker as $subjectKey => $data) {
            $entry = $data['subject'];
            $blocks = $data['blocks'];
            $yearLevel = $entry['yearLevel'] ?? '1st Year';
            
            // Add any missing blocks based on all blocks present for this year level
            $expectedBlocks = $allBlocksPerYear[$yearLevel] ?? ['A'];
            foreach ($expectedBlocks as $expectedBlock) {
                if (!in_array($expectedBlock, $blocks)) {
                    $newEntry = $entry;
                    $newEntry['block'] = $expectedBlock;
                    $newEntry['section'] = trim($entry['dept'] ?? 'General') . '-' . trim($yearLevel) . ' ' . $expectedBlock;
                    Log::info("Adding missing block {$expectedBlock} for subject {$entry['courseCode']} ({$yearLevel}) - multi-block sync");
                    $synchronizedData[] = $newEntry;
                    $blocks[] = $expectedBlock;
                }
            }
            
            // Add all existing blocks
            foreach ($blocks as $block) {
                $entryCopy = $entry;
                $entryCopy['block'] = $block;
                $entryCopy['section'] = trim($entry['dept'] ?? 'General') . '-' . 
                                      trim($yearLevel) . ' ' . $block;
                
                $key = $entry['courseCode'] . '|' . $yearLevel . '|' . $block;
                
                if (!isset($processedKeys[$key])) {
                    $processedKeys[$key] = true;
                    $synchronizedData[] = $entryCopy;
                }
            }
        }

        // Validation: Log summary of processed subjects
        $subjectSummary = [];
        foreach ($subjectTracker as $subjectKey => $data) {
            $subjectSummary[$subjectKey] = count($data['blocks']);
        }
        
        Log::info("Subject synchronization completed (multi-block sync)", [
            'original_count' => count($instructorData),
            'synchronized_count' => count($synchronizedData),
            'unique_subjects' => count($subjectTracker),
            'blocks_per_year' => $allBlocksPerYear,
            'subject_summary' => $subjectSummary
        ]);

        return $synchronizedData;
    }
    
    /**
     * Determine if a subject should have both A and B blocks based on patterns
     */
    private function shouldSubjectHaveBothBlocks(string $subjectKey, array $subjectTracker): bool
    {
        // Check if other subjects in the same year level have both blocks
        $yearLevel = explode('|', $subjectKey)[1];
        $subjectsInSameYear = array_filter($subjectTracker, function($key) use ($yearLevel) {
            return strpos($key, "|{$yearLevel}") !== false;
        }, ARRAY_FILTER_USE_KEY);
        
        // If more than 50% of subjects in the same year have both blocks, this one should too
        $subjectsWithBothBlocks = 0;
        $totalSubjectsInYear = count($subjectsInSameYear);
        
        foreach ($subjectsInSameYear as $data) {
            if (count($data['blocks']) >= 2) {
                $subjectsWithBothBlocks++;
            }
        }
        
        // With parity enforced universally, this helper is no longer used for gating
        return true;
    }
    
    /**
     * Validate that all input subjects have been scheduled
     */
    private function validateSubjectCompleteness(array $inputData, array $scheduledData): array
    {
        $validationResults = [
            'missing_subjects' => [],
            'duplicate_subjects' => [],
            'total_input' => count($inputData),
            'total_scheduled' => count($scheduledData),
            'is_complete' => true
        ];
        
        // Create tracking arrays
        $inputSubjects = [];
        $scheduledSubjects = [];
        $normalize = function($code, $year, $block, $dept = null, $instructor = null) {
            $c = strtoupper(trim((string)$code));
            $y = trim((string)$year);
            $b = strtoupper(trim((string)($block === '' ? 'A' : $block)));
            $d = $dept !== null ? strtoupper(trim((string)$dept)) : '';
            $i = $instructor !== null ? strtoupper(trim((string)$instructor)) : '';
            return $d . '|' . $c . '|' . $y . '|' . $b . '|' . $i;
        };
        
        // Track input subjects
        foreach ($inputData as $entry) {
            $key = $normalize($entry['courseCode'] ?? '', $entry['yearLevel'] ?? '', $entry['block'] ?? 'A', $entry['dept'] ?? null, $entry['name'] ?? ($entry['instructor'] ?? null));
            $inputSubjects[$key] = $entry;
        }
        
        // Track scheduled subjects
        foreach ($scheduledData as $schedule) {
            $key = $normalize($schedule['subject_code'] ?? '', $schedule['year_level'] ?? '', $schedule['block'] ?? 'A', $schedule['dept'] ?? null, $schedule['instructor'] ?? ($schedule['instructor_name'] ?? null));
            if (isset($scheduledSubjects[$key])) {
                $validationResults['duplicate_subjects'][] = $key;
                Log::warning("Duplicate scheduled subject detected (by subject): {$key}");
            }
            $scheduledSubjects[$key] = $schedule;
        }
        
        // Find missing subjects
        foreach ($inputSubjects as $key => $inputSubject) {
            if (!isset($scheduledSubjects[$key])) {
                $validationResults['missing_subjects'][] = $key;
                Log::error("Missing scheduled subject (by subject): {$key}");
            }
        }
        
        // Check completeness
        if (!empty($validationResults['missing_subjects']) || !empty($validationResults['duplicate_subjects'])) {
            $validationResults['is_complete'] = false;
        }
        
        Log::info("Subject validation completed (subject-level)", $validationResults);
        return $validationResults;
    }

    /**
     * Analyze the section structure from the uploaded data
     */
    private function analyzeSectionStructure(array $instructorData): array
    {
        $sectionAnalysis = [
            'all_blocks' => [],
            'blocks_per_year' => [],
            'blocks_per_course' => []
        ];
        
        foreach ($instructorData as $entry) {
            $block = $entry['block'] ?? 'A';
            $yearLevel = $entry['yearLevel'] ?? '1st Year';
            $courseKey = $entry['courseCode'] . '|' . $yearLevel;
            
            // Collect all unique blocks
            if (!in_array($block, $sectionAnalysis['all_blocks'])) {
                $sectionAnalysis['all_blocks'][] = $block;
            }
            
            // Track blocks per year level
            if (!isset($sectionAnalysis['blocks_per_year'][$yearLevel])) {
                $sectionAnalysis['blocks_per_year'][$yearLevel] = [];
            }
            if (!in_array($block, $sectionAnalysis['blocks_per_year'][$yearLevel])) {
                $sectionAnalysis['blocks_per_year'][$yearLevel][] = $block;
            }
            
            // Track blocks per course
            if (!isset($sectionAnalysis['blocks_per_course'][$courseKey])) {
                $sectionAnalysis['blocks_per_course'][$courseKey] = [];
            }
            if (!in_array($block, $sectionAnalysis['blocks_per_course'][$courseKey])) {
                $sectionAnalysis['blocks_per_course'][$courseKey][] = $block;
            }
        }
        
        // Sort blocks for consistency
        foreach ($sectionAnalysis['blocks_per_year'] as $year => $blocks) {
            sort($sectionAnalysis['blocks_per_year'][$year]);
        }
        foreach ($sectionAnalysis['blocks_per_course'] as $course => $blocks) {
            sort($sectionAnalysis['blocks_per_course'][$course]);
        }
        
        Log::info("Section structure analysis", $sectionAnalysis);
        return $sectionAnalysis;
    }
    
    /**
     * Get expected sections for a course based on analysis
     */
    private function getExpectedSections(array $entry, array $sectionAnalysis): array
    {
        $yearLevel = $entry['yearLevel'] ?? '1st Year';
        $courseKey = $entry['courseCode'] . '|' . $yearLevel;
        
        // If this specific course already has multiple sections, use those
        if (isset($sectionAnalysis['blocks_per_course'][$courseKey]) && 
            count($sectionAnalysis['blocks_per_course'][$courseKey]) > 1) {
            return $sectionAnalysis['blocks_per_course'][$courseKey];
        }
        
        // If this year level has multiple sections, use those
        if (isset($sectionAnalysis['blocks_per_year'][$yearLevel]) && 
            count($sectionAnalysis['blocks_per_year'][$yearLevel]) > 1) {
            return $sectionAnalysis['blocks_per_year'][$yearLevel];
        }
        
        // Default: if we see A and B in the data, create both; otherwise use what's provided
        if (in_array('A', $sectionAnalysis['all_blocks']) && in_array('B', $sectionAnalysis['all_blocks'])) {
            return ['A', 'B'];
        }
        
        // Otherwise, just use the original block
        return [$entry['block'] ?? 'A'];
    }

    /**
     * TRANSLATOR: Call Python OR-Tools Algorithm
     * Laravel only translates data and saves results - Python does all scheduling logic
     */
    private function runOrtoolsAlgorithm(array $instructorData, array $rooms, int $groupId, string $sessionOption = 'A'): array
    {
        try {
            Log::info("Starting OR-Tools algorithm...");
            $roomUsage = [];
            $roomDayUsage = [];
            $rrIndex = 0; // round-robin pointer to spread when multiple rooms are free

            // Build quick lookup for session type per course+instructor
            $sessionTypeByKey = [];
            $labCount = 0; $nonLabCount = 0;
            foreach ($instructorData as $row) {
                $key = strtoupper(trim(($row['courseCode'] ?? '') . '|' . ($row['subject'] ?? '') . '|' . ($row['name'] ?? '')));
                $st = $row['sessionType'] ?? 'Non-Lab session';
                $sessionTypeByKey[$key] = $st;
                if (strcasecmp($st, 'Lab session') === 0) { $labCount++; } else { $nonLabCount++; }
            }
            \Log::info('SessionType distribution (ORT)', ['lab' => $labCount, 'non_lab' => $nonLabCount]);
            
            $payload = [
                'instructorData' => $instructorData,
                'rooms' => $rooms,
                'timeLimitSec' => 45, // Increased timeout for complex problems
            ];

            // Invoke Python OR-Tools script
            $python = base_path('venv/Scripts/python.exe');
            if (!file_exists($python)) {
                $python = 'python';
            }

            // Run as module to support package-relative imports
            $process = new \Symfony\Component\Process\Process([$python, '-m', 'PythonAlgo.Scheduler']);
            $process->setInput(json_encode($payload));
            $process->setTimeout(60); // Increased timeout to allow algorithm to complete
            $process->setWorkingDirectory(base_path()); // Run from project root
            
            // Set environment variables to limit output
            $process->setEnv(['PYTHONUNBUFFERED' => '1', 'PYTHONIOENCODING' => 'utf-8']);
            
            $process->run();

            if (!$process->isSuccessful()) {
                $errorOutput = $process->getErrorOutput();
                $exitCode = $process->getExitCode();
                Log::warning("OR-Tools process failed with exit code {$exitCode}: {$errorOutput}");
                return ['success' => false, 'message' => "OR-Tools process failed: {$errorOutput}"];
            }

            $rawOutput = $process->getOutput();
            
            // Check output size to prevent pipe overflow
            if (strlen($rawOutput) > 1000000) { // 1MB limit
                Log::warning("OR-Tools algorithm output too large: " . strlen($rawOutput) . " bytes");
                return [
                    'success' => false,
                    'message' => 'OR-Tools algorithm output too large',
                    'schedules' => [],
                    'errors' => ['Output too large']
                ];
            }
            
            $output = json_decode($rawOutput, true);
            if (!is_array($output) || !$output['success']) {
                Log::warning('OR-Tools returned failure: ' . json_encode($output));
                return ['success' => false, 'message' => 'OR-Tools failed to find solution'];
            }

            // Process the OR-Tools results
            $schedules = $output['schedules'] ?? [];
            // Don't expand schedules to both blocks - let Python handle the correct assignments
            $errors = $output['errors'] ?? [];

            Log::info("OR-Tools completed with " . count($schedules) . " schedules");
            Log::info("Errors: " . json_encode($errors));

            // Save schedules to database
            $savedSchedules = [];
            foreach ($schedules as $schedule) {
                // Normalize potential combined section for OR-Tools path as well
                $deptForSection = $schedule['dept'] ?? $department; // Use the main department instead of 'General'
                $sectionRaw = $schedule['section'] ?? '';
                [$parsedYear, $parsedBlock] = $this->parseSectionParts($sectionRaw);
                $yearLevelOut = $schedule['year_level'] ?? $parsedYear;
                $blockOut = $schedule['block'] ?? $parsedBlock;
                
                // Use year level from schedule data (no override)
                
                $entry = $this->createEntryAndMeeting(
                    $groupId,
                    $schedule['room_id'],
                    $schedule['instructor'],
                    $schedule['subject_code'],
                    $schedule['subject_description'],
                    $schedule['unit'],
                    $schedule['day'],
                    $schedule['start_time'],
                    $schedule['end_time'],
                    $deptForSection,
                    $yearLevelOut,
                    $blockOut
                );
                
                if ($entry) {
                    $savedSchedules[] = $entry;
                }
            }

            return [
                'success' => true,
                'schedules' => $savedSchedules,
                'errors' => $errors,
                'algorithm' => 'ortools'
            ];

        } catch (\Throwable $e) {
            Log::error('OR-Tools algorithm error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'OR-Tools algorithm error: ' . $e->getMessage()];
        }
    }

    /**
     * TRANSLATOR: Call Python Genetic Algorithm
     * Laravel only translates data and saves results - Python does all scheduling logic
     */
    private function runGeneticAlgorithm(array $instructorData, array $rooms, int $groupId): array
    {
        try {
            Log::info("Starting genetic algorithm...");
            $roomUsage = [];
            $roomDayUsage = [];
            $rrIndex = 0; // round-robin pointer to spread when multiple rooms are free

            // Build quick lookup for session type per course+instructor
            $sessionTypeByKey = [];
            $labCount = 0; $nonLabCount = 0;
            foreach ($instructorData as $row) {
                $key = strtoupper(trim(($row['courseCode'] ?? '') . '|' . ($row['subject'] ?? '') . '|' . ($row['name'] ?? '')));
                $st = $row['sessionType'] ?? 'Non-Lab session';
                $sessionTypeByKey[$key] = $st;
                if (strcasecmp($st, 'Lab session') === 0) { $labCount++; } else { $nonLabCount++; }
            }
            \Log::info('SessionType distribution (GA)', ['lab' => $labCount, 'non_lab' => $nonLabCount]);
            
            $payload = [
                'instructorData' => $instructorData,
                'rooms' => $rooms,
                'timeLimitSec' => 45, // Increased timeout for complex problems
            ];

            // Invoke Python genetic algorithm script
            $python = base_path('venv/Scripts/python.exe');
            if (!file_exists($python)) {
                $python = 'python';
            }

            // Run as module to support package-relative imports
            $process = new \Symfony\Component\Process\Process([$python, '-m', 'PythonAlgo.GeneticScheduler']);
            $process->setInput(json_encode($payload));
            $process->setTimeout(60); // Increased timeout to allow algorithm to complete
            $process->setWorkingDirectory(base_path()); // Run from project root
            
            // Set environment variables to limit output
            $process->setEnv(['PYTHONUNBUFFERED' => '1', 'PYTHONIOENCODING' => 'utf-8']);
            
            $process->run();

            if (!$process->isSuccessful()) {
                $errorOutput = $process->getErrorOutput();
                $exitCode = $process->getExitCode();
                Log::warning("Genetic algorithm process failed with exit code {$exitCode}: {$errorOutput}");
                
                // Check if it's a timeout error
                if (strpos($errorOutput, 'timeout') !== false || strpos($errorOutput, 'Maximum execution time') !== false) {
                    return ['success' => false, 'message' => "Genetic algorithm timed out after 60 seconds"];
                }
                
                return ['success' => false, 'message' => "Genetic algorithm failed: {$errorOutput}"];
            }

            $rawOutput = $process->getOutput();
            
            // Check output size to prevent pipe overflow
            if (strlen($rawOutput) > 1000000) { // 1MB limit
                Log::warning("Genetic algorithm output too large: " . strlen($rawOutput) . " bytes");
                return [
                    'success' => false,
                    'message' => 'Genetic algorithm output too large',
                    'schedules' => [],
                    'errors' => ['Output too large']
                ];
            }
            
            $output = json_decode($rawOutput, true);
            if (!is_array($output)) {
                Log::warning('Genetic algorithm returned invalid JSON: ' . $rawOutput);
                return ['success' => false, 'message' => 'Genetic algorithm returned invalid response'];
            }
            
            if (!$output || !isset($output['success']) || !$output['success']) {
                Log::error('Genetic algorithm failed: ' . ($output['message'] ?? 'Unknown error'));
                return ['success' => false, 'message' => 'Genetic algorithm failed: ' . ($output['message'] ?? 'Unknown error')];
            }

            // Process the Genetic algorithm results
            $schedules = $output['schedules'] ?? [];
            // Don't expand schedules to both blocks - let Python handle the correct assignments
            $errors = $output['errors'] ?? [];

            Log::info("Genetic algorithm completed with " . count($schedules) . " schedules");
            Log::info("Errors: " . json_encode($errors));

            // Save schedules to database
            $savedSchedules = [];
            foreach ($schedules as $schedule) {
                // Normalize potential combined section for Genetic path as well
                $deptForSection = $schedule['dept'] ?? $department; // Use the main department instead of 'General'
                $sectionRaw = $schedule['section'] ?? '';
                [$parsedYear, $parsedBlock] = $this->parseSectionParts($sectionRaw);
                $yearLevelOut = $schedule['year_level'] ?? $parsedYear;
                $blockOut = $schedule['block'] ?? $parsedBlock;
                
                // Use year level from schedule data (no override)

                $entry = $this->createEntryAndMeeting(
                    $groupId,
                    $schedule['room_id'],
                    $schedule['instructor'],
                    $schedule['subject_code'],
                    $schedule['subject_description'],
                    $schedule['unit'],
                    $schedule['day'],
                    $schedule['start_time'],
                    $schedule['end_time'],
                    $deptForSection,
                    $yearLevelOut,
                    $blockOut
                );

                if ($entry) {
                    $savedSchedules[] = $entry;
                }
            }

            return [
                'success' => true,
                'schedules' => $savedSchedules,
                'errors' => $errors,
                'algorithm' => 'genetic'
            ];

        } catch (\Exception $e) {
            Log::error('Genetic algorithm error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Genetic algorithm error: ' . $e->getMessage()];
        }
    }

    /**
     * MAIN SCHEDULER: Run PHP Constraint Satisfaction Algorithm
     * Primary scheduling algorithm - handles all logic internally without external processes
     */
    private function runPhpScheduler(array $instructorData, array $rooms, int $groupId, array $filterPreferences = [], string $department = 'BSBA'): array
    {
        try {
            Log::info("Starting PHP constraint satisfaction scheduler...");
            
            // Synchronize subjects across blocks to ensure both A and B sections are created
            $synchronizedData = $this->synchronizeSubjectsAcrossBlocks($instructorData);
            Log::info("Synchronized course data: " . count($synchronizedData) . " entries (original: " . count($instructorData) . ")");
            
            // Determine education level based on department (college departments = "College")
            // This ensures reference schedules from basic education (SHS/HS) are loaded for conflict prevention
            $educationLevel = 'College'; // Default to College for college departments
            
            // Create PHP scheduler instance with department and education level
            // Education level is used to filter reference schedules - when generating College schedules,
            // we load SHS/HS reference schedules to prevent conflicts with shared classrooms and instructors
            $phpScheduler = new PhpScheduler($synchronizedData, $rooms, $department, $educationLevel);
            // Provide group context so scheduler can guard with DB-level conflicts
            $phpScheduler->setGroupContext($groupId);

            // Deterministic seeding: ensure identical input yields identical schedules across runs
            // Allow overriding via request 'seed'; else derive from synchronized data
            try {
                $reqSeed = (int)($request->input('seed') ?? 0);
                $seed = $reqSeed !== 0 ? $reqSeed : (int) sprintf('%u', crc32(json_encode($synchronizedData)));
                // Seed PHP's RNG used by shuffle()/rand() to stabilize candidate order
                mt_srand($seed);
            } catch (\Throwable $t) {
                // No-op on seeding error; proceed non-deterministically
            }
            
            // Apply filter preferences if provided
            if (!empty($filterPreferences)) {
                Log::info("Applying filter preferences to PHP scheduler", $filterPreferences);
                $phpScheduler->setFilterPreferences($filterPreferences);
            }
            
            // Run the incremental scheduler with tighter timeout to meet runtime target
            $result = $phpScheduler->solveIncremental(35);
            
            // Do NOT fallback to legacy solver; it can introduce conflicts. Keep incremental-only.
            if (!$result['success'] || empty($result['schedules'])) {
                Log::error("Incremental scheduler failed and legacy fallback is disabled to avoid conflicts.");
                return [
                    'success' => false,
                    'schedules' => [],
                    'message' => 'Incremental scheduler failed; no conflict-free schedule produced.'
                ];
            }
            
            // Validate subject completeness (but don't force schedule to avoid conflicts)
            if ($result['success'] && !empty($result['schedules'])) {
                $validation = $this->validateSubjectCompleteness($synchronizedData, $result['schedules']);
                
                // Log validation results but don't force schedule missing subjects
                // This preserves the conflict-free nature of the algorithm
                if (!$validation['is_complete']) {
                    Log::warning("Some subjects were not scheduled, but preserving conflict-free algorithm", $validation);
                    
                    // Detailed analysis of missing subjects: log reasons why each couldn't be placed
                    try {
                        $this->analyzeMissingSubjectsReasons(
                            $validation['missing_subjects'] ?? [],
                            $synchronizedData,
                            $result['schedules'],
                            $rooms,
                            $department
                        );
                    } catch (\Throwable $t) {
                        Log::error("Missing-subjects reason analysis failed: " . $t->getMessage());
                    }
                    
                    // Check if any missing subjects belong to part-time instructors
                    $partTimeMissing = [];
                    foreach ($validation['missing_subjects'] as $missing) {
                        if (isset($missing['employment_type']) && $missing['employment_type'] === 'PART-TIME') {
                            $partTimeMissing[] = $missing;
                        }
                    }
                    
                    if (!empty($partTimeMissing)) {
                        Log::error("CRITICAL: Part-time instructors have unscheduled courses!", $partTimeMissing);
                        Log::error("This may be due to insufficient evening slots or instructor overload");
                    }
                } else {
                    Log::info("All subjects successfully scheduled - validation passed");
                }
                
                $result['validation'] = $validation;
            }
            
            if ($result['success'] && !empty($result['schedules'])) {
                // Since PhpScheduler already validated with ResourceTracker, we can trust the results
                // Skip redundant validation to avoid false positives
                Log::info("PHP scheduler completed successfully with " . count($result['schedules']) . " conflict-free schedules");
                
                // CRITICAL: Check for overlaps after scheduling
                // Normalize instructor field name for overlap detection (PhpScheduler uses 'instructor', we need 'instructor_name')
                $normalizedSchedules = array_map(function($schedule) {
                    if (!isset($schedule['instructor_name']) && isset($schedule['instructor'])) {
                        $schedule['instructor_name'] = $schedule['instructor'];
                    }
                    return $schedule;
                }, $result['schedules']);
                
                $overlaps = $this->detectScheduleOverlaps($normalizedSchedules);
                if (!empty($overlaps)) {
                    Log::warning("Found " . count($overlaps) . " schedule overlaps in incremental scheduler results:");
                    foreach ($overlaps as $overlap) {
                        $schedule1 = $overlap['schedule1'];
                        $schedule2 = $overlap['schedule2'];
                        Log::warning("Overlap: {$schedule1['subject_code']} vs {$schedule2['subject_code']} for section " . ($schedule1['year_level'] ?? '') . ' ' . ($schedule1['block'] ?? '') . " on " . ($schedule1['day'] ?? '') . " {$schedule1['start_time']}-{$schedule1['end_time']}");
                    }
                } else {
                    Log::info("No schedule overlaps detected in incremental scheduler results");
                }
                
                // Enrich schedules with IDs for reliable conflict checks
                $enriched = $this->enrichSchedulesWithIds($result['schedules']);
                // Pre-filter to drop schedules that would create overlaps before saving
                $filtered = $this->filterSchedulesByConflicts($enriched);
                // Save schedules to database - group by course to avoid duplicates
                $savedSchedules = $this->saveSchedulesToDatabase($filtered, $groupId);
                // Post-process to resolve any residual section/day overlaps safely
                $this->resolveSectionConflictsForGroup($groupId);
                
                Log::info("PHP scheduler completed: " . count($savedSchedules) . " schedules saved to database");
                
                // Detailed analysis of which courses were scheduled
                $this->analyzeScheduledCourses($synchronizedData, $savedSchedules);
                
                return [
                    'success' => true,
                    'message' => $result['message'],
                    'schedules' => $savedSchedules,
                    'total_conflicts' => $result['total_conflicts'] ?? 0,
                    'algorithm' => 'php_constraint_satisfaction',
                    // Surface unscheduled subjects to the client for visibility
                    'unscheduled' => $result['validation']['missing_subjects'] ?? [],
                    'stats' => [
                        'total_courses' => count($instructorData),
                        'total_scheduled' => count($savedSchedules),
                        'success_rate' => round((count($savedSchedules) / count($instructorData)) * 100, 2)
                    ]
                ];
            } else {
                // If primary solver fails, try genetic algorithm fallback
                Log::info("PHP constraint solver failed, trying PHP genetic algorithm...");
                $geneticResult = $phpScheduler->solveWithGeneticAlgorithm(30);
                
                if ($geneticResult['success'] && !empty($geneticResult['schedules'])) {
                    // Enrich schedules with IDs for reliable conflict checks
                    $enrichedGa = $this->enrichSchedulesWithIds($geneticResult['schedules']);
                    // Pre-filter GA output for conflicts before saving
                    $filteredGa = $this->filterSchedulesByConflicts($enrichedGa);
                    // Save genetic algorithm results - group by course to avoid duplicates
                    $savedSchedules = $this->saveSchedulesToDatabase($filteredGa, $groupId);
                    // Post-process to resolve any residual section/day overlaps safely
                    $this->resolveSectionConflictsForGroup($groupId);
                    
                    Log::info("PHP genetic algorithm completed: " . count($savedSchedules) . " schedules saved to database");
                    
                    return [
                        'success' => true,
                        'message' => $geneticResult['message'],
                        'schedules' => $savedSchedules,
                        'total_conflicts' => $geneticResult['total_conflicts'] ?? 0,
                        'algorithm' => 'php_genetic_algorithm',
                        'stats' => [
                            'total_courses' => count($instructorData),
                            'total_scheduled' => count($savedSchedules),
                            'success_rate' => round((count($savedSchedules) / count($instructorData)) * 100, 2)
                        ]
                    ];
                }
                
                return [
                    'success' => false,
                    'message' => 'PHP scheduler failed: ' . ($result['message'] ?? 'Unknown error'),
                    'schedules' => [],
                    'errors' => $result['errors'] ?? ['PHP scheduler failed']
                ];
            }
            
        } catch (\Exception $e) {
            Log::error('PHP scheduler error: ' . $e->getMessage());
            return [
                'success' => false, 
                'message' => 'PHP scheduler error: ' . $e->getMessage(),
                'schedules' => [],
                'errors' => [$e->getMessage()]
            ];
        }
    }

    /**
     * Save a single schedule entry to the database
     */
    private function saveScheduleToDatabase(array $schedule, int $groupId): ?array
    {
        try {
            // Get units for processing
            $units = $schedule['unit'] ?? 0;
            
            // Resolve instructor ID
            $instructorId = $this->resolveInstructorId($schedule['instructor'], $schedule['employment_type'] ?? 'FULL-TIME');
            
            // Resolve subject ID with proper units handling
            $subjectId = $this->resolveSubjectId(
                $schedule['subject_code'],
                $schedule['subject_description'] ?? null,
                $units
            );
            
            // Resolve section ID
            $sectionId = $this->resolveSectionIdBySection(
                $schedule['dept'] ?? 'General',
                trim($schedule['year_level'] . ' ' . $schedule['block'])
            );
            
            // Try to find existing schedule entry or create new one (instructor now on meetings)
            $entry = ScheduleEntry::firstOrCreate([
                'group_id' => $groupId,
                'subject_id' => $subjectId,
                'section_id' => $sectionId,
            ], [
                'status' => 'confirmed'
            ]);
            
            // Create schedule meetings - expand combined days into individual rows
            $meetingType = $this->determineMeetingType($schedule);
            $expanded = \App\Services\DayScheduler::expandMeetings([
                'day' => $schedule['day'] ?? '',
                'start_time' => $schedule['start_time'],
                'end_time' => $schedule['end_time'],
                'room_id' => $schedule['room_id'],
                'meeting_type' => $meetingType
            ]);

            // Lock room across joint sessions for this entry
            $lockedRoomId = null;
            foreach ($expanded as $m) {
                $dayNorm = DayScheduler::normalizeDay($m['day']);

                // Prevent inserting overlapping meetings: check DB first
                $candidateRoom = (int)($lockedRoomId !== null ? $lockedRoomId : $m['room_id']);
                if ($this->hasDbOverlap($groupId, $instructorId, $candidateRoom, $sectionId, $dayNorm, $m['start_time'], $m['end_time'], $subjectId)) {
                    // Try to keep the same time window; move to another day/room if possible
                    $alt = $this->findSameTimeAlternative($groupId, $instructorId, $sectionId, $dayNorm, $m['start_time'], $m['end_time'], $candidateRoom, $subjectId);
                    if (is_array($alt)) {
                        [$altDay, $altStart, $altEnd, $altRoom] = $alt;
                        $dayNorm = $altDay;
                        $m['start_time'] = $altStart;
                        $m['end_time'] = $altEnd;
                        $m['room_id'] = $lockedRoomId !== null ? $lockedRoomId : $altRoom;
                    } else {
                        // Try next available slot with same duration
                        $next = $this->findNextAvailableSlot($groupId, $instructorId, $sectionId, $candidateRoom, $m['start_time'], $m['end_time'], $subjectId);
                        if (is_array($next)) {
                            [$altDay, $altStart, $altEnd, $altRoom] = $next;
                            $dayNorm = $altDay;
                            $m['start_time'] = $altStart;
                            $m['end_time'] = $altEnd;
                            $m['room_id'] = $lockedRoomId !== null ? $lockedRoomId : $altRoom;
                        } else {
                            // No conflict-free slot available; skip this meeting
                            \Log::warning("SKIP MEETING (no-alt): {$schedule['subject_code']} {$schedule['year_level']} {$schedule['block']} {$dayNorm} {$m['start_time']}-{$m['end_time']}");
                            continue;
                        }
                    }
                }

                ScheduleMeeting::create([
                    'entry_id' => $entry->entry_id,
                    'instructor_id' => $instructorId,
                    'day' => $dayNorm,
                    'start_time' => $m['start_time'],
                    'end_time' => $m['end_time'],
                    'room_id' => $m['room_id'],
                    'meeting_type' => $m['meeting_type']
                ]);

                // Lock room after first successful create
                if ($lockedRoomId === null) { $lockedRoomId = (int)$m['room_id']; }
            }
            
            return [
                'entry_id' => $entry->entry_id,
                'instructor_name' => $schedule['instructor'],
                'subject_code' => $schedule['subject_code'],
                'subject_description' => $schedule['subject_description'] ?? '',
                'units' => $units, // Include units in return array
                'day' => $schedule['day'],
                'start_time' => $schedule['start_time'],
                'end_time' => $schedule['end_time'],
                'room_id' => $schedule['room_id'],
                'year_level' => $schedule['year_level'],
                'block' => $schedule['block'],
                'section' => $schedule['section'] ?? '',
                'employment_type' => $this->normalizeEmploymentType($schedule['employment_type'] ?? 'FULL-TIME')
            ];
            
        } catch (\Exception $e) {
            Log::error("Failed to save schedule to database: " . $e->getMessage());
            return null;
        }
    }

    private function determineMeetingType(array $schedule): string
    {
        // Simplified meeting type determination without excessive logging
        $sessionType = strtolower($schedule['sessionType'] ?? 'non-lab session');
        
        // Check the explicit sessionType from frontend
        if ($sessionType === 'lab session') {
            return 'lab';
        }
        
        return 'lecture';
    }

    /**
     * Save schedules to database - group by course and create one entry with multiple meetings
     */
    private function saveSchedulesToDatabase(array $schedules, int $groupId): array
    {
        Log::debug("saveSchedulesToDatabase: Received " . count($schedules) . " schedule entries to save");
        
        // Harmonize room assignment across joint sessions (same subject, instructor, and time window)
        // Ensures all sections sharing a joint meeting use the same room
        if (!empty($schedules)) {
            $jointRoomMap = [];
            // First pass: determine canonical room per joint key
            foreach ($schedules as $idx => $s) {
                $subj = $s['subject_code'] ?? '';
                $inst = $s['instructor'] ?? '';
                $start = isset($s['start_time']) ? (strlen($s['start_time']) === 5 ? $s['start_time'] . ':00' : $s['start_time']) : '';
                $end = isset($s['end_time']) ? (strlen($s['end_time']) === 5 ? $s['end_time'] . ':00' : $s['end_time']) : '';
                if ($subj === '' || $inst === '' || $start === '' || $end === '') { continue; }
                $key = $subj . '|' . $inst . '|' . $start . '|' . $end;
                $roomId = $s['room_id'] ?? null;
                if (!isset($jointRoomMap[$key]) && $roomId !== null) {
                    $jointRoomMap[$key] = (int)$roomId;
                }
            }
            // Second pass: rewrite room_ids to the canonical one when applicable
            foreach ($schedules as $idx => $s) {
                $subj = $s['subject_code'] ?? '';
                $inst = $s['instructor'] ?? '';
                $start = isset($s['start_time']) ? (strlen($s['start_time']) === 5 ? $s['start_time'] . ':00' : $s['start_time']) : '';
                $end = isset($s['end_time']) ? (strlen($s['end_time']) === 5 ? $s['end_time'] . ':00' : $s['end_time']) : '';
                if ($subj === '' || $inst === '' || $start === '' || $end === '') { continue; }
                $key = $subj . '|' . $inst . '|' . $start . '|' . $end;
                if (isset($jointRoomMap[$key])) {
                    $schedules[$idx]['room_id'] = $jointRoomMap[$key];
                }
            }
        }
        
        // Group schedules by course (subject_code|instructor|year_level|block)
        // Include instructor in key to differentiate same course taught by different instructors
        $groupedSchedules = [];
        foreach ($schedules as $schedule) {
            // Normalize times for consistent grouping
            $normStart = isset($schedule['start_time']) ? (strlen($schedule['start_time']) === 5 ? $schedule['start_time'] . ':00' : $schedule['start_time']) : '';
            $normEnd = isset($schedule['end_time']) ? (strlen($schedule['end_time']) === 5 ? $schedule['end_time'] . ':00' : $schedule['end_time']) : '';

            // Include time window in the grouping key so different times become separate entries
            $key = implode('|', [
                $schedule['subject_code'] ?? '',
                $schedule['instructor'] ?? '',
                $schedule['year_level'] ?? '',
                $schedule['block'] ?? '',
                $normStart,
                $normEnd
            ]);
            
            if (!isset($groupedSchedules[$key])) {
                $groupedSchedules[$key] = [
                    'schedule' => $schedule,
                    'meetings' => []
                ];
            } else {
                Log::debug("Grouping additional session for course: {$key}");
            }
            
            // Expand combined days into individual meetings
            $expanded = \App\Services\DayScheduler::expandMeetings([
                'day' => $schedule['day'] ?? '',
                'start_time' => $schedule['start_time'],
                'end_time' => $schedule['end_time'],
                'room_id' => $schedule['room_id']
            ]);

            foreach ($expanded as $m) {
                // Check for duplicate meetings
                // CRITICAL: Check if this day is already used (regardless of time)
                // Multi-session courses should be on DIFFERENT days (Mon/Sat), not same day different times
                $meetingExists = false;
                $sameDayDifferentTime = false;
                
                foreach ($groupedSchedules[$key]['meetings'] as $existingMeeting) {
                    if ($existingMeeting['day'] === $m['day']) {
                        // Day already used - check if same time or different time
                        if ($existingMeeting['start_time'] === $m['start_time'] &&
                            $existingMeeting['end_time'] === $m['end_time']) {
                            // Exact duplicate (same day + same time)
                            $meetingExists = true;
                            Log::debug("Skipping exact duplicate meeting for {$key} on {$m['day']} at {$m['start_time']}");
                        } else {
                            // Same day but different time - this violates the rule!
                            $sameDayDifferentTime = true;
                            Log::warning("BLOCKED: Attempted to add second session on same day {$m['day']} with different time {$m['start_time']} for {$key}. Existing time: {$existingMeeting['start_time']}. Sessions must be on different days!");
                        }
                        break;
                    }
                }
                
                // Only add if it's not a duplicate AND not a same-day-different-time violation
                if (!$meetingExists && !$sameDayDifferentTime) {
                    $groupedSchedules[$key]['meetings'][] = [
                        'day' => $m['day'],
                        'start_time' => $m['start_time'],
                        'end_time' => $m['end_time'],
                        'room_id' => $m['room_id']
                    ];
                }
            }
            
            // Log the meetings array for this course after processing
            if (!empty($groupedSchedules[$key]['meetings'])) {
                Log::debug("Meetings for {$key}: " . json_encode($groupedSchedules[$key]['meetings']));
            }
        }
        
        Log::info("saveSchedulesToDatabase: Grouped " . count($schedules) . " entries into " . count($groupedSchedules) . " unique courses");
        
        $savedSchedules = [];
        foreach ($groupedSchedules as $key => $group) {
            $schedule = $group['schedule'];
            $meetings = $group['meetings'];
            
            Log::debug("Saving course {$key} with " . count($meetings) . " meetings");
            
            try {
                // Resolve IDs
                $instructorId = $this->resolveInstructorId($schedule['instructor'], $schedule['employment_type'] ?? 'FULL-TIME');
                $subjectId = $this->resolveSubjectId(
                    $schedule['subject_code'],
                    $schedule['subject_description'] ?? null,
                    $schedule['unit'] ?? 0
                );
                $sectionId = $this->resolveSectionIdBySection(
                    $schedule['dept'] ?? 'General',
                    trim($schedule['year_level'] . ' ' . $schedule['block'])
                );
                
                // Create or reuse a single ScheduleEntry per (group,instructor,subject,section)
                // We do NOT split by time here; multiple time windows become separate meetings under the same entry
                $entry = ScheduleEntry::firstOrCreate(
                    [
                        'group_id' => $groupId,
                        'subject_id' => $subjectId,
                        'section_id' => $sectionId,
                    ],
                    [
                        // Initialize core attributes on first creation
                        'status' => 'confirmed'
                    ]
                );
                
                // Create multiple ScheduleMeeting records for this entry
                $meetingType = $this->determineMeetingType($schedule);
                
                // Enforce canonical time and room for joint sessions when all meetings share the same time
                $createdMeetings = 0;
                $bulkRows = [];
                DB::transaction(function () use (&$bulkRows, &$createdMeetings, $meetings, $entry, $instructorId, $meetingType, $sectionId, $subjectId, $groupId, $schedule) {
                    $now = now();
                    
                    // Check if all meetings in this batch share the same time (indicating joint sessions)
                    $canonicalTime = null;
                    $canonicalRoom = null;
                    if (count($meetings) > 1) {
                        $firstMeeting = $meetings[0];
                        $allSameTime = true;
                        $allSameRoom = true;
                        foreach ($meetings as $m) {
                            if ($m['start_time'] !== $firstMeeting['start_time'] || $m['end_time'] !== $firstMeeting['end_time']) {
                                $allSameTime = false;
                            }
                            if ($m['room_id'] !== $firstMeeting['room_id']) {
                                $allSameRoom = false;
                            }
                        }
                        // Only enforce canonical time/room for true joint sessions (same time, different days)
                        if ($allSameTime) {
                            $canonicalTime = [
                                'start' => $firstMeeting['start_time'],
                                'end' => $firstMeeting['end_time']
                            ];
                            // Use canonical room only if all meetings share the same room
                            if ($allSameRoom) {
                                $canonicalRoom = $firstMeeting['room_id'];
                            }
                            \Log::debug("Joint session detected: using canonical time {$canonicalTime['start']}-{$canonicalTime['end']} and room " . ($canonicalRoom ?? 'varies') . " for entry {$entry->entry_id}");
                        }
                    }
                    
                    foreach ($meetings as $meeting) {
                        $dayNorm = DayScheduler::normalizeDay($meeting['day']);
                        $targetDay = $dayNorm;
                        
                        // ENFORCE CANONICAL TIME for joint sessions only
                        if ($canonicalTime !== null) {
                            $targetStart = $canonicalTime['start'];
                            $targetEnd = $canonicalTime['end'];
                            // Use canonical room if all meetings share it, otherwise use per-meeting room
                            $targetRoom = ($canonicalRoom !== null) ? $canonicalRoom : (int)$meeting['room_id'];
                        } else {
                            // Multi-session course or single meeting - use the scheduler's suggested time
                            $targetStart = $meeting['start_time'];
                            $targetEnd = $meeting['end_time'];
                            $targetRoom = (int)$meeting['room_id'];
                        }

                        // Guard against conflicts; adjust day/room only (keep same time)
                        if ($this->hasDbOverlap($groupId, $instructorId, $targetRoom, $sectionId, $targetDay, $targetStart, $targetEnd, $subjectId)) {
                            $alt = $this->findSameTimeAlternative($groupId, $instructorId, $sectionId, $targetDay, $targetStart, $targetEnd, $targetRoom, $subjectId);
                            if (is_array($alt)) {
                                [$targetDay, $targetStart, $targetEnd, $targetRoom] = $alt;
                            } else {
                                \Log::warning("SKIP MEETING: No conflict-free slot for {$schedule['subject_code']} ({$schedule['year_level']} {$schedule['block']}) on {$dayNorm} {$targetStart}-{$targetEnd}");
                                continue;
                            }
                        }

                        $bulkRows[] = [
                            'entry_id' => $entry->entry_id,
                            'instructor_id' => $instructorId,
                            'day' => $targetDay,
                            'start_time' => $targetStart,
                            'end_time' => $targetEnd,
                            'room_id' => $targetRoom,
                            'meeting_type' => $meetingType,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $createdMeetings++;
                    }

                    if (!empty($bulkRows)) {
                        // Use upsert to honor unique (entry_id, day, start_time, end_time) without throwing
                        \Illuminate\Support\Facades\DB::table('schedule_meetings')->upsert(
                            $bulkRows,
                            ['entry_id', 'day', 'start_time', 'end_time'],
                            ['room_id', 'instructor_id', 'meeting_type', 'updated_at']
                        );
                    }
                });
                // Remove the entry if we couldn't create any meetings for it
                if ($createdMeetings === 0) {
                    try { $entry->delete(); } catch (\Throwable $t) { /* ignore */ }
                    continue;
                }
                
                $savedSchedules[] = [
                    'entry_id' => $entry->entry_id,
                    'instructor_name' => $schedule['instructor'],
                    'subject_code' => $schedule['subject_code'],
                    'subject_description' => $schedule['subject_description'] ?? '',
                    'units' => $schedule['unit'] ?? 0,
                    'year_level' => $schedule['year_level'],
                    'block' => $schedule['block'],
                    'section' => $schedule['section'] ?? '',
                    'employment_type' => $this->normalizeEmploymentType($schedule['employment_type'] ?? 'FULL-TIME'),
                    'meetings_count' => count($meetings)
                ];
                
                Log::info("Created schedule entry for {$schedule['subject_code']} ({$schedule['year_level']} {$schedule['block']}) with " . count($meetings) . " meeting(s)");
                
            } catch (\Exception $e) {
                Log::error("Failed to save schedule for {$schedule['subject_code']}: " . $e->getMessage());
            }
        }
        
        return $savedSchedules;
    }

    /**
     * Validate schedules before saving to database
     */
    private function validateSchedulesBeforeSave(array $schedules): array
    {
        $conflicts = [];
        $resourceTracker = new ResourceTracker();
        
        foreach ($schedules as $schedule) {
            $instructorName = $schedule['instructor'] ?? '';
            $roomId = $schedule['room_id'] ?? 0;
            $sectionName = $schedule['section'] ?? '';
            $day = $schedule['day'] ?? '';
            $startTime = $schedule['start_time'] ?? '';
            $endTime = $schedule['end_time'] ?? '';
            
            $scheduleConflicts = $resourceTracker->validateBeforeAssignment(
                $instructorName, $roomId, $sectionName, $day, $startTime, $endTime
            );
            
            if (!empty($scheduleConflicts)) {
                foreach ($scheduleConflicts as $conflict) {
                    $conflicts[] = $conflict['message'];
                }
            }
            
            // Reserve resources for next validation
            $resourceTracker->reserveAllResources(
                $instructorName, $roomId, $sectionName, $day, $startTime, $endTime, $schedule
            );
        }
        
        return [
            'valid' => empty($conflicts),
            'conflicts' => $conflicts
        ];
    }

    /**
     * Validate schedules after saving to database
     */
    private function validateSchedulesAfterSave(array $savedSchedules): array
    {
        $conflicts = [];
        
        // Check for database-level conflicts
        foreach ($savedSchedules as $schedule) {
            // Check for overlapping instructor schedules
            $instructorConflicts = \App\Models\ScheduleMeeting::hasConflict(
                (int)($schedule['group_id'] ?? 0),
                (int)($schedule['instructor_id'] ?? 0) ?: null,
                (int)($schedule['room_id'] ?? 0) ?: null,
                (int)($schedule['section_id'] ?? 0) ?: null,
                (string)($schedule['day'] ?? ''),
                (string)($schedule['start_time'] ?? ''),
                (string)($schedule['end_time'] ?? ''),
                (int)($schedule['subject_id'] ?? 0) ?: null
            ) ? 1 : 0;
                
            if ($instructorConflicts > 0) {
                $conflicts[] = "Instructor conflict detected in database for " . ($schedule['instructor_name'] ?? 'Unknown');
            }
            
            // Check for overlapping room schedules
            $roomConflicts = 0; // included in hasConflict result above if room_id provided
                
            if ($roomConflicts > 0) {
                $conflicts[] = "Room conflict detected in database for room " . ($schedule['room_id'] ?? 'Unknown');
            }
        }
        
        return [
            'valid' => empty($conflicts),
            'conflicts' => $conflicts
        ];
    }

    /**
     * Enrich schedules with instructor_id, subject_id, section_id without creating new rows.
     * Uses batched lookups to avoid N+1 queries.
     */
    private function enrichSchedulesWithIds(array $schedules): array
    {
        if (empty($schedules)) return $schedules;

        // Collect uniques
        $instructorNames = [];
        $subjectCodes = [];
        $sectionCodes = [];
        foreach ($schedules as $s) {
            if (!empty($s['instructor'] ?? $s['instructor_name'] ?? '')) {
                $instructorNames[] = trim($s['instructor'] ?? $s['instructor_name']);
            }
            if (!empty($s['subject_code'] ?? '')) {
                $subjectCodes[] = strtoupper(trim($s['subject_code']));
            }
            // Derive section code if not present
            $dept = $s['dept'] ?? null;
            $secLabel = $s['section'] ?? (trim(($s['year_level'] ?? '') . ' ' . ($s['block'] ?? '')));
            if (!empty($secLabel)) {
                $code = ($dept ? trim($dept) . '-' : '') . trim($secLabel);
                $sectionCodes[] = $code;
            }
        }
        $instructorNames = array_values(array_unique($instructorNames));
        $subjectCodes = array_values(array_unique($subjectCodes));
        $sectionCodes = array_values(array_unique($sectionCodes));

        // Batch load maps
        $instructorMap = [];
        if (!empty($instructorNames)) {
            $rows = \App\Models\Instructor::whereIn('name', $instructorNames)->get(['instructor_id','name']);
            foreach ($rows as $r) { $instructorMap[$r->name] = (int)$r->instructor_id; }
        }
        $subjectMap = [];
        if (!empty($subjectCodes)) {
            $rows = \App\Models\Subject::whereIn('code', $subjectCodes)->get(['subject_id','code']);
            foreach ($rows as $r) { $subjectMap[strtoupper($r->code)] = (int)$r->subject_id; }
        }
        $sectionMap = [];
        if (!empty($sectionCodes)) {
            $rows = \App\Models\Section::whereIn('code', $sectionCodes)->get(['section_id','code']);
            foreach ($rows as $r) { $sectionMap[$r->code] = (int)$r->section_id; }
        }

        // Enrich each schedule
        foreach ($schedules as &$s) {
            if (empty($s['instructor_id'])) {
                $name = $s['instructor'] ?? $s['instructor_name'] ?? null;
                if ($name && isset($instructorMap[$name])) {
                    $s['instructor_id'] = $instructorMap[$name];
                }
            }
            if (empty($s['subject_id'])) {
                $code = isset($s['subject_code']) ? strtoupper(trim($s['subject_code'])) : null;
                if ($code && isset($subjectMap[$code])) {
                    $s['subject_id'] = $subjectMap[$code];
                }
            }
            if (empty($s['section_id'])) {
                $dept = $s['dept'] ?? null;
                $secLabel = $s['section'] ?? (trim(($s['year_level'] ?? '') . ' ' . ($s['block'] ?? '')));
                if (!empty($secLabel)) {
                    $code = ($dept ? trim($dept) . '-' : '') . trim($secLabel);
                    if (isset($sectionMap[$code])) {
                        $s['section_id'] = $sectionMap[$code];
                    }
                }
            }
        }
        unset($s);

        return $schedules;
    }

    /**
     * Rollback schedule changes if validation fails
     */
    private function rollbackScheduleChanges(int $groupId): void
    {
        try {
            DB::transaction(function() use ($groupId) {
                // Delete all schedule meetings for this group
                ScheduleMeeting::whereHas('scheduleEntry', function($query) use ($groupId) {
                    $query->where('group_id', $groupId);
                })->delete();
                
                // Delete all schedule entries for this group
                ScheduleEntry::where('group_id', $groupId)->delete();
                
                Log::info("ROLLBACK: Successfully rolled back schedule changes for group {$groupId}");
            });
        } catch (\Exception $e) {
            Log::error("ROLLBACK FAILED: " . $e->getMessage());
        }
    }

    /**
     * Post-save resolver: detect and fix overlapping meetings within the same section per day.
     * Strategy: for each section/day, sort by start time; when overlap is found, attempt to shift
     * the later meeting to start at the earlier meeting's end time, preserving duration and room when valid.
     * All changes are validated against existing DB schedules using simple overlap checks.
     */
    private function resolveSectionConflictsForGroup(int $groupId): void
    {
        try {
            $entries = \App\Models\ScheduleEntry::with(['meetings', 'section'])
                ->where('group_id', $groupId)
                ->get();

            // Build per-section/day buckets
            $buckets = [];
            foreach ($entries as $entry) {
                foreach ($entry->meetings as $m) {
                    $sectionCode = optional($entry->section)->code ?? null;
                    if (!$sectionCode) { continue; }
                    $day = \App\Services\DayScheduler::normalizeDay($m->day);
                    $key = $sectionCode . '|' . $day;
                    if (!isset($buckets[$key])) { $buckets[$key] = []; }
                    $buckets[$key][] = [
                        'entry' => $entry,
                        'meeting' => $m,
                        'day' => $day
                    ];
                }
            }

            foreach ($buckets as $key => $items) {
                // Sort by start time
                usort($items, function($a, $b) {
                    return strcmp($a['meeting']->start_time, $b['meeting']->start_time);
                });

                for ($i = 0; $i < count($items) - 1; $i++) {
                    $current = $items[$i];
                    $next = $items[$i + 1];

                    // If overlap, try shift next to current end time
                    if ($this->timesOverlap(
                        $current['meeting']->start_time,
                        $current['meeting']->end_time,
                        $next['meeting']->start_time,
                        $next['meeting']->end_time
                    )) {
                        $durationMin = \App\Services\TimeScheduler::timeToMinutes($next['meeting']->end_time)
                                      - \App\Services\TimeScheduler::timeToMinutes($next['meeting']->start_time);
                        $proposedStart = $current['meeting']->end_time; // shift to right after
                        $proposedEndMin = \App\Services\TimeScheduler::timeToMinutes($proposedStart) + $durationMin;
                        $proposedEnd = sprintf('%02d:%02d:00', intdiv($proposedEndMin,60), $proposedEndMin%60);

                        // If the right-shift exceeds bounds OR fails validation later, we will try alternate days and time scans below

                        // Validate: instructor, room, section no-overlap on this day
                        $ok = $this->dbSlotIsFreeForMeeting(
                            $next['meeting']->instructor_id,
                            $next['meeting']->room_id,
                            $next['entry']->section_id,
                            $next['day'],
                            $proposedStart,
                            $proposedEnd,
                            $next['meeting']->meeting_id
                        );

                        // If right-shift invalid or exceeds bounds, try left-shift of the earlier meeting within same day/room
                        if (!$ok || $proposedEnd > '21:00:00') {
                            $currDurMin = \App\Services\TimeScheduler::timeToMinutes($current['meeting']->end_time)
                                         - \App\Services\TimeScheduler::timeToMinutes($current['meeting']->start_time);
                            $altEnd = $next['meeting']->start_time;
                            $altStartMin = \App\Services\TimeScheduler::timeToMinutes($altEnd) - $currDurMin;
                            $dayStartMin = \App\Services\TimeScheduler::timeToMinutes('07:00:00');
                            if ($altStartMin >= $dayStartMin) {
                                $altStart = sprintf('%02d:%02d:00', intdiv($altStartMin,60), $altStartMin%60);
                                $okAlt = $this->dbSlotIsFreeForMeeting(
                                    $current['meeting']->instructor_id,
                                    $current['meeting']->room_id,
                                    $current['entry']->section_id,
                                    $current['day'],
                                    $altStart,
                                    $altEnd,
                                    $current['meeting']->meeting_id
                                );
                                if ($okAlt) {
                                    $current['meeting']->start_time = $altStart;
                                    $current['meeting']->end_time = $altEnd;
                                    $current['meeting']->save();
                                    // Re-sort window since times changed
                                    if (strcmp($items[$i]['meeting']->start_time, $items[$i+1]['meeting']->start_time) > 0) {
                                        $tmp = $items[$i]; $items[$i] = $items[$i+1]; $items[$i+1] = $tmp; $i = max(-1, $i-2);
                                    }
                                    continue; // resolved via left-shift
                                }
                            }
                            continue;
                        }

                        if ($ok && $next['day'] === $current['day']) {
                            // Apply update
                            $next['meeting']->start_time = $proposedStart;
                            $next['meeting']->end_time = $proposedEnd;
                            $next['meeting']->save();

                            // Re-sort window since times changed
                            // Small local re-sort: swap if out of order
                            if (strcmp($items[$i]['meeting']->start_time, $items[$i+1]['meeting']->start_time) > 0) {
                                $tmp = $items[$i];
                                $items[$i] = $items[$i+1];
                                $items[$i+1] = $tmp;
                                $i = max(-1, $i-2); // next loop will increment to 0 or more
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('resolveSectionConflictsForGroup failed: ' . $e->getMessage());
        }
    }

    // Helper: DB-level overlap check for instructor/room/section on a day
    private function dbSlotIsFreeForMeeting(int $instructorId, int $roomId, int $sectionId, string $day, string $start, string $end, int $ignoreMeetingId = 0): bool
    {
        // Instructor
        $instrBusy = \App\Models\ScheduleMeeting::where('day', $day)
            ->where('instructor_id', $instructorId)
            ->where('meeting_id', '!=', $ignoreMeetingId)
            ->where(function($q) use ($start, $end) {
                $q->whereBetween('start_time', [$start, $end])
                  ->orWhereBetween('end_time', [$start, $end])
                  ->orWhere(function($qq) use ($start, $end) { $qq->where('start_time', '<', $start)->where('end_time', '>', $end); });
            })
            ->exists();
        if ($instrBusy) { return false; }

        // Room
        $roomBusy = \App\Models\ScheduleMeeting::where('day', $day)
            ->where('room_id', $roomId)
            ->where('meeting_id', '!=', $ignoreMeetingId)
            ->where(function($q) use ($start, $end) {
                $q->whereBetween('start_time', [$start, $end])
                  ->orWhereBetween('end_time', [$start, $end])
                  ->orWhere(function($qq) use ($start, $end) { $qq->where('start_time', '<', $start)->where('end_time', '>', $end); });
            })
            ->exists();
        if ($roomBusy) { return false; }

        // Section
        $sectionBusy = \App\Models\ScheduleMeeting::where('day', $day)
            ->whereHas('scheduleEntry', function($q) use ($sectionId) { $q->where('section_id', $sectionId); })
            ->where('meeting_id', '!=', $ignoreMeetingId)
            ->where(function($q) use ($start, $end) {
                $q->whereBetween('start_time', [$start, $end])
                  ->orWhereBetween('end_time', [$start, $end])
                  ->orWhere(function($qq) use ($start, $end) { $qq->where('start_time', '<', $start)->where('end_time', '>', $end); });
            })
            ->exists();
        if ($sectionBusy) { return false; }

        return true;
    }

    // Helper: find any free room for a given day/time window (keeping meeting_id excluded)
    private function findFreeRoomForWindow(string $day, string $start, string $end, int $ignoreMeetingId = 0): ?int
    {
        $rooms = \App\Models\Room::where('is_active', true)->pluck('room_id')->all();
        foreach ($rooms as $roomId) {
            $busy = \App\Models\ScheduleMeeting::where('day', $day)
                ->where('room_id', $roomId)
                ->where('meeting_id', '!=', $ignoreMeetingId)
                ->where(function($q) use ($start, $end) {
                    $q->whereBetween('start_time', [$start, $end])
                      ->orWhereBetween('end_time', [$start, $end])
                      ->orWhere(function($qq) use ($start, $end) { $qq->where('start_time', '<', $start)->where('end_time', '>', $end); });
                })
                ->exists();
            if (!$busy) {
                return (int)$roomId;
            }
        }
        return null;
    }

    private function detectScheduleOverlaps(array $schedules): array
    {
        $overlaps = [];
        
        // EXPAND: Convert consolidated rows into atomic meeting-level rows so we don't miss
        // overlaps when a course has multiple joint session time groups
        $expanded = [];
        foreach ($schedules as $schedule) {
            $base = $schedule;
            // Prefer explicit joint_sessions if present
            if (!empty($schedule['joint_sessions']) && is_array($schedule['joint_sessions'])) {
                foreach ($schedule['joint_sessions'] as $session) {
                    $expanded[] = array_merge($base, [
                        'day' => DayScheduler::combineDays(DayScheduler::sortDaysInWeeklyOrder(array_unique($session['individual_days'] ?? []))),
                        'start_time' => $session['start_time'] ?? ($schedule['start_time'] ?? ''),
                        'end_time' => $session['end_time'] ?? ($schedule['end_time'] ?? ''),
                        'room_name' => $session['room_name'] ?? ($schedule['room_name'] ?? ''),
                        'room_id' => $session['room_id'] ?? ($schedule['room_id'] ?? null), // Use room_id from joint session, fallback to base
                    ]);
                }
            } else {
                // Fallback to the existing consolidated row
                $expanded[] = $schedule;
            }
        }
        
        for ($i = 0; $i < count($expanded); $i++) {
            for ($j = $i + 1; $j < count($expanded); $j++) {
                $schedule1 = $expanded[$i];
                $schedule2 = $expanded[$j];
                
                // Parse individual days from combined strings (e.g., "MonSat" -> ["Mon", "Sat"])
                $days1 = \App\Services\DayScheduler::splitCombinedDays($schedule1['day'] ?? '');
                $days2 = \App\Services\DayScheduler::splitCombinedDays($schedule2['day'] ?? '');
                
                // Check if days intersect (e.g., "MonSat" and "MonTue" both have "Mon")
                $overlappingDays = array_intersect($days1, $days2);
                
                $s1Section = trim(($schedule1['year_level'] ?? '') . ' ' . ($schedule1['block'] ?? ''));
                $s2Section = trim(($schedule2['year_level'] ?? '') . ' ' . ($schedule2['block'] ?? ''));
                
                // Debug logging for BAC 3 vs Bis Fin conflict on 1st Year A
                if (in_array($schedule1['subject_code'] ?? '', ['BAC 3', 'Bis Fin']) && 
                    in_array($schedule2['subject_code'] ?? '', ['BAC 3', 'Bis Fin']) &&
                    $s1Section === '1st Year A' && $s2Section === '1st Year A') {
                    Log::debug("CONFLICT CHECK: {$schedule1['subject_code']} vs {$schedule2['subject_code']}");
                    Log::debug("  Days1: " . json_encode($days1) . " Days2: " . json_encode($days2));
                    Log::debug("  Overlapping days: " . json_encode($overlappingDays));
                    Log::debug("  Times1: {$schedule1['start_time']}-{$schedule1['end_time']}");
                    Log::debug("  Times2: {$schedule2['start_time']}-{$schedule2['end_time']}");
                }
                
                if (!empty($overlappingDays) && 
                    $this->timesOverlap($schedule1['start_time'], $schedule1['end_time'], 
                                      $schedule2['start_time'], $schedule2['end_time'])) {
                    
                    // Check if same instructor
                    if ($schedule1['instructor_name'] === $schedule2['instructor_name']) {
                        $overlaps[] = [
                            'schedule1' => $schedule1,
                            'schedule2' => $schedule2,
                            'type' => 'instructor_conflict',
                            'conflicting_days' => $overlappingDays
                        ];
                    }
                    
                    // Check if same room
                    if ($schedule1['room_id'] === $schedule2['room_id']) {
                        $overlaps[] = [
                            'schedule1' => $schedule1,
                            'schedule2' => $schedule2,
                            'type' => 'room_conflict',
                            'conflicting_days' => $overlappingDays
                        ];
                    }
                    
                    // Check if same section (students can't be in two places at once!)
                    $section1 = trim(($schedule1['year_level'] ?? '') . ' ' . ($schedule1['block'] ?? ''));
                    $section2 = trim(($schedule2['year_level'] ?? '') . ' ' . ($schedule2['block'] ?? ''));
                    
                    if ($section1 === $section2 && !empty($section1)) {
                        Log::warning("SECTION CONFLICT DETECTED: {$schedule1['subject_code']} and {$schedule2['subject_code']} for section {$section1} on " . implode(', ', $overlappingDays) . " - {$schedule1['start_time']}-{$schedule1['end_time']} overlaps {$schedule2['start_time']}-{$schedule2['end_time']}");
                        $overlaps[] = [
                            'schedule1' => $schedule1,
                            'schedule2' => $schedule2,
                            'type' => 'section_conflict',
                            'conflicting_days' => $overlappingDays
                        ];
                    }
                }
            }
        }
        
        return $overlaps;
    }

    // Filter out schedules that would create overlaps by instructor/room/section before saving
    private function filterSchedulesByConflicts(array $schedules): array
    {
        $tracker = new \App\Services\ResourceTracker();

        // Preload existing DB schedules for the same group into the tracker to prevent cross-run overlaps
        $groupId = $schedules[0]['group_id'] ?? null;
        if (!empty($groupId)) {
            $existing = \App\Models\ScheduleEntry::with(['meetings', 'section'])
                ->where('group_id', $groupId)
                ->get();

            $existingSchedules = [];
            foreach ($existing as $entry) {
                foreach ($entry->meetings as $meet) {
                    $existingSchedules[] = [
                        'instructor' => $entry->instructor->name ?? ($entry->instructor_name ?? ''),
                        'room_id' => $entry->room_id ?? 0,
                        'section' => $entry->section->code ?? (trim(($entry->year_level ?? '') . ' ' . ($entry->block ?? ''))),
                        'day' => $meet->day,
                        'start_time' => $meet->start_time,
                        'end_time' => $meet->end_time,
                    ];
                }
            }
            if (!empty($existingSchedules)) {
                $tracker->loadExistingSchedules($existingSchedules);
            }
        }
        $filtered = [];
        foreach ($schedules as $schedule) {
            $instructor = $schedule['instructor'] ?? '';
            $roomId = $schedule['room_id'] ?? 0;
            // Prefer section_id -> code; fallback to provided 'section' string
            $section = $schedule['section'] ?? (trim(($schedule['year_level'] ?? '') . ' ' . ($schedule['block'] ?? '')));
            if (empty($section) && !empty($schedule['section_id'])) {
                $sectionModel = \App\Models\Section::find($schedule['section_id']);
                if ($sectionModel) {
                    $section = $sectionModel->code;
                }
            }
            $day = $schedule['day'] ?? '';
            $start = $schedule['start_time'] ?? '';
            $end = $schedule['end_time'] ?? '';
            $instructorId = $schedule['instructor_id'] ?? null;
            $sectionId = $schedule['section_id'] ?? null;
            $subjectId = $schedule['subject_id'] ?? null;

            // Resolve IDs non-destructively for DB conflict checks when missing
            if (is_null($instructorId) && !empty($instructor)) {
                $instructorId = \App\Models\Instructor::where('name', $instructor)->value('instructor_id');
            }
            if (is_null($subjectId) && !empty($schedule['subject_code'] ?? '')) {
                $subjectId = \App\Models\Subject::where('code', $schedule['subject_code'])->value('subject_id');
            }
            if (is_null($sectionId)) {
                $sectionCode = !empty($schedule['section_id']) ? null : (trim(($schedule['dept'] ?? 'General') . '-' . $section));
                if (!empty($sectionCode)) {
                    $sectionId = \App\Models\Section::where('code', $sectionCode)->value('section_id');
                }
            }

            // Also guard against DB-level overlaps using centralized model method
            if (!empty($groupId)) {
                $hasDbConflict = \App\Models\ScheduleMeeting::hasConflict(
                    (int)$groupId,
                    $instructorId ? (int)$instructorId : null,
                    $roomId ? (int)$roomId : null,
                    $sectionId ? (int)$sectionId : null,
                    $day,
                    $start,
                    $end,
                    $subjectId ? (int)$subjectId : null
                );
                if ($hasDbConflict) {
                    continue; // Skip conflicting candidate
                }
            }
            $conflicts = $tracker->validateBeforeAssignment($instructor, $roomId, $section, $day, $start, $end);
            if (empty($conflicts)) {
                // Reserve so subsequent checks include this one
                $tracker->reserveAllResources($instructor, $roomId, $section, $day, $start, $end, $schedule);
                $filtered[] = $schedule;
            }
        }
        return $filtered;
    }

    private function timesOverlap(string $start1, string $end1, string $start2, string $end2): bool
    {
        $start1Time = strtotime($start1);
        $end1Time = strtotime($end1);
        $start2Time = strtotime($start2);
        $end2Time = strtotime($end2);
        
        return ($start1Time < $end2Time) && ($start2Time < $end1Time);
    }

    public function getSchedules(Request $request): JsonResponse
    {
        try {
            $groupId = $request->input('group_id');
            $department = $request->input('department');
            $semester = $request->input('semester');
            $schoolYear = $request->input('school_year');

            $query = ScheduleEntry::with(['instructor', 'subject', 'section', 'meetings.room', 'meetings.instructor']);

            if ($groupId) {
                $query->where('group_id', $groupId);
            }

            if ($department) {
                $query->whereHas('section', function($q) use ($department) {
                    $q->where('department', $department);
                });
            }

            if ($semester) {
                $query->whereHas('scheduleGroup', function($q) use ($semester) {
                    $q->where('semester', $semester);
                });
            }

            if ($schoolYear) {
                $query->whereHas('scheduleGroup', function($q) use ($schoolYear) {
                    $q->where('school_year', $schoolYear);
                });
            }

            $schedules = $query->get();

            // Consolidate course entries into per-day rows (day + time + room)
            $consolidatedSchedules = $this->consolidateCourseEntries($schedules);

            // Fallback: if nothing to show (e.g., meetings missing), expand to atomic meeting rows
            if (empty($consolidatedSchedules)) {
                $consolidatedSchedules = $this->expandEntriesToMeetingRows($schedules);
            }

            // Fallback: if nothing to show (e.g., meetings missing), expand to atomic meeting rows
            if (empty($consolidatedSchedules)) {
                $consolidatedSchedules = $this->expandEntriesToMeetingRows($schedules);
            }

            // Group schedules by year level and block for frontend display
            $groupedSchedules = $this->groupSchedulesByYearLevelAndBlock($consolidatedSchedules);

            return response()->json([
                'success' => true,
                'data' => $groupedSchedules,
                'total' => count($consolidatedSchedules)
            ]);

        } catch (Exception $e) {
            Log::error('Get schedules error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve schedules: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * EXPAND: Convert consolidated rows into atomic meeting-level rows so we don't miss
     * entries when some records lack consolidated meeting data.
     */
    private function expandEntriesToMeetingRows($entries): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            // Get employment_type from entry, meeting's instructor, or entry's instructor relationship
            $employmentType = $entry->employment_type;
            if (!$employmentType) {
                // Try to get from meeting's instructor (instructors are stored on meetings)
                if ($entry->meetings && $entry->meetings->count() > 0) {
                    $firstMeeting = $entry->meetings->first();
                    if ($firstMeeting->instructor) {
                        $employmentType = $firstMeeting->instructor->employment_type ?? null;
                    }
                }
                // Fallback to entry's instructor relationship
                if (!$employmentType && $entry->instructor) {
                    $employmentType = $entry->instructor->employment_type ?? null;
                }
            }
            // Normalize employment type
            $employmentType = $employmentType ? $this->normalizeEmploymentType($employmentType) : 'FULL-TIME';
            
            // Build base info from relationships
            $base = [
                'subject_code' => $entry->subject_code,
                'subject_description' => $entry->subject_description,
                'instructor_name' => $entry->instructor_name,
                'year_level' => $entry->year_level,
                'block' => $entry->block,
                'department' => $entry->department,
                'units' => $entry->units,
                'employment_type' => $employmentType
            ];

            if ($entry->meetings && $entry->meetings->count() > 0) {
                foreach ($entry->meetings as $m) {
                    $rows[] = array_merge($base, [
                        'day' => $m->day,
                        'days' => $m->day,
                        'start_time' => $m->start_time,
                        'end_time' => $m->end_time,
                        'time_range' => $this->formatTimeForDisplay($m->start_time) . '–' . $this->formatTimeForDisplay($m->end_time),
                        'room_name' => $m->room ? $m->room->room_name : 'TBA',
                        'is_lab' => $m->room ? (bool)$m->room->is_lab : false,
                        'meeting_count' => 1
                    ]);
                }
            } else {
                // No meetings saved; skip this entry to avoid TBA placeholders
                continue;
            }
        }

        // Sort similar to consolidated output
        usort($rows, function($a, $b) {
            $yearOrder = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4];
            $dayOrder = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
            $yearA = $yearOrder[$a['year_level']] ?? 5;
            $yearB = $yearOrder[$b['year_level']] ?? 5;
            if ($yearA !== $yearB) return $yearA - $yearB;
            $blockA = $a['block'] ?? 'A';
            $blockB = $b['block'] ?? 'A';
            if ($blockA !== $blockB) return strcmp($blockA, $blockB);
            $firstDayA = preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat)/', $a['days'] ?? 'Mon', $mA) ? $mA[1] : 'Mon';
            $firstDayB = preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat)/', $b['days'] ?? 'Mon', $mB) ? $mB[1] : 'Mon';
            $dayA = $dayOrder[$firstDayA] ?? 1;
            $dayB = $dayOrder[$firstDayB] ?? 1;
            if ($dayA !== $dayB) return $dayA - $dayB;
            return strcmp($a['start_time'] ?? '00:00:00', $b['start_time'] ?? '00:00:00');
        });

        return $rows;
    }

    private function consolidateCourseEntries($courseEntries)
    {
        Log::debug("consolidateCourseEntries: Processing " . count($courseEntries) . " schedule entries");
        
        // Group by course (subject_code|year_level|block) and combine days
        $byCourse = [];
        foreach ($courseEntries as $entry) {
            $key = implode('|', [
                (string)$entry->subject_code,
                (string)$entry->year_level,
                (string)$entry->block
            ]);

            // AGGREGATE instructor_name from meeting-level instructors (patch):
            $meetingInstructorNames = collect($entry->meetings)->map(function($m){
                return optional($m->instructor)->name ?? optional($m->instructor)->instructor_name ?? null;
            })->filter();
            $instructorCounts = $meetingInstructorNames->countBy();
            if ($instructorCounts->isNotEmpty()) {
                $finalInstructorName = $instructorCounts->sortDesc()->keys()->first();
            } else {
                $finalInstructorName = $entry->instructor_name ?? $entry->instructor ?? 'N/A';
            }

            if (!isset($byCourse[$key])) {
                // Get employment_type from entry, meeting's instructor, or entry's instructor relationship
                $employmentType = $entry->employment_type;
                if (!$employmentType) {
                    // Try to get from meeting's instructor (instructors are stored on meetings)
                    $meetingInstructors = collect($entry->meetings)->map(function($m){
                        return $m->instructor;
                    })->filter();
                    if ($meetingInstructors->isNotEmpty()) {
                        $firstInstructor = $meetingInstructors->first();
                        $employmentType = $firstInstructor->employment_type ?? null;
                    }
                    // Fallback to entry's instructor relationship
                    if (!$employmentType && $entry->instructor) {
                        $employmentType = $entry->instructor->employment_type ?? null;
                    }
                }
                // Normalize employment type
                $employmentType = $employmentType ? $this->normalizeEmploymentType($employmentType) : 'FULL-TIME';
                
                $byCourse[$key] = [
                    'subject_code' => $entry->subject_code,
                    'subject_description' => $entry->subject_description,
                    'instructor_name' => $finalInstructorName,
                    'year_level' => $entry->year_level,
                    'block' => $entry->block,
                    'all_days' => [],
                    'units' => $entry->units,
                    'department' => $entry->department,
                    'employment_type' => $employmentType,
                    'start_time' => null,
                    'end_time' => null,
                    'room_name' => null,
                    'joint_sessions' => []
                ];
            }

            // Collect all meetings from this entry
            foreach ($entry->meetings as $meeting) {
                $byCourse[$key]['joint_sessions'][] = [
                    'days' => $meeting->day,
                    'individual_days' => DayScheduler::parseCombinedDays($meeting->day) ?: [$meeting->day],
                    'start_time' => $meeting->start_time,
                    'end_time' => $meeting->end_time,
                    'room_name' => $meeting->room ? $meeting->room->room_name : 'TBA',
                    'room_id' => $meeting->room_id,
                    'is_lab' => $meeting->room ? $meeting->room->is_lab : false,
                    'meeting_count' => 1
                ];
            }
        }

        // Build final consolidated rows with joint session support
        $consolidated = [];
        foreach ($byCourse as $course) {
            if (!empty($course['joint_sessions'])) {
                // Group joint sessions by matching time range to combine same-time sessions
                $timeGroups = [];
                foreach ($course['joint_sessions'] as $jointSession) {
                    $timeKey = $jointSession['start_time'] . '|' . $jointSession['end_time'];
                    if (!isset($timeGroups[$timeKey])) {
                        $timeGroups[$timeKey] = [
                            'all_days' => [],
                            'start_time' => $jointSession['start_time'],
                            'end_time' => $jointSession['end_time'],
                            'room_name' => $jointSession['room_name'],
                            'room_id' => $jointSession['room_id'] ?? null,
                            'is_lab' => $jointSession['is_lab'],
                            'meeting_count' => 0
                        ];
                    }
                    // Collect all days for this time range
                    $timeGroups[$timeKey]['all_days'] = array_merge(
                        $timeGroups[$timeKey]['all_days'], 
                        $jointSession['individual_days']
                    );
                    $timeGroups[$timeKey]['meeting_count'] += $jointSession['meeting_count'];
                    
                    // Warn if room_id differs for same time group (indicates data inconsistency)
                    if ($timeGroups[$timeKey]['room_id'] !== null && $jointSession['room_id'] !== null) {
                        if ($timeGroups[$timeKey]['room_id'] !== $jointSession['room_id']) {
                            Log::warning("CONSOLIDATION WARNING: {$course['subject_code']} ({$course['year_level']} {$course['block']}) has different room_ids for same time {$timeKey}: {$timeGroups[$timeKey]['room_id']} vs {$jointSession['room_id']}");
                        }
                    }
                }
                
                // Create ONE consolidated row per course with multiple joint sessions
                $allJointSessions = [];
                $allDays = [];
                $primaryTimeGroup = null;
                
                foreach ($timeGroups as $timeGroup) {
                    // Sort and combine days for this time group
                    $sortedDays = DayScheduler::sortDaysInWeeklyOrder(array_unique($timeGroup['all_days']));
                    $combinedDays = DayScheduler::combineDays($sortedDays);
                    
                    // Store joint session data
                    $allJointSessions[] = [
                        'days' => $combinedDays,
                        'individual_days' => $timeGroup['all_days'],
                        'start_time' => $timeGroup['start_time'],
                        'end_time' => $timeGroup['end_time'],
                        'room_name' => $timeGroup['room_name'],
                        'room_id' => $timeGroup['room_id'] ?? null,
                        'is_lab' => $timeGroup['is_lab'],
                        'meeting_count' => $timeGroup['meeting_count']
                    ];
                    
                    // Collect all days for primary display
                    $allDays = array_merge($allDays, $timeGroup['all_days']);
                    
                    // Use first time group as primary for display
                    if ($primaryTimeGroup === null) {
                        $primaryTimeGroup = $timeGroup;
                    }
                }
                
                // CRITICAL FIX: If there are multiple time groups with different times,
                // create SEPARATE display entries for each time group to prevent misleading displays
                // This prevents issues like showing "MonThu 4:30 PM" when Mon is actually at 8:00 AM
                // Always create ONE consolidated row per course showing combined days
                // Use the primary time group for the main display, include all joint sessions
                if (count($timeGroups) > 1) {
                    Log::warning("Course {$course['subject_code']} ({$course['year_level']} {$course['block']}) has " . count($timeGroups) . " different time groups - using primary time for display");
                }
                
                $consolidated[] = array_merge($course, [
                    'day' => DayScheduler::combineDays(DayScheduler::sortDaysInWeeklyOrder(array_unique($allDays))),
                    'days' => DayScheduler::combineDays(DayScheduler::sortDaysInWeeklyOrder(array_unique($allDays))),
                    'time_range' => $this->formatTimeForDisplay($primaryTimeGroup['start_time']) . '–' . 
                                   $this->formatTimeForDisplay($primaryTimeGroup['end_time']),
                    'room_name' => $primaryTimeGroup['room_name'],
                    'is_lab' => $primaryTimeGroup['is_lab'],
                    'meeting_count' => array_sum(array_column($allJointSessions, 'meeting_count')),
                    'joint_sessions' => $allJointSessions
                ]);
                
                // Override start_time and end_time after merge to ensure they're not null
                $consolidated[count($consolidated) - 1]['start_time'] = $primaryTimeGroup['start_time'];
                $consolidated[count($consolidated) - 1]['end_time'] = $primaryTimeGroup['end_time'];
            } else {
                // Display individual days (legacy behavior)
                $allDaysUnique = array_values(array_unique($course['all_days']));
                $allDaysSorted = DayScheduler::sortDaysInWeeklyOrder($allDaysUnique);
                $allDaysCombined = DayScheduler::combineDays($allDaysSorted);

                $consolidated[] = [
                    'subject_code' => $course['subject_code'],
                    'subject_description' => $course['subject_description'],
                    'instructor_name' => $course['instructor_name'],
                    'year_level' => $course['year_level'],
                    'block' => $course['block'],
                    'day' => $allDaysCombined,
                    'days' => $allDaysCombined,
                    'start_time' => $course['start_time'],
                    'end_time' => $course['end_time'],
                    'time_range' => $this->formatTimeForDisplay($course['start_time']) . '–' . $this->formatTimeForDisplay($course['end_time']),
                    'room_name' => $course['room_name'],
                    'units' => $course['units'],
                    'department' => $course['department'],
                    'employment_type' => $course['employment_type'],
                    'meeting_count' => count($allDaysUnique)
                ];
            }
        }

        // Sort consolidated rows
        usort($consolidated, function($a, $b) {
            $yearOrder = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4];
            $dayOrder = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

            $yearA = $yearOrder[$a['year_level']] ?? 5;
            $yearB = $yearOrder[$b['year_level']] ?? 5;
            if ($yearA !== $yearB) return $yearA - $yearB;

            $blockA = $a['block'] ?? 'A';
            $blockB = $b['block'] ?? 'A';
            if ($blockA !== $blockB) return strcmp($blockA, $blockB);

            $firstDayA = preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat)/', $a['days'], $mA) ? $mA[1] : 'Mon';
            $firstDayB = preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat)/', $b['days'], $mB) ? $mB[1] : 'Mon';
            $dayA = $dayOrder[$firstDayA] ?? 1;
            $dayB = $dayOrder[$firstDayB] ?? 1;
            if ($dayA !== $dayB) return $dayA - $dayB;

            return strcmp($a['start_time'] ?? '00:00:00', $b['start_time'] ?? '00:00:00');
        });

        // Debug: Check for duplicates in final consolidated list
        $duplicateCheck = [];
        $foundDuplicates = [];
        foreach ($consolidated as $item) {
            $checkKey = $item['subject_code'] . '|' . $item['year_level'] . '|' . $item['block'] . '|' . 
                        $item['time_range'] . '|' . $item['room_name'];
            if (isset($duplicateCheck[$checkKey])) {
                $foundDuplicates[] = $checkKey;
            }
            $duplicateCheck[$checkKey] = true;
        }
        
        if (!empty($foundDuplicates)) {
            Log::warning("DUPLICATES FOUND IN CONSOLIDATED LIST:", $foundDuplicates);
        }
        
        Log::debug("Consolidated " . count($courseEntries) . " entries into " . count($consolidated) . " display rows");
        Log::debug('Consolidated schedule rows (one per course with combined days):', array_map(function($e) {
            return $e['subject_code'] . ' (' . $e['year_level'] . ' ' . $e['block'] . ': ' . $e['days'] . ' ' . $e['time_range'] . ' ' . $e['room_name'] . ')';
        }, $consolidated));

        return $consolidated;
    }

    private function groupSchedulesByYearLevelAndBlock($consolidatedSchedules)
    {
        $grouped = [];
        
        foreach ($consolidatedSchedules as $schedule) {
            $yearLevel = $schedule['year_level'] ?? '1st Year';
            $block = $schedule['block'] ?? 'A';
            $key = "{$yearLevel} {$block}";
            
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            
            $grouped[$key][] = $schedule;
        }
        
        // Sort the groups by year level and block
        $sortedGrouped = [];
        $yearOrder = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4];
        $blockOrder = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'F' => 6];
        
        $keys = array_keys($grouped);
        usort($keys, function($a, $b) use ($yearOrder, $blockOrder) {
            // Extract year level and block from key like "1st Year A", "1st Year B", "1st Year C"
            preg_match('/(\d+(?:st|nd|rd|th) Year)\s+([A-Z])/', $a, $matchesA);
            preg_match('/(\d+(?:st|nd|rd|th) Year)\s+([A-Z])/', $b, $matchesB);
            
            $aYear = $yearOrder[$matchesA[1] ?? '1st Year'] ?? 5;
            $bYear = $yearOrder[$matchesB[1] ?? '1st Year'] ?? 5;
            $aBlock = $blockOrder[$matchesA[2] ?? 'A'] ?? 99;
            $bBlock = $blockOrder[$matchesB[2] ?? 'A'] ?? 99;
            
            if ($aYear !== $bYear) {
                return $aYear - $bYear;
            }
            return $aBlock - $bBlock;
        });
        
        foreach ($keys as $key) {
            // Sort entries within each group by day and time
            $dayOrder = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
            
            usort($grouped[$key], function($a, $b) use ($dayOrder) {
                // Sort by the first day of the week
                $daysA = $a['days'] ?? '';
                $daysB = $b['days'] ?? '';
                
                // Extract the first day from each day string (e.g., "MonWed" -> "Mon")
                $firstDayA = preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat)/', $daysA, $matchesA) ? $matchesA[1] : 'Mon';
                $firstDayB = preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat)/', $daysB, $matchesB) ? $matchesB[1] : 'Mon';
                
                $dayOrderA = $dayOrder[$firstDayA] ?? 1;
                $dayOrderB = $dayOrder[$firstDayB] ?? 1;
                
                if ($dayOrderA !== $dayOrderB) {
                    return $dayOrderA - $dayOrderB;
                }
                
                // Finally sort by start time
                $timeA = $a['start_time'] ?? '00:00:00';
                $timeB = $b['start_time'] ?? '00:00:00';
                
                return strcmp($timeA, $timeB);
            });
            
            $sortedGrouped[$key] = $grouped[$key];
        }
        
        return $sortedGrouped;
    }

    private function createContinuousTimeRange($courseEntries)
    {
        $firstEntry = $courseEntries->first();
        
        // Get days from meetings relationship and sort chronologically
        $days = $courseEntries->flatMap(function($entry) {
            return $entry->meetings->pluck('day');
        })->unique()->values()->toArray();
        
        // Sort days in weekly order (Mon, Tue, Wed, Thu, Fri, Sat)
        $sortedDays = DayScheduler::sortDaysInWeeklyOrder($days);
        $combinedDays = DayScheduler::combineDays($sortedDays);
        
        // Get time range - handle multiple sessions with different times
        $allMeetings = $courseEntries->flatMap(function($entry) {
            return $entry->meetings;
        });
        
        $timeRange = '';
        if ($allMeetings->count() > 0) {
            // Group meetings by time to handle multiple sessions
            $timeGroups = $allMeetings->groupBy(function($meeting) {
                return $meeting->start_time . '-' . $meeting->end_time;
            });
            
            if ($timeGroups->count() == 1) {
                // All sessions have the same time - show single time range
                $firstMeeting = $allMeetings->first();
            $timeRange = $this->formatTimeForDisplay($firstMeeting->start_time) . '–' . 
                        $this->formatTimeForDisplay($firstMeeting->end_time);
                Log::debug("Single time range for {$firstEntry->subject_code}: {$timeRange}");
            } else {
                // Multiple sessions with different times - show individual session times
                $timeRanges = [];
                foreach ($timeGroups as $timeGroup) {
                    $meeting = $timeGroup->first();
                    $timeRanges[] = $this->formatTimeForDisplay($meeting->start_time) . '–' . 
                                    $this->formatTimeForDisplay($meeting->end_time);
                }
                $timeRange = implode(' / ', $timeRanges);
                Log::debug("Multiple time ranges for {$firstEntry->subject_code}: {$timeRange} (from {$timeGroups->count()} different time slots)");
            }
        }
        
        $primaryRoom = $this->selectPrimaryRoom($courseEntries);

        // Get the earliest start time and latest end time for the consolidated entry
        // For multiple sessions, use the first session's time for start/end fields
        $firstMeeting = $allMeetings->count() > 0 ? $allMeetings->first() : null;
        $earliestStart = $firstMeeting ? $firstMeeting->start_time : null;
        $latestEnd = $firstMeeting ? $firstMeeting->end_time : null;

        return [
            'subject_code' => $firstEntry->subject_code,
            'subject_description' => $firstEntry->subject_description,
            'instructor_name' => $firstEntry->instructor_name,
            'year_level' => $firstEntry->year_level,
            'block' => $firstEntry->block,
            'day' => $combinedDays, // Use combined days for display
            'days' => $combinedDays,
            'start_time' => $earliestStart,
            'end_time' => $latestEnd,
            'time_range' => $timeRange,
            'room_name' => $primaryRoom ? $primaryRoom->room_name : 'TBA',
            'units' => $firstEntry->units,
            'department' => $firstEntry->department
        ];
    }

    private function selectPrimaryRoom($courseEntries)
    {
        // Get room from meetings relationship instead of direct room_id
        $roomCounts = $courseEntries->flatMap(function($entry) {
            return $entry->meetings->pluck('room_id');
        })->countBy();
        
        $mostUsedRoomId = $roomCounts->sortDesc()->keys()->first();
        
        // Find the room from the first entry that has this room_id in meetings
        foreach ($courseEntries as $entry) {
            $meeting = $entry->meetings->firstWhere('room_id', $mostUsedRoomId);
            if ($meeting && $meeting->room) {
                return $meeting->room;
            }
        }
        
        // Fallback: try to get room from any meeting
        foreach ($courseEntries as $entry) {
            $meeting = $entry->meetings->first();
            if ($meeting && $meeting->room) {
                return $meeting->room;
            }
        }
        
        // Final fallback to entry's room
        return $courseEntries->first()->room ?? null;
    }

    public function testTimeFormats(): JsonResponse
    {
        $testTimes = [
            '08:00:00',
            '12:00:00', 
            '17:00:00',
            '20:00:00'
        ];

        $results = [];
        foreach ($testTimes as $time) {
            $results[] = [
                '24hour' => $time,
                '12hour' => $this->convertTo12Hour($time),
                'display' => $this->formatTimeForDisplay($time)
            ];
        }

        return response()->json($results);
    }

    public function testDatabase(): JsonResponse
    {
        try {
            $instructors = Instructor::count();
            $subjects = Subject::count();
            $sections = Section::count();
            $rooms = Room::count();
            $scheduleEntries = ScheduleEntry::count();
            $scheduleMeetings = ScheduleMeeting::count();
            
            // Get a sample schedule entry with relationships
            $sampleEntry = ScheduleEntry::with(['instructor', 'subject', 'section', 'room', 'meetings.room'])->first();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'instructors' => $instructors,
                    'subjects' => $subjects,
                    'sections' => $sections,
                    'rooms' => $rooms,
                    'schedule_entries' => $scheduleEntries,
                    'schedule_meetings' => $scheduleMeetings,
                    'sample_entry' => $sampleEntry ? [
                        'entry_id' => $sampleEntry->entry_id,
                        'subject_code' => $sampleEntry->subject_code,
                        'subject_description' => $sampleEntry->subject_description,
                        'instructor_name' => $sampleEntry->instructor_name,
                        'year_level' => $sampleEntry->year_level,
                        'block' => $sampleEntry->block,
                        'department' => $sampleEntry->department,
                        'day' => $sampleEntry->day,
                        'start_time' => $sampleEntry->start_time,
                        'end_time' => $sampleEntry->end_time,
                        'meetings_count' => $sampleEntry->meetings->count(),
                        'meetings' => $sampleEntry->meetings->map(function($meeting) {
                            return [
                                'day' => $meeting->day,
                                'start_time' => $meeting->start_time,
                                'end_time' => $meeting->end_time,
                                'room_id' => $meeting->room_id,
                                'room_name' => $meeting->room ? $meeting->room->room_name : 'No room'
                            ];
                        })
                    ] : null
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function debugData(Request $request): JsonResponse
    {
        try {
            $rawData = $request->input('data', []);
            
            $transformed = $this->transformInstructorData($rawData);
            $synchronized = $this->synchronizeSubjectsAcrossBlocks($transformed);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'original_count' => count($rawData),
                    'transformed_count' => count($transformed),
                    'synchronized_count' => count($synchronized),
                    'transformed' => $transformed,
                    'synchronized' => $synchronized
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    private function formatTimeForDisplay($time): string
    {
        if (empty($time)) {
            return 'TBA';
        }
        
        // Handle both 24-hour and 12-hour formats
        if (strpos($time, 'AM') !== false || strpos($time, 'PM') !== false) {
            return $time; // Already in 12-hour format
        }
        
        // Convert 24-hour to 12-hour
        return $this->convertTo12Hour($time);
    }

    private function convertTo12Hour(string $time24Hour): string
    {
        $time = DateTime::createFromFormat('H:i:s', $time24Hour);
        if (!$time) {
            $time = DateTime::createFromFormat('H:i', $time24Hour);
        }
        
        if (!$time) {
            return 'TBA';
        }
        
        return $time->format('g:i A');
    }

    public function getScheduleByGroupId(Request $request): JsonResponse
    {
        try {
            $groupId = $request->input('group_id');
            
            if (!$groupId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Group ID is required'
                ], 400);
            }

            $schedules = ScheduleEntry::with(['instructor', 'subject', 'section', 'meetings.room', 'meetings.instructor'])
                ->where('group_id', $groupId)
                ->get();

            // Consolidate course entries into per-day rows (day + time + room)
            $consolidatedSchedules = $this->consolidateCourseEntries($schedules);
            if (empty($consolidatedSchedules)) {
                $consolidatedSchedules = $this->expandEntriesToMeetingRows($schedules);
            }

            // Group schedules by year level and block for frontend display
            $groupedSchedules = $this->groupSchedulesByYearLevelAndBlock($consolidatedSchedules);

            // Debug: Log the consolidated data structure
            Log::info('Consolidated schedules sample:', $consolidatedSchedules[0] ?? []);
            Log::info('Grouped schedules keys:', array_keys($groupedSchedules));

            // Get department from schedule group
            $scheduleGroup = ScheduleGroup::find($groupId);
            $department = $scheduleGroup ? $scheduleGroup->department : 'BSBA';

            return response()->json([
                'success' => true,
                'data' => $groupedSchedules,
                'total' => count($consolidatedSchedules),
                'group_id' => $groupId,
                'department' => $department
            ]);

        } catch (Exception $e) {
            Log::error('Get schedule by group ID error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve schedule: ' . $e->getMessage()
            ], 500);
        }
    }


    private function validateSubjectConsistency(array $instructorData): array
    {
        $issues = [];
        $subjectGroups = [];
        
        // Group by subject code and year level
        foreach ($instructorData as $entry) {
            $key = $entry['courseCode'] . '|' . $entry['yearLevel'];
            if (!isset($subjectGroups[$key])) {
                $subjectGroups[$key] = [];
            }
            $subjectGroups[$key][] = $entry;
        }
        
        // Check each group for consistency
        foreach ($subjectGroups as $key => $entries) {
            $blocks = array_unique(array_column($entries, 'block'));
            $units = array_unique(array_column($entries, 'unit'));
            $instructors = array_unique(array_column($entries, 'name'));
            
            if (count($blocks) < 2) {
                $issues[] = [
                    'type' => 'missing_block',
                    'subject' => $key,
                    'message' => "Subject {$key} only appears in block(s): " . implode(', ', $blocks)
                ];
            }
            
            if (count($units) > 1) {
                        $issues[] = [
                    'type' => 'unit_mismatch',
                    'subject' => $key,
                    'message' => "Subject {$key} has inconsistent units: " . implode(', ', $units)
                ];
            }
        }
        
        return $issues;
    }

    public function debugSubjectConsistency(Request $request): JsonResponse
    {
        try {
            $rawData = $request->input('data', []);
            $transformed = $this->transformInstructorData($rawData);
            $issues = $this->validateSubjectConsistency($transformed);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'total_entries' => count($transformed),
                    'issues' => $issues,
                    'issue_count' => count($issues)
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Debug method to verify section distribution in generated schedule
     */
    public function debugSectionDistribution(Request $request): JsonResponse
    {
        try {
            $groupId = $request->input('group_id');
            
            if (!$groupId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Group ID is required'
                ], 400);
            }

            // Get schedule entries for this group
            $scheduleEntries = ScheduleEntry::with(['subject', 'section', 'instructor'])
                ->where('group_id', $groupId)
                ->get();

            // Analyze section distribution
            $sectionAnalysis = [];
            $yearLevelBlocks = [];
            
            foreach ($scheduleEntries as $entry) {
                $yearLevel = $entry->year_level;
                $block = $entry->block ?? 'A';
                $key = "{$yearLevel} {$block}";
                
                if (!isset($sectionAnalysis[$key])) {
                    $sectionAnalysis[$key] = [
                        'year_level' => $yearLevel,
                        'block' => $block,
                        'section_id' => $entry->section_id,
                        'section_code' => $entry->section ? $entry->section->code : 'No section',
                        'courses' => [],
                        'course_count' => 0
                    ];
                }
                
                $sectionAnalysis[$key]['courses'][] = [
                    'subject_code' => $entry->subject_code,
                    'subject_description' => $entry->subject_description,
                    'instructor_name' => $entry->instructor_name,
                    'units' => $entry->units
                ];
                $sectionAnalysis[$key]['course_count']++;
                
                // Track year level blocks
                if (!isset($yearLevelBlocks[$yearLevel])) {
                    $yearLevelBlocks[$yearLevel] = [];
                }
                if (!in_array($block, $yearLevelBlocks[$yearLevel])) {
                    $yearLevelBlocks[$yearLevel][] = $block;
                }
            }

            // Check for missing blocks
            $missingBlocks = [];
            foreach ($yearLevelBlocks as $yearLevel => $blocks) {
                if (!in_array('A', $blocks)) {
                    $missingBlocks[] = "{$yearLevel} A";
                }
                if (!in_array('B', $blocks)) {
                    $missingBlocks[] = "{$yearLevel} B";
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total_entries' => count($scheduleEntries),
                    'section_analysis' => $sectionAnalysis,
                    'year_level_blocks' => $yearLevelBlocks,
                    'missing_blocks' => $missingBlocks,
                    'has_both_blocks' => empty($missingBlocks),
                    'summary' => [
                        'total_sections' => count($sectionAnalysis),
                        'expected_sections' => count($yearLevelBlocks) * 2, // A and B for each year level
                        'missing_count' => count($missingBlocks)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Debug section distribution error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to debug section distribution: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Debug method to check sections and schedule entries
     */
    public function debugSectionsAndEntries(Request $request): JsonResponse
    {
        try {
            $groupId = $request->input('group_id');
            
            if (!$groupId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Group ID is required'
                ], 400);
            }

            // Get all sections
            $sections = Section::all();
            
            // Get schedule entries for this group
            $scheduleEntries = ScheduleEntry::with(['subject', 'section', 'instructor'])
                ->where('group_id', $groupId)
                ->get();

            // Get schedule meetings
            $scheduleMeetings = ScheduleMeeting::with(['entry.subject', 'entry.section'])
                ->whereHas('entry', function($query) use ($groupId) {
                    $query->where('group_id', $groupId);
                })
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'sections' => $sections->map(function($section) {
                        return [
                            'section_id' => $section->section_id,
                            'code' => $section->code,
                            'year_level' => $section->year_level,
                            'department' => $section->department
                        ];
                    }),
                    'schedule_entries' => $scheduleEntries->map(function($entry) {
                        return [
                            'entry_id' => $entry->entry_id,
                            'subject_code' => $entry->subject_code,
                            'subject_description' => $entry->subject_description,
                            'year_level' => $entry->year_level,
                            'block' => $entry->block,
                            'section_code' => $entry->section ? $entry->section->code : 'No section',
                            'section_year_level' => $entry->section ? $entry->section->year_level : 'No year level'
                        ];
                    }),
                    'schedule_meetings' => $scheduleMeetings->map(function($meeting) {
                        return [
                            'meeting_id' => $meeting->meeting_id,
                            'day' => $meeting->day,
                            'start_time' => $meeting->start_time,
                            'end_time' => $meeting->end_time,
                            'subject_code' => $meeting->entry->subject_code,
                            'year_level' => $meeting->entry->year_level,
                            'section_code' => $meeting->entry->section ? $meeting->entry->section->code : 'No section'
                        ];
                    })
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Debug sections and entries error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to debug sections and entries: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Regenerate sections and fix all year level assignments
     */
    public function regenerateSectionsAndFixAssignments(Request $request): JsonResponse
    {
        try {
            $groupId = $request->input('group_id');
            
            if (!$groupId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Group ID is required'
                ], 400);
            }

            // Get the schedule group to determine department
            $scheduleGroup = ScheduleGroup::find($groupId);
            if (!$scheduleGroup) {
                return response()->json([
                    'success' => false,
                    'message' => 'Schedule group not found'
                ], 404);
            }

            $department = $scheduleGroup->department;
            
            // Get all schedule entries for this group
            $scheduleEntries = ScheduleEntry::with(['subject', 'section'])
                ->where('group_id', $groupId)
                ->get();

            $fixedCount = 0;
            $issues = [];
            $processedSubjects = [];

            foreach ($scheduleEntries as $entry) {
                if ($entry->subject) {
                    $subjectCode = $entry->subject->code;
                    $correctYearLevel = $entry->year_level; // Use actual year level from data
                    
                    // Skip if we've already processed this subject
                    if (in_array($subjectCode, $processedSubjects)) {
                        continue;
                    }
                    $processedSubjects[] = $subjectCode;
                    
                    // Create sections for both A and B blocks with correct year level
                    $sectionAId = $this->resolveSectionId($department, $correctYearLevel, 'A');
                    $sectionBId = $this->resolveSectionId($department, $correctYearLevel, 'B');
                    
                    // Update all entries for this subject to use the correct section
                    $subjectEntries = ScheduleEntry::where('group_id', $groupId)
                        ->where('subject_id', $entry->subject_id)
                        ->get();
                    
                    foreach ($subjectEntries as $subjectEntry) {
                        $currentYearLevel = $subjectEntry->year_level;
                        $currentBlock = $subjectEntry->block ?? 'A';
                        
                        if ($currentYearLevel !== $correctYearLevel) {
                            // Determine which section to use based on current block
                            $newSectionId = ($currentBlock === 'B') ? $sectionBId : $sectionAId;
                            
                            $subjectEntry->section_id = $newSectionId;
                            $subjectEntry->save();
                            
                            $fixedCount++;
                            $issues[] = [
                                'subject_code' => $subjectCode,
                                'old_year_level' => $currentYearLevel,
                                'new_year_level' => $correctYearLevel,
                                'block' => $currentBlock,
                                'entry_id' => $subjectEntry->entry_id
                            ];
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Regenerated sections and fixed {$fixedCount} year level assignments",
                'fixed_count' => $fixedCount,
                'issues' => $issues,
                'processed_subjects' => $processedSubjects
            ]);

        } catch (Exception $e) {
            Log::error('Regenerate sections and fix assignments error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to regenerate sections and fix assignments: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fix year level assignments in existing schedule data
     */
    public function fixYearLevelAssignments(Request $request): JsonResponse
    {
        try {
            $groupId = $request->input('group_id');
            
            if (!$groupId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Group ID is required'
                ], 400);
            }

            $scheduleEntries = ScheduleEntry::with(['subject', 'section'])
                ->where('group_id', $groupId)
                ->get();

            $fixedCount = 0;
            $issues = [];

            foreach ($scheduleEntries as $entry) {
                if ($entry->subject) {
                    $correctYearLevel = $entry->year_level; // Use actual year level from data
                    $currentYearLevel = $entry->year_level;
                    
                    if ($currentYearLevel !== $correctYearLevel) {
                        // Update the section to reflect the correct year level
                        $correctSectionId = $this->resolveSectionId(
                            $entry->section->department ?? 'BSBA',
                            $correctYearLevel,
                            $entry->block ?? 'A'
                        );
                        
                        $entry->section_id = $correctSectionId;
                        $entry->save();
                        
                        $fixedCount++;
                        $issues[] = [
                            'subject_code' => $entry->subject->code,
                            'old_year_level' => $currentYearLevel,
                            'new_year_level' => $correctYearLevel
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Fixed {$fixedCount} year level assignments",
                'fixed_count' => $fixedCount,
                'issues' => $issues
            ]);

        } catch (Exception $e) {
            Log::error('Fix year level assignments error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fix year level assignments: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Log generated schedule data in organized format
     */
    private function logGeneratedSchedule(array $schedules, string $algorithm, int $groupId): void
    {
        try {
            // Debug: Log the first few schedules to see what data we're receiving
            Log::info("logGeneratedSchedule: Received " . count($schedules) . " schedules");
            if (!empty($schedules)) {
                Log::info("logGeneratedSchedule: First schedule data: " . json_encode($schedules[0]));
                Log::info("logGeneratedSchedule: First schedule instructor_name: " . ($schedules[0]['instructor_name'] ?? 'NOT_SET'));
                Log::info("logGeneratedSchedule: First schedule instructor: " . ($schedules[0]['instructor'] ?? 'NOT_SET'));
                Log::info("logGeneratedSchedule: First schedule employment_type: " . ($schedules[0]['employment_type'] ?? 'NOT_SET'));
            }
            
            // Get department from schedule group
            $scheduleGroup = ScheduleGroup::find($groupId);
            $department = $scheduleGroup ? $scheduleGroup->department : 'BSBA';
            $semester = $scheduleGroup ? $scheduleGroup->semester : '1st Semester';
            $schoolYear = $scheduleGroup ? $scheduleGroup->school_year : '2024-2025';
            
            // Count subjects per year level
            $subjectsPerYearLevel = [];
            $yearLevels = [];
            $sections = [];
            $instructors = [];
            $totalSubjects = 0;
            
            foreach ($schedules as $schedule) {
                $yearLevel = $schedule['year_level'] ?? '1st Year';
                $subjectCode = $schedule['subject_code'] ?? '';
                $sectionKey = $yearLevel . ' ' . ($schedule['block'] ?? 'A');
                $instructor = $schedule['instructor'] ?? '';
                
                // Count subjects per year level
                if (!isset($subjectsPerYearLevel[$yearLevel])) {
                    $subjectsPerYearLevel[$yearLevel] = [];
                }
                $subjectsPerYearLevel[$yearLevel][$subjectCode] = true;
                
                // Track year levels
                if (!in_array($yearLevel, $yearLevels)) {
                    $yearLevels[] = $yearLevel;
                }
                
                // Track sections
                if (!in_array($sectionKey, $sections)) {
                    $sections[] = $sectionKey;
                }
                
                // Track instructors
                if (!in_array($instructor, $instructors)) {
                    $instructors[] = $instructor;
                }
                
                $totalSubjects++;
            }
            
            // Count subjects per year level
            $subjectsCountPerYearLevel = [];
            foreach ($subjectsPerYearLevel as $yearLevel => $subjects) {
                $subjectsCountPerYearLevel[$yearLevel] = count($subjects);
            }
            
            // Optional diagnostic logging for schedule summary
            if ((bool) env('LOG_SCHEDULER_VERBOSE', false)) {
                Log::debug('=== GENERATED SCHEDULE SUMMARY ===', [
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                    'algorithm' => $algorithm,
                    'department' => $department,
                    'semester' => $semester,
                    'school_year' => $schoolYear,
                    'total_schedules' => count($schedules),
                    'total_subjects' => $totalSubjects,
                    'total_instructors' => count($instructors),
                    'total_year_levels' => count($yearLevels),
                    'year_levels' => $yearLevels,
                    'subjects_per_year_level' => $subjectsCountPerYearLevel,
                    'total_sections' => count($sections)
                ]);
                Log::debug('=== DETAILED GENERATED SCHEDULE ===');
                $schedulesToLog = array_slice($schedules, 0, 3);
                foreach ($schedulesToLog as $index => $schedule) {
                    Log::debug("Schedule #{$index}", $schedule);
                }
            }
            if (count($schedules) > 10) {
                Log::info("... and " . (count($schedules) - 10) . " more schedules (truncated to prevent pipe overflow)");
            }
            
        } catch (\Exception $e) {
            Log::error('Error logging generated schedule: ' . $e->getMessage());
        }
    }

    /**
     * Get file upload logs for debugging and monitoring
     */
    public function getFileUploadLogs(Request $request): JsonResponse
    {
        try {
            $logFile = storage_path('logs/laravel.log');
            
            if (!file_exists($logFile)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Log file not found'
                ], 404);
            }
            
            // Read the last 1000 lines of the log file
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $recentLines = array_slice($lines, -1000);
            
            // Filter for file upload related logs
            $fileUploadLogs = [];
            $currentLog = null;
            
            foreach ($recentLines as $line) {
                if (strpos($line, '=== FILE UPLOAD DATA SUMMARY ===') !== false) {
                    if ($currentLog) {
                        $fileUploadLogs[] = $currentLog;
                    }
                    $currentLog = [
                        'timestamp' => $this->extractTimestamp($line),
                        'summary' => [],
                        'courses' => []
                    ];
                } elseif ($currentLog && strpos($line, 'timestamp') !== false) {
                    // Extract summary data
                    $currentLog['summary'] = $this->extractLogData($line);
                } elseif ($currentLog && strpos($line, 'Course ') !== false) {
                    // Extract course data
                    $currentLog['courses'][] = $this->extractLogData($line);
                } elseif ($currentLog && strpos($line, '=== DETAILED COURSE INFORMATION ===') !== false) {
                    // Skip this line
                    continue;
                } elseif ($currentLog && strpos($line, '=== FILE UPLOAD DATA SUMMARY ===') === false) {
                    // End of current log entry
                    if (strpos($line, '[') === false && strpos($line, ']') === false) {
                        $fileUploadLogs[] = $currentLog;
                        $currentLog = null;
                    }
                }
            }
            
            // Add the last log if it exists
            if ($currentLog) {
                $fileUploadLogs[] = $currentLog;
            }
            
            return response()->json([
                'success' => true,
                'data' => array_reverse($fileUploadLogs), // Most recent first
                'total_logs' => count($fileUploadLogs)
            ]);
            
        } catch (Exception $e) {
            Log::error('Error retrieving file upload logs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve logs: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Extract timestamp from log line
     */
    private function extractTimestamp(string $line): string
    {
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
            return $matches[1];
        }
        return 'Unknown';
    }
    
    /**
     * Extract structured data from log line
     */
    private function extractLogData(string $line): array
    {
        // Try to extract JSON data from the line
        if (preg_match('/\{.*\}/', $line, $matches)) {
            $jsonData = json_decode($matches[0], true);
            if ($jsonData) {
                return $jsonData;
            }
        }
        
        // Fallback to basic parsing
        return ['raw_line' => trim($line)];
    }

    /**
     * Generate schedule using OR-Tools algorithm with Option A and Option B session distribution
     */
    public function generateScheduleOrtools(Request $request): JsonResponse
    {
        // Increase execution time limit for Python algorithms
        set_time_limit(120); // 2 minutes (increased from 1)
        
        try {
            $rawInstructorData = $request->input('instructorData', []);
            $semester = $request->input('semester', '1st Semester');
            $schoolYear = $request->input('schoolYear', '2024-2025');
            $sessionOption = $request->input('sessionOption', 'A'); // A or B

            if (empty($rawInstructorData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No instructor data provided'
                ], 400);
            }

            // Log the file upload data in organized format
            $this->logFileUploadData($rawInstructorData, $semester, $schoolYear);

            // Transform the raw data array to the expected format
            $instructorData = $this->transformInstructorData($rawInstructorData);
            Log::info("Transformed " . count($rawInstructorData) . " raw entries to " . count($instructorData) . " valid entries");

            // Extract department from the first valid entry
            $department = 'BSBA'; // Default
            if (!empty($instructorData)) {
                foreach ($instructorData as $entry) {
                    if (!empty($entry['dept'])) {
                        $department = $entry['dept'];
                        break;
                    }
                }
            }
            
            // Allow multiple schedule versions for same department/semester/year
            // Users can generate and compare different schedules
            $scheduleGroup = ScheduleGroup::create([
                'department' => $department,
                'school_year' => $schoolYear,
                'semester' => $semester,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Get available rooms
            $rooms = Room::all()->toArray();
            
            // Run OR-Tools algorithm with session distribution
            $ortoolsResult = ['success' => false, 'message' => 'OR-Tools algorithm not attempted'];
            try {
                $ortoolsResult = $this->runOrtoolsAlgorithm($instructorData, $rooms, $scheduleGroup->group_id, $sessionOption);
            } catch (Exception $e) {
                $ortoolsResult = ['success' => false, 'message' => 'OR-Tools algorithm exception: ' . $e->getMessage()];
            } catch (Error $e) {
                $ortoolsResult = ['success' => false, 'message' => 'OR-Tools algorithm fatal error: ' . $e->getMessage()];
            }

            // Process OR-Tools results if successful
            if ($ortoolsResult['success']) {
                Log::info('OR-Tools algorithm succeeded with ' . count($ortoolsResult['schedules']) . ' entries');
                
                // Store the generated schedules
                if (!empty($ortoolsResult['schedules'])) {
                    $this->logGeneratedSchedule($ortoolsResult['schedules'], 'ortools', $scheduleGroup->group_id);
                }
                
                return response()->json([
                    'success' => true,
                    'message' => 'Schedule generated successfully using OR-Tools with Option ' . $sessionOption,
                    'data' => $ortoolsResult,
                    'session_option' => $sessionOption,
                    'algorithm' => 'ortools'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'OR-Tools algorithm failed: ' . ($ortoolsResult['message'] ?? 'Unknown error'),
                    'ortools' => $ortoolsResult['message'] ?? 'Unknown error',
                    'algorithm' => 'ortools'
                ], 500);
            }
            
        } catch (Exception $e) {
            Log::error('Generate schedule error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error generating schedule: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get available session distribution options for a subject
     */
    public function getSessionOptions(Request $request): JsonResponse
    {
        try {
            $units = $request->input('units', 3);
            $employmentType = $request->input('employment_type', 'FULL-TIME');
            
            // Call Python script to get session options
            $python = base_path('venv/Scripts/python.exe');
            if (!file_exists($python)) {
                $python = 'python';
            }
            
            $payload = [
                'action' => 'get_session_options',
                'units' => $units,
                'employment_type' => $employmentType
            ];
            
            $process = new \Symfony\Component\Process\Process([
                $python, '-c', 
                "
import sys
import json
sys.path.append('PythonAlgo')
from TimeScheduler import get_available_session_options

payload = json.loads(sys.stdin.read())
units = payload['units']
employment_type = payload['employment_type']

descriptions = get_available_session_options(units, employment_type)

result = {
    'success': True,
    'descriptions': descriptions,
    'available_options': list(descriptions.keys())
}

print(json.dumps(result))
                "
            ]);
            
            $process->setInput(json_encode($payload));
            $process->setTimeout(30);
            $process->setWorkingDirectory(base_path());
            $process->run();
            
            if (!$process->isSuccessful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to get session options',
                    'error' => $process->getErrorOutput()
                ], 500);
            }
            
            $result = json_decode($process->getOutput(), true);
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error getting session options: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate schedule with selected session options
     */
    public function generateScheduleWithOptions(Request $request): JsonResponse
    {
        try {
            $units = $request->input('units', 3);
            $employmentType = $request->input('employment_type', 'FULL-TIME');
            $sessionOption = $request->input('session_option', 'A');
            
            // Call Python script to generate schedule with options
            $python = base_path('venv/Scripts/python.exe');
            if (!file_exists($python)) {
                $python = 'python';
            }
            
            $payload = [
                'action' => 'generate_schedule_with_options',
                'units' => $units,
                'employment_type' => $employmentType,
                'session_option' => $sessionOption
            ];
            
            $process = new \Symfony\Component\Process\Process([
                $python, '-c', 
                "
import sys
import json
sys.path.append('PythonAlgo')
from TimeScheduler import generate_session_distribution_options

payload = json.loads(sys.stdin.read())
units = payload['units']
employment_type = payload['employment_type']
session_option = payload['session_option']

sessions = generate_session_distribution_options(units, employment_type, session_option)

result = {
    'success': True,
    'sessions': sessions,
    'option': session_option,
    'total_units': units,
    'employment_type': employment_type
}

print(json.dumps(result))
                "
            ]);
            
            $process->setInput(json_encode($payload));
            $process->setTimeout(30);
            $process->setWorkingDirectory(base_path());
            $process->run();
            
            if (!$process->isSuccessful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate schedule with options',
                    'error' => $process->getErrorOutput()
                ], 500);
            }
            
            $result = json_decode($process->getOutput(), true);
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating schedule with options: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * DEPRECATED: Old PHP fallback algorithm - no longer used with new architecture
     * This method is kept for compatibility but should not be called
     */
    private function runPhpFallbackAlgorithm(array $instructorData, array $rooms, int $groupId): array
    {
        Log::warning("DEPRECATED: runPhpFallbackAlgorithm called but no longer used in new architecture");
        return [
            'success' => false, 
            'message' => 'This method is deprecated and replaced by the new PHP primary scheduler',
            'schedules' => []
        ];
    }

    /**
     * PRESERVED: Legacy methods continue below - leaving them for compatibility
     */
    private function legacyExtractDepartment($instructorData): string
    {
            $department = 'BSBA'; // Default
            if (!empty($instructorData)) {
                foreach ($instructorData as $entry) {
                    if (!empty($entry['dept'])) {
                        $department = $entry['dept'];
                        break;
                    }
                }
            }
            
            // Synchronize subjects across blocks to ensure both A and B sections are created
            $synchronizedData = $this->synchronizeSubjectsAcrossBlocks($instructorData);
            Log::info("Synchronized data: " . count($synchronizedData) . " entries (original: " . count($instructorData) . ")");
            
            $startTime = time();
            $maxExecutionTime = 120; // 120 seconds max for PHP fallback to process all courses
            
            // Increase PHP execution time limit
            set_time_limit(300); // 5 minutes (increased from 2.5)
            
            $schedules = [];
            $usedSlots = [];
            $usedRooms = [];
            $usedCombinations = []; // Track unique combinations to prevent duplicates
            
            // Initialize room usage tracking for dynamic room distribution
            $roomUsage = []; // Track room usage by day and time
            $roomDayUsage = []; // Track daily room usage counts
            $rrIndex = 0; // Round-robin index for room selection
            
            // Comprehensive time slots for better scheduling coverage - Updated for 7:00 AM start and 1:00 PM afternoon
            // Create time slots with better day distribution by interleaving days
            $allTimeSlots = [
                // Monday - Updated time slots
                ['day' => 'Monday', 'start' => '07:00:00', 'end' => '08:30:00'],
                ['day' => 'Monday', 'start' => '08:30:00', 'end' => '10:00:00'],
                ['day' => 'Monday', 'start' => '10:00:00', 'end' => '11:30:00'],
                ['day' => 'Monday', 'start' => '11:30:00', 'end' => '12:30:00'],
                ['day' => 'Monday', 'start' => '12:30:00', 'end' => '14:00:00'],
                ['day' => 'Monday', 'start' => '14:00:00', 'end' => '15:30:00'],
                ['day' => 'Monday', 'start' => '15:30:00', 'end' => '17:00:00'],
                ['day' => 'Monday', 'start' => '17:00:00', 'end' => '19:00:00'],
                ['day' => 'Monday', 'start' => '19:00:00', 'end' => '21:00:00'],
                // Additional slots for better coverage
                ['day' => 'Monday', 'start' => '07:00:00', 'end' => '09:00:00'],
                ['day' => 'Monday', 'start' => '07:00:00', 'end' => '09:30:00'], // 2.5-hour slot
                ['day' => 'Monday', 'start' => '09:00:00', 'end' => '11:00:00'],
                ['day' => 'Monday', 'start' => '11:00:00', 'end' => '12:30:00'],
                ['day' => 'Monday', 'start' => '12:30:00', 'end' => '14:30:00'],
                ['day' => 'Monday', 'start' => '14:30:00', 'end' => '16:30:00'],
                ['day' => 'Monday', 'start' => '16:30:00', 'end' => '18:30:00'],
                ['day' => 'Monday', 'start' => '18:30:00', 'end' => '20:30:00'],
                ['day' => 'Monday', 'start' => '20:30:00', 'end' => '21:00:00'],
                // 5-hour slots for 5-unit courses (respecting lunch break)
                ['day' => 'Monday', 'start' => '06:30:00', 'end' => '11:30:00'],
                ['day' => 'Monday', 'start' => '13:00:00', 'end' => '18:00:00'],
                
                // Tuesday - Updated time slots
                ['day' => 'Tuesday', 'start' => '07:00:00', 'end' => '08:30:00'],
                ['day' => 'Tuesday', 'start' => '08:30:00', 'end' => '10:00:00'],
                ['day' => 'Tuesday', 'start' => '10:00:00', 'end' => '11:30:00'],
                ['day' => 'Tuesday', 'start' => '11:30:00', 'end' => '12:30:00'],
                ['day' => 'Tuesday', 'start' => '12:30:00', 'end' => '14:00:00'],
                ['day' => 'Tuesday', 'start' => '14:00:00', 'end' => '15:30:00'],
                ['day' => 'Tuesday', 'start' => '15:30:00', 'end' => '17:00:00'],
                ['day' => 'Tuesday', 'start' => '17:00:00', 'end' => '19:00:00'],
                ['day' => 'Tuesday', 'start' => '19:00:00', 'end' => '21:00:00'],
                ['day' => 'Tuesday', 'start' => '07:00:00', 'end' => '09:00:00'],
                ['day' => 'Tuesday', 'start' => '07:00:00', 'end' => '09:30:00'], // 2.5-hour slot
                ['day' => 'Tuesday', 'start' => '09:00:00', 'end' => '11:00:00'],
                ['day' => 'Tuesday', 'start' => '11:00:00', 'end' => '12:30:00'],
                ['day' => 'Tuesday', 'start' => '12:30:00', 'end' => '14:30:00'],
                ['day' => 'Tuesday', 'start' => '14:30:00', 'end' => '16:30:00'],
                ['day' => 'Tuesday', 'start' => '16:30:00', 'end' => '18:30:00'],
                ['day' => 'Tuesday', 'start' => '18:30:00', 'end' => '20:30:00'],
                ['day' => 'Tuesday', 'start' => '20:30:00', 'end' => '21:00:00'],
                // 5-hour slots for 5-unit courses (respecting lunch break)
                ['day' => 'Tuesday', 'start' => '06:30:00', 'end' => '11:30:00'],
                ['day' => 'Tuesday', 'start' => '13:00:00', 'end' => '18:00:00'],
                
                // Wednesday - Updated time slots
                ['day' => 'Wednesday', 'start' => '07:00:00', 'end' => '08:30:00'],
                ['day' => 'Wednesday', 'start' => '08:30:00', 'end' => '10:00:00'],
                ['day' => 'Wednesday', 'start' => '10:00:00', 'end' => '11:30:00'],
                ['day' => 'Wednesday', 'start' => '11:30:00', 'end' => '12:30:00'],
                ['day' => 'Wednesday', 'start' => '12:30:00', 'end' => '14:00:00'],
                ['day' => 'Wednesday', 'start' => '14:00:00', 'end' => '15:30:00'],
                ['day' => 'Wednesday', 'start' => '15:30:00', 'end' => '17:00:00'],
                ['day' => 'Wednesday', 'start' => '17:00:00', 'end' => '19:00:00'],
                ['day' => 'Wednesday', 'start' => '19:00:00', 'end' => '21:00:00'],
                ['day' => 'Wednesday', 'start' => '07:00:00', 'end' => '09:00:00'],
                ['day' => 'Wednesday', 'start' => '07:00:00', 'end' => '09:30:00'], // 2.5-hour slot
                ['day' => 'Wednesday', 'start' => '09:00:00', 'end' => '11:00:00'],
                ['day' => 'Wednesday', 'start' => '11:00:00', 'end' => '12:30:00'],
                ['day' => 'Wednesday', 'start' => '12:30:00', 'end' => '14:30:00'],
                ['day' => 'Wednesday', 'start' => '14:30:00', 'end' => '16:30:00'],
                ['day' => 'Wednesday', 'start' => '16:30:00', 'end' => '18:30:00'],
                ['day' => 'Wednesday', 'start' => '18:30:00', 'end' => '20:30:00'],
                ['day' => 'Wednesday', 'start' => '20:30:00', 'end' => '21:00:00'],
                // 5-hour slots for 5-unit courses (respecting lunch break)
                ['day' => 'Wednesday', 'start' => '06:30:00', 'end' => '11:30:00'],
                ['day' => 'Wednesday', 'start' => '13:00:00', 'end' => '18:00:00'],
                
                // Thursday - Updated time slots
                ['day' => 'Thursday', 'start' => '07:00:00', 'end' => '08:30:00'],
                ['day' => 'Thursday', 'start' => '08:30:00', 'end' => '10:00:00'],
                ['day' => 'Thursday', 'start' => '10:00:00', 'end' => '11:30:00'],
                ['day' => 'Thursday', 'start' => '11:30:00', 'end' => '12:30:00'],
                ['day' => 'Thursday', 'start' => '12:30:00', 'end' => '14:00:00'],
                ['day' => 'Thursday', 'start' => '14:00:00', 'end' => '15:30:00'],
                ['day' => 'Thursday', 'start' => '15:30:00', 'end' => '17:00:00'],
                ['day' => 'Thursday', 'start' => '17:00:00', 'end' => '19:00:00'],
                ['day' => 'Thursday', 'start' => '19:00:00', 'end' => '21:00:00'],
                ['day' => 'Thursday', 'start' => '07:00:00', 'end' => '09:00:00'],
                ['day' => 'Thursday', 'start' => '07:00:00', 'end' => '09:30:00'], // 2.5-hour slot
                ['day' => 'Thursday', 'start' => '09:00:00', 'end' => '11:00:00'],
                ['day' => 'Thursday', 'start' => '11:00:00', 'end' => '12:30:00'],
                ['day' => 'Thursday', 'start' => '12:30:00', 'end' => '14:30:00'],
                ['day' => 'Thursday', 'start' => '14:30:00', 'end' => '16:30:00'],
                ['day' => 'Thursday', 'start' => '16:30:00', 'end' => '18:30:00'],
                ['day' => 'Thursday', 'start' => '18:30:00', 'end' => '20:30:00'],
                ['day' => 'Thursday', 'start' => '20:30:00', 'end' => '21:00:00'],
                // 5-hour slots for 5-unit courses (respecting lunch break)
                ['day' => 'Thursday', 'start' => '06:30:00', 'end' => '11:30:00'],
                ['day' => 'Thursday', 'start' => '13:00:00', 'end' => '18:00:00'],
                
                // Friday - Enhanced time slots for better utilization (currently only 8% usage)
                // Morning slots (7:00 AM - 1:00 PM)
                ['day' => 'Friday', 'start' => '07:00:00', 'end' => '08:30:00'],
                ['day' => 'Friday', 'start' => '08:30:00', 'end' => '10:00:00'],
                ['day' => 'Friday', 'start' => '10:00:00', 'end' => '11:30:00'],
                ['day' => 'Friday', 'start' => '11:30:00', 'end' => '12:30:00'],
                ['day' => 'Friday', 'start' => '07:00:00', 'end' => '09:00:00'],
                ['day' => 'Friday', 'start' => '07:00:00', 'end' => '09:30:00'], // 2.5-hour slot
                ['day' => 'Friday', 'start' => '09:00:00', 'end' => '11:00:00'],
                ['day' => 'Friday', 'start' => '11:00:00', 'end' => '12:30:00'],
                ['day' => 'Friday', 'start' => '07:00:00', 'end' => '10:00:00'],
                ['day' => 'Friday', 'start' => '08:00:00', 'end' => '11:00:00'],
                ['day' => 'Friday', 'start' => '09:00:00', 'end' => '12:00:00'],
                
                // Afternoon slots (1:00 PM - 5:00 PM) - Currently UNUSED
                ['day' => 'Friday', 'start' => '12:30:00', 'end' => '14:00:00'],
                ['day' => 'Friday', 'start' => '14:00:00', 'end' => '15:30:00'],
                ['day' => 'Friday', 'start' => '15:30:00', 'end' => '17:00:00'],
                ['day' => 'Friday', 'start' => '12:30:00', 'end' => '14:30:00'],
                ['day' => 'Friday', 'start' => '14:30:00', 'end' => '16:30:00'],
                ['day' => 'Friday', 'start' => '16:30:00', 'end' => '18:30:00'],
                ['day' => 'Friday', 'start' => '12:30:00', 'end' => '15:30:00'],
                ['day' => 'Friday', 'start' => '13:00:00', 'end' => '16:00:00'],
                ['day' => 'Friday', 'start' => '13:30:00', 'end' => '16:30:00'],
                ['day' => 'Friday', 'start' => '14:00:00', 'end' => '17:00:00'],
                
                // Evening slots (5:00 PM - 8:45 PM) - Currently UNUSED
                ['day' => 'Friday', 'start' => '17:00:00', 'end' => '19:00:00'],
                ['day' => 'Friday', 'start' => '19:00:00', 'end' => '21:00:00'],
                ['day' => 'Friday', 'start' => '18:30:00', 'end' => '20:30:00'],
                ['day' => 'Friday', 'start' => '20:30:00', 'end' => '21:00:00'],
                ['day' => 'Friday', 'start' => '17:00:00', 'end' => '20:00:00'],
                ['day' => 'Friday', 'start' => '17:30:00', 'end' => '20:30:00'],
                ['day' => 'Friday', 'start' => '18:00:00', 'end' => '21:00:00'],
                ['day' => 'Friday', 'start' => '16:00:00', 'end' => '19:00:00'],
                
                // 5-hour evening slots for PART-TIME instructors (5-unit courses)
                ['day' => 'Friday', 'start' => '16:00:00', 'end' => '21:00:00'],
                ['day' => 'Friday', 'start' => '17:00:00', 'end' => '22:00:00'],
                
                // 5-hour slots for 5-unit courses (respecting lunch break)
                ['day' => 'Friday', 'start' => '06:30:00', 'end' => '11:30:00'],
                ['day' => 'Friday', 'start' => '13:00:00', 'end' => '18:00:00'],
                ['day' => 'Friday', 'start' => '13:00:00', 'end' => '17:00:00'],
                ['day' => 'Friday', 'start' => '14:00:00', 'end' => '18:00:00'],
                
                // Saturday - Enhanced time slots for better utilization (currently only 8% usage)
                // Morning slots (7:00 AM - 1:00 PM)
                ['day' => 'Saturday', 'start' => '07:00:00', 'end' => '08:30:00'],
                ['day' => 'Saturday', 'start' => '08:30:00', 'end' => '10:00:00'],
                ['day' => 'Saturday', 'start' => '10:00:00', 'end' => '11:30:00'],
                ['day' => 'Saturday', 'start' => '11:30:00', 'end' => '12:30:00'],
                ['day' => 'Saturday', 'start' => '07:00:00', 'end' => '09:00:00'],
                ['day' => 'Saturday', 'start' => '07:00:00', 'end' => '09:30:00'], // 2.5-hour slot
                ['day' => 'Saturday', 'start' => '09:00:00', 'end' => '11:00:00'],
                ['day' => 'Saturday', 'start' => '11:00:00', 'end' => '12:30:00'],
                ['day' => 'Saturday', 'start' => '07:00:00', 'end' => '10:00:00'],
                ['day' => 'Saturday', 'start' => '08:00:00', 'end' => '11:00:00'],
                ['day' => 'Saturday', 'start' => '09:00:00', 'end' => '12:00:00'],
                
                // Afternoon slots (1:00 PM - 5:00 PM) - Currently UNUSED
                ['day' => 'Saturday', 'start' => '12:30:00', 'end' => '14:00:00'],
                ['day' => 'Saturday', 'start' => '14:00:00', 'end' => '15:30:00'],
                ['day' => 'Saturday', 'start' => '15:30:00', 'end' => '17:00:00'],
                ['day' => 'Saturday', 'start' => '12:30:00', 'end' => '14:30:00'],
                ['day' => 'Saturday', 'start' => '14:30:00', 'end' => '16:30:00'],
                ['day' => 'Saturday', 'start' => '16:30:00', 'end' => '18:30:00'],
                ['day' => 'Saturday', 'start' => '12:30:00', 'end' => '15:30:00'],
                ['day' => 'Saturday', 'start' => '13:00:00', 'end' => '16:00:00'],
                ['day' => 'Saturday', 'start' => '13:30:00', 'end' => '16:30:00'],
                ['day' => 'Saturday', 'start' => '14:00:00', 'end' => '17:00:00'],
                
                // Evening slots (5:00 PM - 8:45 PM) - Currently UNUSED
                ['day' => 'Saturday', 'start' => '17:00:00', 'end' => '19:00:00'],
                ['day' => 'Saturday', 'start' => '19:00:00', 'end' => '21:00:00'],
                ['day' => 'Saturday', 'start' => '18:30:00', 'end' => '20:30:00'],
                ['day' => 'Saturday', 'start' => '20:30:00', 'end' => '21:00:00'],
                ['day' => 'Saturday', 'start' => '17:00:00', 'end' => '20:00:00'],
                ['day' => 'Saturday', 'start' => '17:30:00', 'end' => '20:30:00'],
                ['day' => 'Saturday', 'start' => '18:00:00', 'end' => '21:00:00'],
                ['day' => 'Saturday', 'start' => '16:00:00', 'end' => '19:00:00'],
                
                // 5-hour evening slots for PART-TIME instructors (5-unit courses)
                ['day' => 'Saturday', 'start' => '16:00:00', 'end' => '21:00:00'],
                ['day' => 'Saturday', 'start' => '17:00:00', 'end' => '22:00:00'],
                
                // 5-hour slots for 5-unit courses (respecting lunch break)
                ['day' => 'Saturday', 'start' => '06:30:00', 'end' => '11:30:00'],
                ['day' => 'Saturday', 'start' => '13:00:00', 'end' => '18:00:00'],
                ['day' => 'Saturday', 'start' => '13:00:00', 'end' => '17:00:00'],
                ['day' => 'Saturday', 'start' => '14:00:00', 'end' => '18:00:00'],
            ];
            
            // Shuffle time slots to ensure better day distribution
            // Group slots by day first, then interleave them for better distribution
            $dayGroups = [];
            foreach ($allTimeSlots as $slot) {
                $day = $slot['day'];
                if (!isset($dayGroups[$day])) {
                    $dayGroups[$day] = [];
                }
                $dayGroups[$day][] = $slot;
            }
            
            // Interleave slots from different days for better distribution
            $timeSlots = [];
            $maxSlotsPerDay = max(array_map('count', $dayGroups));
            
            for ($i = 0; $i < $maxSlotsPerDay; $i++) {
                foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
                    if (isset($dayGroups[$day][$i])) {
                        $timeSlots[] = $dayGroups[$day][$i];
                    }
                }
            }
            
            // Debug: Log the first 20 time slots to verify day distribution
            $first20Slots = array_slice($timeSlots, 0, 20);
            $dayCounts = [];
            foreach ($first20Slots as $slot) {
                $day = $slot['day'];
                $dayCounts[$day] = ($dayCounts[$day] ?? 0) + 1;
            }
            Log::info("PHP Fallback - First 20 time slots day distribution: " . json_encode($dayCounts));
            
            // Process ALL synchronized courses but with timeout protection
            Log::info("Processing " . count($synchronizedData) . " total courses (synchronized)");
            
            // Debug: Log year levels in synchronized data
            $yearLevelsInData = array_unique(array_column($synchronizedData, 'yearLevel'));
            Log::info("Year levels in synchronized data: " . implode(', ', $yearLevelsInData));
            
            // Sort courses by priority: ensure both A and B sections are scheduled
            $courseGroups = [];
            foreach ($synchronizedData as $course) {
                $key = $course['courseCode'] . '|' . $course['yearLevel'];
                if (!isset($courseGroups[$key])) {
                    $courseGroups[$key] = [];
                }
                $courseGroups[$key][] = $course;
            }
            
            // Prioritize courses that need both A and B sections
            $prioritizedCourses = [];
            foreach ($courseGroups as $key => $courses) {
                // If this course has both A and B sections, prioritize it
                $hasA = false;
                $hasB = false;
                foreach ($courses as $course) {
                    if ($course['block'] === 'A') $hasA = true;
                    if ($course['block'] === 'B') $hasB = true;
                }
                
                if ($hasA && $hasB) {
                    // High priority: both sections needed
                    array_unshift($prioritizedCourses, ...$courses);
                } else {
                    // Lower priority: single section
                    $prioritizedCourses = array_merge($prioritizedCourses, $courses);
                }
            }
            
            // Process each course individually to preserve original year level and block assignments
            foreach ($prioritizedCourses as $course) {
                // Check timeout
                if (time() - $startTime > $maxExecutionTime) {
                    Log::warning("PHP fallback algorithm timeout reached");
                    return [
                        'success' => false,
                        'message' => 'PHP fallback algorithm timeout reached',
                        'schedules' => $schedules,
                        'total_entries' => count($schedules)
                    ];
                }
                
                $units = intval($course['unit'] ?? 3);
                $yearLevel = $course['yearLevel'] ?? '1st Year';
                $block = $course['block'] ?? 'A';
                
                // Reduced logging to prevent pipe overflow
                if (count($synchronizedData) <= 10 || count($schedules) % 5 === 0) {
                    Log::info("Processing course group {$course['courseCode']}|{$yearLevel}: {$course['courseCode']} - {$course['subject']} ({$units} units) for {$course['name']}");
                }
                
                // Create unique key for this course assignment
                $uniqueKey = $groupId . '|' . $course['courseCode'] . '|' . $course['name'] . '|' . $yearLevel . $block;
                
                if (in_array($uniqueKey, $usedCombinations)) {
                    Log::info("Skipping course {$course['courseCode']} {$yearLevel} {$block} - already processed");
                    continue;
                }
                
                // Calculate course requirements for intelligent room matching
                $requiresLab = strcasecmp($course['sessionType'] ?? 'Non-Lab session', 'Lab session') === 0;
                $courseRequirements = [
                    'requires_lab' => $requiresLab,
                    'estimated_students' => min(50, max(20, $units * 10)), // Estimate based on units
                    'units' => $units,
                    'year_level' => $yearLevel,
                    'department' => $course['dept'] ?? $department
                ];
                
                // Debug: Log lab session processing
                if ($requiresLab) {
                    Log::info("Processing LAB session: {$course['courseCode']} - {$yearLevel} {$block}");
                }
                
                $assigned = false;
                
                // FAIR SCHEDULING: Both PART-TIME and FULL-TIME can use all available time slots
                $employmentType = $this->normalizeEmploymentType($course['employmentType'] ?? 'FULL-TIME');
                $filteredTimeSlots = $timeSlots; // Use all time slots for fair scheduling
                
                Log::info("FAIR SCHEDULING: Instructor {$course['name']} ({$employmentType}) can use all " . count($timeSlots) . " available time slots");
                
                // RESPECT LUNCH TIME: Filter out slots that overlap with lunch break (11:30 AM - 1:00 PM)
                $filteredTimeSlots = array_filter($filteredTimeSlots, function($slot) {
                    if (TimeScheduler::isLunchBreakViolation($slot['start'], $slot['end'])) {
                        Log::info("LUNCH RESPECT: Excluding slot {$slot['day']} {$slot['start']}-{$slot['end']} - overlaps with lunch break (11:30 AM - 1:00 PM)");
                        return false;
                    }
                    return true;
                });
                
                Log::info("LUNCH RESPECT: Filtered to " . count($filteredTimeSlots) . " slots (excluding lunch break 11:30 AM - 1:00 PM)");
                
                // Try to find an available slot and room for this course using dynamic room distribution
                // Randomize slot selection to ensure better day distribution
                $maxSlotsToTry = min(50, count($filteredTimeSlots)); // Limit slots to try for performance
                $slotIndices = range(0, min($maxSlotsToTry - 1, count($filteredTimeSlots) - 1));
                shuffle($slotIndices); // Randomize the order of slots to try
                
                for ($j = 0; $j < count($slotIndices) && !$assigned; $j++) {
                    $i = $slotIndices[$j];
                    $slot = $filteredTimeSlots[$i];
                    $slotKey = $slot['day'] . '|' . $slot['start'] . '|' . $slot['end'];
                    
                    // Use dynamic room selection instead of iterating through all rooms
                    $roomId = $this->pickAvailableRoomId($groupId, $slot['day'], $slot['start'], $slot['end'], $rooms, null, $roomUsage, $roomDayUsage, $rrIndex, $courseRequirements);
                    
                    // Skip if no suitable room found (e.g., no lab rooms available for lab session)
                    if ($roomId === 0) {
                        continue;
                    }
                    
                    $roomKey = $roomId . '|' . $slotKey;
                        
                        if (in_array($roomKey, $usedRooms)) {
                            continue;
                        }
                        
                        try {
                            // Debug: Log what we're trying to create (reduced logging for performance)
                            if (count($schedules) % 5 === 0) {
                                Log::info("Attempting to create entry: {$course['courseCode']} - {$yearLevel} {$block} for {$course['name']}");
                            }
                            
                            // Create schedule entry preserving original year level and block
                            $entry = $this->createEntryAndMeeting(
                                $groupId,
                            $roomId,  // Use dynamically selected room
                                $course['name'],
                                $course['courseCode'],
                                $course['subject'],
                                $units,
                                $slot['day'],
                                $slot['start'],
                                $slot['end'],
                                $course['dept'] ?? $department,
                                $yearLevel,  // Use original year level
                                $block       // Use original block
                            );
                            
                            if ($entry) {
                                $roomName = $rooms[$roomId]['room_name'] ?? 'Unknown';
                                $isLabRoom = $rooms[$roomId]['is_lab'] ?? false;
                                Log::info("Successfully created entry for {$course['courseCode']} - {$yearLevel} {$block} in room {$roomName} (Lab: " . ($isLabRoom ? 'Yes' : 'No') . ")");
                                
                                // Debug: Log lab room assignment
                                if ($requiresLab && !$isLabRoom) {
                                    Log::warning("LAB session assigned to NON-LAB room: {$course['courseCode']} -> {$roomName}");
                                } elseif ($requiresLab && $isLabRoom) {
                                    Log::info("LAB session correctly assigned to LAB room: {$course['courseCode']} -> {$roomName}");
                                }
                                
                                $schedules[] = $entry;
                                $usedRooms[] = $roomKey; // Only track room-specific usage
                                $usedCombinations[] = $uniqueKey; // Track unique combinations
                                $assigned = true;
                                // Reduced logging to prevent pipe overflow
                                if (count($schedules) % 10 === 0) {
                                    Log::info("Assigned " . count($schedules) . " courses so far...");
                                }
                                break; // Only break out of time slot loop, continue with next course
                            }
                        } catch (\Exception $e) {
                            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                                // Reduced logging to prevent pipe overflow
                                $usedCombinations[] = $uniqueKey; // Mark as used to prevent retry
                                continue;
                            } else {
                                // Only log critical errors to prevent pipe overflow
                                if (strpos($e->getMessage(), 'timeout') !== false || strpos($e->getMessage(), 'fatal') !== false) {
                                    Log::warning("Failed to create entry for {$course['courseCode']} {$yearLevel} {$block}: " . $e->getMessage());
                                }
                                continue;
                            }
                        }
                    }
                }
                
                if (!$assigned) {
                    Log::warning("Could not assign course {$course['courseCode']} {$yearLevel} {$block}");
                    // Debug: Log available resources
                    $availableSlots = count($timeSlots);
                    $availableRooms = count($rooms);
                    $usedRoomsCount = count($usedRooms);
                    Log::info("Debug: {$availableSlots} time slots, {$availableRooms} rooms total, {$usedRoomsCount} rooms used");
                    
                    // Try backtracking: remove a conflicting course to make room
                    $backtrackSuccess = $this->attemptBacktracking($course, $schedules, $usedSlots, $usedRooms, $usedCombinations, $timeSlots, $rooms, $groupId, $department);
                    if ($backtrackSuccess) {
                        Log::info("Successfully assigned {$course['courseCode']} {$yearLevel} {$block} via backtracking");
                        $schedules[] = $backtrackSuccess;
                        $assigned = true;
                    }
                }
            
            $processedCourses = count($schedules);
            $totalCourses = count($synchronizedData);
            $originalCourses = count($instructorData);
            $successRate = $totalCourses > 0 ? round(($processedCourses / $totalCourses) * 100, 1) : 0;
            
            // Check for overlaps in the generated schedule
            $overlaps = $this->detectScheduleOverlaps($schedules);
            if (!empty($overlaps)) {
                Log::warning("Found " . count($overlaps) . " schedule overlaps:");
                foreach ($overlaps as $overlap) {
                    $schedule1 = $overlap['schedule1'];
                    $schedule2 = $overlap['schedule2'];
                    Log::warning("Overlap: {$schedule1['instructor_name']} - {$schedule1['subject_code']} conflicts with {$schedule2['subject_code']} on {$schedule1['day']} {$schedule1['start_time']}-{$schedule1['end_time']}");
                }
            }
            
            // Track course completion status
            $courseCompletionStatus = $this->analyzeCourseCompletion($synchronizedData, $schedules);
            Log::info("Course completion analysis: " . json_encode($courseCompletionStatus));
            
            // Reduced logging to prevent pipe overflow
            Log::info("PHP fallback completed: {$processedCourses}/{$totalCourses} courses scheduled ({$successRate}% success rate)");
            
            // Prepare response data
            $responseData = [
                'success' => true,
                'message' => "PHP fallback algorithm completed: {$processedCourses}/{$totalCourses} courses scheduled ({$successRate}% success rate) - Both A and B sections included",
                'schedules' => $schedules,
                'total_entries' => count($schedules),
                'processed_courses' => $processedCourses,
                'total_courses' => $totalCourses,
                'original_courses' => $originalCourses,
                'success_rate' => $successRate,
                'algorithm' => 'php_fallback'
            ];
            
            // Log final summary without detailed data to prevent pipe overflow
            Log::info("Final summary: " . count($schedules) . " schedules created for " . count($synchronizedData) . " courses");
            
            // Debug: Count schedules by year level
            $schedulesByYearLevel = [];
            foreach ($schedules as $schedule) {
                $yearLevel = $schedule['year_level'] ?? 'Unknown';
                if (!isset($schedulesByYearLevel[$yearLevel])) {
                    $schedulesByYearLevel[$yearLevel] = 0;
                }
                $schedulesByYearLevel[$yearLevel]++;
            }
            Log::info("Schedules by year level: " . json_encode($schedulesByYearLevel));
            
            return $responseData;
    }
    
    /**
     * Attempt backtracking to assign a course by removing conflicting courses
     */
    private function attemptBacktracking(array $course, array &$schedules, array &$usedSlots, array &$usedRooms, array &$usedCombinations, array $timeSlots, array $rooms, int $groupId, string $department): ?array
    {
        $units = intval($course['unit'] ?? 3);
        $yearLevel = $course['yearLevel'] ?? '1st Year';
        $block = $course['block'] ?? 'A';
        
        // Find a suitable time slot and room
        foreach ($timeSlots as $slot) {
            $slotKey = $slot['day'] . '|' . $slot['start'] . '|' . $slot['end'];
            
            foreach ($rooms as $room) {
                $roomKey = $room['room_id'] . '|' . $slotKey;
                
                // Check if this slot/room combination conflicts with existing schedules
                $conflictingSchedule = null;
                foreach ($schedules as $index => $existingSchedule) {
                    if ($existingSchedule['day'] === $slot['day'] && 
                        $existingSchedule['room_id'] === $room['room_id'] &&
                        $this->timesOverlap($existingSchedule['start_time'], $existingSchedule['end_time'], $slot['start'], $slot['end'])) {
                        $conflictingSchedule = $existingSchedule;
                        $conflictingIndex = $index;
                        break;
                    }
                }
                
                if ($conflictingSchedule) {
                    // Try to reschedule the conflicting course to a different slot
                    $conflictingCourse = [
                        'courseCode' => $conflictingSchedule['subject_code'],
                        'name' => $conflictingSchedule['instructor'],
                        'subject' => $conflictingSchedule['subject_description'],
                        'unit' => $conflictingSchedule['unit'],
                        'yearLevel' => $conflictingSchedule['year_level'],
                        'block' => $conflictingSchedule['block'],
                        'dept' => $conflictingSchedule['dept'] ?? $department
                    ];
                    
                    // Remove the conflicting schedule temporarily
                    unset($schedules[$conflictingIndex]);
                    $schedules = array_values($schedules); // Re-index array
                    
                    // Update used slots and rooms
                    $conflictingSlotKey = $conflictingSchedule['day'] . '|' . $conflictingSchedule['start_time'] . '|' . $conflictingSchedule['end_time'];
                    $conflictingRoomKey = $conflictingSchedule['room_id'] . '|' . $conflictingSlotKey;
                    $usedSlots = array_filter($usedSlots, function($key) use ($conflictingSlotKey) {
                        return $key !== $conflictingSlotKey;
                    });
                    $usedRooms = array_filter($usedRooms, function($key) use ($conflictingRoomKey) {
                        return $key !== $conflictingRoomKey;
                    });
                    
                    // Try to find a new slot for the conflicting course
                    $rescheduled = false;
                    foreach ($timeSlots as $newSlot) {
                        $newSlotKey = $newSlot['day'] . '|' . $newSlot['start'] . '|' . $newSlot['end'];
                        
                        if (!in_array($newSlotKey, $usedSlots)) {
                            foreach ($rooms as $newRoom) {
                                $newRoomKey = $newRoom['room_id'] . '|' . $newSlotKey;
                                
                                if (!in_array($newRoomKey, $usedRooms)) {
                                    // Found a new slot for the conflicting course
                                    try {
                                        $newEntry = $this->createEntryAndMeeting(
                                            $groupId,
                                            $newRoom['room_id'],
                                            $conflictingCourse['name'],
                                            $conflictingCourse['courseCode'],
                                            $conflictingCourse['subject'],
                                            $conflictingCourse['unit'],
                                            $newSlot['day'],
                                            $newSlot['start'],
                                            $newSlot['end'],
                                            $conflictingCourse['dept'] ?? $department,
                                            $conflictingCourse['yearLevel'],
                                            $conflictingCourse['block']
                                        );
                                        
                                        if ($newEntry) {
                                            $schedules[] = $newEntry;
                                            $usedSlots[] = $newSlotKey;
                                            $usedRooms[] = $newRoomKey;
                                            $rescheduled = true;
                                            break 2;
                                        }
                                    } catch (\Exception $e) {
                                        // Continue trying other slots
                                        continue;
                                    }
                                }
                            }
                        }
                    }
                    
                    if ($rescheduled) {
                        // Now try to assign the original course
                        try {
                            $entry = $this->createEntryAndMeeting(
                                $groupId,
                                $room['room_id'],
                                $course['name'],
                                $course['courseCode'],
                                $course['subject'],
                                $units,
                                $slot['day'],
                                $slot['start'],
                                $slot['end'],
                                $course['dept'] ?? $department,
                                $yearLevel,
                                $block
                            );
                            
                            if ($entry) {
                                $usedSlots[] = $slotKey;
                                $usedRooms[] = $roomKey;
                                return $entry;
                            }
                        } catch (\Exception $e) {
                            // Failed to assign, restore the conflicting schedule
                            $schedules[] = $conflictingSchedule;
                            $usedSlots[] = $conflictingSlotKey;
                            $usedRooms[] = $conflictingRoomKey;
                        }
                    } else {
                        // Couldn't reschedule conflicting course, restore it
                        $schedules[] = $conflictingSchedule;
                        $usedSlots[] = $conflictingSlotKey;
                        $usedRooms[] = $conflictingRoomKey;
                    }
                } else {
                    // No conflict, try to assign directly
                    try {
                        $entry = $this->createEntryAndMeeting(
                            $groupId,
                            $room['room_id'],
                            $course['name'],
                            $course['courseCode'],
                            $course['subject'],
                            $units,
                            $slot['day'],
                            $slot['start'],
                            $slot['end'],
                            $course['dept'] ?? $department,
                            $yearLevel,
                            $block
                        );
                        
                        if ($entry) {
                            $usedSlots[] = $slotKey;
                            $usedRooms[] = $roomKey;
                            return $entry;
                        }
                    } catch (\Exception $e) {
                        // Continue trying other slots
                        continue;
                    }
                }
            }
        }
        
        return null; // Backtracking failed
    }
    
    /**
     * Analyze course completion status to identify missing courses
     */
    private function analyzeCourseCompletion(array $synchronizedData, array $schedules): array
    {
        $completionStatus = [
            'total_courses' => count($synchronizedData),
            'scheduled_courses' => count($schedules),
            'missing_courses' => [],
            'course_groups' => [],
            'completion_rate' => 0
        ];
        
        // Group courses by course code and year level
        $courseGroups = [];
        foreach ($synchronizedData as $course) {
            $key = $course['courseCode'] . '|' . $course['yearLevel'];
            if (!isset($courseGroups[$key])) {
                $courseGroups[$key] = [];
            }
            $courseGroups[$key][] = $course;
        }
        
        // Check completion for each course group
        foreach ($courseGroups as $key => $courses) {
            $scheduledBlocks = [];
            $totalBlocks = count($courses);
            
            // Check which blocks are scheduled
            foreach ($schedules as $schedule) {
                foreach ($courses as $course) {
                    if ($schedule['subject_code'] === $course['courseCode'] && 
                        $schedule['year_level'] === $course['yearLevel'] && 
                        $schedule['block'] === $course['block']) {
                        $scheduledBlocks[] = $course['block'];
                    }
                }
            }
            
            $scheduledCount = count(array_unique($scheduledBlocks));
            $completionStatus['course_groups'][$key] = [
                'total_blocks' => $totalBlocks,
                'scheduled_blocks' => $scheduledCount,
                'missing_blocks' => $totalBlocks - $scheduledCount,
                'blocks' => array_unique(array_column($courses, 'block')),
                'scheduled_blocks_list' => array_unique($scheduledBlocks)
            ];
            
            // Identify missing courses
            if ($scheduledCount < $totalBlocks) {
                $missingBlocks = array_diff(array_unique(array_column($courses, 'block')), $scheduledBlocks);
                foreach ($missingBlocks as $missingBlock) {
                    $missingCourse = array_filter($courses, function($c) use ($missingBlock) {
                        return $c['block'] === $missingBlock;
                    });
                    if (!empty($missingCourse)) {
                        $missingCourse = reset($missingCourse);
                        $completionStatus['missing_courses'][] = [
                            'course_code' => $missingCourse['courseCode'],
                            'year_level' => $missingCourse['yearLevel'],
                            'block' => $missingCourse['block'],
                            'instructor' => $missingCourse['name'],
                            'units' => $missingCourse['unit']
                        ];
                    }
                }
            }
        }
        
        $completionStatus['completion_rate'] = $completionStatus['total_courses'] > 0 ? 
            round(($completionStatus['scheduled_courses'] / $completionStatus['total_courses']) * 100, 1) : 0;
        
        return $completionStatus;
    }

    /**
     * Analyze which courses were successfully scheduled vs missing
     */
    private function analyzeScheduledCourses(array $instructorData, array $savedSchedules): void
    {
        try {
            Log::info("=== COURSE SCHEDULING ANALYSIS ===");
            
            // Group original courses by course code + year level + block
            $originalCourses = [];
            foreach ($instructorData as $course) {
                $key = $course['courseCode'] . '|' . $course['yearLevel'] . '|' . $course['block'];
                $originalCourses[$key] = $course;
            }
            
            // Group saved schedules by course code + year level + block
            $scheduledCourses = [];
            foreach ($savedSchedules as $schedule) {
                $key = $schedule['subject_code'] . '|' . $schedule['year_level'] . '|' . $schedule['block'];
                if (!isset($scheduledCourses[$key])) {
                    $scheduledCourses[$key] = [];
                }
                $scheduledCourses[$key][] = $schedule;
            }
            
            Log::info("Original courses: " . count($originalCourses) . ", Scheduled course groups: " . count($scheduledCourses));
            
            // Find missing courses
            $missingCourses = [];
            foreach ($originalCourses as $key => $originalCourse) {
                if (!isset($scheduledCourses[$key])) {
                    $missingCourses[] = [
                        'course_code' => $originalCourse['courseCode'],
                        'course_name' => $originalCourse['subject'],
                        'instructor' => $originalCourse['name'],
                        'year_level' => $originalCourse['yearLevel'],
                        'block' => $originalCourse['block'],
                        'units' => $originalCourse['unit'],
                        'employment_type' => $originalCourse['employmentType']
                    ];
                }
            }
            
            if (empty($missingCourses)) {
                Log::info("✅ All " . count($originalCourses) . " courses were successfully scheduled!");
            } else {
                Log::warning("❌ " . count($missingCourses) . " courses are missing from the schedule:");
                foreach ($missingCourses as $missing) {
                    Log::warning("Missing: {$missing['course_code']} - {$missing['course_name']} ({$missing['units']} units) for {$missing['year_level']} {$missing['block']} by {$missing['instructor']} ({$missing['employment_type']})");
                }
            }
            
            // Show schedule count by course (to understand session splitting)
            Log::info("=== SCHEDULE ENTRIES PER COURSE ===");
            foreach ($scheduledCourses as $key => $schedules) {
                $firstSchedule = $schedules[0];
                $units = $firstSchedule['unit'] ?? $firstSchedule['units'] ?? 'Unknown';
                
                // Count total meetings across all schedule entries for this course
                $totalMeetings = 0;
                foreach ($schedules as $schedule) {
                    $totalMeetings += $schedule['meetings_count'] ?? 1;
                }
                
                Log::info("Course: {$firstSchedule['subject_code']} ({$units} units) - {$firstSchedule['year_level']} {$firstSchedule['block']} has {$totalMeetings} meeting(s)");
            }
            
        } catch (Exception $e) {
            Log::error("Error in course scheduling analysis: " . $e->getMessage());
        }
    }

    /**
     * Get current instructor data for filter preferences
     */
    public function getCurrentInstructorData(Request $request): JsonResponse
    {
        try {
            // Get instructor data from session (stored when file is uploaded)
            $instructorData = session('current_instructor_data', []);
            
            if (empty($instructorData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No instructor data available. Please upload a file first.',
                    'instructors' => []
                ]);
            }

            // Transform the data for filter preferences
            $transformedInstructors = [];
            foreach ($instructorData as $entry) {
                $transformedInstructors[] = [
                    'name' => $entry['name'] ?? 'Unknown Instructor',
                    'courseCode' => $entry['courseCode'] ?? 'Unknown',
                    'subject' => $entry['subject'] ?? $entry['courseCode'] ?? 'Unknown Subject',
                    'unit' => $entry['unit'] ?? $entry['units'] ?? 0,
                    'employmentType' => $entry['employmentType'] ?? 'Full-time',
                    'sessionType' => $entry['sessionType'] ?? 'Non-Lab session',
                    'dept' => $entry['dept'] ?? 'General',
                    'yearLevel' => $entry['yearLevel'] ?? '1st Year',
                    'block' => $entry['block'] ?? 'A'
                ];
            }

            return response()->json([
                'success' => true,
                'instructors' => $transformedInstructors,
                'count' => count($transformedInstructors)
            ]);

        } catch (\Exception $e) {
            Log::error("Error getting current instructor data: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve instructor data',
                'instructors' => []
            ], 500);
        }
    }

    /**
     * Store instructor data in session for filter preferences
     */
    public function storeInstructorDataForFilter(Request $request): JsonResponse
    {
        try {
            $instructorData = $request->input('instructorData', []);
            
            // Store in session for filter preferences
            session(['current_instructor_data' => $instructorData]);
            
            Log::info("Stored " . count($instructorData) . " instructor entries for filter preferences");
            
            return response()->json([
                'success' => true,
                'message' => 'Instructor data stored successfully',
                'count' => count($instructorData)
            ]);

        } catch (\Exception $e) {
            Log::error("Error storing instructor data for filter: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to store instructor data'
            ], 500);
        }
    }

    /**
     * Analyze missing subjects and log per-course reasons why they could not be scheduled
     */
    private function analyzeMissingSubjectsReasons(array $missingKeys, array $inputData, array $scheduledData, array $rooms, string $department): void
    {
        if (empty($missingKeys)) {
            return;
        }
        
        // Build quick lookup for input entries by key
        $inputLookup = [];
        foreach ($inputData as $entry) {
            $key = ($entry['courseCode'] ?? '') . '|' . ($entry['yearLevel'] ?? '') . '|' . ($entry['block'] ?? 'A');
            $inputLookup[$key] = $entry;
        }
        
        // Initialize resource tracker with existing scheduled data
        $tracker = new \App\Services\ResourceTracker();
        // Normalize schedule entries for tracker
        $normalized = array_map(function($s) {
            return [
                'instructor' => $s['instructor'] ?? ($s['instructor_name'] ?? 'Unknown'),
                'room_id' => (int)($s['room_id'] ?? 0),
                'section' => $s['section'] ?? (($s['year_level'] ?? '') . ' ' . ($s['block'] ?? '')),
                'day' => $s['day'] ?? 'Mon',
                'start_time' => $s['start_time'] ?? '00:00:00',
                'end_time' => $s['end_time'] ?? '00:00:00'
            ];
        }, $scheduledData);
        $tracker->loadExistingSchedules($normalized);
        
        // Generate comprehensive slots once
        $slots = \App\Services\TimeScheduler::generateComprehensiveTimeSlots();
        $roomIds = array_values(array_map(function($r){ return (int)($r['id'] ?? 0); }, $rooms));
        
        $aggregate = [
            'no_instructor_time' => 0,
            'no_room_available' => 0,
            'section_busy' => 0,
            'no_slot_fit' => 0,
            'feasible_found' => 0
        ];
        
        foreach ($missingKeys as $key) {
            $entry = $inputLookup[$key] ?? null;
            if (!$entry) {
                Log::warning("Missing-subject analysis: input not found for {$key}");
                continue;
            }
            $course = $entry['courseCode'] ?? 'UNKNOWN';
            $year = $entry['yearLevel'] ?? '';
            $block = $entry['block'] ?? 'A';
            $instructor = $entry['instructor'] ?? ($entry['name'] ?? 'Unknown');
            $sectionName = ($department ? ($department . '-') : '') . $year . ' ' . $block;
            
            $noInstructor = true; $noRoom = true; $sectionClash = true; $foundFeasible = false;
            foreach ($slots as $slot) {
                $day = $slot['day'] ?? 'Mon';
                $start = $slot['start'] ?? '00:00:00';
                $end = $slot['end'] ?? '00:00:00';
                
                // First, check instructor and section availability (cheap)
                $instrFree = $tracker->isInstructorAvailable($instructor, $day, $start, $end);
                $sectFree = $tracker->isSectionAvailable($sectionName, $day, $start, $end);
                if ($instrFree) { $noInstructor = false; }
                if ($sectFree) { $sectionClash = false; }
                if (!$instrFree || !$sectFree) {
                    continue; // try next slot
                }
                
                // Try rooms
                $roomFit = false;
                foreach ($roomIds as $roomId) {
                    if ($roomId <= 0) continue;
                    if ($tracker->isRoomAvailable($roomId, $day, $start, $end)) {
                        $roomFit = true;
                        // feasible option found
                        $foundFeasible = true;
                        break;
                    }
                }
                if ($roomFit) {
                    break; // we can place this subject somewhere
                } else {
                    $noRoom = true; // remains true unless a room was found in any slot
                }
            }
            
            if ($foundFeasible) {
                $aggregate['feasible_found']++;
                Log::info("UNSCHEDULED BUT FEASIBLE: {$key} — At least one viable (day/time/room) exists; review prioritization/ordering.");
            } else {
                // Determine dominant reason
                if ($noInstructor) { $aggregate['no_instructor_time']++; Log::error("UNSCHEDULED REASON: {$key} — Instructor busy in all viable slots."); }
                if ($sectionClash) { $aggregate['section_busy']++; Log::error("UNSCHEDULED REASON: {$key} — Section already occupied in all viable slots."); }
                if ($noRoom && !$noInstructor && !$sectionClash) { $aggregate['no_room_available']++; Log::error("UNSCHEDULED REASON: {$key} — No room available for otherwise free instructor/section slots."); }
                if (!$noInstructor && !$sectionClash && $noRoom) {
                    // already counted no_room_available
                } elseif (!$noInstructor && !$sectionClash && !$noRoom && !$foundFeasible) {
                    $aggregate['no_slot_fit']++; Log::error("UNSCHEDULED REASON: {$key} — No slot fit after checks (unexpected).");
                }
            }
        }
        
        Log::info("=== UNSCHEDULED SUBJECTS ANALYSIS SUMMARY ===", $aggregate);
    }
}