<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleEntry extends Model
{
    use HasFactory;

    protected $table = 'schedule_entries';
    protected $primaryKey = 'entry_id';

    protected $fillable = [
        'group_id',
        'instructor_id',
        'subject_id',
        'section_id',
        'status'
    ];

    protected $casts = [
    ];

    // Removed appends since fields are now direct columns

    /**
     * Get the schedule group for this entry
     */
    public function scheduleGroup()
    {
        return $this->belongsTo(ScheduleGroup::class, 'group_id', 'group_id');
    }

    // Room is associated to meetings, not entries

    public function instructor()
    {
        return $this->belongsTo(Instructor::class, 'instructor_id', 'instructor_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id', 'subject_id');
    }

    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id', 'section_id');
    }

    public function meetings()
    {
        return $this->hasMany(ScheduleMeeting::class, 'entry_id', 'entry_id');
    }

    protected $appends = [
        'subject_code',
        'subject_description',
        'units',
        'instructor_name',
        'day',
        'start_time',
        'end_time',
        'year_level',
        'block',
        'department',
        'employment_type'
    ];

    // Compatibility accessors for legacy consumers (views/JS)
    public function getSubjectCodeAttribute(): ?string
    {
        return optional($this->subject)->code;
    }

    public function getSubjectDescriptionAttribute(): ?string
    {
        return optional($this->subject)->description;
    }

    public function getUnitsAttribute(): ?int
    {
        return optional($this->subject)->units;
    }

    public function getInstructorNameAttribute(): ?string
    {
        return optional($this->instructor)->name;
    }

    public function getDayAttribute(): ?string
    {
        $m = $this->meetings->first();
        return $m ? $m->day : null;
    }

    public function getStartTimeAttribute(): ?string
    {
        $m = $this->meetings->first();
        return $m ? $m->start_time : null;
    }

    public function getEndTimeAttribute(): ?string
    {
        $m = $this->meetings->first();
        return $m ? $m->end_time : null;
    }

    public function getYearLevelAttribute(): ?string
    {
        $code = optional($this->section)->code;
        if (!$code) return null;
        // Expect format like DEPT-3rd Year A → extract year level
        if (preg_match('/-(\d+(?:st|nd|rd|th)\s+Year)/', $code, $m)) {
            return $m[1];
        }
        // Fallback for old format DEPT-2A
        if (preg_match('/-(\d+)/', $code, $m)) {
            return $m[1];
        }
        return null;
    }

    public function getBlockAttribute(): ?string
    {
        $code = optional($this->section)->code;
        if (!$code) return null;
        // Expect format like DEPT-3rd Year A → extract block letter
        if (preg_match('/Year\s+([A-Z])$/', $code, $m)) {
            return $m[1];
        }
        // Fallback for old format DEPT-2A
        if (preg_match('/\d+([A-Z])$/', $code, $m)) {
            return $m[1];
        }
        return null;
    }

    public function getDepartmentAttribute(): ?string
    {
        return optional($this->section)->department;
    }

    public function getEmploymentTypeAttribute(): ?string
    {
        return $this->employment_type ?? optional($this->instructor)->employment_type;
    }

    /**
     * Get the drafts for this schedule entry
     */
    public function drafts()
    {
        return $this->hasMany(Draft::class);
    }
} 