<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use App\Models\ScheduleEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DraftsController extends Controller
{
    /**
     * Display a listing of the drafts
     */
    public function index(): JsonResponse
    {
        $drafts = Draft::with('scheduleGroup')->get();
        return response()->json($drafts);
    }



    /**
     * Store a newly created draft
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'entry_id' => 'required|exists:schedule_entries,entry_id'
        ]);

        // Check if draft already exists for this schedule
        $existingDraft = Draft::where('entry_id', $request->entry_id)->first();
        if ($existingDraft) {
            return response()->json(['message' => 'Draft already exists for this schedule'], 422);
        }

        $draft = Draft::create($request->all());
        return response()->json($draft->load('scheduleGroup'), 201);
    }

    /**
     * Display the specified draft
     */
    public function show(Draft $draft): JsonResponse
    {
        $draft = Draft::with(['scheduleGroup.scheduleEntries.room','scheduleGroup.scheduleEntries.meetings'])->findOrFail($draft->id);
        
        // Get the schedule group and entries
        $scheduleGroup = $draft->scheduleGroup;
        if (!$scheduleGroup) {
            return response()->json([
                'success' => false,
                'message' => 'Schedule group not found for this draft.'
            ], 404);
        }
        
        // Group entries by year level and block
        $entries = $scheduleGroup->scheduleEntries()->with(['section','instructor','subject','room','meetings'])->get();
        $grouped = $entries->groupBy(function ($entry) {
            return optional($entry->section)->code ?? 'UNKNOWN-SECTION';
        });
        
        return response()->json([
            'success' => true,
            'draft' => $draft,
            'scheduleGroup' => $scheduleGroup,
            'groupedSchedules' => $grouped,
            'department' => $scheduleGroup->department ?? 'General'
        ]);
    }

    /**
     * Update the specified draft
     */
    public function update(Request $request, Draft $draft): JsonResponse
    {
        $request->validate([
            'entry_id' => 'required|exists:schedule_entries,entry_id'
        ]);

        $draft->update($request->all());
        return response()->json($draft->load('scheduleGroup'));
    }

    /**
     * Remove the specified draft
     */
    public function destroy(Draft $draft): JsonResponse
    {
        $draft->delete();
        return response()->json(['message' => 'Draft deleted successfully']);
    }

    /**
     * Approve a draft (move schedule from draft to active)
     */
    public function approve(Draft $draft): JsonResponse
    {
        // The schedule is already active, just remove the draft
        $draft->delete();
        return response()->json(['message' => 'Draft approved successfully']);
    }

    /**
     * Reject a draft (delete both draft and associated schedule)
     */
    public function reject(Draft $draft): JsonResponse
    {
        $schedule = null;
        $draft->delete();
        $schedule->delete();
        
        return response()->json(['message' => 'Draft rejected and schedule deleted']);
    }

    /**
     * Get drafts by department
     */
    public function getByDepartment(Request $request): JsonResponse
    {
        $request->validate([
            'department' => 'required|string'
        ]);

        $drafts = Draft::whereHas('scheduleGroup', function ($query) use ($request) {
            $query->where('department', $request->department);
        })->with('scheduleGroup')->get();

        return response()->json($drafts);
    }

    /**
     * Get drafts by school year and semester
     */
    public function getByAcademicPeriod(Request $request): JsonResponse
    {
        $request->validate([
            'school_year' => 'required|string',
            'semester' => 'required|string'
        ]);

        $drafts = Draft::whereHas('scheduleGroup', function ($query) use ($request) {
            $query->where('school_year', $request->school_year)
                  ->where('semester', $request->semester);
        })->with('scheduleGroup')->get();

        return response()->json($drafts);
    }

    /**
     * Bulk approve multiple drafts
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $request->validate([
            'draft_ids' => 'required|array',
            'draft_ids.*' => 'exists:drafts,draft_id'
        ]);

        $approvedCount = 0;
        foreach ($request->draft_ids as $draftId) {
            $draft = Draft::find($draftId);
            if ($draft) {
                $draft->delete();
                $approvedCount++;
            }
        }

        return response()->json([
            'message' => "Successfully approved {$approvedCount} drafts",
            'approved_count' => $approvedCount
        ]);
    }

    /**
     * Bulk reject multiple drafts
     */
    public function bulkReject(Request $request): JsonResponse
    {
        $request->validate([
            'draft_ids' => 'required|array',
            'draft_ids.*' => 'exists:drafts,draft_id'
        ]);

        $rejectedCount = 0;
        foreach ($request->draft_ids as $draftId) {
            $draft = Draft::find($draftId);
            if ($draft) {
                $draft->delete();
                $rejectedCount++;
            }
        }

        return response()->json([
            'message' => "Successfully rejected {$rejectedCount} drafts",
            'rejected_count' => $rejectedCount
        ]);
    }

    /**
     * Save a schedule as a draft
     */
    public function saveDraft(Request $request): JsonResponse
    {
        $request->validate([
            'group_id' => 'required|exists:schedule_groups,group_id',
            'draft_name' => 'required|string|max:255',
        ]);

        // Check if a draft for this group already exists
        $existing = Draft::where('group_id', $request->group_id)->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Draft already exists for this schedule.'
            ], 409);
        }

        $draft = Draft::create([
            'group_id' => $request->group_id,
            'draft_name' => $request->draft_name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Schedule saved as draft successfully.',
            'draft_id' => $draft->draft_id,
        ]);
    }
} 