import sys
import json
import math
from typing import List, Dict, Any
from datetime import datetime, timedelta
import random

from ortools.sat.python import cp_model

# Import DAYS constant for day diversity
try:
    from .DayScheduler import DAYS
except ImportError:
    from DayScheduler import DAYS


def read_input() -> Dict[str, Any]:
    data = sys.stdin.read()
    if not data:
        return {}
    return json.loads(data)


def generate_comprehensive_time_slots() -> List[Dict[str, Any]]:
    """Delegate to TimeScheduler.generate_comprehensive_time_slots() for shared logic"""
    from .TimeScheduler import generate_comprehensive_time_slots as _gen_slots
    return _gen_slots()


def generate_randomized_sessions(units: int, employment_type: str) -> List[float]:
    """Delegate to TimeScheduler.generate_randomized_sessions() for shared logic"""
    from .TimeScheduler import generate_randomized_sessions as _gen_sessions
    return _gen_sessions(units, employment_type)


def calculate_required_slots(units: int, employment_type: str) -> int:
    """Calculate how many time slots are needed based on units and employment type"""
    # Generate randomized sessions to get the actual number needed
    sessions = generate_randomized_sessions(units, employment_type)
    return len(sessions)


def validate_units_coverage(schedules: List[Dict], course_units: Dict[str, int]) -> bool:
    """Validate that the total teaching time matches the required units for each course"""
    course_teaching_time = {}
    
    for schedule in schedules:
        course_code = schedule.get('subject_code', '')
        if course_code not in course_teaching_time:
            course_teaching_time[course_code] = 0
        
        # Calculate teaching time for this session based on start/end time
        start_time = schedule.get('start_time', '')
        end_time = schedule.get('end_time', '')
        
        if start_time and end_time:
            # Parse times and calculate duration
            try:
                start_hour = int(start_time.split(':')[0])
                start_min = int(start_time.split(':')[1])
                end_hour = int(end_time.split(':')[0])
                end_min = int(end_time.split(':')[1])
                
                start_minutes = start_hour * 60 + start_min
                end_minutes = end_hour * 60 + end_min
                duration_hours = (end_minutes - start_minutes) / 60.0
                
                course_teaching_time[course_code] += duration_hours
            except (ValueError, IndexError):
                # Fallback to 1.5 hours if parsing fails
                course_teaching_time[course_code] += 1.5
        else:
            # Fallback to 1.5 hours if no time info
            course_teaching_time[course_code] += 1.5
    
    # Check if teaching time matches required units
    for course_code, required_units in course_units.items():
        actual_time = course_teaching_time.get(course_code, 0)
        if abs(actual_time - required_units) > 0.1:  # Allow small floating point differences
            print(f"WARNING: Course {course_code} requires {required_units} units but got {actual_time:.1f} hours", file=sys.stderr)
            return False
    
    return True


def filter_time_slots_by_employment(time_slots: List[Dict], employment_type: str) -> List[Dict]:
    """Delegate to TimeScheduler.filter_time_slots_by_employment() for shared logic"""
    from .TimeScheduler import filter_time_slots_by_employment as _filter
    return _filter(time_slots, employment_type)


def get_room_capacity(room: Dict[str, Any]) -> int:
    """Delegate to RoomScheduler.get_room_capacity()"""
    from .RoomScheduler import get_room_capacity as _cap
    return _cap(room)


def is_room_suitable_for_course(room: Dict[str, Any], course: Dict[str, Any]) -> bool:
    """Delegate to RoomScheduler.is_room_suitable_for_course_dict()"""
    from .RoomScheduler import is_room_suitable_for_course_dict as _suitable
    return _suitable(room, course)


def is_lunch_break_violation(start_time: str, end_time: str) -> bool:
    """Delegate to TimeScheduler.is_lunch_break_violation() for shared logic"""
    from .TimeScheduler import is_lunch_break_violation as _lunch
    return _lunch(start_time, end_time)


def select_optimal_room_dynamic(rooms, course, slot, used_room_times, room_usage_count, room_day_usage, rr_pointer):
    """
    Select optimal room using dynamic distribution algorithm
    Considers room capacity, lab requirements, unavailability, and balances usage
    """
    if not rooms:
        return None
    
    # Calculate course requirements
    requires_lab = course.get('sessionType', 'Non-Lab session').lower() == 'lab session'
    estimated_students = min(50, max(20, int(course.get('unit', 3)) * 10))
    min_capacity = max(20, estimated_students * 1.2)
    
    # Get suitable and available rooms
    suitable_rooms = []
    for room in rooms:
        # Check if room is suitable for course
        if not is_room_suitable_for_course(room, course):
            continue
            
        # Check if room is available at this time
        key = (slot["day"], slot["start"], slot["end"], room["room_id"])
        if key in used_room_times:
            continue
            
        suitable_rooms.append(room)
    
    # Debug: Log lab room selection
    if requires_lab:
        lab_rooms = [r for r in suitable_rooms if r.get("is_lab", False)]
        print(f"DEBUG: Lab session {course.get('courseCode', 'Unknown')} - Found {len(lab_rooms)} lab rooms out of {len(suitable_rooms)} suitable rooms")
    
    if not suitable_rooms:
        # For lab sessions, only fallback to lab rooms
        if requires_lab:
            for room in rooms:
                if room.get("is_lab", False):
                    key = (slot["day"], slot["start"], slot["end"], room["room_id"])
                    if key not in used_room_times:
                        suitable_rooms.append(room)
                        break
        else:
            # For non-lab sessions, fallback to any available NON-LAB room
            for room in rooms:
                if not room.get("is_lab", False):  # Only use non-lab rooms for non-lab sessions
                    key = (slot["day"], slot["start"], slot["end"], room["room_id"])
                    if key not in used_room_times:
                        suitable_rooms.append(room)
                        break
    
    if not suitable_rooms:
        return None
    
    # Select optimal room using intelligent distribution
    if len(suitable_rooms) == 1:
        selected_room = suitable_rooms[0]
    else:
        # Calculate room scores based on usage and capacity
        room_scores = []
        for room in suitable_rooms:
            room_id = room["room_id"]
            total_usage = room_usage_count.get(room_id, 0)
            
            # Calculate today's usage
            day_key = slot["day"]
            today_usage = room_day_usage.get(day_key, {}).get(room_id, 0)
            
            # Score: lower usage = higher score (prefer less used rooms)
            # Also consider capacity efficiency
            capacity = room.get("capacity", 30)
            efficiency_score = min(1.0, capacity / 50)  # Prefer rooms closer to optimal size
            
            score = (100 - total_usage) + (50 - today_usage) + (efficiency_score * 20)
            room_scores.append((score, room))
        
        # Sort by score (highest first)
        room_scores.sort(key=lambda x: x[0], reverse=True)
        
        # Use round-robin among top 3 rooms to ensure some distribution
        top_rooms = room_scores[:min(3, len(room_scores))]
        selected_index = rr_pointer % len(top_rooms)
        selected_room = top_rooms[selected_index][1]
    
    # Update usage tracking
    room_id = selected_room["room_id"]
    day_key = slot["day"]
    
    # Add to used room times
    key = (slot["day"], slot["start"], slot["end"], room_id)
    used_room_times.add(key)
    
    # Update usage counts
    room_usage_count[room_id] = room_usage_count.get(room_id, 0) + 1
    if day_key not in room_day_usage:
        room_day_usage[day_key] = {}
    room_day_usage[day_key][room_id] = room_day_usage[day_key].get(room_id, 0) + 1
    
    return selected_room


def solve_with_cp_sat(payload: Dict[str, Any]) -> Dict[str, Any]:
    instructor_data: List[Dict[str, Any]] = payload.get("instructorData", [])
    rooms: List[Dict[str, Any]] = payload.get("rooms", [])
    
    # Generate comprehensive time slots via shared TimeScheduler
    time_slots = generate_comprehensive_time_slots()

    # Basic validation
    if not instructor_data:
        return {
            "success": False,
            "message": "Missing instructorData",
            "schedules": [],
            "errors": ["No instructor data provided"]
        }
    
    if not rooms:
        return {
            "success": False,
            "message": "Missing rooms data",
            "schedules": [],
            "errors": ["No room data provided"]
        }
    
    # Reduced debug output to prevent pipe overflow
    if len(instructor_data) <= 10:  # Only debug for small datasets
        print(f"DEBUG: Processing {len(instructor_data)} courses with {len(rooms)} rooms and {len(time_slots)} time slots", file=sys.stderr)
    try:
        room_summary = [
            {
                "id": r.get("room_id"),
                "name": r.get("room_name"),
                "cap": r.get("capacity"),
                "lab": r.get("is_lab"),
                "active": r.get("is_active", True),
            }
            for r in rooms
        ]
        # Reduced debug output to prevent pipe overflow
        if len(instructor_data) <= 10:
            print(f"DEBUG: Rooms from controller: {json.dumps(room_summary)}", file=sys.stderr)
    except Exception:
        pass

    model = cp_model.CpModel()

    # Index helpers
    room_ids = [r["room_id"] for r in rooms]
    slot_ids = list(range(len(time_slots)))

    # Precompute slot metadata
    def _time_to_minutes(t: str) -> int:
        return int(t.split(':')[0]) * 60 + int(t.split(':')[1])

    slot_start_min = [_time_to_minutes(ts["start"]) for ts in time_slots]
    slot_end_min = [_time_to_minutes(ts["end"]) for ts in time_slots]
    slot_day = [ts["day"] for ts in time_slots]

    # For each slot, list of slot indices on the same day that overlap with it (including itself)
    overlapping_slots: Dict[int, List[int]] = {}
    for s in slot_ids:
        overlaps: List[int] = []
        for t in slot_ids:
            if slot_day[s] != slot_day[t]:
                continue
            # overlap if not (end1 <= start2 or end2 <= start1)
            if not (slot_end_min[s] <= slot_start_min[t] or slot_end_min[t] <= slot_start_min[s]):
                overlaps.append(t)
        overlapping_slots[s] = overlaps

    # Build course list with proper data mapping and expand multi-block entries
    courses: List[Dict[str, Any]] = []
    for course_data in instructor_data:
        # Map frontend sessionType to room requirement
        session_type = str(course_data.get("sessionType", "Non-Lab session") or "").strip().lower()
        requires_lab_flag = (session_type == "lab session")

        base_course = {
            "name": course_data.get("name", ""),
            "courseCode": course_data.get("courseCode", ""),
            "courseDescription": course_data.get("subject", ""),
            "unit": int(course_data.get("unit", 3)),
            "yearLevel": course_data.get("yearLevel", "1st Year"),
            "employment_type": course_data.get("employmentType", "FULL-TIME"),
            "dept": course_data.get("dept", "General"),
            "requires_lab": requires_lab_flag,
        }

        raw_block = (course_data.get("block") or "").strip()
        multi_blocks: List[str] = []
        if raw_block.upper() in ("A & B", "A&B"):
            multi_blocks = ["A", "B"]
        elif "," in raw_block:
            multi_blocks = [b.strip() for b in raw_block.split(",") if b.strip()]
        elif raw_block:
            multi_blocks = [raw_block]
        else:
            # Use the original block from the data, default to "A" if empty
            original_block = course_data.get("block", "A")
            multi_blocks = [original_block] if original_block else ["A"]

        for b in multi_blocks:
            course = dict(base_course)
            course["block"] = b
            courses.append(course)

    # Create decision variables for each course
    # Each course can be assigned to multiple time slots based on units
    x_slot = {}
    course_sessions = {}  # Store session durations for each course
    
    for idx, course in enumerate(courses):
        # Generate randomized sessions for this course
        sessions = generate_randomized_sessions(course["unit"], course["employment_type"])
        course_sessions[idx] = sessions
        required_slots = len(sessions)
        
        # Create variables for each possible slot assignment
        for slot_idx in range(required_slots):
            for s in slot_ids:
                var = model.NewBoolVar(f"c{idx}_slot{slot_idx}_{s}")
                x_slot[(idx, slot_idx, s)] = var
                # Disallow assigning a session to a slot shorter than the session duration
                session_minutes = int(round(sessions[slot_idx] * 60))
                slot_minutes = slot_end_min[s] - slot_start_min[s]
                # Only disallow slots that are too short for the session
                if session_minutes > slot_minutes:
                    model.Add(var == 0)
            # Room selection simplified: assign later to first available room

    # Constraints for each course
    for idx, course in enumerate(courses):
        sessions = course_sessions[idx]
        required_slots = len(sessions)
        
        # Each course must use exactly the required number of slots
        for slot_idx in range(required_slots):
            # Exactly one time slot per slot position
            model.Add(sum(x_slot[(idx, slot_idx, s)] for s in slot_ids) == 1)
            # Room decision removed (single-room/simple assignment handled at output time)

    # Hard constraints: no instructor overlap, no room overlap
    instructor_to_courses: Dict[str, List[int]] = {}
    for idx, course in enumerate(courses):
        instructor_name = course.get("name", "")
        instructor_to_courses.setdefault(instructor_name, []).append(idx)

    # No instructor teaches two courses in the same time slot
    for instructor, course_indices in instructor_to_courses.items():
        for s in slot_ids:
            total_assignments = 0
            for course_idx in course_indices:
                sessions = course_sessions[course_idx]
                required_slots = len(sessions)
                for slot_idx in range(required_slots):
                    total_assignments += x_slot[(course_idx, slot_idx, s)]
            model.Add(total_assignments <= 1)

    # No section (yearLevel + block) can attend two courses in the same or overlapping time slots (same day)
    section_to_courses: Dict[str, List[int]] = {}
    for idx, course in enumerate(courses):
        section_key = f"{course.get('yearLevel', '')} {course.get('block', '')}".strip()
        section_to_courses.setdefault(section_key, []).append(idx)

    for section, course_indices in section_to_courses.items():
        for s in slot_ids:
            total_assignments = []
            for course_idx in course_indices:
                sessions = course_sessions[course_idx]
                required_slots = len(sessions)
                for slot_idx in range(required_slots):
                    for t in overlapping_slots[s]:
                        total_assignments.append(x_slot[(course_idx, slot_idx, t)])
            if total_assignments:
                model.Add(sum(total_assignments) <= 1)

    # Room conflict constraints removed for simplified single-room assignment

    # Soft constraint: Prefer no classes during lunch break (12:00 PM - 12:59 PM)
    lunch_penalty_terms = []
    for idx, course in enumerate(courses):
        sessions = course_sessions[idx]
        required_slots = len(sessions)
        for slot_idx in range(required_slots):
            for s in slot_ids:
                slot = time_slots[s]
                # Check if slot violates lunch break
                if is_lunch_break_violation(slot["start"], slot["end"]):
                    # Add penalty instead of hard constraint
                    lunch_penalty_terms.append(10 * x_slot[(idx, slot_idx, s)])  # Reduced from 50 to 10

    # Soft constraints: employment type preferences and room suitability
    penalty_terms = []
    
    for idx, course in enumerate(courses):
        sessions = course_sessions[idx]
        required_slots = len(sessions)
        employment_type = course["employment_type"]
        
        for slot_idx in range(required_slots):
            for s in slot_ids:
                slot = time_slots[s]
                
                # Reduced penalty for wrong employment type time slots
                # Allow more flexibility for part-time instructors
                if employment_type == "PART-TIME" and slot["period"] != "evening":
                    penalty_terms.append(2 * x_slot[(idx, slot_idx, s)])  # Reduced from 10 to 2
                elif employment_type == "FULL-TIME" and slot["period"] == "evening":
                    penalty_terms.append(3 * x_slot[(idx, slot_idx, s)])  # Reduced from 10 to 3

                # Add constraint relaxation for part-time instructors
                if employment_type == "PART-TIME":
                    # Allow morning slots for part-time as last resort
                    if slot["period"] == "morning":
                        penalty_terms.append(5 * x_slot[(idx, slot_idx, s)])  # Lower penalty
                
                # Room suitability penalties skipped in simplified room model

    # Reduced day diversity penalty to encourage spreading across different days
    day_diversity_penalties = []
    day_usage_count = {day: 0 for day in DAYS}

    for idx, course in enumerate(courses):
        sessions = course_sessions[idx]
        required_slots = len(sessions)
        for slot_idx in range(required_slots):
            for s in slot_ids:
                day = slot_day[s]
                # Reduced penalty for overusing any single day
                day_diversity_penalties.append(1 * x_slot[(idx, slot_idx, s)])  # Reduced from 5 to 1

    # Minimize penalties (including lunch break penalties and day diversity)
    all_penalties = penalty_terms + lunch_penalty_terms + day_diversity_penalties
    if all_penalties:
        model.Minimize(sum(all_penalties))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = float(payload.get("timeLimitSec", 60))  # Increased timeout to 60 seconds
    solver.parameters.num_search_workers = 4  # Increased workers for better performance
    # Use default search branching (AUTOMATIC is not available in newer OR-Tools versions)
    # solver.parameters.search_branching = cp_model.AUTOMATIC  # Removed - not available
    solver.parameters.cp_model_presolve = True  # Enable presolve
    solver.parameters.cp_model_probing_level = 0  # Reduced probing to speed up

    # Reduced debug output to prevent pipe overflow
    if len(courses) <= 10:
        print(f"DEBUG: Starting solver with {len(courses)} courses", file=sys.stderr)
    status = solver.Solve(model)
    if len(courses) <= 10:
        print(f"DEBUG: Solver status: {status}", file=sys.stderr)

    if status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        print(f"DEBUG: Solver failed with status: {status}", file=sys.stderr)
        return {
            "success": False,
            "message": f"No feasible assignment found (status: {status})",
            "schedules": [],
            "errors": ["Infeasible"]
        }

    # Build schedule output
    schedules = []
    # Track used (day, start, end, room_id) to avoid room conflicts
    used_room_times: set = set()
    # Track per-room usage to balance assignments across existing rooms
    room_usage_count = {r["room_id"]: 0 for r in rooms}
    # Track per-day usage per room to diversify rooms within the same day
    room_day_usage: Dict[str, Dict[Any, int]] = {}
    # Global round-robin pointer to rotate starting room each assignment
    rr_pointer = 0
    # Reduced debug output to prevent pipe overflow
    if len(courses) <= 10:
        print(f"DEBUG: Building schedules for {len(courses)} courses", file=sys.stderr)
    
    for idx, course in enumerate(courses):
        sessions = course_sessions[idx]
        required_slots = len(sessions)
        # Reduced debug output to prevent pipe overflow
        if len(courses) <= 10:
            print(f"DEBUG: Course {idx} ({course['courseCode']}) needs {required_slots} slots with durations: {sessions}", file=sys.stderr)
        
        for slot_idx in range(required_slots):
            chosen_slot = None
            chosen_room_idx = 0  # Simplified: pick first room
            
            # Find assigned time slot
            for s in slot_ids:
                if solver.BooleanValue(x_slot[(idx, slot_idx, s)]):
                    chosen_slot = s
                    break
            
            # Room already chosen as index 0 in simplified model

            if chosen_slot is not None:
                slot = time_slots[chosen_slot]
                # Greedy room assignment from provided rooms (DB) avoiding conflicts
                assigned_room = None
                
                # Calculate actual start and end times based on session duration
                session_duration = sessions[slot_idx]
                start_time = slot["start"]
                
                # Calculate end time based on session duration
                start_hour = int(start_time.split(':')[0])
                start_min = int(start_time.split(':')[1])
                start_minutes = start_hour * 60 + start_min
                end_minutes = start_minutes + int(session_duration * 60)
                end_hour = end_minutes // 60
                end_min = end_minutes % 60
                end_time = f"{end_hour:02d}:{end_min:02d}:00"

                # Dynamic room selection with intelligent distribution
                assigned_room = select_optimal_room_dynamic(rooms, course, slot, used_room_times, room_usage_count, room_day_usage, rr_pointer)
                if assigned_room:
                    rr_pointer += 1
                
                section_str = f"{course['yearLevel']} {course['block']}".strip()
                schedule_entry = {
                    "instructor": course["name"],
                    "subject_code": course["courseCode"],
                    "subject_description": course["courseDescription"],
                    "unit": course["unit"],
                    "day": slot["day"],
                    "start_time": start_time,
                    "end_time": end_time,
                    "block": course["block"],
                    "year_level": course["yearLevel"],
                    "section": section_str,
                    "dept": course.get("dept", "General"),
                    "employment_type": course["employment_type"],
                    "sessionType": course.get("sessionType", "Non-Lab session"),
                    "room_id": assigned_room["room_id"] if assigned_room else None
                }
                
                schedules.append(schedule_entry)
                # Reduced debug output to prevent pipe overflow
                if len(courses) <= 10:
                    print(f"DEBUG: Created schedule: {course['courseCode']} on {slot['day']} {start_time}-{end_time} ({session_duration}h) in room {schedule_entry['room_id']}", file=sys.stderr)
            else:
                print(f"DEBUG: Failed to find slot/room for course {idx} slot {slot_idx}", file=sys.stderr)
    
    # Reduced debug output to prevent pipe overflow
    if len(courses) <= 10:
        print(f"DEBUG: Generated {len(schedules)} total schedule entries", file=sys.stderr)
    try:
        # Print per-room usage summary
        usage_sorted = []
        try:
            usage_sorted = sorted(room_usage_count.items(), key=lambda kv: (-kv[1], kv[0]))
        except Exception:
            usage_sorted = []
        # Reduced debug output to prevent pipe overflow
        if len(courses) <= 10:
            print(f"DEBUG: Room usage counts: {usage_sorted}", file=sys.stderr)
            used_room_ids = sorted({s.get("room_id") for s in schedules})
            print(f"DEBUG: Rooms used this run: {used_room_ids}", file=sys.stderr)
    except Exception:
        pass

    # Validate units coverage
    course_units = {course["courseCode"]: course["unit"] for course in courses}
    units_valid = validate_units_coverage(schedules, course_units)
    
    if not units_valid:
        print("WARNING: Units coverage validation failed", file=sys.stderr)

    return {
        "success": True,
        "message": "Solved" + (" (with units validation warnings)" if not units_valid else ""),
        "schedules": schedules,
        "errors": [] if units_valid else ["Units coverage validation failed"]
    }


def format_time_12hour(time_24: str) -> str:
    """Delegate to TimeScheduler.format_time_12hour() for shared logic"""
    from .TimeScheduler import format_time_12hour as _fmt
    return _fmt(time_24)


def main() -> None:
    try:
        payload = read_input()
        if not payload:
            print(json.dumps({"success": False, "message": "Empty input"}))
            return

        result = solve_with_cp_sat(payload)
        print(json.dumps(result), flush=True)
        
    except Exception as e:
        error_result = {
            "success": False,
            "message": f"OR-Tools algorithm error: {str(e)}",
            "schedules": [],
            "errors": [str(e)]
        }
        print(json.dumps(error_result), flush=True)


if __name__ == "__main__":
    main()


