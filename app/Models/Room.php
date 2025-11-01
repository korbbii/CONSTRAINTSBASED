<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;

    protected $table = 'rooms';
    protected $primaryKey = 'room_id';

    protected $fillable = [
        'room_name',
        'building',
        'floor_level',
        'capacity',
        'is_lab'
    ];

    protected $casts = [
        'is_lab' => 'boolean',
        'capacity' => 'integer'
    ];

    /**
     * Get the schedule entries for this room
     */
    public function scheduleEntries()
    {
        return $this->hasMany(ScheduleEntry::class, 'room_id', 'room_id');
    }

    /**
     * Scope to filter rooms by building
     */
    public function scopeByBuilding($query, $building)
    {
        return $query->where('building', $building);
    }

    /**
     * Scope to filter rooms by floor level
     */
    public function scopeByFloorLevel($query, $floorLevel)
    {
        return $query->where('floor_level', $floorLevel);
    }

    /**
     * Scope to filter rooms by building and floor level
     */
    public function scopeByBuildingAndFloor($query, $building, $floorLevel)
    {
        return $query->where('building', $building)->where('floor_level', $floorLevel);
    }

    /**
     * Get formatted room location (Building - Floor Level)
     */
    public function getLocationAttribute()
    {
        if ($this->building && $this->floor_level) {
            return "{$this->building} - {$this->floor_level}";
        }
        return $this->building ?? $this->floor_level ?? 'Unknown Location';
    }
} 