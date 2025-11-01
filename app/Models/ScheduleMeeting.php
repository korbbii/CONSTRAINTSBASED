<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleMeeting extends Model
{
    use HasFactory;

    protected $table = 'schedule_meetings';
    protected $primaryKey = 'meeting_id';

    protected $fillable = [
        'entry_id',
        'instructor_id',
        'day',
        'start_time',
        'end_time',
        'room_id',
        'meeting_type'
    ];

    public function entry()
    {
        return $this->belongsTo(ScheduleEntry::class, 'entry_id', 'entry_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id', 'room_id');
    }

    public function instructor()
    {
        return $this->belongsTo(Instructor::class, 'instructor_id', 'instructor_id');
    }

    /**
     * Check for conflicts with other meetings
     * Returns true if there's a conflict for instructor, room, or section
     * 
     * @param int $groupId Group ID to scope the check
     * @param int|null $instructorId Instructor ID to check
     * @param int|null $roomId Room ID to check
     * @param int|null $sectionId Section ID to check
     * @param string $day Day of the meeting
     * @param string $start Start time
     * @param string $end End time
     * @return bool True if conflict exists
     */
    public static function hasConflict(
        int $groupId,
        ?int $instructorId,
        ?int $roomId,
        ?int $sectionId,
        string $day,
        string $start,
        string $end,
        ?int $subjectId = null
    ): bool {
        // Expand combined day strings like "MonSat" so conflicts on any included day are detected
        $days = \App\Services\DayScheduler::parseCombinedDays($day);
        if (empty($days)) {
            $days = [\App\Services\DayScheduler::normalizeDay($day)];
        }
        
        // Time overlap condition (STRICT): start < other_end AND end > other_start
        $overlapCondition = function($q) use ($start, $end) {
            $q->where('start_time', '<', $end)
              ->where('end_time', '>', $start);
        };

        $query = self::whereIn('day', $days)
            ->where($overlapCondition)
            ->whereHas('entry', function($q) use ($groupId) {
                $q->where('group_id', $groupId);
            });

        // Check if any conflict exists: same instructor, same room, or same section
        $conflictExists = $query->where(function($q) use ($instructorId, $roomId, $sectionId) {
            $added = false;
            // Instructor conflict
            if (!is_null($instructorId)) {
                // Compare against meeting-level instructor_id
                $q->orWhere('instructor_id', $instructorId);
                $added = true;
            }
            // Room conflict (check via meeting's room_id)
            if (!is_null($roomId)) {
                $q->orWhere('room_id', $roomId);
                $added = true;
            }
            // Section conflict
            if (!is_null($sectionId)) {
                $q->orWhereHas('entry', function($qq) use ($sectionId) {
                    $qq->where('section_id', $sectionId);
                });
                $added = true;
            }
            // Ensure this "where" group isn't empty; if no filters provided, make it always-false
            if (!$added) {
                $q->whereRaw('0 = 1');
            }
        })->exists();

        return $conflictExists;
    }
}


