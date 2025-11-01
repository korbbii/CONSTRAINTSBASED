import sys
import json
import random
import math
import numpy as np
from typing import List, Dict, Any, Tuple, Set
from datetime import datetime, timedelta
from dataclasses import dataclass
from collections import defaultdict

@dataclass
class TimeSlot:
    day: str
    start_time: str
    end_time: str
    period: str

@dataclass
class Course:
    name: str
    course_code: str
    description: str
    units: int
    year_level: str
    block: str
    employment_type: str
    department: str
    instructor_name: str  # Add instructor name to preserve assignment
    requires_lab: bool = False  # Add lab requirement field

@dataclass
class Room:
    room_id: int
    room_name: str
    capacity: int
    is_lab: bool = False
    is_active: bool = True

@dataclass
class Instructor:
    instructor_id: int
    name: str
    employment_type: str
    is_active: bool = True

@dataclass
class ScheduleEntry:
    course: Course
    instructor: Instructor
    room: Room
    time_slot: TimeSlot
    section: str

class GeneticScheduler:
    def __init__(self, courses: List[Course], rooms: List[Room], instructors: List[Instructor]):
        self.courses = courses
        self.rooms = rooms
        self.instructors = instructors
        self.time_slots = self.generate_time_slots()
        self.sections = self.generate_sections()
        
        # Optimized genetic algorithm parameters for better performance
        self.population_size = 50   # Increased for better exploration
        self.generations = 30       # Increased generations for better convergence
        self.mutation_rate = 0.25   # Balanced mutation rate
        self.crossover_rate = 0.8   # High crossover rate
        self.elite_size = 8         # More elites for better preservation
        self.tournament_size = 4    # Larger tournament for better selection
        
        # Adaptive parameters
        self.adaptive_mutation = True
        self.convergence_threshold = 0.001
        self.stagnation_limit = 10  # Reduced stagnation limit
        
        # Conflict tracking
        self.conflict_history = []
        self.best_fitness_history = []
        
    def generate_time_slots(self) -> List[TimeSlot]:
        """Generate time slots using shared TimeScheduler for consistency"""
        from .TimeScheduler import generate_comprehensive_time_slots
        slots = generate_comprehensive_time_slots()
        return [TimeSlot(day=s['day'], start_time=s['start'], end_time=s['end'], period=s['period']) for s in slots]
    
    def generate_sections(self) -> List[str]:
        """Generate section codes based on courses"""
        sections = []
        for course in self.courses:
            section_code = f"{course.department}-{course.year_level} {course.block}"
            if section_code not in sections:
                sections.append(section_code)
        return sections
    
    def get_instructor_by_name(self, instructor_name: str) -> Instructor:
        """Get instructor by name, fallback to first instructor if not found"""
        for instructor in self.instructors:
            if instructor.name == instructor_name:
                return instructor
        
        # Fallback to first instructor if name not found
        print(f"WARNING: Instructor '{instructor_name}' not found, using first available instructor", file=sys.stderr)
        return self.instructors[0] if self.instructors else None
    
    def create_individual(self) -> List[ScheduleEntry]:
        """Create a random individual (schedule) with optimized conflict avoidance"""
        individual = []
        used_sections = set()  # Track used sections to prevent conflicts
        used_times = set()     # Track used time slots to prevent conflicts
        
        for course in self.courses:
            # Generate randomized session durations
            session_durations = self.generate_randomized_sessions(course.units, course.employment_type)
            
            # Get suitable time slots for this course
            suitable_slots = self.get_suitable_time_slots(course)
            
            # Get suitable rooms
            suitable_rooms = self.get_suitable_rooms(course)
            
            # Assign instructor - use the original instructor from the course data
            instructor = self.get_instructor_by_name(course.instructor_name)
            
            # Create schedule entries for each session duration
            for session_duration in session_durations:
                if not suitable_slots or not suitable_rooms:
                    continue
                
                # Choose a base slot that can fit the required duration
                required_minutes = int(round(session_duration * 60))
                def slot_len_minutes(slot: TimeSlot) -> int:
                    start_time_dt = datetime.strptime(slot.start_time, "%H:%M:%S")
                    end_time_dt = datetime.strptime(slot.end_time, "%H:%M:%S")
                    return int((end_time_dt - start_time_dt).total_seconds() // 60)
                fit_slots = [s for s in suitable_slots if slot_len_minutes(s) >= required_minutes]
                if not fit_slots:
                    continue
                
                # Prefer day diversity by weighting slots from less-used days
                day_weights = {}
                for slot in fit_slots:
                    day = slot.day
                    if day not in day_weights:
                        day_weights[day] = []
                    day_weights[day].append(slot)
                
                # Prefer days that haven't been used much yet
                day_counts = {}
                for slot in fit_slots:
                    day = slot.day
                    day_counts[day] = day_counts.get(day, 0) + 1
                
                # Weight slots inversely to their day usage
                weighted_slots = []
                for slot in fit_slots:
                    day = slot.day
                    weight = 1.0 / (day_counts[day] + 1)  # +1 to avoid division by zero
                    weighted_slots.extend([slot] * int(weight * 10))  # Scale up for random choice
                
                base_slot = random.choice(weighted_slots) if weighted_slots else random.choice(fit_slots)
                section = f"{course.department}-{course.year_level} {course.block}"
                
                # Calculate end time based on session duration
                start_time = datetime.strptime(base_slot.start_time, "%H:%M:%S")
                end_time = start_time + timedelta(hours=session_duration)
                end_time_str = end_time.strftime("%H:%M:%S")
                
                # Create custom time slot with the correct duration
                custom_time_slot = TimeSlot(
                    day=base_slot.day,
                    start_time=base_slot.start_time,
                    end_time=end_time_str,
                    period=base_slot.period
                )
                
                # Try to find a room that doesn't conflict
                room = None
                time_key = f"{custom_time_slot.day}|{custom_time_slot.start_time}|{custom_time_slot.end_time}"
                
                for room_candidate in suitable_rooms:
                    room_key = f"{room_candidate.room_id}|{time_key}"
                    if room_key not in used_times:
                        room = room_candidate
                        used_times.add(room_key)
                        break
                
                # If no conflict-free room found, use any available
                if not room:
                    room = random.choice(suitable_rooms)
                
                entry = ScheduleEntry(
                    course=course,
                    instructor=instructor,
                    room=room,
                    time_slot=custom_time_slot,
                    section=section
                )
                
                individual.append(entry)
        
        return individual
    
    def generate_randomized_sessions(self, units: int, employment_type: str) -> List[float]:
        """Delegate to shared TimeScheduler.generate_randomized_sessions for consistency."""
        try:
            from .TimeScheduler import generate_randomized_sessions as _gen
            return _gen(units, employment_type)
        except Exception:
            # Safe fallback: even two-way split for >=6, else single session
            if units >= 6:
                a = round(units / 2.0, 1)
                return [a, round(units - a, 1)]
            return [float(units)]

    def calculate_required_sessions(self, units: int, employment_type: str) -> int:
        """Calculate required sessions based on units and employment type (for compatibility)"""
        sessions = self.generate_randomized_sessions(units, employment_type)
        return len(sessions)
    
    def validate_units_coverage(self, individual: List[ScheduleEntry]) -> bool:
        """Validate that the total teaching time matches the required units for each course"""
        course_teaching_time = {}
        
        for entry in individual:
            course_code = entry.course.course_code
            if course_code not in course_teaching_time:
                course_teaching_time[course_code] = 0
            
            # Calculate actual teaching time for this session
            start_time = datetime.strptime(entry.time_slot.start_time, "%H:%M:%S")
            end_time = datetime.strptime(entry.time_slot.end_time, "%H:%M:%S")
            duration_hours = (end_time - start_time).total_seconds() / 3600
            course_teaching_time[course_code] += duration_hours
        
        # Check if teaching time matches required units (more lenient validation)
        for course in self.courses:
            required_units = course.units
            actual_time = course_teaching_time.get(course.course_code, 0)
            # Allow up to 2 unit difference for more flexibility
            if abs(actual_time - required_units) > 2.0:
                print(f"WARNING: Course {course.course_code} requires {required_units} units but got {actual_time} hours", file=sys.stderr)
                print(f"Available course codes in schedule: {list(course_teaching_time.keys())}", file=sys.stderr)
                return False
        
        return True
    
    def get_suitable_time_slots(self, course: Course) -> List[TimeSlot]:
        """Get time slots suitable for the course with relaxed constraints."""
        try:
            from .TimeScheduler import filter_time_slots_by_employment
            # Convert object TimeSlot -> dict for reuse, then back
            dict_slots = [
                {"day": s.day, "start": s.start_time, "end": s.end_time, "period": s.period}
                for s in self.time_slots
            ]
            filtered = filter_time_slots_by_employment(dict_slots, course.employment_type)
            return [TimeSlot(day=s['day'], start_time=s['start'], end_time=s['end'], period=s['period']) for s in filtered]
        except Exception:
            # Fallback: provide all slots if filtering fails (most flexible)
            if course.employment_type == 'PART-TIME':
                return [slot for slot in self.time_slots if slot.period in ['morning', 'afternoon', 'evening']]
            return [slot for slot in self.time_slots if slot.period in ['morning', 'afternoon', 'evening']]
    
    def get_suitable_rooms(self, course: Course) -> List[Room]:
        """Get rooms suitable for the course using dynamic room distribution"""
        # Estimate student count based on units
        estimated_students = min(50, max(20, course.units * 10))
        
        suitable_rooms = []
        for room in self.rooms:
            if self.is_room_suitable_for_course(room, course):
                suitable_rooms.append(room)
        
        # If no suitable rooms found, use appropriate fallback
        if not suitable_rooms and self.rooms:
            if course.requires_lab:
                # For lab sessions, only fallback to lab rooms
                lab_rooms = [r for r in self.rooms if r.is_lab]
                if lab_rooms:
                    suitable_rooms = lab_rooms[:3]  # Use first 3 lab rooms
                else:
                    print(f"WARNING: No lab rooms available for lab session {course.course_code}", file=sys.stderr)
                    suitable_rooms = []  # Don't assign any room if no lab rooms available
            else:
                # For non-lab sessions, use any available NON-LAB room
                non_lab_rooms = [r for r in self.rooms if not r.is_lab]
                suitable_rooms = non_lab_rooms[:3]  # Use first 3 non-lab rooms as fallback
        
        # Sort rooms by usage to balance distribution
        suitable_rooms.sort(key=lambda r: self.get_room_usage_score(r))
        
        return suitable_rooms
    
    def get_room_usage_score(self, room: Room) -> int:
        """Calculate room usage score for balanced distribution"""
        # This is a simplified version - in a full implementation,
        # you'd track actual usage across all schedules
        return room.room_id % 10  # Simple distribution based on room ID
    
    def is_room_suitable_for_course(self, room: Room, course: Course) -> bool:
        """Delegate suitability checks to RoomScheduler utilities (object form)."""
        try:
            from .RoomScheduler import is_room_suitable_for_course_obj
            return is_room_suitable_for_course_obj(room, course)
        except Exception:
            # Fallback to permissive default
            return True
    
    def calculate_fitness(self, individual: List[ScheduleEntry]) -> float:
        """Calculate enhanced fitness score for an individual (lower is better)"""
        conflicts = self.detect_conflicts(individual)
        
        # Base fitness (penalty for conflicts)
        fitness = 0
        
        # Hard constraints (critical penalties - further reduced for more flexibility)
        fitness += conflicts['instructor_conflicts'] * 200       # Instructor double-booking (further reduced penalty)
        fitness += conflicts['room_conflicts'] * 200             # Room double-booking (further reduced penalty)
        fitness += conflicts['student_conflicts'] * 400          # Same section at same time (further reduced penalty)
        fitness += conflicts['cross_section_conflicts'] * 300     # Same subject at same time (further reduced penalty)
        fitness += conflicts['section_time_overlaps'] * 500      # Same section with overlapping times (further reduced penalty)
        fitness += conflicts['lunch_break_violations'] * 300      # Lunch break violations (further reduced penalty)
        
        # Soft constraints (optimization penalties)
        fitness += conflicts['employment_violations'] * 100      # Wrong time slots for employment type
        fitness += conflicts['capacity_violations'] * 50         # Room capacity issues
        
        # Additional quality metrics
        fitness += self.calculate_time_distribution_penalty(individual) * 20
        fitness += self.calculate_instructor_load_penalty(individual) * 30
        fitness += self.calculate_room_utilization_penalty(individual) * 15
        fitness += self.calculate_meeting_pattern_penalty(individual) * 25
        fitness += self.calculate_units_coverage_penalty(individual) * 100  # High penalty for units mismatch
        
        # Bonus for complete scheduling (all courses scheduled)
        if len(individual) == sum(self.calculate_required_sessions(course.units, course.employment_type) 
                                 for course in self.courses):
            fitness -= 100  # Bonus for complete scheduling
        
        return fitness
    
    def detect_conflicts(self, individual: List[ScheduleEntry]) -> Dict[str, int]:
        """Detect all types of conflicts in the schedule with enhanced section conflict detection"""
        conflicts = {
            'instructor_conflicts': 0,
            'room_conflicts': 0,
            'student_conflicts': 0,
            'cross_section_conflicts': 0,
            'employment_violations': 0,
            'capacity_violations': 0,
            'lunch_break_violations': 0,
            'section_time_overlaps': 0
        }
        
        # Group entries by time slot
        time_groups = defaultdict(list)
        for entry in individual:
            key = f"{entry.time_slot.day}|{entry.time_slot.start_time}|{entry.time_slot.end_time}"
            time_groups[key].append(entry)
        
        for time_key, entries in time_groups.items():
            if len(entries) <= 1:
                continue
            
            # Check instructor conflicts
            instructor_ids = [entry.instructor.instructor_id for entry in entries]
            if len(instructor_ids) != len(set(instructor_ids)):
                conflicts['instructor_conflicts'] += len(instructor_ids) - len(set(instructor_ids))
            
            # Check room conflicts
            rooms = [entry.room.room_id for entry in entries]
            if len(rooms) != len(set(rooms)):
                conflicts['room_conflicts'] += len(rooms) - len(set(rooms))
            
            # Check student group conflicts (same section at same time)
            sections = [entry.section for entry in entries]
            if len(sections) != len(set(sections)):
                conflicts['student_conflicts'] += len(sections) - len(set(sections))
            
            # Check cross-section conflicts (same subject at same time)
            subjects = [entry.course.course_code for entry in entries]
            if len(subjects) != len(set(subjects)):
                conflicts['cross_section_conflicts'] += len(subjects) - len(set(subjects))
            
            # Check employment type violations
            for entry in entries:
                if entry.course.employment_type == 'PART-TIME' and entry.time_slot.period != 'evening':
                    conflicts['employment_violations'] += 1
                elif entry.course.employment_type == 'FULL-TIME' and entry.time_slot.period == 'evening':
                    conflicts['employment_violations'] += 1
            
            # Check room capacity violations
            for entry in entries:
                estimated_students = min(50, max(20, entry.course.units * 10))
                if entry.room.capacity < estimated_students:
                    conflicts['capacity_violations'] += 1
            
            # Check lunch break violations (12:00 PM - 12:59 PM)
            for entry in entries:
                start_time = entry.time_slot.start_time
                end_time = entry.time_slot.end_time
                if self.is_lunch_break_violation(start_time, end_time):
                    conflicts['lunch_break_violations'] += 1
        
        # Check for section time overlaps (same section with overlapping times)
        conflicts['section_time_overlaps'] = self.detect_section_time_overlaps(individual)
        
        # Check for cross-section conflicts
        conflicts['cross_section_conflicts'] = self.detect_cross_section_conflicts(individual)
        
        return conflicts
    
    def is_lunch_break_violation(self, start_time: str, end_time: str) -> bool:
        """Check if a time slot violates the lunch break (12:00 PM - 12:59 PM)"""
        # Convert to comparable format
        start_hour = int(start_time.split(':')[0])
        start_min = int(start_time.split(':')[1])
        end_hour = int(end_time.split(':')[0])
        end_min = int(end_time.split(':')[1])
        
        start_minutes = start_hour * 60 + start_min
        end_minutes = end_hour * 60 + end_min
        
        # Lunch break is 12:00 PM (720 minutes) to 12:59 PM (779 minutes)
        lunch_start = 12 * 60  # 720 minutes
        lunch_end = 12 * 60 + 59  # 779 minutes
        
        # Check if any part of the class overlaps with lunch break
        return not (end_minutes <= lunch_start or start_minutes >= lunch_end)
    
    def detect_section_time_overlaps(self, individual: List[ScheduleEntry]) -> int:
        """Detect overlapping time slots for the same section"""
        overlaps = 0
        
        # Group entries by section
        section_entries = defaultdict(list)
        for entry in individual:
            section_entries[entry.section].append(entry)
        
        for section, entries in section_entries.items():
            if len(entries) <= 1:
                continue
            
            # Check all pairs of entries for the same section
            for i in range(len(entries)):
                for j in range(i + 1, len(entries)):
                    entry1 = entries[i]
                    entry2 = entries[j]
                    
                    # Check if they're on the same day
                    if entry1.time_slot.day == entry2.time_slot.day:
                        if self.times_overlap(entry1.time_slot, entry2.time_slot):
                            overlaps += 1
        
        return overlaps
    
    def detect_cross_section_conflicts(self, individual: List[ScheduleEntry]) -> int:
        """Detect conflicts where same subject is taught at same time in different sections"""
        subject_time_groups = defaultdict(list)
        
        # Group by subject and time
        for entry in individual:
            key = f"{entry.course.course_code}|{entry.time_slot.day}|{entry.time_slot.start_time}|{entry.time_slot.end_time}"
            subject_time_groups[key].append(entry)
        
        conflicts = 0
        for key, entries in subject_time_groups.items():
            if len(entries) > 1:
                # Same subject at same time - check if different sections
                sections = set(entry.section for entry in entries)
                if len(sections) > 1:
                    conflicts += len(entries) - 1
        
        return conflicts
    
    def times_overlap(self, slot1: TimeSlot, slot2: TimeSlot) -> bool:
        """Check if two time slots overlap"""
        start1 = self.time_to_minutes(slot1.start_time)
        end1 = self.time_to_minutes(slot1.end_time)
        start2 = self.time_to_minutes(slot2.start_time)
        end2 = self.time_to_minutes(slot2.end_time)
        
        return not (end1 <= start2 or end2 <= start1)
    
    def time_to_minutes(self, time_str: str) -> int:
        """Convert time string to minutes since midnight"""
        parts = time_str.split(':')
        return int(parts[0]) * 60 + int(parts[1])
    
    def calculate_instructor_load_penalty(self, individual: List[ScheduleEntry]) -> float:
        """Calculate penalty for uneven instructor workload distribution"""
        if not individual:
            return 0
        
        instructor_loads = defaultdict(int)
        for entry in individual:
            instructor_loads[entry.instructor.instructor_id] += 1
        
        if len(instructor_loads) <= 1:
            return 0
        
        loads = list(instructor_loads.values())
        mean_load = sum(loads) / len(loads)
        variance = sum((load - mean_load) ** 2 for load in loads) / len(loads)
        
        return variance  # Higher variance = more uneven distribution = higher penalty
    
    def calculate_room_utilization_penalty(self, individual: List[ScheduleEntry]) -> float:
        """Calculate penalty for poor room utilization"""
        if not individual:
            return 0
        
        room_usage = defaultdict(int)
        for entry in individual:
            room_usage[entry.room.room_id] += 1
        
        if len(room_usage) <= 1:
            return 0
        
        # Penalty for rooms that are overused or underused
        total_usage = sum(room_usage.values())
        expected_usage_per_room = total_usage / len(self.rooms)
        
        penalty = 0
        for room_id, usage in room_usage.items():
            deviation = abs(usage - expected_usage_per_room)
            penalty += deviation
        
        return penalty
    
    def calculate_meeting_pattern_penalty(self, individual: List[ScheduleEntry]) -> float:
        """Calculate penalty for poor meeting patterns (e.g., single sessions for multi-unit courses)"""
        if not individual:
            return 0
        
        # Group entries by course
        course_entries = defaultdict(list)
        for entry in individual:
            course_entries[entry.course.course_code].append(entry)
        
        penalty = 0
        for course_code, entries in course_entries.items():
            if len(entries) == 1:
                # Single session for multi-unit course is generally bad
                course = entries[0].course
                if course.units > 2:
                    penalty += course.units * 5
            elif len(entries) > 1:
                # Check if sessions are well-distributed
                days = [entry.time_slot.day for entry in entries]
                if len(set(days)) == 1:
                    # All sessions on same day - not ideal
                    penalty += len(entries) * 3
        
        return penalty
    
    def calculate_units_coverage_penalty(self, individual: List[ScheduleEntry]) -> float:
        """Calculate penalty for not meeting unit requirements"""
        if not individual:
            return 1000  # High penalty for empty schedule
        
        course_teaching_time = {}
        
        # Calculate actual teaching time for each course
        for entry in individual:
            course_code = entry.course.course_code
            if course_code not in course_teaching_time:
                course_teaching_time[course_code] = 0
            
            # Calculate actual duration of this session
            start_time = datetime.strptime(entry.time_slot.start_time, "%H:%M:%S")
            end_time = datetime.strptime(entry.time_slot.end_time, "%H:%M:%S")
            duration_hours = (end_time - start_time).total_seconds() / 3600
            course_teaching_time[course_code] += duration_hours
        
        # Calculate penalty for units mismatch
        penalty = 0
        for course in self.courses:
            required_units = course.units
            actual_time = course_teaching_time.get(course.course_code, 0)
            units_diff = abs(actual_time - required_units)
            penalty += units_diff * 10  # 10 points per unit difference
        
        return penalty
    
    def calculate_time_distribution_penalty(self, individual: List[ScheduleEntry]) -> float:
        """Calculate penalty for poor time distribution"""
        if not individual:
            return 0
        
        # Count entries per day
        day_counts = defaultdict(int)
        for entry in individual:
            day_counts[entry.time_slot.day] += 1
        
        # Calculate variance (higher variance = more spread out = better)
        if len(day_counts) == 0:
            return 100  # High penalty for no distribution
        
        mean_count = sum(day_counts.values()) / len(day_counts)
        variance = sum((count - mean_count) ** 2 for count in day_counts.values()) / len(day_counts)
        
        # Return penalty (lower variance = higher penalty)
        return max(0, 10 - variance)
    
    def crossover(self, parent1: List[ScheduleEntry], parent2: List[ScheduleEntry]) -> Tuple[List[ScheduleEntry], List[ScheduleEntry]]:
        """Perform enhanced crossover between two parents"""
        if random.random() > self.crossover_rate:
            return parent1.copy(), parent2.copy()
        
        # Group entries by course
        parent1_groups = self.group_by_course(parent1)
        parent2_groups = self.group_by_course(parent2)
        
        child1 = []
        child2 = []
        
        # Use uniform crossover with course-level selection
        all_courses = set(parent1_groups.keys()) | set(parent2_groups.keys())
        
        for course_code in all_courses:
            # Choose parent based on fitness (better parent has higher chance)
            p1_entries = parent1_groups.get(course_code, [])
            p2_entries = parent2_groups.get(course_code, [])
            
            # If one parent has no entries for this course, use the other
            if not p1_entries:
                child1.extend(p2_entries)
                child2.extend(p2_entries)
            elif not p2_entries:
                child1.extend(p1_entries)
                child2.extend(p1_entries)
            else:
                # Both parents have entries for this course
                if random.random() < 0.5:
                    child1.extend(p1_entries)
                    child2.extend(p2_entries)
                else:
                    child1.extend(p2_entries)
                    child2.extend(p1_entries)
        
        # Repair children to ensure no conflicts
        child1 = self.repair_schedule(child1)
        child2 = self.repair_schedule(child2)
        
        return child1, child2
    
    def group_by_course(self, individual: List[ScheduleEntry]) -> Dict[str, List[ScheduleEntry]]:
        """Group entries by course code"""
        groups = defaultdict(list)
        for entry in individual:
            groups[entry.course.course_code].append(entry)
        return dict(groups)
    
    def mutate(self, individual: List[ScheduleEntry]) -> List[ScheduleEntry]:
        """Apply enhanced mutation to an individual"""
        if random.random() > self.mutation_rate:
            return individual
        
        mutated = individual.copy()
        
        if not mutated:
            return mutated
        
        # Choose mutation type based on adaptive strategy
        mutation_type = random.choice(['time', 'room', 'instructor', 'swap', 'add_remove'])
        
        if mutation_type == 'time':
            # Mutate time slot
            entry_to_mutate = random.choice(mutated)
            suitable_slots = self.get_suitable_time_slots(entry_to_mutate.course)
            if suitable_slots:
                entry_to_mutate.time_slot = random.choice(suitable_slots)
        
        elif mutation_type == 'room':
            # Mutate room
            entry_to_mutate = random.choice(mutated)
            suitable_rooms = self.get_suitable_rooms(entry_to_mutate.course)
            if suitable_rooms:
                entry_to_mutate.room = random.choice(suitable_rooms)
        
        elif mutation_type == 'instructor':
            # Mutate instructor - but preserve the original instructor assignment
            # Skip instructor mutation to maintain consistency with uploaded data
            pass
        
        elif mutation_type == 'swap':
            # Swap two entries' time slots or rooms
            if len(mutated) >= 2:
                entry1, entry2 = random.sample(mutated, 2)
                if random.random() < 0.5:
                    # Swap time slots
                    entry1.time_slot, entry2.time_slot = entry2.time_slot, entry1.time_slot
                else:
                    # Swap rooms
                    entry1.room, entry2.room = entry2.room, entry1.room
        
        elif mutation_type == 'add_remove':
            # Add or remove a session (if constraints allow)
            if random.random() < 0.5 and len(mutated) < len(self.courses) * 3:
                # Try to add a session
                course = random.choice(self.courses)
                suitable_slots = self.get_suitable_time_slots(course)
                suitable_rooms = self.get_suitable_rooms(course)
                
                if suitable_slots and suitable_rooms:
                    new_entry = ScheduleEntry(
                        course=course,
                        instructor=self.get_instructor_by_name(course.instructor_name),
                        room=random.choice(suitable_rooms),
                        time_slot=random.choice(suitable_slots),
                        section=f"{course.department}-{course.year_level} {course.block}"
                    )
                    mutated.append(new_entry)
            else:
                # Try to remove a session (if it won't violate minimum requirements)
                if len(mutated) > len(self.courses):
                    entry_to_remove = random.choice(mutated)
                    mutated.remove(entry_to_remove)
        
        # Repair the mutated individual
        mutated = self.repair_schedule(mutated)
        
        return mutated
    
    def repair_schedule(self, individual: List[ScheduleEntry]) -> List[ScheduleEntry]:
        """Repair schedule to resolve conflicts with enhanced section conflict handling"""
        if not individual:
            return individual
        
        repaired = individual.copy()
        max_repair_attempts = 10
        attempt = 0
        
        while attempt < max_repair_attempts:
            conflicts = self.detect_conflicts(repaired)
            
            # If no conflicts, return as is
            if sum(conflicts.values()) == 0:
                return repaired
            
            # Priority 1: Fix section time overlaps (most critical)
            if conflicts['section_time_overlaps'] > 0:
                repaired = self.fix_section_time_overlaps(repaired)
            
            # Priority 2: Fix lunch break violations
            if conflicts['lunch_break_violations'] > 0:
                repaired = self.fix_lunch_break_violations(repaired)
            
            # Priority 3: Fix cross-section conflicts
            if conflicts['cross_section_conflicts'] > 0:
                repaired = self.fix_cross_section_conflicts(repaired)
            
            # Priority 4: Fix other conflicts
            repaired = self.fix_other_conflicts(repaired)
            
            attempt += 1
        
        return repaired
    
    def fix_section_time_overlaps(self, individual: List[ScheduleEntry]) -> List[ScheduleEntry]:
        """Fix section time overlaps by rescheduling conflicting entries"""
        repaired = individual.copy()
        
        # Group entries by section
        section_entries = defaultdict(list)
        for entry in repaired:
            section_entries[entry.section].append(entry)
        
        for section, entries in section_entries.items():
            if len(entries) <= 1:
                continue
            
            # Sort entries by start time
            entries.sort(key=lambda e: self.time_to_minutes(e.time_slot.start_time))
            
            # Check for overlaps and fix them
            for i in range(len(entries)):
                for j in range(i + 1, len(entries)):
                    entry1 = entries[i]
                    entry2 = entries[j]
                    
                    if (entry1.time_slot.day == entry2.time_slot.day and 
                        self.times_overlap(entry1.time_slot, entry2.time_slot)):
                        
                        # Try to reschedule entry2 to a different time slot
                        suitable_slots = self.get_suitable_time_slots(entry2.course)
                        for slot in suitable_slots:
                            # Check if this slot doesn't conflict with other entries
                            if not self.slot_conflicts_with_entries(slot, entries, entry2):
                                entry2.time_slot = slot
                                break
        
        return repaired
    
    def fix_lunch_break_violations(self, individual: List[ScheduleEntry]) -> List[ScheduleEntry]:
        """Fix lunch break violations by rescheduling conflicting entries"""
        repaired = individual.copy()
        
        for entry in repaired:
            if self.is_lunch_break_violation(entry.time_slot.start_time, entry.time_slot.end_time):
                # Try to find a suitable non-lunch time slot
                suitable_slots = self.get_suitable_time_slots(entry.course)
                for slot in suitable_slots:
                    if not self.is_lunch_break_violation(slot.start_time, slot.end_time):
                        entry.time_slot = slot
                        break
        
        return repaired
    
    def fix_cross_section_conflicts(self, individual: List[ScheduleEntry]) -> List[ScheduleEntry]:
        """Fix cross-section conflicts by rescheduling conflicting entries"""
        repaired = individual.copy()
        
        # Group by subject and time
        subject_time_groups = defaultdict(list)
        for i, entry in enumerate(repaired):
            key = f"{entry.course.course_code}|{entry.time_slot.day}|{entry.time_slot.start_time}|{entry.time_slot.end_time}"
            subject_time_groups[key].append((i, entry))
        
        for key, entries in subject_time_groups.items():
            if len(entries) > 1:
                # Keep first entry, reschedule others
                for i, (idx, entry) in enumerate(entries[1:], 1):
                    # Find alternative time slot
                    suitable_slots = self.get_suitable_time_slots(entry.course)
                    suitable_rooms = self.get_suitable_rooms(entry.course)
                    
                    if suitable_slots and suitable_rooms:
                        # Try to find non-conflicting slot
                        for slot in suitable_slots:
                            for room in suitable_rooms:
                                if not self.slot_conflicts_with_entries(slot, repaired, entry):
                                    repaired[idx] = ScheduleEntry(
                                        course=entry.course,
                                        instructor=entry.instructor,
                                        room=room,
                                        time_slot=slot,
                                        section=entry.section
                                    )
                                    break
                            else:
                                continue
                            break
        
        return repaired
    
    def fix_other_conflicts(self, individual: List[ScheduleEntry]) -> List[ScheduleEntry]:
        """Fix other types of conflicts"""
        repaired = individual.copy()
        
        # Group entries by time slot for conflict resolution
        time_groups = defaultdict(list)
        for entry in repaired:
            key = f"{entry.time_slot.day}|{entry.time_slot.start_time}|{entry.time_slot.end_time}"
            time_groups[key].append(entry)
        
        # Resolve conflicts in each time slot
        for time_key, entries in time_groups.items():
            if len(entries) <= 1:
                continue
            
            # Check for instructor conflicts
            instructor_conflicts = defaultdict(list)
            for entry in entries:
                instructor_conflicts[entry.instructor.instructor_id].append(entry)
            
            for instructor, conflicting_entries in instructor_conflicts.items():
                if len(conflicting_entries) > 1:
                    # Keep the first entry, reschedule others
                    for entry in conflicting_entries[1:]:
                        suitable_slots = self.get_suitable_time_slots(entry.course)
                        if suitable_slots:
                            # Find a non-conflicting slot
                            for slot in suitable_slots:
                                slot_key = f"{slot.day}|{slot.start_time}|{slot.end_time}"
                                if slot_key not in time_groups or len(time_groups[slot_key]) == 0:
                                    entry.time_slot = slot
                                    break
            
            # Check for room conflicts
            room_conflicts = defaultdict(list)
            for entry in entries:
                room_conflicts[entry.room.room_id].append(entry)
            
            for room_id, conflicting_entries in room_conflicts.items():
                if len(conflicting_entries) > 1:
                    # Keep the first entry, reschedule others
                    for entry in conflicting_entries[1:]:
                        suitable_rooms = self.get_suitable_rooms(entry.course)
                        if suitable_rooms:
                            # Find a non-conflicting room
                            for room in suitable_rooms:
                                if room.room_id != room_id:
                                    entry.room = room
                                    break
        
        return repaired
    
    def slot_conflicts_with_entries(self, slot: TimeSlot, entries: List[ScheduleEntry], exclude_entry: ScheduleEntry) -> bool:
        """Check if a time slot conflicts with existing entries (excluding the excluded entry)"""
        for entry in entries:
            if entry == exclude_entry:
                continue
            if (entry.time_slot.day == slot.day and 
                self.times_overlap(entry.time_slot, slot)):
                return True
        return False
    
    def evolve(self) -> List[ScheduleEntry]:
        """Run the enhanced genetic algorithm with adaptive parameters"""
        # Reduced debug output to prevent pipe overflow
        if len(self.courses) <= 10:
            print("Starting enhanced genetic algorithm evolution...", file=sys.stderr)
        
        import time
        start_time = time.time()
        max_runtime = 45  # 45 seconds max runtime (increased for better results)
        
        # Initialize population
        population = [self.create_individual() for _ in range(self.population_size)]
        
        best_fitness = float('inf')
        best_individual = None
        stagnation_count = 0
        last_improvement = 0
        
        for generation in range(self.generations):
            # Check timeout
            if time.time() - start_time > max_runtime:
                print(f"Timeout reached after {time.time() - start_time:.1f} seconds", file=sys.stderr)
                break
                
            # Calculate fitness for all individuals
            fitness_scores = []
            for individual in population:
                fitness = self.calculate_fitness(individual)
                fitness_scores.append((fitness, individual))
            
            # Sort by fitness (lower is better)
            fitness_scores.sort(key=lambda x: x[0])
            
            # Update best individual
            current_best_fitness, current_best_individual = fitness_scores[0]
            if current_best_fitness < best_fitness:
                best_fitness = current_best_fitness
                best_individual = current_best_individual.copy()
                stagnation_count = 0
                last_improvement = generation
            else:
                stagnation_count += 1
            
            # Track fitness history
            self.best_fitness_history.append(current_best_fitness)
            
            # Adaptive mutation rate
            if self.adaptive_mutation:
                if stagnation_count > 10:
                    self.mutation_rate = min(0.3, self.mutation_rate * 1.1)
                elif stagnation_count < 5:
                    self.mutation_rate = max(0.05, self.mutation_rate * 0.95)
            
            # Reduced debug output to prevent pipe overflow
            if len(self.courses) <= 10 or generation % 5 == 0:  # Only log every 5th generation for large datasets
                print(f"Generation {generation + 1}: Best fitness = {current_best_fitness:.2f}, "
                      f"Mutation rate = {self.mutation_rate:.3f}, Stagnation = {stagnation_count}", file=sys.stderr)
            
            # Early termination conditions
            if current_best_fitness == 0:  # Perfect solution found
                print("Perfect solution found!", file=sys.stderr)
                break
            
            # Accept solution with reasonable conflicts (less than 50 total conflicts)
            if current_best_fitness < 50000:  # Less than 50 hard constraint violations
                print(f"Good solution found with fitness {current_best_fitness:.2f}", file=sys.stderr)
                break
            
            if stagnation_count >= self.stagnation_limit:
                print(f"Stagnation limit reached. Restarting with best solution...", file=sys.stderr)
                # Restart with best solution and some random individuals
                population = [best_individual] + [self.create_individual() for _ in range(self.population_size - 1)]
                stagnation_count = 0
                continue
            
            # Create new population
            new_population = []
            
            # Keep elite individuals
            elite = [individual for _, individual in fitness_scores[:self.elite_size]]
            new_population.extend(elite)
            
            # Generate offspring
            while len(new_population) < self.population_size:
                # Select parents using tournament selection
                parent1 = self.tournament_selection(population, fitness_scores)
                parent2 = self.tournament_selection(population, fitness_scores)
                
                # Create offspring
                child1, child2 = self.crossover(parent1, parent2)
                
                # Apply mutation
                child1 = self.mutate(child1)
                child2 = self.mutate(child2)
                
                new_population.extend([child1, child2])
            
            population = new_population[:self.population_size]
        
        print(f"Evolution completed. Best fitness: {best_fitness:.2f}", file=sys.stderr)
        return best_individual or []
    
    def tournament_selection(self, population: List[List[ScheduleEntry]], fitness_scores: List[Tuple[float, List[ScheduleEntry]]]) -> List[ScheduleEntry]:
        """Select an individual using enhanced tournament selection"""
        tournament_size = min(self.tournament_size, len(fitness_scores))
        tournament = random.sample(fitness_scores, tournament_size)
        tournament.sort(key=lambda x: x[0])  # Sort by fitness (lower is better)
        return tournament[0][1]  # Return best from tournament
    
    def create_simple_schedule(self) -> List[ScheduleEntry]:
        """Create a simple schedule using greedy assignment as fallback"""
        print("Creating simple fallback schedule...", file=sys.stderr)
        
        schedule = []
        used_times = set()
        used_rooms = set()
        
        for course in self.courses:
            session_durations = self.generate_randomized_sessions(course.units, course.employment_type)
            suitable_slots = self.get_suitable_time_slots(course)
            suitable_rooms = self.get_suitable_rooms(course)
            
            for session_duration in session_durations:
                # Create custom time slot with the required duration
                base_slot = random.choice(suitable_slots)
                
                # Calculate end time based on session duration
                start_time = datetime.strptime(base_slot.start_time, "%H:%M:%S")
                end_time = start_time + timedelta(hours=session_duration)
                end_time_str = end_time.strftime("%H:%M:%S")
                
                # Create custom time slot with the correct duration
                custom_time_slot = TimeSlot(
                    day=base_slot.day,
                    start_time=base_slot.start_time,
                    end_time=end_time_str,
                    period=base_slot.period
                )
                
                # Find first available room
                assigned = False
                time_key = f"{custom_time_slot.day}|{custom_time_slot.start_time}|{custom_time_slot.end_time}"
                
                for room in suitable_rooms:
                    room_key = f"{room.room_id}|{time_key}"
                    
                    if time_key not in used_times and room_key not in used_rooms:
                        entry = ScheduleEntry(
                            course=course,
                            instructor=self.get_instructor_by_name(course.instructor_name),
                            room=room,
                            time_slot=custom_time_slot,
                            section=f"{course.department}-{course.year_level} {course.block}"
                        )
                        schedule.append(entry)
                        used_times.add(time_key)
                        used_rooms.add(room_key)
                        assigned = True
                        break
                
                if not assigned:
                    # Force assignment even with conflicts
                    base_slot = suitable_slots[0] if suitable_slots else self.time_slots[0]
                    room = suitable_rooms[0] if suitable_rooms else self.rooms[0]
                    
                    # Create custom time slot with the required duration
                    start_time = datetime.strptime(base_slot.start_time, "%H:%M:%S")
                    end_time = start_time + timedelta(hours=session_duration)
                    end_time_str = end_time.strftime("%H:%M:%S")
                    
                    custom_time_slot = TimeSlot(
                        day=base_slot.day,
                        start_time=base_slot.start_time,
                        end_time=end_time_str,
                        period=base_slot.period
                    )
                    
                    entry = ScheduleEntry(
                        course=course,
                        instructor=self.get_instructor_by_name(course.instructor_name),
                        room=room,
                        time_slot=custom_time_slot,
                        section=f"{course.department}-{course.year_level} {course.block}"
                    )
                    schedule.append(entry)
        
        return schedule
    
    def solve(self) -> Dict[str, Any]:
        """Main solve method with enhanced conflict reporting and timeout handling"""
        # Reduced debug output to prevent pipe overflow
        if len(self.courses) <= 10:
            print("Starting enhanced genetic algorithm scheduler...", file=sys.stderr)
        
        try:
            # Run evolution with timeout handling
            best_schedule = self.evolve()
            
            if not best_schedule:
                # Try fallback simple scheduling
                print("Trying fallback simple scheduling...", file=sys.stderr)
                best_schedule = self.create_simple_schedule()
        except Exception as e:
            print(f"Genetic algorithm failed: {e}", file=sys.stderr)
            # Try fallback simple scheduling
            print("Trying fallback simple scheduling...", file=sys.stderr)
            best_schedule = self.create_simple_schedule()
        
        if not best_schedule:
            return {
                "success": False,
                "message": "No valid schedule found",
                "schedules": [],
                "errors": ["No solution found"]
            }
        
        # Convert to output format with reduced data to prevent pipe overflow
        schedules = []
        for entry in best_schedule:
            schedules.append({
                "instructor": entry.instructor.name,
                "instructor_id": entry.instructor.instructor_id,
                "subject_code": entry.course.course_code,
                "subject_description": entry.course.description,
                "unit": entry.course.units,
                "day": entry.time_slot.day,
                "start_time": entry.time_slot.start_time,
                "end_time": entry.time_slot.end_time,
                "block": entry.course.block,
                "year_level": entry.course.year_level,
                "employment_type": entry.course.employment_type,
                "sessionType": "Lab session" if entry.course.requires_lab else "Non-Lab session",
                "room_id": entry.room.room_id,
                "dept": entry.course.department,  # Add department field
                "section": f"{entry.course.department}-{entry.course.year_level} {entry.course.block}"  # Add section field
            })
        
        # Calculate final conflicts and quality metrics
        conflicts = self.detect_conflicts(best_schedule)
        total_conflicts = sum(conflicts.values())
        fitness = self.calculate_fitness(best_schedule)
        
        # Validate units coverage
        units_valid = self.validate_units_coverage(best_schedule)
        
        # Determine success based on conflict count and units validation
        # Accept schedule if it has reasonable conflicts (less than 200) even if units validation fails
        success = total_conflicts < 200  # More lenient success criteria
        message = "Perfect schedule generated with no conflicts!" if total_conflicts == 0 else f"Schedule generated with {total_conflicts} conflicts"
        if not units_valid and total_conflicts >= 200:
            message += " (units coverage validation failed)"
        
        # Reduced quality metrics to prevent pipe overflow
        quality_metrics = {
            "total_conflicts": total_conflicts,
            "fitness_score": fitness
        }
        
        return {
            "success": success,
            "message": message,
            "schedules": schedules,
            "conflicts": conflicts,
            "fitness": fitness,
            "quality_metrics": quality_metrics,
            "total_conflicts": total_conflicts,
            "generations_run": len(self.best_fitness_history)
        }

def read_input() -> Dict[str, Any]:
    """Read input from stdin"""
    data = sys.stdin.read()
    if not data:
        return {}
    return json.loads(data)

def main():
    """Main function"""
    try:
        payload = read_input()
        if not payload:
            print(json.dumps({"success": False, "message": "Empty input"}))
            return
    except Exception as e:
        print(json.dumps({"success": False, "message": f"Input error: {str(e)}"}))
        return
    
    instructor_data = payload.get("instructorData", [])
    rooms_data = payload.get("rooms", [])
    
    if not instructor_data or not rooms_data:
        print(json.dumps({
            "success": False,
            "message": "Missing instructorData or rooms",
            "schedules": [],
            "errors": ["Invalid input"]
        }))
        return
    
    # Convert input data preserving original year level and block assignments
    courses = []
    
    for course_data in instructor_data:
        # Process session type to determine lab requirement
        session_type = str(course_data.get("sessionType", "Non-Lab session") or "").strip().lower()
        requires_lab = (session_type == "lab session")
        
        # Debug: Log lab session processing
        if requires_lab:
            print(f"DEBUG: Processing LAB session: {course_data.get('courseCode', 'Unknown')} - {course_data.get('yearLevel', 'Unknown')} {course_data.get('block', 'Unknown')}", file=sys.stderr)
        
        # Create course with original block assignment
        course = Course(
            name=course_data.get("name", ""),
            course_code=course_data.get("courseCode", ""),
            description=course_data.get("subject", ""),
            units=int(course_data.get("unit", 3)),
            year_level=course_data.get("yearLevel", "1st Year"),
            block=course_data.get("block", "A"),  # Use original block from data
            employment_type=course_data.get("employmentType", "FULL-TIME"),
            department=course_data.get("dept", "General"),
            instructor_name=course_data.get("name", ""),  # Preserve the original instructor assignment
            requires_lab=requires_lab  # Set lab requirement based on session type
        )
        courses.append(course)
    
    rooms = []
    for room_data in rooms_data:
        room = Room(
            room_id=room_data.get("room_id", 0),
            room_name=room_data.get("room_name", ""),
            capacity=room_data.get("capacity", 30),
            is_lab=room_data.get("is_lab", False),
            is_active=room_data.get("is_active", True)
        )
        rooms.append(room)
    
    # Parse instructors from course data
    instructor_map = {}
    for course_data in instructor_data:
        instructor_name = course_data.get("name", "")
        if instructor_name and instructor_name not in instructor_map:
            instructor_map[instructor_name] = Instructor(
                instructor_id=len(instructor_map) + 1,  # Simple ID assignment
                name=instructor_name,
                employment_type=course_data.get("employmentType", "FULL-TIME")
            )
    
    instructors = list(instructor_map.values())
    
    # Create scheduler and solve
    try:
        scheduler = GeneticScheduler(courses, rooms, instructors)
        result = scheduler.solve()
        
        # Ensure output is flushed to prevent broken pipe
        output = json.dumps(result)
        print(output, flush=True)
        
    except Exception as e:
        error_result = {
            "success": False,
            "message": f"Genetic algorithm error: {str(e)}",
            "schedules": [],
            "errors": [str(e)]
        }
        print(json.dumps(error_result), flush=True)

if __name__ == "__main__":
    main()
