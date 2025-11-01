<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conflict Checker</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-4">
    <div class="max-w-7xl mx-auto">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Schedule Conflict Checker</h1>
        
        @if(isset($latestGroup))
        <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
            <p class="text-sm text-blue-800">
                <strong>Checking:</strong> {{ $latestGroup->department }} - {{ $latestGroup->school_year }} - {{ ucfirst($latestGroup->semester) }} Semester
                <span class="text-gray-600">(Generated: {{ $latestGroup->created_at->format('M d, Y H:i') }})</span>
            </p>
        </div>
        @endif
        
        <!-- Grid Layout -->
        <div class="grid grid-cols-3 gap-4 h-screen">
            <!-- Instructor Conflicts -->
            <div class="bg-white rounded-lg shadow-md p-4">
                <h2 class="text-xl font-semibold text-red-600 mb-4 border-b-2 border-red-200 pb-2">
                    Instructor Conflicts
                </h2>
                <div class="overflow-y-auto h-full">
                    @if($instructorConflicts->count() > 0)
                        @foreach($instructorConflicts as $conflictGroup)
                            @php
                                $groupItems = (is_array($conflictGroup) && isset($conflictGroup['items'])) ? $conflictGroup['items'] : $conflictGroup;
                            @endphp
                            @if($groupItems && $groupItems->count() > 0)
                            <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                                <div class="text-sm font-medium text-red-800 mb-2">
                                    @if(is_array($conflictGroup) && isset($conflictGroup['conflicting_days']) && !empty($conflictGroup['conflicting_days']))
                                        Days: {{ implode(', ', $conflictGroup['conflicting_days']) }}
                                    @else
                                        {{ $groupItems->first()['day'] }}
                                    @endif
                                    {{ $groupItems->first()['start_time'] }} - {{ $groupItems->first()['end_time'] }}
                                    ({{ $groupItems->first()['school_year'] }} - {{ ucfirst($groupItems->first()['semester']) }} Semester)
                                </div>
                                <div class="text-sm font-medium text-red-700 mb-2">
                                    Instructor: {{ $groupItems->first()['instructor_name'] }}
                                </div>
                                @foreach($groupItems as $conflict)
                                    <div class="ml-4 mb-2 p-2 bg-white border border-red-100 rounded text-sm">
                                        <div class="font-medium text-gray-800">{{ $conflict['subject_code'] }}</div>
                                        <div class="text-gray-600">{{ $conflict['department'] }}</div>
                                        <div class="text-gray-600">Section: {{ $conflict['section_code'] }}</div>
                                        <div class="text-gray-600">Room: {{ $conflict['room_name'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                            @endif
                        @endforeach
                    @else
                        <div class="text-center text-gray-500 mt-8">
                            <div class="text-6xl mb-4">✅</div>
                            <p class="text-lg">No instructor conflicts found!</p>
                        </div>
                    @endif
                </div>
            </div>
            
            <!-- Room Conflicts -->
            <div class="bg-white rounded-lg shadow-md p-4">
                <h2 class="text-xl font-semibold text-orange-600 mb-4 border-b-2 border-orange-200 pb-2">
                    Room Conflicts
                </h2>
                <div class="overflow-y-auto h-full">
                    @if($roomConflicts->count() > 0)
                        @foreach($roomConflicts as $conflictGroup)
                            @php
                                $groupItems = (is_array($conflictGroup) && isset($conflictGroup['items'])) ? $conflictGroup['items'] : $conflictGroup;
                            @endphp
                            @if($groupItems && $groupItems->count() > 0)
                            <div class="mb-4 p-3 bg-orange-50 border border-orange-200 rounded-lg">
                                <div class="text-sm font-medium text-orange-800 mb-2">
                                    @if(is_array($conflictGroup) && isset($conflictGroup['conflicting_days']) && !empty($conflictGroup['conflicting_days']))
                                        Days: {{ implode(', ', $conflictGroup['conflicting_days']) }}
                                    @else
                                        {{ $groupItems->first()['day'] }}
                                    @endif
                                    {{ $groupItems->first()['start_time'] }} - {{ $groupItems->first()['end_time'] }}
                                    ({{ $groupItems->first()['school_year'] }} - {{ ucfirst($groupItems->first()['semester']) }} Semester)
                                </div>
                                <div class="text-sm font-medium text-orange-700 mb-2">
                                    Room: {{ $groupItems->first()['room_name'] }}
                                </div>
                                @foreach($groupItems as $conflict)
                                    <div class="ml-4 mb-2 p-2 bg-white border border-orange-100 rounded text-sm">
                                        <div class="font-medium text-gray-800">{{ $conflict['subject_code'] }}</div>
                                        <div class="text-gray-600">{{ $conflict['department'] }}</div>
                                        <div class="text-gray-600">Instructor: {{ $conflict['instructor_name'] }}</div>
                                        <div class="text-gray-600">Section: {{ $conflict['section_code'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                            @endif
                        @endforeach
                    @else
                        <div class="text-center text-gray-500 mt-8">
                            <div class="text-6xl mb-4">✅</div>
                            <p class="text-lg">No room conflicts found!</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Section Conflicts -->
            <div class="bg-white rounded-lg shadow-md p-4">
                <h2 class="text-xl font-semibold text-blue-600 mb-4 border-b-2 border-blue-200 pb-2">
                    Section Conflicts
                </h2>
                <div class="overflow-y-auto h-full">
                    @if($sectionConflicts->count() > 0)
                        @foreach($sectionConflicts as $conflictGroup)
                            @php
                                $groupItems = (is_array($conflictGroup) && isset($conflictGroup['items'])) ? $conflictGroup['items'] : $conflictGroup;
                            @endphp
                            @if($groupItems && $groupItems->count() > 0)
                            <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                <div class="text-sm font-medium text-blue-800 mb-2">
                                    @if(is_array($conflictGroup) && isset($conflictGroup['conflicting_days']) && !empty($conflictGroup['conflicting_days']))
                                        Days: {{ implode(', ', $conflictGroup['conflicting_days']) }}
                                    @else
                                        {{ $groupItems->first()['day'] }}
                                    @endif
                                    {{ $groupItems->first()['start_time'] }} - {{ $groupItems->first()['end_time'] }}
                                    ({{ $groupItems->first()['school_year'] }} - {{ ucfirst($groupItems->first()['semester']) }} Semester)
                                </div>
                                <div class="text-sm font-medium text-blue-700 mb-2">
                                    Section: {{ $groupItems->first()['section_code'] }}
                                </div>
                                @foreach($groupItems as $conflict)
                                    <div class="ml-4 mb-2 p-2 bg-white border border-blue-100 rounded text-sm">
                                        <div class="font-medium text-gray-800">{{ $conflict['subject_code'] }}</div>
                                        <div class="text-gray-600">{{ $conflict['department'] }}</div>
                                        <div class="text-gray-600">Instructor: {{ $conflict['instructor_name'] }}</div>
                                        <div class="text-gray-600">Room: {{ $conflict['room_name'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                            @endif
                        @endforeach
                    @else
                        <div class="text-center text-gray-500 mt-8">
                            <div class="text-6xl mb-4">✅</div>
                            <p class="text-lg">No section conflicts found!</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</body>
</html>
