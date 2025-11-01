<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Instructor extends Model
{
    use HasFactory;

    protected $table = 'instructors';
    protected $primaryKey = 'instructor_id';

    protected $fillable = [
        'name',
        'employment_type',
        'is_active',
    ];

    public function scheduleEntries()
    {
        return $this->hasMany(ScheduleEntry::class, 'instructor_id', 'instructor_id');
    }
}


