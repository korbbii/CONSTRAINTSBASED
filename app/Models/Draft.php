<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Draft extends Model
{
    use HasFactory;

    protected $primaryKey = 'draft_id';

    protected $fillable = [
        'group_id',
        'draft_name'
    ];

    protected $casts = [
        'group_id' => 'integer'
    ];

    /**
     * Get the schedule group for this draft
     */
    public function scheduleGroup()
    {
        return $this->belongsTo(ScheduleGroup::class, 'group_id', 'group_id');
    }
} 