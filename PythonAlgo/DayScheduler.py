from typing import List, Dict, Any, Iterable


# Canonical day ordering used across the project
DAYS: List[str] = [
    "Monday",
    "Tuesday",
    "Wednesday",
    "Thursday",
    "Friday",
    "Saturday",
]


def all_days() -> List[str]:
    """Return the canonical ordered list of days (Mon-Sat)."""
    return DAYS.copy()


def is_valid_day(day: str) -> bool:
    """Check if a string is a recognized day name (case-insensitive)."""
    if not isinstance(day, str):
        return False
    return normalize_day(day) in DAYS


def normalize_day(day: str) -> str:
    """Normalize common day inputs to canonical capitalized form.

    Accepts values like "mon", "MONDAY", "Th", "thu", etc.
    Returns the canonical full day name or the original string if unknown.
    """
    if not isinstance(day, str):
        return day

    trimmed = day.strip().lower()
    mapping = {
        "m": "Monday", "mon": "Monday", "monday": "Monday",
        "t": "Tuesday", "tue": "Tuesday", "tues": "Tuesday", "tuesday": "Tuesday",
        "w": "Wednesday", "wed": "Wednesday", "wednesday": "Wednesday",
        "th": "Thursday", "thu": "Thursday", "thur": "Thursday", "thurs": "Thursday", "thursday": "Thursday",
        "f": "Friday", "fri": "Friday", "friday": "Friday",
        "s": "Saturday", "sat": "Saturday", "saturday": "Saturday",
    }
    return mapping.get(trimmed, day.strip())


def day_index(day: str) -> int:
    """Get the index of the day in canonical ordering (0=Mon). Raises ValueError if invalid."""
    nd = normalize_day(day)
    if nd not in DAYS:
        raise ValueError(f"Unrecognized day: {day}")
    return DAYS.index(nd)


def next_day(day: str) -> str:
    """Return the next canonical day (wraps Saturday -> Monday)."""
    idx = day_index(day)
    return DAYS[(idx + 1) % len(DAYS)]


def sort_by_day_then_time(schedules: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """Sort schedule dicts by day (Mon-Sat) and start_time (HH:MM:SS).

    Expects each item to include keys: 'day' and 'start_time'.
    Unknown days are placed at the end preserving relative order.
    """
    def sort_key(item: Dict[str, Any]):
        day_val = item.get("day", "")
        time_val = item.get("start_time", "00:00:00")
        nd = normalize_day(day_val)
        try:
            d_idx = DAYS.index(nd)
        except ValueError:
            d_idx = 999  # unknown days last
        return (d_idx, str(time_val))

    return sorted(schedules, key=sort_key)


def group_by_day(schedules: Iterable[Dict[str, Any]]) -> Dict[str, List[Dict[str, Any]]]:
    """Group schedule dicts by canonical day string."""
    grouped: Dict[str, List[Dict[str, Any]]] = {d: [] for d in DAYS}
    for item in schedules:
        nd = normalize_day(item.get("day", ""))
        if nd in grouped:
            grouped[nd].append(item)
        else:
            # Skip unknown day values quietly
            continue
    # Ensure each bucket is sorted by time for consistency
    for d in DAYS:
        grouped[d] = sort_by_day_then_time(grouped[d])
    return grouped


def to_compact_day_label(days: Iterable[str]) -> str:
    """Convert a list of days to a compact label like 'MWF' or 'TTh' or 'MThF'.

    Ordering follows canonical Mon-Sat. Duplicates ignored. Unknown days skipped.
    """
    abbrev_map = {
        "Monday": "M",
        "Tuesday": "T",
        "Wednesday": "W",
        "Thursday": "Th",
        "Friday": "F",
        "Saturday": "Sat",
    }
    seen = []
    for d in DAYS:
        if any(normalize_day(x) == d for x in days):
            seen.append(abbrev_map[d])
    return "".join(seen)


def preferred_two_day_patterns() -> List[List[str]]:
    """Return preferred 2-day patterns in rank order (Mon/Fri first)."""
    return [
        ["Monday", "Friday"],
        ["Tuesday", "Thursday"],
        ["Monday", "Wednesday"],
        ["Wednesday", "Friday"],
        ["Monday", "Thursday"],
        ["Tuesday", "Friday"],
    ]


def choose_days_for_sessions(num_sessions: int) -> List[str]:
    """Suggest days for the given number of sessions, preferring spread across the week."""
    if num_sessions <= 0:
        return []
    if num_sessions == 1:
        # Randomly select from available days instead of hardcoding Monday
        import random
        return [random.choice(DAYS)]
    if num_sessions == 2:
        patterns = preferred_two_day_patterns()
        return patterns[0] if patterns else ["Monday", "Friday"]
    if num_sessions == 3:
        return ["Monday", "Wednesday", "Friday"]
    # 4+ sessions: round-robin across canonical days (Mon-Sat)
    result: List[str] = []
    i = 0
    while len(result) < num_sessions:
        result.append(DAYS[i % len(DAYS)])
        i += 1
    return result


__all__ = [
    "DAYS",
    "all_days",
    "is_valid_day",
    "normalize_day",
    "day_index",
    "next_day",
    "sort_by_day_then_time",
    "group_by_day",
    "to_compact_day_label",
    "preferred_two_day_patterns",
    "choose_days_for_sessions",
]
