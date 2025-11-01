<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleGroup extends Model
{
    use HasFactory;

    protected $table = 'schedule_groups';
    protected $primaryKey = 'group_id';

    protected $fillable = [
        'department',
        'school_year',
        'semester'
    ];

    /**
     * Get the schedule entries for this group
     */
    public function scheduleEntries()
    {
        return $this->hasMany(ScheduleEntry::class, 'group_id', 'group_id');
    }
} 