import random
from datetime import datetime
from typing import List, Dict, Any
try:
    from .DayScheduler import DAYS
except ImportError:
    from DayScheduler import DAYS


def generate_comprehensive_time_slots() -> List[Dict[str, Any]]:
	"""Generate comprehensive time slots for the week with lunch break constraint."""
	time_slots: List[Dict[str, Any]] = []
	days = DAYS

	morning_slots = [
		{'start': '07:30:00', 'end': '09:00:00', 'period': 'morning'},
		{'start': '09:00:00', 'end': '10:30:00', 'period': 'morning'},
		{'start': '10:30:00', 'end': '12:00:00', 'period': 'morning'},
	]

	afternoon_slots = [
		{'start': '13:00:00', 'end': '14:30:00', 'period': 'afternoon'},
		{'start': '14:30:00', 'end': '16:00:00', 'period': 'afternoon'},
		{'start': '16:00:00', 'end': '17:30:00', 'period': 'afternoon'},
		{'start': '15:00:00', 'end': '16:30:00', 'period': 'afternoon'},
		# Long blocks to support 3h/3.5h/4.5h/5h full-time sessions
		{'start': '13:00:00', 'end': '16:30:00', 'period': 'afternoon_long'},
		{'start': '13:00:00', 'end': '17:30:00', 'period': 'afternoon_long'},
		{'start': '13:00:00', 'end': '17:00:00', 'period': 'afternoon_long'},
		{'start': '13:00:00', 'end': '18:00:00', 'period': 'afternoon_long'},
		{'start': '16:00:00', 'end': '19:00:00', 'period': 'afternoon_long'},
	]

	evening_slots = [
		# Keep within 8:00 PM latest end; include a 3h evening window
		{'start': '17:00:00', 'end': '20:00:00', 'period': 'evening'},
		{'start': '17:00:00', 'end': '18:30:00', 'period': 'evening'},
		{'start': '18:00:00', 'end': '19:30:00', 'period': 'evening'},
		{'start': '18:30:00', 'end': '20:00:00', 'period': 'evening'},
	]

	# Create time slots with better day distribution
	all_slots = []
	for day in days:
		for slot in morning_slots:
			all_slots.append({'day': day, 'start': slot['start'], 'end': slot['end'], 'period': slot['period']})
		for slot in afternoon_slots:
			all_slots.append({'day': day, 'start': slot['start'], 'end': slot['end'], 'period': slot['period']})
		for slot in evening_slots:
			all_slots.append({'day': day, 'start': slot['start'], 'end': slot['end'], 'period': slot['period']})

	# Shuffle to randomize order
	random.shuffle(all_slots)
	
	# Reorganize to ensure better day distribution at the beginning
	# Group slots by day and interleave them
	day_groups = {}
	for slot in all_slots:
		day = slot['day']
		if day not in day_groups:
			day_groups[day] = []
		day_groups[day].append(slot)
	
	# Interleave slots from different days for better distribution
	time_slots = []
	max_slots_per_day = max(len(slots) for slots in day_groups.values()) if day_groups else 0
	
	# Randomize day order to prevent Monday bias
	randomized_days = days.copy()
	random.shuffle(randomized_days)
	
	for i in range(max_slots_per_day):
		for day in randomized_days:
			if i < len(day_groups.get(day, [])):
				time_slots.append(day_groups[day][i])
	
	return time_slots


def filter_time_slots_by_employment(time_slots: List[Dict[str, Any]], employment_type: str) -> List[Dict[str, Any]]:
	"""Filter slots based on employment type policy with maximum flexibility."""
	if employment_type == 'PART-TIME':
		# Part-time: prefer evening, then afternoon, then morning as last resort
		evening_slots = [slot for slot in time_slots if slot['start'] >= '17:00:00']
		afternoon_slots = [slot for slot in time_slots if '13:00:00' <= slot['start'] < '17:00:00']
		morning_slots = [slot for slot in time_slots if '07:00:00' <= slot['start'] < '13:00:00']

		# Return preferred slots first, then fallbacks
		return evening_slots + afternoon_slots + morning_slots
	# Full-time: allow 7 AM to 8 PM (end must be <= 20:00)
	return [slot for slot in time_slots if slot['start'] >= '07:00:00' and slot['end'] <= '20:00:00']


def generate_randomized_sessions(units: int, employment_type: str) -> List[float]:
	"""Generate session durations with explicit FULL-TIME splits for 6–10 units.

	Policy:
	- PART-TIME: pack using 1.5h and 1.0h to fit evening slots.
	- FULL-TIME explicit splits:
	  6→[3,3], 7→[3.5,3.5], 8→[4,4], 9→[4.5,4.5], 10→[5,5].
	  Others: prefer two sessions up to 5h each; else minimal number of <=5h sessions.
	"""
	if units <= 0:
		return []

	# PART-TIME: keep short evening-friendly sessions
	if employment_type == 'PART-TIME':
		sessions: List[float] = []
		remaining = float(units)
		while remaining >= 1.5 - 1e-9:
			sessions.append(1.5)
			remaining -= 1.5
		if remaining >= 1.0 - 1e-9:
			sessions.append(1.0)
		return sessions

	# FULL-TIME rules below
	max_per_session = 5.0
	u = float(units)

	# Explicit splits 6–10 (for integer unit values)
	ft_map = {
		6: [3.0, 3.0],
		7: [3.5, 3.5],
		8: [4.0, 4.0],
		9: [4.5, 4.5],
		10: [5.0, 5.0],
	}
	if abs(u - round(u)) < 1e-9 and int(round(u)) in ft_map:
		return ft_map[int(round(u))][:]

	# Exactly 5 units: split into two 2.5-hour sessions
	if abs(u - 5.0) < 1e-9:
		return [2.5, 2.5]

	# >10 or non-explicit cases: minimal count with max 5h each
	if u > 10.0:
		full = int(u // max_per_session)
		sessions = [max_per_session] * full
		rem = round(u - full * max_per_session, 2)
		if rem >= 1.0 - 1e-9:
			sessions.append(rem)
		return sessions

	# Default for 5<u<10 non-explicit: even two-way split capped at 5h
	a = min(max_per_session, max(2.0, round(u / 2.0, 1)))
	b = round(u - a, 1)
	return [round(a, 2), round(b, 2)]


def generate_session_distribution_options(units: int, employment_type: str, option: str = 'A') -> List[float]:
	"""Generate session durations with improved efficiency for part-time instructors.

	Option A: Single Session (all units in one block)
	Option B: Two Sessions (split evenly)

	Policy:
	- PART-TIME: Use longer sessions to reduce slot requirements
	- FULL-TIME: Option A (single session) or Option B (two sessions)
	"""
	if units <= 0:
		return []

	# PART-TIME: Use longer sessions to reduce time slot requirements
	if employment_type == 'PART-TIME':
		sessions: List[float] = []
		remaining = float(units)

		# Use 4-5 hour sessions for high-unit courses to reduce slot count
		if units >= 10:
			# For 10-unit courses, use 4-hour sessions
			while remaining >= 4.0 - 1e-9:
				sessions.append(4.0)
				remaining -= 4.0
			if remaining >= 2.0 - 1e-9:
				sessions.append(2.0)
		elif units >= 6:
			# For 6-unit courses, use 3-hour sessions
			while remaining >= 3.0 - 1e-9:
				sessions.append(3.0)
				remaining -= 3.0
			if remaining >= 1.5 - 1e-9:
				sessions.append(1.5)
		else:
			# For lower unit courses, use 1.5-hour sessions
			while remaining >= 1.5 - 1e-9:
				sessions.append(1.5)
				remaining -= 1.5

		if remaining >= 1.0 - 1e-9:
			sessions.append(1.0)
		return sessions

	# FULL-TIME: Only Option A and Option B
	u = float(units)
	max_per_session = 5.0

	if option == 'A':
		# Option A: Single Session (all units in one block)
		if u <= max_per_session:
			return [u]
		else:
			# For units > 5, split into multiple sessions of max 5h each
			sessions = []
			remaining = u
			while remaining > 0:
				session_duration = min(max_per_session, remaining)
				sessions.append(session_duration)
				remaining -= session_duration
			return sessions

	elif option == 'B':
		# Option B: Two Sessions (split evenly)
		if u <= 2.0:
			return [u]  # Single session if <= 2 units
		else:
			# Split into two sessions
			first_session = round(u / 2.0, 1)
			second_session = round(u - first_session, 1)
			return [first_session, second_session]

	else:
		# Default to Option A
		return generate_session_distribution_options(units, employment_type, 'A')


def get_available_session_options(units: int, employment_type: str) -> Dict[str, str]:
	"""Get user-friendly descriptions of available session options."""
	options = {}
	
	if employment_type == 'PART-TIME':
		# PART-TIME only has one option (evening-friendly sessions)
		sessions = generate_session_distribution_options(units, employment_type, 'A')
		options['A'] = f"Evening Sessions: {len(sessions)} sessions averaging {round(units/len(sessions), 1)} hours each"
	else:
		# FULL-TIME has Option A and Option B
		option_a_sessions = generate_session_distribution_options(units, employment_type, 'A')
		option_b_sessions = generate_session_distribution_options(units, employment_type, 'B')
		
		options['A'] = f"Single Session: {units} hours in one block"
		options['B'] = f"Two Sessions: {len(option_b_sessions)} sessions averaging {round(units/len(option_b_sessions), 1)} hours each"
	
	return options


def is_lunch_break_violation(start_time: str, end_time: str) -> bool:
	"""Check if a slot overlaps 12:00-12:59."""
	start_hour = int(start_time.split(':')[0])
	start_min = int(start_time.split(':')[1])
	end_hour = int(end_time.split(':')[0])
	end_min = int(end_time.split(':')[1])
	start_minutes = start_hour * 60 + start_min
	end_minutes = end_hour * 60 + end_min
	lunch_start = 12 * 60
	lunch_end = 12 * 60 + 59
	return not (end_minutes <= lunch_start or start_minutes >= lunch_end)


def time_to_minutes(time_str: str) -> int:
	parts = time_str.split(':')
	return int(parts[0]) * 60 + int(parts[1])


def times_overlap(start1: str, end1: str, start2: str, end2: str) -> bool:
	s1 = time_to_minutes(start1)
	e1 = time_to_minutes(end1)
	s2 = time_to_minutes(start2)
	e2 = time_to_minutes(end2)
	return not (e1 <= s2 or e2 <= s1)


def format_time_12hour(time_24: str) -> str:
	"""Convert 24-hour time to 12-hour with AM/PM."""
	try:
		return datetime.strptime(time_24, "%H:%M:%S").strftime("%I:%M %p").lstrip('0')
	except ValueError:
		return time_24


