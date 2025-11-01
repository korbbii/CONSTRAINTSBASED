from typing import Dict, Any


def get_room_capacity(room: Dict[str, Any]) -> int:
	"""Get room capacity, default to 30 if not specified."""
	return room.get('capacity', 30)


def is_room_suitable_for_course_dict(room: Dict[str, Any], course: Dict[str, Any]) -> bool:
	"""Suitability check for dict-shaped room/course (used by Scheduler.py)."""
	room_capacity = get_room_capacity(room)
	course_units = course.get('unit', 3)
	estimated_students = min(50, max(20, course_units * 10))
	if room_capacity < estimated_students * 0.8:
		return False
	# Enforce lab vs non-lab matching
	requires_lab = bool(course.get('requires_lab', False))
	is_lab_room = bool(room.get('is_lab', False))
	if requires_lab and not is_lab_room:
		return False
	if not requires_lab and is_lab_room:
		return False
	if not room.get('is_active', True):
		return False
	return True


def is_room_suitable_for_course_obj(room: Any, course: Any) -> bool:
	"""Suitability check for dataclass-like objects (used by GeneticScheduler.py)."""
	# Basic capacity check
	estimated_students = min(50, max(20, getattr(course, 'units', 3) * 10))
	capacity = getattr(room, 'capacity', 30)
	if capacity < estimated_students * 0.8:
		return False
	# Lab requirement
	requires_lab = bool(getattr(course, 'requires_lab', False))
	is_lab = bool(getattr(room, 'is_lab', False))
	if requires_lab and not is_lab:
		return False
	if not requires_lab and is_lab:
		return False
	# Active flag
	is_active = getattr(room, 'is_active', True)
	if not is_active:
		return False
	return True


