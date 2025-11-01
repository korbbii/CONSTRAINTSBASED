<?php

namespace App\Http\Controllers;

use App\Models\Reference;
use App\Models\ReferenceGroup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class ReferenceController extends Controller
{
    /**
     * Display a listing of reference schedules
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 10);
        $references = Reference::with('referenceGroup')->paginate($perPage);
        
        return response()->json([
            'data' => $references->items(),
            'pagination' => [
                'current_page' => $references->currentPage(),
                'last_page' => $references->lastPage(),
                'per_page' => $references->perPage(),
                'total' => $references->total(),
                'from' => $references->firstItem(),
                'to' => $references->lastItem(),
                'has_more_pages' => $references->hasMorePages(),
                'has_previous_page' => $references->previousPageUrl() !== null,
                'has_next_page' => $references->nextPageUrl() !== null,
            ]
        ]);
    }

    /**
     * Get all reference schedules without pagination
     */
    public function getAll(): JsonResponse
    {
        $references = Reference::with('referenceGroup')->get();
        return response()->json($references);
    }

    /**
     * Upload and process DOCX reference schedule file
     */
    public function uploadReferenceSchedule(Request $request): JsonResponse
    {
        try {
            // Validate the uploaded file
            $request->validate([
                'file' => 'required|file|mimes:docx|max:10240', // 10MB max
            ]);

            $file = $request->file('file');
            
            // Store the file temporarily
            $tempPath = $file->store('temp', 'local');
            $fullPath = storage_path('app/' . $tempPath);

            // Process the DOCX file
            $processedData = $this->processDocxFile($fullPath);

            if (empty($processedData)) {
                // Clean up temp file
                Storage::disk('local')->delete($tempPath);
                return response()->json([
                    'success' => false,
                    'message' => 'No valid schedule data found in the uploaded file.'
                ], 422);
            }

            // Store the processed data in database
            $savedCount = $this->storeReferenceData($processedData);

            // Clean up temp file
            Storage::disk('local')->delete($tempPath);

            return response()->json([
                'success' => true,
                'message' => "Reference schedule uploaded successfully! {$savedCount} entries processed.",
                'processed_count' => $savedCount,
                'total_entries' => count($processedData)
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            Log::error('Error uploading reference schedule: ' . $e->getMessage());
            
            // Clean up temp file if it exists
            if (isset($tempPath)) {
                Storage::disk('local')->delete($tempPath);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing the file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process DOCX file and extract schedule data
     */
    private function processDocxFile(string $filePath): array
    {
        try {
            // Set PhpWord settings
            Settings::setOutputEscapingEnabled(true);
            
            // Load the document
            $phpWord = IOFactory::load($filePath);
            
            $scheduleData = [];
            
            // Extract text from all sections
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                        // Process table data
                        $tableData = $this->extractTableData($element);
                        $scheduleData = array_merge($scheduleData, $tableData);
                    } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                        // Process text runs for schedule data
                        $textData = $this->extractTextData($element);
                        if (!empty($textData)) {
                            $scheduleData[] = $textData;
                        }
                    }
                }
            }
            
            return $scheduleData;
            
        } catch (Exception $e) {
            Log::error('Error processing DOCX file: ' . $e->getMessage());
            throw new Exception('Failed to process DOCX file: ' . $e->getMessage());
        }
    }

    /**
     * Extract data from table elements
     * Detects the layout type and uses the appropriate parser
     */
    private function extractTableData($table): array
    {
        $data = [];
        
        // First pass: Detect layout type by examining header row(s)
        $layoutInfo = $this->detectTableLayout($table);
        $layoutType = $layoutInfo['type'];
        $headerData = $layoutInfo['headers'] ?? [];
        
        Log::info("Detected table layout: " . $layoutType);
        
        // Store header data for group-based layout processing
        $this->cachedHeaderData = $headerData;
        
        // Reset afternoon flag for each table
        $this->isAfternoon = false;
        
        foreach ($table->getRows() as $rowIndex => $row) {
            $rowData = [];
            $cells = $row->getCells();
            
            // Skip if no cells
            if (empty($cells)) {
                continue;
            }
            
            // Extract cell content
            foreach ($cells as $cell) {
                $cellText = '';
                foreach ($cell->getElements() as $element) {
                    if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                        $cellText .= $element->getText();
                    } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                        foreach ($element->getElements() as $textElement) {
                            if ($textElement instanceof \PhpOffice\PhpWord\Element\Text) {
                                $cellText .= $textElement->getText();
                            }
                        }
                    }
                }
                $rowData[] = trim($cellText);
            }
            
            // Filter out empty cells at the end
            $rowData = array_filter($rowData, function($value) {
                return !empty(trim($value));
            });
            
            // Reset array indices
            $rowData = array_values($rowData);
            
            // Skip if no data
            if (empty($rowData)) {
                continue;
            }
            
            // Log the raw row data for debugging
            Log::info('Processing row data: ' . json_encode($rowData));
            
            // Check if this is a noon break row - if so, all subsequent rows are afternoon
            if (!empty($rowData) && preg_match('/noon\s*break/i', implode(' ', $rowData))) {
                $this->isAfternoon = true;
                Log::info('Detected NOON BREAK - switching to afternoon hours');
                continue;
            }
            
            // Process row data based on detected layout
            if (count($rowData) >= 2) {
                $processedRows = null;
                
                if ($layoutType === 'group-based') {
                    // Use new parser for group-based layout (TIME | G-7 MOLAVE | G-7 GEMELINA | etc.)
                    // Pass the day name if detected from table title
                    $dayName = $layoutInfo['day_name'] ?? null;
                    $processedRows = $this->processGroupBasedRowData($rowData, $dayName);
                } else {
                    // Use old parser for day-based layout (TIME | MONDAY | TUESDAY | etc.)
                    $processedRows = $this->processRowData($rowData);
                }
                
                if ($processedRows) {
                    // processRowData now returns an array of entries
                    if (is_array($processedRows)) {
                        foreach ($processedRows as $processedRow) {
                            $data[] = $processedRow;
                            Log::info('Successfully processed entry: ' . json_encode($processedRow));
                        }
                    } else {
                        // Backward compatibility for single entry
                        $data[] = $processedRows;
                        Log::info('Successfully processed row: ' . json_encode($processedRows));
                    }
                } else {
                    Log::info('Failed to process row: ' . json_encode($rowData));
                }
            }
        }
        
        return $data;
    }
    
    // Cache header data for group-based layout
    private $cachedHeaderData = [];
    
    // Track whether we're processing afternoon hours (after noon break)
    private $isAfternoon = false;
    
    /**
     * Detect table layout type by examining header rows
     */
    private function detectTableLayout($table): array
    {
        $hasDayHeaders = false;
        $hasGroupHeaders = false;
        $detectedDayName = null;
        $headerInfo = [];
        
        // Check first few rows for header indicators
        $rowsToCheck = min(5, count($table->getRows()));
        $allRowData = [];
        
        for ($i = 0; $i < $rowsToCheck; $i++) {
            $row = $table->getRows()[$i];
            $cells = $row->getCells();
            
            if (empty($cells)) {
                continue;
            }
            
            $rowData = [];
            foreach ($cells as $cell) {
                $cellText = '';
                foreach ($cell->getElements() as $element) {
                    if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                        $cellText .= $element->getText();
                    } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                        foreach ($element->getElements() as $textElement) {
                            if ($textElement instanceof \PhpOffice\PhpWord\Element\Text) {
                                $cellText .= $textElement->getText();
                            }
                        }
                    }
                }
                $rowData[] = trim($cellText);
            }
            
            $allRowData[] = $rowData;
        }
        
        // Analyze headers
        foreach ($allRowData as $rowIndex => $rowData) {
            foreach ($rowData as $colIndex => $cellContent) {
                $cellUpper = strtoupper($cellContent);
                
                // Check for day headers (typically in the title of the table)
                if (in_array($cellUpper, ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY'])) {
                    $hasDayHeaders = true;
                    if ($rowIndex === 0 || strlen($cellContent) > 3) {
                        // Likely the table title
                        $detectedDayName = ucfirst(strtolower($cellContent));
                    }
                }
                
                // Check for group headers (G-7, G-8, etc. or room patterns)
                // Store the header information for later use
                if ($colIndex > 0 && preg_match('/^(G-\d+|GRADE\s+\d+)/i', $cellContent, $matches)) {
                    $hasGroupHeaders = true;
                    // Extract group name and room from cell content like "G-7 MOLAVE (203 H.S BLDG)"
                    preg_match('/^([A-Z0-9\s\-]+?)\s*\(([\d\sA-Z\.]+)\)/', $cellContent, $roomMatch);
                    if ($roomMatch) {
                        $headerInfo[$colIndex] = [
                            'group' => trim($roomMatch[1]),
                            'room' => trim($roomMatch[2])
                        ];
                    } else {
                        // Try to extract just the group name
                        $headerInfo[$colIndex] = [
                            'group' => trim($cellContent),
                            'room' => 'Room TBD'
                        ];
                    }
                } elseif ($colIndex > 0 && preg_match('/\d+\s+(H\.S\s+BLDG|HS\s+BLDG|H\.S\.|ROOM)/', $cellContent)) {
                    $hasGroupHeaders = true;
                }
            }
        }
        
        // Determine layout type based on detected headers
        $layoutType = 'day-based'; // default
        if ($hasGroupHeaders && !$hasDayHeaders) {
            $layoutType = 'group-based';
        } elseif ($hasDayHeaders && !$hasGroupHeaders) {
            $layoutType = 'day-based';
        } elseif ($hasGroupHeaders && $hasDayHeaders) {
            // If both detected, prefer group-based (newer format)
            $layoutType = 'group-based';
        }
        
        return [
            'type' => $layoutType,
            'day_name' => $detectedDayName,
            'headers' => $headerInfo
        ];
    }

    /**
     * Process row data for group-based layout
     * Handles: TIME | G-7 MOLAVE (203 H.S BLDG) | G-7 GEMELINA (204 H.S BLDG) | etc.
     * Each column represents a different group/classroom, not a day
     */
    private function processGroupBasedRowData(array $rowData, ?string $dayName = null): ?array
    {
        try {
            // Skip if row doesn't have enough data (need at least TIME + one group column)
            if (count($rowData) < 2) {
                return null;
            }
            
            // Clean and trim all data
            $cleanData = array_map('trim', $rowData);
            
            // First column should be TIME (format: HH:MM-HH:MM or with meridiem)
            $timeValue = $cleanData[0] ?? '';
            if (!preg_match('/^\d{1,2}:\d{2}-\d{1,2}:\d{2}$/', $timeValue) && 
                !preg_match('/^\d{1,2}:\d{2}\s*(AM|PM)?-\d{1,2}:\d{2}\s*(AM|PM)?$/', $timeValue)) {
                return null; // Skip if first column is not a valid time
            }
            
            // Skip special rows that span all columns (like breaks, cleaning, etc.)
            $specialRows = ['cleaning', 'garden', 'home room', 'flag ceremony', 'snacks', 'break', 'noon break', 'morning preliminaries', 'preliminaries', 'recess'];
            $firstCellLower = strtolower($timeValue);
            foreach ($specialRows as $special) {
                if (strpos($firstCellLower, $special) !== false) {
                    return null; // Skip special rows
                }
            }
            
            // Use detected day name or default to Monday
            $dayName = $dayName ?? 'Monday';
            
            $scheduleEntries = [];
            
            // Process each group column (skip TIME column at index 0)
            for ($groupIndex = 1; $groupIndex < count($cleanData); $groupIndex++) {
                $cellContent = $cleanData[$groupIndex] ?? '';
                
                // Skip empty cells
                if (empty($cellContent)) {
                    continue;
                }
                
                // Skip if cell contains special activities (all caps or contains break/cleaning)
                if (preg_match('/^[A-Z\s\/]+$/', $cellContent) || 
                    preg_match('/break|cleaning|garden|snack|ceremony|preliminaries|recess|noon|morning/i', $cellContent)) {
                    continue;
                }
                
                // Skip if cell contains only time format (duplicate time column from table structure)
                if (preg_match('/^\d{1,2}:\d{2}-\d{1,2}:\d{2}$/', $cellContent) || 
                    preg_match('/^\d{1,2}:\d{2}\s*(AM|PM)?-\d{1,2}:\d{2}\s*(AM|PM)?$/', $cellContent)) {
                    continue; // Skip time-only cells
                }
                
                // Parse subject and instructor from cell content for group-based layout
                // In this layout, instructor is on top, subject is below
                $parsedData = $this->parseGroupBasedCellContent($cellContent);
                if (!$parsedData) {
                    continue;
                }
                
                // Extract group and room information from cached header data
                $groupInfo = $this->cachedHeaderData[$groupIndex] ?? null;
                $roomName = 'Room TBD';
                $groupName = 'Group TBD';
                
                if ($groupInfo) {
                    $roomName = $groupInfo['room'] ?? 'Room TBD';
                    $groupName = $groupInfo['group'] ?? 'Group TBD';
                }
                
                // Extract education level and year level from group name (e.g., "G-7" = "Grade 7")
                $yearLevel = $this->extractYearLevelFromGroup($groupName);
                $educationLevel = $this->extractEducationLevelFromGroup($groupName);
                
                $scheduleEntry = [
                    'group_data' => [
                        'school_year' => '2025-2026', // Default, could be extracted from document
                        'education_level' => $educationLevel,
                        'year_level' => $yearLevel,
                    ],
                    'schedule_data' => [
                        'time' => $this->convertTimeTo24Hour($timeValue),
                        'day' => $dayName,
                        'room' => $roomName,
                        'instructor' => $parsedData['instructor'],
                        'subject' => $parsedData['subject'],
                    ]
                ];
                
                $scheduleEntries[] = $scheduleEntry;
            }
            
            // Return all valid entries (one for each group column with data)
            return !empty($scheduleEntries) ? $scheduleEntries : null;
            
        } catch (Exception $e) {
            Log::warning('Error processing group-based row data: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Parse cell content for group-based layout
     * In group-based layout: Subject followed by instructor in parentheses on same line
     * Format: "Subject (Instructor, Last.)" or "Subject\nInstructor"
     */
    private function parseGroupBasedCellContent(string $cellContent): ?array
    {
        try {
            // Clean the cell content first
            $cellContent = trim($cellContent);
            
            if (empty($cellContent)) {
                return null;
            }
            
            // Log the raw cell content for debugging
            Log::info('Parsing group-based cell content: "' . $cellContent . '"');
            
            $instructor = '';
            $subject = '';
            
            // Method 1: Check if content has parentheses pattern "Subject (Instructor)"
            if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/', $cellContent, $matches)) {
                // Pattern: "Subject (Instructor)"
                $subject = trim($matches[1]);
                $instructor = trim($matches[2]);
                Log::info('Matched parentheses pattern - Subject: "' . $subject . '", Instructor: "' . $instructor . '"');
            }
            // Method 2: Check if content has multiple lines
            elseif (strpos($cellContent, "\n") !== false) {
                $lines = array_filter(array_map('trim', explode("\n", $cellContent)));
                if (count($lines) >= 2) {
                    // First line is subject, second line is instructor
                    $subject = $lines[0];
                    $instructor = $lines[1];
                    // Remove parentheses if present on instructor line
                    $instructor = preg_replace('/^\((.*?)\)$/', '$1', $instructor);
                    Log::info('Matched newline pattern - Subject: "' . $subject . '", Instructor: "' . $instructor . '"');
                }
            }
            // Method 3: Check for carriage returns
            elseif (strpos($cellContent, "\r") !== false) {
                $lines = array_filter(array_map('trim', explode("\r", $cellContent)));
                if (count($lines) >= 2) {
                    $subject = $lines[0];
                    $instructor = $lines[1];
                    $instructor = preg_replace('/^\((.*?)\)$/', '$1', $instructor);
                    Log::info('Matched carriage return pattern - Subject: "' . $subject . '", Instructor: "' . $instructor . '"');
                }
            }
            else {
                // Single line without parentheses - treat as subject with TBD instructor
                $subject = $cellContent;
                $instructor = 'TBD';
                Log::info('Matched single line - Subject: "' . $subject . '", Instructor: TBD');
            }
            
            // Clean up instructor name
            $instructor = trim($instructor);
            $instructor = preg_replace('/[^\w\s,\.]/', '', $instructor); // Keep letters, numbers, spaces, commas, periods
            
            // Clean up subject name
            $subject = trim($subject);
            $subject = preg_replace('/[^\w\s,\.\-]/', '', $subject); // Keep letters, numbers, spaces, commas, periods, hyphens
            
            // Log the parsed results
            Log::info('Parsed group-based - Subject: "' . $subject . '", Instructor: "' . $instructor . '"');
            
            // Validate that we have at least a subject
            if (empty($subject)) {
                return null;
            }
            
            // Default instructor if still empty
            if (empty($instructor)) {
                $instructor = 'TBD';
            }
            
            return [
                'subject' => $subject,
                'instructor' => $instructor
            ];
            
        } catch (Exception $e) {
            Log::warning('Error parsing group-based cell content: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Extract year level from group name (e.g., "G-7 MOLAVE" -> "Grade 7")
     */
    private function extractYearLevelFromGroup(string $groupName): string
    {
        // Extract grade number from group name
        if (preg_match('/G[-.]?(\d+)/i', $groupName, $matches)) {
            $gradeNum = $matches[1];
            return 'Grade ' . $gradeNum;
        }
        
        // Default fallback
        return 'Grade TBD';
    }
    
    /**
     * Extract education level from group name
     */
    private function extractEducationLevelFromGroup(string $groupName): string
    {
        // Grade 7-10 = HS (Junior High School)
        // Grade 11-12 = SHS (Senior High School)
        if (preg_match('/G[-.]?(\d+)/i', $groupName, $matches)) {
            $gradeNum = intval($matches[1]);
            if ($gradeNum >= 7 && $gradeNum <= 10) {
                return 'HS';
            } elseif ($gradeNum >= 11 && $gradeNum <= 12) {
                return 'SHS';
            }
        }
        
        // Default fallback
        return 'HS';
    }
    
    /**
     * Extract data from text elements
     */
    private function extractTextData($textRun): ?array
    {
        $text = '';
        foreach ($textRun->getElements() as $element) {
            if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                $text .= $element->getText();
            }
        }
        
        // Try to parse schedule data from text
        return $this->parseTextScheduleData($text);
    }

    /**
     * Process row data and map to reference schedule fields
     * This handles the specific structure: TIME | MONDAY | TUESDAY | WEDNESDAY | THURSDAY | FRIDAY
     */
    private function processRowData(array $rowData): ?array
    {
        try {
            // Skip if row doesn't have enough data (need at least TIME + one day column)
            if (count($rowData) < 2) {
                return null;
            }
            
            // Clean and trim all data
            $cleanData = array_map('trim', $rowData);
            
            // First column should be TIME (format: HH:MM-HH:MM or with meridiem)
            $timeValue = $cleanData[0] ?? '';
            if (!preg_match('/^\d{1,2}:\d{2}-\d{1,2}:\d{2}$/', $timeValue) && 
                !preg_match('/^\d{1,2}:\d{2}\s*(AM|PM)?-\d{1,2}:\d{2}\s*(AM|PM)?$/', $timeValue)) {
                return null; // Skip if first column is not a valid time
            }
            
            // Skip special rows that span all columns (like breaks, cleaning, etc.)
            $specialRows = ['cleaning', 'garden', 'home room', 'flag ceremony', 'snacks', 'break', 'noon break'];
            $firstCellLower = strtolower($timeValue);
            foreach ($specialRows as $special) {
                if (strpos($firstCellLower, $special) !== false) {
                    return null; // Skip special rows
                }
            }
            
            // Process each day column (columns 1-5 should be MONDAY through FRIDAY)
            $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            $scheduleEntries = [];
            
            for ($dayIndex = 1; $dayIndex <= 5 && $dayIndex < count($cleanData); $dayIndex++) {
                $cellContent = $cleanData[$dayIndex] ?? '';
                
                // Skip empty cells
                if (empty($cellContent)) {
                    continue;
                }
                
                // Skip if cell contains special activities (all caps or contains break/cleaning)
                if (preg_match('/^[A-Z\s\/]+$/', $cellContent) || 
                    preg_match('/break|cleaning|garden|snack|ceremony/i', $cellContent)) {
                    continue;
                }
                
                // Parse subject and instructor from cell content
                $parsedData = $this->parseCellContent($cellContent);
                if (!$parsedData) {
                    continue;
                }
                
                $dayName = $dayNames[$dayIndex - 1]; // Convert 1-5 to Monday-Friday
                
                $scheduleEntry = [
                    'group_data' => [
                    'school_year' => '2025-2026', // From the sample
                    'education_level' => 'SHS', // From the sample
                    'year_level' => 'Grade 12', // From the sample
                    ],
                    'schedule_data' => [
                    'time' => $this->convertTimeTo24Hour($timeValue),
                    'day' => $dayName,
                    'room' => 'SHS 112', // From the sample footer
                    'instructor' => $parsedData['instructor'],
                    'subject' => $parsedData['subject'],
                    ]
                ];
                
                // Add subject information to a separate field or use it for validation
                // For now, we'll store it in the instructor field or create a separate entry
                $scheduleEntries[] = $scheduleEntry;
            }
            
            // Return all valid entries (one for each day column with data)
            return !empty($scheduleEntries) ? $scheduleEntries : null;
            
        } catch (Exception $e) {
            Log::warning('Error processing row data: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Convert time to 24-hour format for storage in database
     * Handles both "7:45-8:45" and "7:45 AM-8:45 AM" formats
     */
    private function convertTimeTo24Hour(string $timeRange): string
    {
        try {
            // Extract start and end times
            if (preg_match('/^(\d{1,2}:\d{2})\s*(AM|PM)?-(\d{1,2}:\d{2})\s*(AM|PM)?$/i', $timeRange, $matches)) {
                $startTime = $matches[1];
                $startMeridiem = isset($matches[2]) ? strtoupper($matches[2]) : null;
                $endTime = $matches[3];
                $endMeridiem = isset($matches[4]) ? strtoupper($matches[4]) : null;
                
                // If no meridiem specified, assume based on typical school hours
                // Use the afternoon flag if we're processing afternoon hours
                if ($startMeridiem === null) {
                    $startHour = (int) explode(':', $startTime)[0];
                    $startMeridiem = $this->determineMeridiem($startHour, $this->isAfternoon);
                }
                
                if ($endMeridiem === null) {
                    $endHour = (int) explode(':', $endTime)[0];
                    $endMeridiem = $this->determineMeridiem($endHour, $this->isAfternoon);
                }
                
                // Convert to 24-hour format
                $start24Hour = $this->convertTo24HourFormat($startTime, $startMeridiem);
                $end24Hour = $this->convertTo24HourFormat($endTime, $endMeridiem);
                
                return $start24Hour . '-' . $end24Hour;
            }
            
            // If format doesn't match, return original
            return $timeRange;
            
        } catch (Exception $e) {
            Log::warning('Error converting time to 24-hour format: ' . $e->getMessage());
            return $timeRange;
        }
    }
    
    /**
     * Convert a single time value to 24-hour format
     */
    private function convertTo24HourFormat(string $time, string $meridiem): string
    {
        $parts = explode(':', $time);
        $hour = (int) $parts[0];
        $minute = $parts[1];
        
        if ($meridiem === 'PM' && $hour < 12) {
            $hour += 12;
        } elseif ($meridiem === 'AM' && $hour === 12) {
            $hour = 0;
        }
        
        return sprintf('%02d:%02d:00', $hour, $minute);
    }
    
    /**
     * Determine AM/PM based on hour and context
     * 
     * @param int $hour The hour (1-12)
     * @param bool $isAfternoon Whether we're processing afternoon hours (after noon break)
     */
    private function determineMeridiem(int $hour, bool $isAfternoon = false): string
    {
        // For basic education reference schedules:
        // 7-11:59 should be AM (hours 7, 8, 9, 10, 11)
        // 12-6 should be PM (hours 12, 1, 2, 3, 4, 5, 6)
        
        if ($hour >= 7 && $hour <= 11) {
            // Hours 7-11 are morning (AM)
            return 'AM';
        } elseif ($hour == 12 || ($hour >= 1 && $hour <= 6)) {
            // Hours 12 and 1-6 are afternoon/evening (PM)
            // 12:00 is noon (PM), 1-6 PM are afternoon hours
            return 'PM';
        } elseif ($hour == 0) {
            // 12:00 AM (midnight)
            return 'AM';
        } else {
            // Default to AM for other cases
            return 'AM';
        }
    }
    
    /**
     * Parse alternative patterns for edge cases
     */
    private function parseAlternativePatterns(string $cellContent): ?array
    {
        try {
            // Known problematic patterns from your data
            $knownPatterns = [
                'PR2Alpuerto' => ['PR2', 'Alpuerto'],
                'PE3Amoncio' => ['PE3', 'Amoncio'],
                'UCSPBallozos' => ['UCSP', 'Ballozos'],
                'EAPPAbogatal' => ['EAPP', 'Abogatal'],
                'Creative NonfictionAbogatal' => ['Creative Nonfiction', 'Abogatal'],
                'Phil. Politics …Abao' => ['Phil. Politics', 'Abao'],
                'Trends, Network, …Gumanit' => ['Trends, Network', 'Gumanit'],
                'Per Dev Lacaran' => ['Per Dev', 'Lacaran'],
            ];
            
            // Check if it matches any known pattern
            foreach ($knownPatterns as $pattern => $result) {
                if (strpos($cellContent, $pattern) === 0) {
                    return $result;
                }
            }
            
            // Try to extract instructor name from the end (common pattern)
            $instructorPatterns = [
                '/^(.+?)(Abao|Ballozos|Abogatal|Lacaran|Alpuerto|Amoncio|Gumanit)$/',
                '/^(.+?)([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)$/'
            ];
            
            foreach ($instructorPatterns as $pattern) {
                if (preg_match($pattern, $cellContent, $matches)) {
                    $subject = trim($matches[1]);
                    $instructor = trim($matches[2]);
                    
                    // Clean up subject
                    $subject = rtrim($subject, ' …,.-');
                    
                    if (strlen($subject) > 1 && strlen($instructor) > 1) {
                        return [$subject, $instructor];
                    }
                }
            }
            
            return null;
            
        } catch (Exception $e) {
            Log::warning('Error in parseAlternativePatterns: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse cell content to extract subject and instructor
     */
    private function parseCellContent(string $cellContent): ?array
    {
        try {
            // Clean the cell content first
            $cellContent = trim($cellContent);
            
            if (empty($cellContent)) {
                return null;
            }
            
            // Log the raw cell content for debugging
            Log::info('Parsing cell content: "' . $cellContent . '"');
            
            // Try different splitting methods
            $lines = [];
            
            // Method 1: Split by actual newlines
            if (strpos($cellContent, "\n") !== false) {
                $lines = array_filter(array_map('trim', explode("\n", $cellContent)));
            }
            // Method 2: Split by carriage returns
            elseif (strpos($cellContent, "\r") !== false) {
                $lines = array_filter(array_map('trim', explode("\r", $cellContent)));
            }
            // Method 3: Split by multiple spaces (in case newlines aren't preserved)
            elseif (preg_match('/\s{2,}/', $cellContent)) {
                $lines = array_filter(array_map('trim', preg_split('/\s{2,}/', $cellContent)));
            }
            // Method 4: Try to detect subject and instructor patterns in single line
            else {
                // Look for common patterns in your data:
                // "SubjectInstructor" (no space) - like "UCSPBallozos", "PR2Alpuerto"
                // "Subject Instructor" (with space) - like "Per Dev Lacaran"
                
                // Pattern 1: SubjectInstructor (no space between) - like "PR2Alpuerto"
                if (preg_match('/^([A-Z][A-Za-z0-9\s,\.\-]+)([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)$/', $cellContent, $matches)) {
                    $subject = trim($matches[1]);
                    $instructor = trim($matches[2]);
                    
                    // Clean up subject (remove trailing spaces/punctuation)
                    $subject = rtrim($subject, ' ,.-');
                    
                    // Additional validation for common subject patterns
                    $validSubjects = ['PR2', 'PE3', 'UCSP', 'EAPP', 'Phil. Politics', 'Creative Nonfiction', 'Per Dev', 'Trends, Network'];
                    $isValidSubject = false;
                    
                    foreach ($validSubjects as $validSubject) {
                        if (strpos($subject, $validSubject) === 0 || $subject === $validSubject) {
                            $isValidSubject = true;
                            break;
                        }
                    }
                    
                    // Check if this looks like a valid split
                    if (strlen($subject) > 1 && strlen($instructor) > 1 && $isValidSubject) {
                        $lines = [$subject, $instructor];
                    } else {
                        // Try alternative parsing for edge cases
                        $result = $this->parseAlternativePatterns($cellContent);
                        if ($result) {
                            $lines = $result;
                        } else {
                            // Fallback to treating as single subject
                            $lines = [$cellContent];
                        }
                    }
                } else {
                    // Try alternative parsing methods
                    $result = $this->parseAlternativePatterns($cellContent);
                    if ($result) {
                        $lines = $result;
                    } else {
                        // Last resort: treat the whole thing as subject, no instructor
                        $lines = [$cellContent];
                    }
                }
            }
            
            if (empty($lines)) {
                return null;
            }
            
            // Clean up the lines
            $lines = array_map('trim', $lines);
            $lines = array_filter($lines, function($line) {
                return !empty($line);
            });
            
            // Reset array indices
            $lines = array_values($lines);
            
            if (empty($lines)) {
                return null;
            }
            
            // First line should be the subject
            $subject = $lines[0] ?? '';
            
            // Last line should be the instructor (if there are multiple lines)
            $instructor = count($lines) > 1 ? end($lines) : '';
            
            // Clean up subject name
            $subject = trim($subject);
            $subject = preg_replace('/[^\w\s,\.\-]/', '', $subject); // Keep letters, numbers, spaces, commas, periods, hyphens
            
            // Clean up instructor name
            $instructor = trim($instructor);
            $instructor = preg_replace('/[^\w\s\.]/', '', $instructor); // Keep letters, numbers, spaces, periods
            
            // Log the parsed results
            Log::info('Parsed - Subject: "' . $subject . '", Instructor: "' . $instructor . '"');
            
            // Validate that we have at least a subject
            if (empty($subject)) {
                return null;
            }
            
            // If no instructor found, we might need to extract it from the subject
            if (empty($instructor)) {
                // Try to extract instructor name from the end of the subject
                if (preg_match('/^(.+?)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)$/', $subject, $matches)) {
                    $subject = trim($matches[1]);
                    $instructor = trim($matches[2]);
                }
            }
            
            // Final validation
            if (empty($subject)) {
                return null;
            }
            
            // If still no instructor, use a default
            if (empty($instructor)) {
                $instructor = 'TBD';
            }
            
            return [
                'subject' => $subject,
                'instructor' => $instructor
            ];
            
        } catch (Exception $e) {
            Log::warning('Error parsing cell content: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse schedule data from text content
     */
    private function parseTextScheduleData(string $text): ?array
    {
        // This is a basic implementation - you may need to adjust based on your file format
        $lines = explode("\n", $text);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Try to match schedule pattern
            // Example: "2023-2024 | SHS | Grade 11 | Block A | 8:00-9:00 | Monday | Room 101 | John Doe"
            if (preg_match('/^(.+?)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*(.+?)$/', $line, $matches)) {
                return [
                    'school_year' => trim($matches[1]),
                    'education_level' => trim($matches[2]),
                    'year_level' => trim($matches[3]),
                    'block' => trim($matches[4]),
                    'time' => trim($matches[5]),
                    'day' => trim($matches[6]),
                    'room' => trim($matches[7]),
                    'instructor' => trim($matches[8]),
                ];
            }
        }
        
        return null;
    }

    /**
     * Store reference schedule data in database
     */
    private function storeReferenceData(array $data): int
    {
        $savedCount = 0;
        $skippedCount = 0;
        
        foreach ($data as $entry) {
            try {
                // Validate entry data before storing
                if (!$this->validateEntryData($entry)) {
                    Log::warning('Invalid entry data, skipping: ' . json_encode($entry));
                    $skippedCount++;
                    continue;
                }
                
                // Get or create the reference group
                $groupData = $entry['group_data'];
                $referenceGroup = ReferenceGroup::firstOrCreate([
                    'school_year' => $groupData['school_year'],
                    'education_level' => $groupData['education_level'],
                    'year_level' => $groupData['year_level'],
                ], $groupData);
                
                // Check if schedule entry already exists
                $scheduleData = $entry['schedule_data'];
                $existing = Reference::where([
                    'group_id' => $referenceGroup->group_id,
                    'day' => $scheduleData['day'],
                    'time' => $scheduleData['time'],
                    'room' => $scheduleData['room'],
                    'instructor' => $scheduleData['instructor'],
                ])->first();
                
                if (!$existing) {
                    // Create the reference schedule entry
                    $scheduleData['group_id'] = $referenceGroup->group_id;
                    Reference::create($scheduleData);
                    $savedCount++;
                } else {
                    $skippedCount++;
                }
                
            } catch (Exception $e) {
                Log::warning('Error storing reference entry: ' . $e->getMessage() . ' - Data: ' . json_encode($entry));
                $skippedCount++;
                continue;
            }
        }
        
        Log::info("Reference data storage completed: {$savedCount} saved, {$skippedCount} skipped");
        return $savedCount;
    }
    
    /**
     * Validate entry data before storing
     */
    private function validateEntryData(array $entry): bool
    {
        // Check required structure
        if (!isset($entry['group_data']) || !isset($entry['schedule_data'])) {
            return false;
        }
        
        // Check required group fields
        $requiredGroupFields = ['school_year', 'education_level', 'year_level'];
        foreach ($requiredGroupFields as $field) {
            if (empty($entry['group_data'][$field])) {
                return false;
            }
        }
        
        // Check required schedule fields
        $requiredScheduleFields = ['time', 'day', 'room', 'instructor', 'subject'];
        foreach ($requiredScheduleFields as $field) {
            if (empty($entry['schedule_data'][$field])) {
                return false;
            }
        }
        
        // Validate day
        if (!in_array($entry['schedule_data']['day'], ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'])) {
            return false;
        }
        
        // Validate time format - accepts both 24-hour format (HH:MM:SS-HH:MM:SS) and 12-hour format (for backward compatibility)
        $timeValue = $entry['schedule_data']['time'];
        $is24HourFormat = preg_match('/^\d{2}:\d{2}:\d{2}-\d{2}:\d{2}:\d{2}$/', $timeValue); // 24-hour format
        $is12HourFormat = preg_match('/^\d{1,2}:\d{2}\s*(AM|PM)?-\d{1,2}:\d{2}\s*(AM|PM)?$/i', $timeValue); // 12-hour format
        $is24HourShortFormat = preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $timeValue); // 24-hour short format (HH:MM-HH:MM)
        
        if (!$is24HourFormat && !$is12HourFormat && !$is24HourShortFormat) {
            Log::warning('Invalid time format: ' . $timeValue);
            return false;
        }
        
        return true;
    }

    /**
     * Store a single reference schedule entry
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'school_year' => 'required|string|max:255',
                'education_level' => 'required|string|max:255',
                'year_level' => 'required|string|max:255',
                'time' => 'required|string|max:255',
                'day' => 'required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday',
                'room' => 'required|string|max:255',
                'instructor' => 'required|string|max:255',
                'subject' => 'required|string|max:255',
            ]);

            // Get or create the reference group
            $referenceGroup = ReferenceGroup::firstOrCreate([
                'school_year' => $request->school_year,
                'education_level' => $request->education_level,
                'year_level' => $request->year_level,
            ]);

            // Create the reference schedule entry
            $reference = Reference::create([
                'group_id' => $referenceGroup->group_id,
                'time' => $request->time,
                'day' => $request->day,
                'room' => $request->room,
                'instructor' => $request->instructor,
                'subject' => $request->subject,
            ]);

            return response()->json($reference->load('referenceGroup'), 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating reference schedule: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while creating the reference schedule'
            ], 500);
        }
    }

    /**
     * Display the specified reference schedule
     */
    public function show(Reference $reference): JsonResponse
    {
        return response()->json($reference->load('referenceGroup'));
    }

    /**
     * Update the specified reference schedule
     */
    public function update(Request $request, Reference $reference): JsonResponse
    {
        try {
            $request->validate([
                'school_year' => 'required|string|max:255',
                'education_level' => 'required|string|max:255',
                'year_level' => 'required|string|max:255',
                'time' => 'required|string|max:255',
                'day' => 'required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday',
                'room' => 'required|string|max:255',
                'instructor' => 'required|string|max:255',
                'subject' => 'required|string|max:255',
            ]);

            // Get or create the reference group
            $referenceGroup = ReferenceGroup::firstOrCreate([
                'school_year' => $request->school_year,
                'education_level' => $request->education_level,
                'year_level' => $request->year_level,
            ]);

            // Update the reference schedule entry
            $reference->update([
                'group_id' => $referenceGroup->group_id,
                'time' => $request->time,
                'day' => $request->day,
                'room' => $request->room,
                'instructor' => $request->instructor,
                'subject' => $request->subject,
            ]);

            return response()->json($reference->load('referenceGroup'));
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating reference schedule: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while updating the reference schedule'
            ], 500);
        }
    }

    /**
     * Remove the specified reference schedule
     */
    public function destroy(Reference $reference): JsonResponse
    {
        try {
            $reference->delete();
            return response()->json(['message' => 'Reference schedule deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Error deleting reference schedule: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while deleting the reference schedule'
            ], 500);
        }
    }

    /**
     * Get reference schedules by school year
     */
    public function getBySchoolYear(Request $request): JsonResponse
    {
        $request->validate([
            'school_year' => 'required|string'
        ]);

        $references = Reference::bySchoolYear($request->school_year)->with('referenceGroup')->get();
        return response()->json($references);
    }

    /**
     * Get reference schedules by education level
     */
    public function getByEducationLevel(Request $request): JsonResponse
    {
        $request->validate([
            'education_level' => 'required|string'
        ]);

        $references = Reference::byEducationLevel($request->education_level)->with('referenceGroup')->get();
        return response()->json($references);
    }

    /**
     * Check for conflicts with reference schedules
     */
    public function checkConflicts(Request $request): JsonResponse
    {
        $request->validate([
            'room' => 'required|string',
            'day' => 'required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday',
            'time' => 'required|string',
            'school_year' => 'nullable|string'
        ]);

        $conflicts = Reference::getRoomConflicts(
            $request->room,
            $request->day,
            $request->time,
            $request->school_year
        );

        return response()->json([
            'has_conflicts' => $conflicts->count() > 0,
            'conflicts' => $conflicts
        ]);
    }

    /**
     * Bulk delete reference schedules
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'reference_ids' => 'required|array',
            'reference_ids.*' => 'exists:reference_schedules,reference_id'
        ]);

        try {
            $deletedCount = Reference::whereIn('reference_id', $request->reference_ids)->delete();
            
            return response()->json([
                'message' => "Successfully deleted {$deletedCount} reference schedules",
                'deleted_count' => $deletedCount
            ]);
        } catch (\Exception $e) {
            Log::error('Error bulk deleting reference schedules: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while deleting reference schedules'
            ], 500);
        }
    }

    /**
     * Clear all reference schedules
     */
    public function clearAll(): JsonResponse
    {
        try {
            $deletedCount = Reference::count();
            Reference::truncate();
            ReferenceGroup::truncate();
            
            return response()->json([
                'message' => 'All reference schedules and groups cleared successfully',
                'deleted_count' => $deletedCount
            ]);
        } catch (\Exception $e) {
            Log::error('Error clearing reference schedules: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while clearing reference schedules'
            ], 500);
        }
    }

    /**
     * Add meridiem to existing reference schedules
     */
    public function addMeridiemToExisting(): JsonResponse
    {
        try {
            $references = Reference::where('time', 'NOT LIKE', '%AM%')
                                  ->where('time', 'NOT LIKE', '%PM%')
                                  ->get();
            
            $updatedCount = 0;
            
            foreach ($references as $reference) {
                $newTime = $this->convertTimeTo24Hour($reference->time);
                if ($newTime !== $reference->time) {
                    $reference->update(['time' => $newTime]);
                    $updatedCount++;
                }
            }
            
            return response()->json([
                'message' => "Successfully updated {$updatedCount} reference schedules to 24-hour format",
                'updated_count' => $updatedCount,
                'total_processed' => $references->count()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error adding meridiem to existing schedules: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while updating time formats'
            ], 500);
        }
    }

    /**
     * Fix parsing issues in existing reference schedules
     */
    public function fixParsingIssues(): JsonResponse
    {
        try {
            // Find entries where subject and instructor might be mixed up
            $references = Reference::where(function($query) {
                $query->where('subject', 'LIKE', '%Abao%')
                      ->orWhere('subject', 'LIKE', '%Ballozos%')
                      ->orWhere('subject', 'LIKE', '%Abogatal%')
                      ->orWhere('subject', 'LIKE', '%Lacaran%')
                      ->orWhere('subject', 'LIKE', '%Alpuerto%')
                      ->orWhere('subject', 'LIKE', '%Amoncio%')
                      ->orWhere('subject', 'LIKE', '%Gumanit%')
                      ->orWhere('subject', 'LIKE', '%PR2Alpuerto%')
                      ->orWhere('subject', 'LIKE', '%PE3Amoncio%')
                      ->orWhere('subject', 'LIKE', '%UCSPBallozos%')
                      ->orWhere('subject', 'LIKE', '%EAPPAbogatal%');
            })->get();
            
            $updatedCount = 0;
            
            foreach ($references as $reference) {
                $cellContent = $reference->subject;
                $parsedData = $this->parseAlternativePatterns($cellContent);
                
                if ($parsedData && count($parsedData) === 2) {
                    $newSubject = $parsedData[0];
                    $newInstructor = $parsedData[1];
                    
                    // Update the reference
                    $reference->update([
                        'subject' => $newSubject,
                        'instructor' => $newInstructor
                    ]);
                    
                    $updatedCount++;
                    Log::info("Fixed parsing for reference {$reference->reference_id}: '{$cellContent}' -> Subject: '{$newSubject}', Instructor: '{$newInstructor}'");
                }
            }
            
            return response()->json([
                'message' => "Successfully fixed parsing for {$updatedCount} reference schedules",
                'updated_count' => $updatedCount,
                'total_processed' => $references->count()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fixing parsing issues: ' . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while fixing parsing issues'
            ], 500);
        }
    }
}
