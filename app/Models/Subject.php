<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;

    protected $table = 'subjects';
    protected $primaryKey = 'subject_id';

    protected $fillable = [
        'code',
        'description',
        'units',
    ];

    public function scheduleEntries()
    {
        return $this->hasMany(ScheduleEntry::class, 'subject_id', 'subject_id');
    }
}


