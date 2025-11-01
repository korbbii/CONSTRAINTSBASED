<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Reference Checker</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-4">
    <div class="max-w-7xl mx-auto">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Cross-Education Room Checker</h1>
        <p class="text-gray-600 mb-6">Rooms used in both Basic Education and College</p>
        
        @if(count($crossEducationRooms) > 0)
            <div class="space-y-6">
                @foreach($crossEducationRooms as $roomData)
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-2xl font-bold text-blue-700 mb-4 border-b-2 border-blue-200 pb-2">
                            {{ $roomData['room'] }}
                        </h2>
                        
                        <!-- Two-column layout -->
                        <div class="grid grid-cols-2 gap-6">
                            <!-- Basic Education (Reference) Schedules -->
                            <div class="bg-blue-50 rounded-lg p-4">
                                <h3 class="text-xl font-semibold text-blue-800 mb-3">Basic Education Schedules</h3>
                                @if(count($roomData['reference_schedules']) > 0)
                                    <div class="space-y-3">
                                        @foreach($roomData['reference_schedules'] as $ref)
                                            <div class="bg-white border rounded p-3 {{ isset($ref['has_conflict']) && $ref['has_conflict'] ? 'border-red-500 bg-red-50' : 'border-blue-200' }}">
                                                <div class="text-sm font-medium text-gray-800">
                                                    <span class="font-bold">Day:</span> {{ $ref['day'] ?? 'N/A' }}
                                                    @if(isset($ref['has_conflict']) && $ref['has_conflict'])
                                                        <span class="inline-block ml-2 px-2 py-0.5 bg-red-500 text-white text-xs font-bold rounded">CONFLICT</span>
                                                    @endif
                                                </div>
                                                <div class="text-sm text-gray-700">
                                                    <span class="font-bold">Time:</span> {{ $ref['time'] }}
                                                </div>
                                                <div class="text-sm text-gray-700">
                                                    <span class="font-bold">Subject:</span> {{ $ref['subject'] ?? 'N/A' }}
                                                </div>
                                                <div class="text-sm text-gray-700">
                                                    <span class="font-bold">Instructor:</span> {{ $ref['instructor'] ?? 'N/A' }}
                                                </div>
                                                <div class="text-sm text-gray-700">
                                                    <span class="font-bold">Room:</span> {{ $ref['room'] ?? 'N/A' }}
                                                </div>
                                                <div class="text-sm text-gray-700">
                                                    <span class="font-bold">Level:</span> {{ $ref['education_level'] ?? 'Unknown' }} - {{ $ref['year_level'] ?? 'Unknown' }}
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-gray-500 text-sm">No basic education schedules found</p>
                                @endif
                            </div>
                            
                            <!-- College Schedules -->
                            <div class="bg-green-50 rounded-lg p-4">
                                <h3 class="text-xl font-semibold text-green-800 mb-3">College Schedules</h3>
                                @if(count($roomData['college_schedules']) > 0)
                                    <div class="space-y-3">
                                        @foreach($roomData['college_schedules'] as $col)
                                            <div class="bg-white border rounded p-3 {{ isset($col['has_conflict']) && $col['has_conflict'] ? 'border-red-500 bg-red-50' : 'border-green-200' }}">
                                                <div class="text-sm font-medium text-gray-800">
                                                    <span class="font-bold">Day:</span> {{ $col['day'] ?? 'N/A' }}
                                                    @if(isset($col['has_conflict']) && $col['has_conflict'])
                                                        <span class="inline-block ml-2 px-2 py-0.5 bg-red-500 text-white text-xs font-bold rounded">CONFLICT</span>
                                                    @endif
                                                </div>
                                                <div class="text-sm text-gray-700">
                                                    <span class="font-bold">Time:</span> {{ $col['start_time'] }} - {{ $col['end_time'] }}
                                                </div>
                                                <div class="text-sm text-gray-700">
                                                    <span class="font-bold">Subject:</span> {{ $col['subject'] ?? 'N/A' }}
                                                </div>
                                                <div class="text-sm text-gray-700">
                                                    <span class="font-bold">Section:</span> {{ $col['section'] ?? 'N/A' }}
                                                </div>
                                                <div class="text-sm text-gray-700">
                                                    <span class="font-bold">Instructor:</span> {{ $col['instructor'] ?? 'N/A' }}
                                                </div>
                                                <div class="text-sm text-gray-700">
                                                    <span class="font-bold">Room:</span> {{ $col['room'] ?? 'N/A' }}
                                                </div>
                                                <div class="text-sm text-gray-700">
                                                    <span class="font-bold">Dept:</span> {{ $col['department'] ?? 'Unknown' }} - {{ $col['school_year'] ?? 'Unknown' }} ({{ $col['semester'] ?? 'Unknown' }})
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-gray-500 text-sm">No college schedules found</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="bg-white rounded-lg shadow-md p-6 text-center">
                <div class="text-6xl mb-4">✅</div>
                <p class="text-lg text-gray-600 mb-2">No rooms found used in both Basic Education and College</p>
                <p class="text-sm text-gray-500">All rooms are uniquely assigned to either Basic Education or College schedules.</p>
            </div>
        @endif
    </div>
</body>
</html>
