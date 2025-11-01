<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferenceGroup extends Model
{
    use HasFactory;

    protected $table = 'reference_groups';
    protected $primaryKey = 'group_id';

    protected $fillable = [
        'school_year',
        'education_level',
        'year_level'
    ];

    /**
     * Get the reference schedules for this group
     */
    public function referenceSchedules()
    {
        return $this->hasMany(Reference::class, 'group_id', 'group_id');
    }

    /**
     * Scope to filter by school year
     */
    public function scopeBySchoolYear($query, $schoolYear)
    {
        return $query->where('school_year', $schoolYear);
    }

    /**
     * Scope to filter by education level
     */
    public function scopeByEducationLevel($query, $educationLevel)
    {
        return $query->where('education_level', $educationLevel);
    }

    /**
     * Scope to filter by year level
     */
    public function scopeByYearLevel($query, $yearLevel)
    {
        return $query->where('year_level', $yearLevel);
    }

    /**
     * Get a unique identifier for the group
     */
    public function getGroupIdentifierAttribute()
    {
        return "{$this->school_year} - {$this->education_level} - {$this->year_level}";
    }
}