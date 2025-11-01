<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RoomController extends Controller
{
    /**
     * Display a listing of the rooms with pagination
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 10); // Default 10 items per page
        $rooms = Room::paginate($perPage);
        
        return response()->json([
            'data' => $rooms->items(),
            'pagination' => [
                'current_page' => $rooms->currentPage(),
                'last_page' => $rooms->lastPage(),
                'per_page' => $rooms->perPage(),
                'total' => $rooms->total(),
                'from' => $rooms->firstItem(),
                'to' => $rooms->lastItem(),
                'has_more_pages' => $rooms->hasMorePages(),
                'has_previous_page' => $rooms->previousPageUrl() !== null,
                'has_next_page' => $rooms->nextPageUrl() !== null,
            ]
        ]);
    }

    /**
     * Get all rooms without pagination (for client-side pagination)
     */
    public function getAll(): JsonResponse
    {
        $rooms = Room::all();
        return response()->json($rooms);
    }

    /**
     * Store a newly created room
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'room_name' => 'required|string|max:255|unique:rooms',
                'capacity' => 'required|integer|min:1',
                'is_lab' => 'boolean',
                'building' => 'nullable|string|in:hs,shs,annex',
                'floor_level' => 'nullable|string|in:Floor 1,Floor 2,Floor 3,Floor 4'
            ]);

            $room = Room::create($request->all());
            return response()->json($room, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error creating room: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while creating the room'
            ], 500);
        }
    }

    /**
     * Display the specified room
     */
    public function show(Room $room): JsonResponse
    {
        return response()->json($room->load('scheduleEntries'));
    }

    /**
     * Update the specified room
     */
    public function update(Request $request, Room $room): JsonResponse
    {
        $request->validate([
            'room_name' => 'required|string|max:255|unique:rooms,room_name,' . $room->room_id . ',room_id',
            'capacity' => 'required|integer|min:1',
            'is_lab' => 'boolean',
            'building' => 'nullable|string|in:hs,shs,annex',
            'floor_level' => 'nullable|string|in:Floor 1,Floor 2,Floor 3,Floor 4'
        ]);

        $room->update($request->all());
        return response()->json($room);
    }

    /**
     * Remove the specified room
     */
    public function destroy(Room $room): JsonResponse
    {
        // Check if room has schedules before deleting
        if ($room->scheduleEntries()->count() > 0) {
            return response()->json(['message' => 'Cannot delete room with existing schedules'], 422);
        }

        $room->delete();
        return response()->json(['message' => 'Room deleted successfully']);
    }

    /**
     * Get rooms by type (lab or regular)
     */
    public function getByType(Request $request): JsonResponse
    {
        $isLab = $request->boolean('is_lab');
        $rooms = Room::where('is_lab', $isLab)->get();
        return response()->json($rooms);
    }

    /**
     * Get available rooms for a specific time slot
     */
    public function getAvailableRooms(Request $request): JsonResponse
    {
        $request->validate([
            'day' => 'required|string',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time'
        ]);

        $day = $request->day;
        $startTime = $request->start_time;
        $endTime = $request->end_time;

        // Get rooms that don't have conflicting schedules
        $occupiedRoomIds = \App\Models\ScheduleMeeting::where('day', $day)
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where(function ($q) use ($startTime, $endTime) {
                    $q->where('start_time', '<', $endTime)
                      ->where('end_time', '>', $startTime);
                });
            })
            ->pluck('room_id');

        $availableRooms = Room::whereNotIn('room_id', $occupiedRoomIds)->get();
        
        return response()->json($availableRooms);
    }
} 