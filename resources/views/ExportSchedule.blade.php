<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Schedule - GINGOOG CITY COLLEGES, INC.</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/xlsx/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        @media print {
            @page {
                margin: 0.25in;
                size: A4 portrait;
            }
            
            * {
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            
            body { 
                margin: 0 !important; 
                padding: 0 !important; 
                font-size: 8px !important;
                line-height: 1.1 !important;
                background: white !important;
            }
            
            .no-print { display: none !important; }
            
            .max-w-7xl {
                max-width: none !important;
                width: 100% !important;
            }
            
            .p-6 {
                padding: 0 !important;
            }
            
            .bg-white.rounded-2xl.shadow-xl {
                border-radius: 0 !important;
                box-shadow: none !important;
                background: white !important;
            }
            
            .text-center.p-6.border-b.border-gray-200 {
                padding: 10px 0 !important;
                margin-bottom: 5px !important;
                border-bottom: 1px solid #ccc !important;
            }
            
            h1 {
                font-size: 18px !important;
                margin: 5px 0 !important;
            }
            
            h2 {
                font-size: 14px !important;
                margin: 3px 0 !important;
            }
            
            p {
                font-size: 10px !important;
                margin: 2px 0 !important;
            }
            
            .mb-8 {
                margin-bottom: 15px !important;
            }
            
            .bg-\[#2e6731\].text-white.px-6.py-3.rounded-t-lg {
                padding: 5px 10px !important;
                border-radius: 0 !important;
                background: #f3f4f6 !important;
                color: #374151 !important;
            }
            
            h3 {
                font-size: 12px !important;
                margin: 0 !important;
            }
            
            table {
                font-size: 7px !important;
                width: 100% !important;
                border-collapse: collapse !important;
                page-break-inside: avoid !important;
                table-layout: fixed !important;
            }
            
            /* Force column widths in print */
            table td, table th {
                width: auto !important;
                min-width: 0 !important;
                max-width: none !important;
            }
            
            th, td {
                padding: 1px 2px !important;
                border: 1px solid #000 !important;
                vertical-align: top !important;
                word-wrap: break-word !important;
            }
            
            th {
                background: #f3f4f6 !important;
                color: #374151 !important;
                font-weight: bold !important;
                font-size: 7px !important;
            }
            
            .truncate {
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                max-width: 100px !important;
            }
            
            .whitespace-nowrap {
                white-space: nowrap !important;
            }
            
            .overflow-x-auto {
                overflow: visible !important;
            }
            
            /* Specific column width adjustments for print */
            th:nth-child(1), td:nth-child(1) { width: 7% !important; max-width: 7% !important; } /* Code */
            th:nth-child(2), td:nth-child(2) { width: 31% !important; max-width: 31% !important; } /* Description */
            th:nth-child(3), td:nth-child(3) { width: 3% !important; max-width: 3% !important; }  /* Units */
            th:nth-child(4), td:nth-child(4) { width: 22% !important; max-width: 22% !important; } /* Instructor */
            th:nth-child(5), td:nth-child(5) { width: 8% !important; max-width: 8% !important; }  /* Day */
            th:nth-child(6), td:nth-child(6) { width: 15% !important; max-width: 15% !important; padding-right: 5px !important; } /* Time */
            th:nth-child(7), td:nth-child(7) { width: 15% !important; max-width: 15% !important; padding-left: 3px !important; } /* Room */
            
            /* Page break control */
            .mb-8:not(:last-child) {
                page-break-after: avoid !important;
            }
            
            .mb-8:last-child {
                page-break-after: auto !important;
            }
        }
        
        /* Regular view column width adjustments */
        .schedule-table th:nth-child(1), 
        .schedule-table td:nth-child(1) { 
            width: 80px !important; 
            min-width: 80px !important; 
            max-width: 80px !important; 
        } /* Code column */
        
        .schedule-table th:nth-child(3), 
        .schedule-table td:nth-child(3) { 
            width: 60px !important; 
            min-width: 60px !important; 
            max-width: 60px !important; 
        } /* Units column */
    </style>
</head>
<body class="bg-white">
    <!-- Export Options Bar -->
    <div class="no-print bg-white shadow-sm border-b border-gray-200 p-4">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <h1 class="text-xl font-bold text-gray-800">Export Schedule</h1>
                <span class="text-gray-500">|</span>
                <span class="text-sm text-gray-600">{{ $scheduleGroup->department ?? 'BSBA' }} - {{ $scheduleGroup->school_year ?? 'N/A' }}</span>
            </div>
            <div class="flex items-center space-x-3">
                <button id="printBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center space-x-2 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                    </svg>
                    <span>Print</span>
                </button>
                <button id="xlsxBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center space-x-2 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <span>Excel</span>
                </button>
                <button id="pdfBtn" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg flex items-center space-x-2 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                    <span>PDF</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Schedule Content -->
    <div id="scheduleContent" class="max-w-7xl mx-auto p-6">
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            <!-- Header -->
            <div class="bg-white text-gray-800 p-6 border-b border-gray-200">
                <div class="text-center">
                    <h1 class="text-3xl font-bold mb-2">GINGOOG CITY COLLEGES, INC.</h1>
                    <p class="text-lg text-gray-600">Gingoog City, Misamis Oriental</p>
                    <div class="mt-4 text-center">
                        <h2 class="text-2xl font-semibold mb-1 text-gray-800">{{ $scheduleGroup->department ?? 'BSBA' }}</h2>
                        <p class="text-lg text-gray-700">{{ $scheduleGroup->semester ?? 'N/A' }} Semester, S.Y {{ $scheduleGroup->school_year ?? 'N/A' }}</p>
                        <p class="text-sm text-gray-500 mt-1">AS OF JANUARY 2025 (REVISION: 3)</p>
                    </div>
                </div>
            </div>

            <!-- Schedule Tables -->
            <div class="p-6">
                @foreach($groupedSchedules as $sectionCode => $entries)
                    <div class="mb-8">
                        <div class="bg-gray-100 text-gray-800 px-6 py-3 rounded-t-lg border border-gray-300">
                            <h3 class="text-xl font-semibold">{{ $sectionCode }}</h3>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="schedule-table w-full border-collapse border border-gray-300" style="table-layout: fixed;">
                                <thead>
                                    <tr class="bg-gray-100 text-gray-800 border border-gray-300">
                                        <th class="border border-gray-300 px-3 py-2 text-xs font-bold">Code</th>
                                        <th class="border border-gray-300 px-3 py-2 text-xs font-bold">Description</th>
                                        <th class="border border-gray-300 px-3 py-2 text-xs font-bold">Units</th>
                                        <th class="border border-gray-300 px-3 py-2 text-xs font-bold">Instructor</th>
                                        <th class="border border-gray-300 px-3 py-2 text-xs font-bold">Day</th>
                                        <th class="border border-gray-300 px-3 py-2 text-xs font-bold">Time</th>
                                        <th class="border border-gray-300 px-3 py-2 text-xs font-bold">Room</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        // Debug: Log the first entry to see its structure
                                        if (isset($entries[0])) {
                                            \Log::info('ExportSchedule view: First entry type: ' . gettype($entries[0]));
                                            \Log::info('ExportSchedule view: First entry keys: ', is_array($entries[0]) ? array_keys($entries[0]) : 'Not an array');
                                            \Log::info('ExportSchedule view: First entry content: ', $entries[0]);
                                        }
                                    @endphp
                                    @foreach($entries as $index => $entry)
                                        @php
                                            // Apply background colors: yellow for part-time, light green for lab sessions, alternating for others
                                            $isPartTime = ($entry['employment_type'] ?? '') === 'PART-TIME' || ($entry['employment_type'] ?? '') === 'part-time';
                                            $isLabSession = ($entry['is_lab'] ?? false) === true || ($entry['is_lab'] ?? false) === '1';
                                            
                                            if ($isPartTime) {
                                                $rowBg = 'bg-yellow-100';
                                            } elseif ($isLabSession) {
                                                $rowBg = 'bg-green-50';
                                            } else {
                                                $rowBg = $index % 2 === 0 ? 'bg-white' : 'bg-gray-50';
                                            }
                                        @endphp
                                        <tr class="{{ $rowBg }}">
                                            <td class="border border-gray-300 px-2 py-1 text-xs text-gray-800 font-medium whitespace-nowrap">{{ $entry['subject_code'] ?? 'N/A' }}</td>
                                            <td class="border border-gray-300 px-2 py-1 text-xs text-gray-700 truncate" title="{{ $entry['subject_description'] ?? 'N/A' }}">{{ $entry['subject_description'] ?? 'N/A' }}</td>
                                            <td class="border border-gray-300 px-2 py-1 text-xs text-gray-800 text-center whitespace-nowrap">{{ $entry['units'] ?? '—' }}</td>
                                            <td class="border border-gray-300 px-2 py-1 text-xs text-gray-700 truncate" title="{{ $entry['instructor_name'] ?? 'N/A' }}">{{ $entry['instructor_name'] ?? 'N/A' }}</td>
                                            <td class="border border-gray-300 px-2 py-1 text-xs text-gray-700 text-center whitespace-nowrap">
                                                {{ $entry['days'] ?? 'N/A' }}
                                            </td>
                                            <td class="border border-gray-300 px-2 py-1 text-xs text-gray-700 text-center whitespace-nowrap">
                                                {{ $entry['time_range'] ?? 'N/A' }}
                                            </td>
                                            <td class="border border-gray-300 px-2 py-1 text-xs text-gray-800 text-center whitespace-nowrap">
                                                {{ $entry['room_name'] ?? 'N/A' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Print Options Modal -->
    <div id="printModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Print Options</h3>
                    <div class="space-y-4">
                        <label class="flex items-center">
                            <input type="radio" name="printOption" value="all" class="mr-3" checked>
                            <span class="text-gray-700">All Schedule</span>
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="printOption" value="perSessions" class="mr-3">
                            <span class="text-gray-700">Per Sessions (Grouped by Instructor)</span>
                        </label>
                    </div>
                    <div class="flex justify-end space-x-3 mt-6">
                        <button id="cancelPrint" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50">
                            Cancel
                        </button>
                        <button id="confirmPrint" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            Print
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Print functionality with options
        document.getElementById('printBtn').addEventListener('click', function() {
            document.getElementById('printModal').classList.remove('hidden');
        });

        document.getElementById('cancelPrint').addEventListener('click', function() {
            document.getElementById('printModal').classList.add('hidden');
        });

        document.getElementById('confirmPrint').addEventListener('click', function() {
            const selectedOption = document.querySelector('input[name="printOption"]:checked').value;
            document.getElementById('printModal').classList.add('hidden');
            
            if (selectedOption === 'all') {
                // Print all schedule (default behavior)
            window.print();
            } else if (selectedOption === 'perSessions') {
                // Print per sessions (grouped by instructor)
                printPerSessions();
            }
        });

        function printPerSessions() {
            // Create a new window for per sessions print
            const printWindow = window.open('', '_blank');
            
            // Get the current schedule data
            const scheduleData = @json($groupedSchedules);
            
            // Group by instructor
            const instructorGroups = {};
            Object.values(scheduleData).forEach(sectionEntries => {
                sectionEntries.forEach(entry => {
                    const instructorName = entry.instructor_name || 'N/A';
                    if (!instructorGroups[instructorName]) {
                        instructorGroups[instructorName] = [];
                    }
                    instructorGroups[instructorName].push(entry);
                });
            });

            // Generate HTML for per sessions view
            let printHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Export Schedule - Per Sessions</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
                        .header { text-align: center; margin-bottom: 30px; }
                        .instructor-section { margin-bottom: 40px; page-break-inside: avoid; }
                        .instructor-header { background: #f3f4f6; color: #374151; padding: 15px; margin-bottom: 0; border: 1px solid #d1d5db; }
                        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                        th, td { border: 1px solid #000; padding: 8px; text-align: left; }
                        th { background: #f3f4f6; color: #374151; font-weight: bold; border: 1px solid #d1d5db; }
                        .instructor-name { font-size: 18px; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>GINGOOG CITY COLLEGES, INC.</h1>
                        <p>Gingoog City, Misamis Oriental</p>
                        <h2>{{ $scheduleGroup->department ?? 'BSBA' }} - Per Sessions Schedule</h2>
                        <p>{{ $scheduleGroup->semester ?? 'N/A' }} Semester, S.Y {{ $scheduleGroup->school_year ?? 'N/A' }}</p>
                        <p>AS OF JANUARY 2025 (REVISION: 3)</p>
                    </div>
            `;

            // Add each instructor's schedule
            Object.keys(instructorGroups).sort().forEach(instructorName => {
                const entries = instructorGroups[instructorName];
                printHTML += `
                    <div class="instructor-section">
                        <div class="instructor-header">
                            <h3 class="instructor-name">${instructorName}</h3>
                        </div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Description</th>
                                    <th>Units</th>
                                    <th>Day</th>
                                    <th>Time</th>
                                    <th>Room</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                entries.forEach(entry => {
                    printHTML += `
                        <tr>
                            <td>${entry.subject_code || 'N/A'}</td>
                            <td>${entry.subject_description || 'N/A'}</td>
                            <td>${entry.units || '—'}</td>
                            <td>${entry.days || 'N/A'}</td>
                            <td>${entry.time_range || 'N/A'}</td>
                            <td>${entry.room_name || 'N/A'}</td>
                        </tr>
                    `;
                });
                
                printHTML += `
                            </tbody>
                        </table>
                    </div>
                `;
            });

            printHTML += `
                </body>
                </html>
            `;

            // Write content and print
            printWindow.document.write(printHTML);
            printWindow.document.close();
            printWindow.focus();
            
            // Wait for content to load then print
            printWindow.onload = function() {
                printWindow.print();
                printWindow.close();
            };
        }

        // Excel export functionality
        document.getElementById('xlsxBtn').addEventListener('click', function() {
            const workbook = XLSX.utils.book_new();
            
            @foreach($groupedSchedules as $sectionCode => $entries)
                const data{{ $loop->index }} = [
                    ['Code', 'Description', 'Units', 'Instructor', 'Day', 'Time', 'Room'],
                    @foreach($entries as $entry)
                        ['{{ $entry['subject_code'] ?? 'N/A' }}', '{{ $entry['subject_description'] ?? 'N/A' }}', '{{ $entry['units'] ?? '' }}', '{{ $entry['instructor_name'] ?? 'N/A' }}', '{{ $entry['days'] ?? 'N/A' }}', '{{ $entry['time_range'] ?? 'N/A' }}', '{{ $entry['room_name'] ?? 'N/A' }}'],
                    @endforeach
                ];
                
                const worksheet{{ $loop->index }} = XLSX.utils.aoa_to_sheet(data{{ $loop->index }});
                XLSX.utils.book_append_sheet(workbook, worksheet{{ $loop->index }}, '{{ $sectionCode }}');
            @endforeach
            
            XLSX.writeFile(workbook, 'Schedule_{{ $scheduleGroup->department ?? 'BSBA' }}_{{ $scheduleGroup->school_year ?? 'N/A' }}.xlsx');
        });

        // PDF export functionality
        document.getElementById('pdfBtn').addEventListener('click', function() {
            const element = document.getElementById('scheduleContent');
            const opt = {
                margin: 1,
                filename: 'Schedule_{{ $scheduleGroup->department ?? 'BSBA' }}_{{ $scheduleGroup->school_year ?? 'N/A' }}.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2 },
                jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
            };
            
            html2pdf().set(opt).from(element).save();
        });
    </script>
</body>
</html>
