<?php

namespace App\Http\Controllers;

use App\Models\ReferenceGroup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ReferenceGroupController extends Controller
{
    /**
     * Display a listing of reference groups
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 10);
        $groups = ReferenceGroup::with('referenceSchedules')->paginate($perPage);
        
        return response()->json([
            'data' => $groups->items(),
            'pagination' => [
                'current_page' => $groups->currentPage(),
                'last_page' => $groups->lastPage(),
                'per_page' => $groups->perPage(),
                'total' => $groups->total(),
                'from' => $groups->firstItem(),
                'to' => $groups->lastItem(),
                'has_more_pages' => $groups->hasMorePages(),
                'has_previous_page' => $groups->previousPageUrl() !== null,
                'has_next_page' => $groups->nextPageUrl() !== null,
            ]
        ]);
    }

    /**
     * Get all reference groups without pagination
     */
    public function getAll(): JsonResponse
    {
        $groups = ReferenceGroup::with('referenceSchedules')->get();
        return response()->json($groups);
    }

    /**
     * Store a newly created reference group
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'school_year' => 'required|string|max:255',
                'education_level' => 'required|string|max:255',
                'year_level' => 'required|string|max:255',
            ]);

            $group = ReferenceGroup::create($request->all());
            return response()->json($group->load('referenceSchedules'), 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating reference group: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while creating the reference group'
            ], 500);
        }
    }

    /**
     * Display the specified reference group
     */
    public function show(ReferenceGroup $referenceGroup): JsonResponse
    {
        return response()->json($referenceGroup->load('referenceSchedules'));
    }

    /**
     * Update the specified reference group
     */
    public function update(Request $request, ReferenceGroup $referenceGroup): JsonResponse
    {
        try {
            $request->validate([
                'school_year' => 'required|string|max:255',
                'education_level' => 'required|string|max:255',
                'year_level' => 'required|string|max:255',
            ]);

            $referenceGroup->update($request->all());
            return response()->json($referenceGroup->load('referenceSchedules'));
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating reference group: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while updating the reference group'
            ], 500);
        }
    }

    /**
     * Remove the specified reference group
     */
    public function destroy(ReferenceGroup $referenceGroup): JsonResponse
    {
        try {
            $referenceGroup->delete();
            return response()->json(['message' => 'Reference group deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Error deleting reference group: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while deleting the reference group'
            ], 500);
        }
    }

    /**
     * Get reference groups by school year
     */
    public function getBySchoolYear(Request $request): JsonResponse
    {
        $request->validate([
            'school_year' => 'required|string'
        ]);

        $groups = ReferenceGroup::bySchoolYear($request->school_year)->with('referenceSchedules')->get();
        return response()->json($groups);
    }

    /**
     * Get reference groups by education level
     */
    public function getByEducationLevel(Request $request): JsonResponse
    {
        $request->validate([
            'education_level' => 'required|string'
        ]);

        $groups = ReferenceGroup::byEducationLevel($request->education_level)->with('referenceSchedules')->get();
        return response()->json($groups);
    }

    /**
     * Get reference groups by year level
     */
    public function getByYearLevel(Request $request): JsonResponse
    {
        $request->validate([
            'year_level' => 'required|string'
        ]);

        $groups = ReferenceGroup::byYearLevel($request->year_level)->with('referenceSchedules')->get();
        return response()->json($groups);
    }

    /**
     * Bulk delete reference groups
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'group_ids' => 'required|array',
            'group_ids.*' => 'exists:reference_groups,group_id'
        ]);

        try {
            $deletedCount = ReferenceGroup::whereIn('group_id', $request->group_ids)->delete();
            
            return response()->json([
                'message' => "Successfully deleted {$deletedCount} reference groups",
                'deleted_count' => $deletedCount
            ]);
        } catch (\Exception $e) {
            Log::error('Error bulk deleting reference groups: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while deleting reference groups'
            ], 500);
        }
    }
}
