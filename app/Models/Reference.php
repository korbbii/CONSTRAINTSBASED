<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reference extends Model
{
    use HasFactory;

    protected $table = 'reference_schedules';
    protected $primaryKey = 'reference_id';

    protected $fillable = [
        'group_id',
        'time',
        'day',
        'room',
        'instructor',
        'subject'
    ];

    protected $casts = [
        'day' => 'string',
    ];

    /**
     * Get the reference group that owns this schedule
     */
    public function referenceGroup()
    {
        return $this->belongsTo(ReferenceGroup::class, 'group_id', 'group_id');
    }

    /**
     * Scope to filter by school year
     */
    public function scopeBySchoolYear($query, $schoolYear)
    {
        return $query->whereHas('referenceGroup', function ($q) use ($schoolYear) {
            $q->where('school_year', $schoolYear);
        });
    }

    /**
     * Scope to filter by education level
     */
    public function scopeByEducationLevel($query, $educationLevel)
    {
        return $query->whereHas('referenceGroup', function ($q) use ($educationLevel) {
            $q->where('education_level', $educationLevel);
        });
    }

    /**
     * Scope to filter by year level
     */
    public function scopeByYearLevel($query, $yearLevel)
    {
        return $query->whereHas('referenceGroup', function ($q) use ($yearLevel) {
            $q->where('year_level', $yearLevel);
        });
    }

    /**
     * Scope to filter by room
     */
    public function scopeByRoom($query, $room)
    {
        return $query->where('room', $room);
    }

    /**
     * Scope to filter by instructor
     */
    public function scopeByInstructor($query, $instructor)
    {
        return $query->where('instructor', $instructor);
    }

    /**
     * Scope to filter by day and time
     */
    public function scopeByDayAndTime($query, $day, $time)
    {
        return $query->where('day', $day)->where('time', $time);
    }

    /**
     * Check if there's a conflict for a specific room, day, and time
     */
    public static function hasRoomConflict($room, $day, $time, $schoolYear = null)
    {
        $query = static::where('room', $room)
                      ->where('day', $day)
                      ->where('time', $time);
        
        if ($schoolYear) {
            $query->whereHas('referenceGroup', function ($q) use ($schoolYear) {
                $q->where('school_year', $schoolYear);
            });
        }
        
        return $query->exists();
    }

    /**
     * Check if there's a conflict for a specific instructor, day, and time
     */
    public static function hasInstructorConflict($instructor, $day, $time, $schoolYear = null)
    {
        $query = static::where('instructor', $instructor)
                      ->where('day', $day)
                      ->where('time', $time);
        
        if ($schoolYear) {
            $query->whereHas('referenceGroup', function ($q) use ($schoolYear) {
                $q->where('school_year', $schoolYear);
            });
        }
        
        return $query->exists();
    }

    /**
     * Get conflicting schedules for a specific room, day, and time
     */
    public static function getRoomConflicts($room, $day, $time, $schoolYear = null)
    {
        $query = static::where('room', $room)
                      ->where('day', $day)
                      ->where('time', $time);
        
        if ($schoolYear) {
            $query->whereHas('referenceGroup', function ($q) use ($schoolYear) {
                $q->where('school_year', $schoolYear);
            });
        }
        
        return $query->with('referenceGroup')->get();
    }

    /**
     * Get conflicting schedules for a specific instructor, day, and time
     */
    public static function getInstructorConflicts($instructor, $day, $time, $schoolYear = null)
    {
        $query = static::where('instructor', $instructor)
                      ->where('day', $day)
                      ->where('time', $time);
        
        if ($schoolYear) {
            $query->whereHas('referenceGroup', function ($q) use ($schoolYear) {
                $q->where('school_year', $schoolYear);
            });
        }
        
        return $query->with('referenceGroup')->get();
    }
}
