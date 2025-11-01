class NotificationManager {
    constructor() {
        this.notifications = [];
        this.container = document.getElementById('notification-container');
    }

    show(message, type = 'success', duration = 5000, isLoading = false) {
        const notification = this.createNotification(message, type);
        
        // Add loading class if specified
        if (isLoading) {
            notification.classList.add('loading-notification');
        }
        
        this.notifications.push(notification);
        this.container.appendChild(notification);
        
        // Ensure notification fits within viewport
        this.ensureNotificationFits(notification);
        
        // Add enter animation
        setTimeout(() => {
            notification.classList.add('notification-enter');
        }, 10);

        // Auto remove after duration
        setTimeout(() => {
            this.remove(notification);
        }, duration);

        return notification;
    }

    createNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = `notification-item rounded-lg shadow-lg p-4 transform translate-x-full opacity-0`;
        notification.style.maxWidth = '280px';
        notification.style.width = '100%';
        notification.style.boxSizing = 'border-box';
        
        // Set background color based on type
        const bgColors = {
            success: 'bg-green-500',
            error: 'bg-red-500',
            warning: 'bg-yellow-500',
            info: 'bg-blue-500'
        };
        
        const iconColors = {
            success: 'text-white',
            error: 'text-white',
            warning: 'text-white',
            info: 'text-white'
        };

        const icons = {
            success: `<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>`,
            error: `<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>`,
            warning: `<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>`,
            info: `<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>`
        };

        notification.innerHTML = `
            <div class="flex items-start space-x-3">
                <div class="flex-shrink-0 ${iconColors[type]}">
                    ${icons[type]}
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-white">${message}</p>
                </div>
                <div class="flex-shrink-0">
                    <button class="text-white/80 hover:text-white transition-colors" onclick="notificationManager.remove(this.parentElement.parentElement.parentElement)">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        `;

        // Add background color
        notification.classList.add(bgColors[type]);
        
        return notification;
    }

    remove(notification) {
        if (!notification || !this.container.contains(notification)) return;
        
        // Add exit animation
        notification.classList.remove('notification-enter');
        notification.classList.add('notification-exit');
        
        // Remove from DOM after animation
        setTimeout(() => {
            if (this.container.contains(notification)) {
                this.container.removeChild(notification);
            }
            // Remove from notifications array
            const index = this.notifications.indexOf(notification);
            if (index > -1) {
                this.notifications.splice(index, 1);
            }
            // Slide up remaining notifications
            this.slideUpNotifications();
        }, 300);
    }

    slideUpNotifications() {
        // Recalculate positions for remaining notifications
        const remainingNotifications = Array.from(this.container.children);
        remainingNotifications.forEach((notification, index) => {
            if (index === 0) {
                notification.style.transform = 'translateY(0)';
            } else {
                const previousNotification = remainingNotifications[index - 1];
                const previousHeight = previousNotification.offsetHeight;
                const margin = 8; // space-y-2 = 8px
                const newTop = (index * (previousHeight + margin));
                notification.style.transform = `translateY(-${newTop}px)`;
            }
        });
    }

    ensureNotificationFits(notification) {
        // Get viewport width
        const viewportWidth = window.innerWidth;
        const containerRight = 288; // 18rem = 288px
        const maxNotificationWidth = Math.min(280, viewportWidth - containerRight - 576); // 576px for extra padding
        
        notification.style.maxWidth = `${maxNotificationWidth}px`;
        notification.style.width = '100%';
    }

    clear() {
        this.notifications.forEach(notification => {
            this.remove(notification);
        });
    }
}

// Initialize notification manager
const notificationManager = new NotificationManager();

// Handle window resize to ensure notifications fit
document.addEventListener('DOMContentLoaded', function() {
    window.addEventListener('resize', function() {
        notificationManager.notifications.forEach(notification => {
            notificationManager.ensureNotificationFits(notification);
        });
    });
    
    // Force reposition notifications on load to ensure they're visible
    setTimeout(() => {
        notificationManager.notifications.forEach(notification => {
            notificationManager.ensureNotificationFits(notification);
        });
    }, 100);
});

// File upload logic
let lastUploadedFile = null;
const dropArea = document.getElementById('drop-area');
const fileElem = document.getElementById('fileElem');
const browseBtn = document.getElementById('browse-btn');
const errorMessage = document.getElementById('error-message');
const reviewSection = document.getElementById('review-section');
const filePreview = document.getElementById('file-preview');
const reviewBtn = document.getElementById('review-btn');
// Modal element variables
const reviewModal = document.getElementById('review-modal');
const closeModal = document.getElementById('close-modal');
const modalFilePreview = document.getElementById('modal-file-preview');
const modalFileName = document.getElementById('modal-file-name');
const modalFileType = document.getElementById('modal-file-type');
const modalFileSize = document.getElementById('modal-file-size');

// Schedule view modal elements
const scheduleViewModal = document.getElementById('schedule-view-modal');
const closeScheduleModal = document.getElementById('close-schedule-modal');
const scheduleDepartment = document.getElementById('schedule-department');
const scheduleSemester = document.getElementById('schedule-semester');
const scheduleDate = document.getElementById('schedule-date');
const scheduleContent = document.getElementById('schedule-content');

// Generated schedule modal elements
const generatedScheduleModal = document.getElementById('generated-schedule-modal');
const closeGeneratedSchedule = document.getElementById('close-generated-schedule');
const generatedDepartment = document.getElementById('generated-department');
const generatedSemester = document.getElementById('generated-semester');
const generatedDate = document.getElementById('generated-date');
const generatedScheduleContent = document.getElementById('generated-schedule-content');
const generateScheduleBtn = document.getElementById('generate-schedule-btn');
const exportScheduleBtn = document.getElementById('export-schedule-btn');
const exportScheduleViewBtn = document.getElementById('export-schedule-view-btn');
const saveDraftBtn = document.getElementById('save-draft-btn');
const editModeToggle = document.getElementById('edit-mode-toggle');
const editStatus = document.getElementById('edit-status');
// History modal inline slider toggle
const editModeToggleView = document.getElementById('edit-mode-toggle-view');

// Global variables for schedule generation
let currentOrganizedData = null;
let isEditMode = false;
let originalScheduleData = null;
let currentScheduleData = null;
const allowedTypes = [
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
    'application/vnd.ms-excel', // .xls
    'text/csv', // .csv
    'application/csv', // .csv
    'text/comma-separated-values' // .csv
];
const maxFiles = 1;

let currentScheduleGroupId = null;
let currentViewedScheduleId = null;

function showError(msg) {
    errorMessage.textContent = msg;
    errorMessage.classList.remove('hidden');
    setTimeout(() => errorMessage.classList.add('hidden'), 3000);
}

function showFileValidationError(msg) {
    // Add red border animation to drop area
    dropArea.classList.add('border-red-500', 'bg-red-50');
    dropArea.classList.remove('border-[#a3c585]/60', 'bg-white/90');
    
    // Show error message
    errorMessage.textContent = msg;
    errorMessage.classList.remove('hidden');
    
    // Reset visual state after 5 seconds
    setTimeout(() => {
        dropArea.classList.remove('border-red-500', 'bg-red-50');
        dropArea.classList.add('border-[#a3c585]/60', 'bg-white/90');
        errorMessage.classList.add('hidden');
    }, 5000);
}

function resetDropArea() {
    reviewSection.classList.add('hidden');
    dropArea.classList.remove('hidden');
    filePreview.innerHTML = '';
    errorMessage.classList.add('hidden');
    lastUploadedFile = null;
    
    // Reset validation states
    dropArea.classList.remove('border-red-500', 'bg-red-50');
    dropArea.classList.add('border-[#a3c585]/60', 'bg-white/90');
}

function getFileIcon(file) {
    if (file.type.includes('pdf')) {
        return `<svg class='w-12 h-12 text-red-400' fill='none' viewBox='0 0 24 24' stroke='currentColor'><rect width='100%' height='100%' rx='8' fill='#fee2e2'/><text x='50%' y='60%' text-anchor='middle' fill='#b91c1c' font-size='1.2em' font-family='Arial' dy='.3em'>PDF</text></svg>`;
    } else if (file.type.includes('word')) {
        return `<svg class='w-12 h-12 text-blue-400' fill='none' viewBox='0 0 24 24' stroke='currentColor'><rect width='100%' height='100%' rx='8' fill='#dbeafe'/><text x='50%' y='60%' text-anchor='middle' fill='#1d4ed8' font-size='1.2em' font-family='Arial' dy='.3em'>DOCX</text></svg>`;
    } else if (file.type.includes('sheet')) {
        return `<svg class='w-12 h-12 text-green-400' fill='none' viewBox='0 0 24 24' stroke='currentColor'><rect width='100%' height='100%' rx='8' fill='#dcfce7'/><text x='50%' y='60%' text-anchor='middle' fill='#166534' font-size='1.2em' font-family='Arial' dy='.3em'>XLSX</text></svg>`;
    }
    return '';
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function showPreview(file) {
    filePreview.innerHTML = '';
    let preview;
    if (file.type.startsWith('image/')) {
        preview = document.createElement('div');
        preview.className = 'flex flex-col items-center w-full';
        const img = document.createElement('img');
        img.className = 'w-40 h-40 object-cover rounded-2xl shadow mb-4 border-4 border-[#a3c585]';
        const reader = new FileReader();
        reader.onload = (e) => { img.src = e.target.result; };
        reader.readAsDataURL(file);
        preview.appendChild(img);
    } else {
        preview = document.createElement('div');
        preview.className = 'flex items-center w-full bg-[#eaf6e3] rounded-2xl p-4 shadow mb-2';
        preview.innerHTML = getFileIcon(file);
    }
    // Info and remove
    const info = document.createElement('div');
    info.className = 'flex-1 ml-4 min-w-0';
    info.innerHTML = `<div class='font-semibold text-[#75975e] truncate' title='${file.name}'>${file.name}</div><div class='text-sm text-[#75975e]'>${file.type} &middot; ${formatBytes(file.size)}</div>`;
    preview.appendChild(info);
    // Remove button
    const removeBtn = document.createElement('button');
    removeBtn.className = 'ml-4 p-2 rounded-full bg-[#ddead1] hover:bg-[#a3c585] transition text-[#75975e] hover:text-white focus:outline-none focus:ring-2 focus:ring-[#a3c585]';
    removeBtn.innerHTML = `<svg xmlns='http://www.w3.org/2000/svg' class='h-5 w-5' fill='none' viewBox='0 0 24 24' stroke='currentColor'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M6 18L18 6M6 6l12 12'/></svg>`;
    removeBtn.title = 'Remove';
    removeBtn.onclick = () => {
        resetDropArea();
        fileElem.value = '';
    };
    preview.appendChild(removeBtn);
    filePreview.appendChild(preview);
}

function handleFiles(files) {
    if (files.length > maxFiles) {
        showError(`You can upload only 1 file at a time.`);
        return;
    }
    const file = files && files.length > 0 ? files[0] : null;
    if (!file) {
        showError('No file selected');
        return;
    }
    
    // Validate file type - only allow Excel/CSV files
    if (!allowedTypes.includes(file.type)) {
        showFileValidationError('Please upload only XLSX, XLS, or CSV files');
        return;
    }
    
    dropArea.classList.add('hidden');
    reviewSection.classList.remove('hidden');
    showPreview(file);
    lastUploadedFile = file;
}

dropArea.addEventListener('click', (e) => {
    if (e.target !== browseBtn) fileElem.click();
});
browseBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    fileElem.click();
});
dropArea.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropArea.classList.add('border-[#75975e]', 'bg-[#ddead1]');
    // Reset any validation error states when dragging over
    dropArea.classList.remove('border-red-500', 'bg-red-50');
});
dropArea.addEventListener('dragleave', (e) => {
    e.preventDefault();
    dropArea.classList.remove('border-[#75975e]', 'bg-[#ddead1]');
    // Don't reset validation error states on drag leave
});
dropArea.addEventListener('drop', (e) => {
    e.preventDefault();
    dropArea.classList.remove('border-[#75975e]', 'bg-[#ddead1]');
    handleFiles(e.dataTransfer.files);
});
fileElem.addEventListener('change', (e) => {
    handleFiles(e.target.files);
});
reviewBtn.addEventListener('click', () => {
    if (lastUploadedFile) {
        // Show loader on button
        reviewBtn.disabled = true;
        const originalText = reviewBtn.textContent;
        reviewBtn.innerHTML = `<svg class='inline mr-2 w-5 h-5 animate-spin text-white' fill='none' viewBox='0 0 24 24'><circle class='opacity-25' cx='12' cy='12' r='10' stroke='currentColor' stroke-width='4'></circle><path class='opacity-75' fill='currentColor' d='M4 12a8 8 0 018-8v8z'></path></svg>Reading file...`;
        setTimeout(() => showModal(lastUploadedFile, () => {
            reviewBtn.disabled = false;
            reviewBtn.textContent = originalText;
        }), 100); // slight delay for UI update
    }
});
closeModal.addEventListener('click', () => {
    reviewModal.classList.add('hidden');
});

// Close review modal when clicking outside
reviewModal.addEventListener('click', (e) => {
    if (e.target === reviewModal) {
        reviewModal.classList.add('hidden');
    }
});



// Schedule view modal event listeners
closeScheduleModal.addEventListener('click', () => {
    scheduleViewModal.classList.add('hidden');
    tabModal.classList.remove('hidden'); // Show history modal again
});
// Close schedule modal when clicking outside
scheduleViewModal.addEventListener('click', (e) => {
    if (e.target === scheduleViewModal) {
        scheduleViewModal.classList.add('hidden');
        tabModal.classList.remove('hidden'); // Show history modal again
    }
});
// Save the last uploaded file for modal
function showModal(file, onDone) {
    modalFilePreview.innerHTML = '';
    
    // Excel preview using SheetJS
    if (file.name.endsWith('.xlsx') || file.type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
        const reader = new FileReader();
        reader.onload = function(e) {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, {type: 'array'});
            const sheetName = workbook.SheetNames && workbook.SheetNames.length > 0 ? workbook.SheetNames[0] : null;
            if (!sheetName) {
                showError('No sheets found in the Excel file');
                return;
            }
            const sheet = workbook.Sheets[sheetName];
            
            // Use a more robust approach to handle merged cells
            const json = XLSX.utils.sheet_to_json(sheet, {
                header: 1,
                defval: '', // Use empty string for empty cells
                raw: false  // Convert all values to strings
            });
            
            console.log('Raw Excel data:', json);
            
            // Process and organize the data
            const organizedData = processExcelData(json);
            displayOrganizedPreview(organizedData);
            if (onDone) onDone();
        };
        reader.readAsArrayBuffer(file);
    } else if (file.type.startsWith('text/') || file.name.endsWith('.csv')) {
        const reader = new FileReader();
        reader.onload = (e) => {
            let content = e.target.result;
            if (file.name.endsWith('.csv') || file.type.includes('csv')) {
                const rows = content.split(/\r?\n/);
                const organizedData = processCSVData(rows);
                displayOrganizedPreview(organizedData);
            } else {
                // Plain text preview
                content = content.length > 2000 ? content.slice(0, 2000) + '\n... (truncated)' : content;
                modalFilePreview.innerHTML = `<pre class="bg-[#f6fbf2] rounded-xl p-4 text-[#75975e] max-h-[70vh] overflow-auto text-base whitespace-pre-line">${content.replace(/</g, '&lt;')}</pre>`;
            }
            if (onDone) onDone();
        };
        reader.readAsText(file);
    } else if (file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.className = 'w-60 h-60 object-cover rounded-xl shadow mb-2 border-4 border-[#a3c585]';
        const reader = new FileReader();
        reader.onload = (e) => { img.src = e.target.result; };
        reader.readAsDataURL(file);
        modalFilePreview.appendChild(img);
        if (onDone) onDone();
    } else {
        modalFilePreview.innerHTML = `<svg width='72' height='72' viewBox='0 0 72 72' fill='none' xmlns='http://www.w3.org/2000/svg'><rect x='10' y='10' width='40' height='52' rx='6' fill='#a3c585'/><polygon points='50,10 62,22 50,22' fill='#ddead1'/><rect x='18' y='22' width='24' height='3' rx='1.5' fill='white'/><rect x='18' y='30' width='24' height='3' rx='1.5' fill='white'/><rect x='18' y='38' width='18' height='3' rx='1.5' fill='white'/><circle cx='54' cy='54' r='15' fill='#75975e' stroke='#fff' stroke-width='3'/><path d='M54 62V48M54 48l-5 5M54 48l5 5' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'/></svg><div class='text-[#75975e] mt-4'>Preview not available for this file type.</div>`;
        if (onDone) onDone();
    }
    reviewModal.classList.remove('hidden');
}

// Process Excel data to extract organized information
function processExcelData(json) {
    // Extract School Year and Semester from the original raw data first
    let extractedSchoolYear = '';
    let extractedSemester = '';
    
    // Look for School Year and Semester in the first 20 rows of raw data
    for (let i = 0; i < Math.min(20, json.length); i++) {
        const row = json[i];
        if (row && row.length > 0) {
            const rowText = row.map(cell => String(cell || '')).join(' ').toLowerCase();
            
            // Extract School Year (patterns like "2024-2025", "SY 2024-2025", "School Year 2024-2025")
            if (!extractedSchoolYear) {
                const schoolYearMatch = rowText.match(/(?:school year|sy|academic year|ay)\s*:?\s*(\d{4}-\d{4})/i);
                if (schoolYearMatch) {
                    extractedSchoolYear = schoolYearMatch[1];
                    console.log('Extracted School Year from raw data:', extractedSchoolYear);
                } else {
                    // Look for just the year pattern
                    const yearMatch = rowText.match(/(\d{4}-\d{4})/);
                    if (yearMatch) {
                        extractedSchoolYear = yearMatch[1];
                        console.log('Extracted School Year (pattern) from raw data:', extractedSchoolYear);
                    }
                }
            }
            
            // Extract Semester (patterns like "1st Semester", "2nd Semester", "Summer")
            if (!extractedSemester) {
                // More comprehensive semester detection
                const semesterPatterns = [
                    { pattern: /1st\s*semester|first\s*semester|semester\s*1|semester\s*i/i, value: '1st Semester' },
                    { pattern: /2nd\s*semester|second\s*semester|semester\s*2|semester\s*ii/i, value: '2nd Semester' },
                    { pattern: /summer|summer\s*semester|summer\s*term/i, value: 'Summer' }
                ];
                
                for (const { pattern, value } of semesterPatterns) {
                    if (pattern.test(rowText)) {
                        extractedSemester = value;
                        console.log('Extracted Semester from raw data:', extractedSemester, 'from text:', rowText);
                        break;
                    }
                }
                
                // Also check individual cells for semester information
                if (!extractedSemester) {
                    for (let cellIndex = 0; cellIndex < row.length; cellIndex++) {
                        const cellText = String(row[cellIndex] || '').toLowerCase().trim();
                        for (const { pattern, value } of semesterPatterns) {
                            if (pattern.test(cellText)) {
                                extractedSemester = value;
                                console.log('Extracted Semester from raw data cell:', extractedSemester, 'cell text:', cellText);
                                break;
                            }
                        }
                        if (extractedSemester) break;
                    }
                }
            }
        }
    }
    
    // Set defaults if not found
    if (!extractedSchoolYear) {
        const currentYear = new Date().getFullYear();
        extractedSchoolYear = `${currentYear}-${currentYear + 1}`;
        console.log('Using default School Year:', extractedSchoolYear);
    }
    if (!extractedSemester) {
        extractedSemester = '2nd Semester';
        console.log('Using default Semester:', extractedSemester);
    }
    
    // Store the extracted values globally for use in schedule generation
    window.extractedSchoolYear = extractedSchoolYear;
    window.extractedSemester = extractedSemester;
    
    // Debug: Log the extracted values
    console.log('=== EXTRACTED VALUES FROM RAW DATA ===');
    console.log('School Year:', extractedSchoolYear);
    console.log('Semester:', extractedSemester);
    console.log('=== END EXTRACTED VALUES ===');
    
    // Find the header row (usually after title rows)
    let headerRowIdx = 0;
    for (let i = 0; i < json.length; i++) {
        const row = json[i];
        if (row && row.some(cell => cell && String(cell).trim() !== '')) {
            // Check if this row contains typical header keywords
            const rowText = row.map(cell => String(cell || '').toLowerCase()).join(' ');
            if (rowText.includes('name') || rowText.includes('course') || rowText.includes('subject') || rowText.includes('unit') || rowText.includes('dept')) {
                headerRowIdx = i;
                break;
            }
        }
    }

    // Extract data rows and preprocess to handle merged cells
    const dataRows = [];
    let currentInstructor = '';
    
    for (let i = headerRowIdx + 1; i < json.length; i++) {
        const row = json[i];
        if (row && row.some(cell => cell && String(cell).trim() !== '')) {
            // Check if this row has an instructor name (first column)
            const instructorName = String(row[0] || '').trim();
            if (instructorName && instructorName.length > 0) {
                currentInstructor = instructorName;
            }
            
            // Create a new row with the current instructor name if it's missing
            const processedRow = [...row];
            if (!processedRow[0] || String(processedRow[0]).trim() === '') {
                processedRow[0] = currentInstructor;
            }
            
            dataRows.push(processedRow);
        }
    }

    console.log('Preprocessed data rows:', dataRows);
    
    // Debug: Log the first few rows to see the structure
    console.log('=== RAW DATA STRUCTURE ===');
    dataRows.slice(0, 5).forEach((row, index) => {
        console.log(`Row ${index}:`, row.map((cell, colIndex) => `Col${colIndex}: "${cell}"`).join(', '));
    });
    console.log('=== END RAW DATA ===');
    
    return processDataRows(dataRows);
}

// Process CSV data
function processCSVData(rows) {
    // Extract School Year and Semester from the original raw data first
    let extractedSchoolYear = '';
    let extractedSemester = '';
    
    // Look for School Year and Semester in the first 20 rows of raw data
    for (let i = 0; i < Math.min(20, rows.length); i++) {
        const rowText = rows[i].toLowerCase();
        
        // Extract School Year (patterns like "2024-2025", "SY 2024-2025", "School Year 2024-2025")
        if (!extractedSchoolYear) {
            const schoolYearMatch = rowText.match(/(?:school year|sy|academic year|ay)\s*:?\s*(\d{4}-\d{4})/i);
            if (schoolYearMatch) {
                extractedSchoolYear = schoolYearMatch[1];
                console.log('Extracted School Year from CSV raw data:', extractedSchoolYear);
            } else {
                // Look for just the year pattern
                const yearMatch = rowText.match(/(\d{4}-\d{4})/);
                if (yearMatch) {
                    extractedSchoolYear = yearMatch[1];
                    console.log('Extracted School Year (pattern) from CSV raw data:', extractedSchoolYear);
                }
            }
        }
        
        // Extract Semester (patterns like "1st Semester", "2nd Semester", "Summer")
        if (!extractedSemester) {
            // More comprehensive semester detection
            const semesterPatterns = [
                { pattern: /1st\s*semester|first\s*semester|semester\s*1|semester\s*i/i, value: '1st Semester' },
                { pattern: /2nd\s*semester|second\s*semester|semester\s*2|semester\s*ii/i, value: '2nd Semester' },
                { pattern: /summer|summer\s*semester|summer\s*term/i, value: 'Summer' }
            ];
            
            for (const { pattern, value } of semesterPatterns) {
                if (pattern.test(rowText)) {
                    extractedSemester = value;
                    console.log('Extracted Semester from CSV raw data:', extractedSemester, 'from text:', rowText);
                    break;
                }
            }
        }
    }
    
    // Set defaults if not found
    if (!extractedSchoolYear) {
        const currentYear = new Date().getFullYear();
        extractedSchoolYear = `${currentYear}-${currentYear + 1}`;
        console.log('Using default School Year for CSV:', extractedSchoolYear);
    }
    if (!extractedSemester) {
        extractedSemester = '2nd Semester';
        console.log('Using default Semester for CSV:', extractedSemester);
    }
    
    // Store the extracted values globally for use in schedule generation
    window.extractedSchoolYear = extractedSchoolYear;
    window.extractedSemester = extractedSemester;
    
    // Debug: Log the extracted values
    console.log('=== EXTRACTED VALUES FROM CSV RAW DATA ===');
    console.log('School Year:', extractedSchoolYear);
    console.log('Semester:', extractedSemester);
    console.log('=== END EXTRACTED VALUES ===');
    
    // Find header row
    let headerRowIdx = 0;
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].trim() !== '') {
            const rowText = rows[i].toLowerCase();
            if (rowText.includes('name') || rowText.includes('course') || rowText.includes('subject') || rowText.includes('unit') || rowText.includes('dept')) {
                headerRowIdx = i;
                break;
            }
        }
    }

    // Extract data rows
    const dataRows = [];
    for (let i = headerRowIdx + 1; i < rows.length; i++) {
        if (rows[i].trim() !== '') {
            const cols = rows[i].split(',');
            dataRows.push(cols);
        }
    }

    return processDataRows(dataRows);
}

// Normalize employment type to standard format
function normalizeEmploymentType(employmentType) {
    const normalized = employmentType.toUpperCase().trim();
    
    // Handle common variations
    if (normalized === 'FULL-TIME' || normalized === 'FULLTIME' || normalized === 'FULL TIME' || normalized === 'FT') {
        return 'FULL-TIME';
    } else if (normalized === 'PART-TIME' || normalized === 'PARTTIME' || normalized === 'PART TIME' || normalized === 'PT') {
        return 'PART-TIME';
    }
    
    // Default to FULL-TIME if unrecognized
    return 'FULL-TIME';
}

// Process data rows to extract organized information
function processDataRows(dataRows) {
    const instructors = new Set();
    const subjects = new Set();
    const departments = new Set();
    const fullTimeCount = { count: 0, instructors: [] };
    const partTimeCount = { count: 0, instructors: [] };
    const organizedRows = [];
    let currentEmploymentType = 'FULL-TIME'; // Default employment type
    let lastInstructorName = ''; // Track the last instructor name for merged cells
    
    console.log('Processing data rows:', dataRows.length);
    console.log('Sample rows:', dataRows.slice(0, 10));
    
    // Debug: Log all potential employment type indicators
    dataRows.forEach((row, index) => {
        const firstCell = String(row[0] || '').trim();
        if (firstCell && (firstCell.toUpperCase().includes('TIME') || 
                         firstCell.toUpperCase().includes('FULL') || 
                         firstCell.toUpperCase().includes('PART'))) {
            console.log(`Potential employment indicator at row ${index}: "${firstCell}"`);
        }
    });

    dataRows.forEach((row, index) => {
        // Safety check for row existence and structure
        if (!row || !Array.isArray(row)) {
            console.warn(`Skipping invalid row at index ${index}:`, row);
            return;
        }
        
        // First, check if this row is an employment type indicator (regardless of row length)
        const firstCell = String(row[0] || '').trim();
        
        // Enhanced detection for various employment type formats
        const normalizedCell = firstCell.toUpperCase().replace(/[^A-Z]/g, '');
        if (normalizedCell === 'FULLTIME' || normalizedCell === 'PARTTIME' || 
            firstCell.toUpperCase().includes('FULL-TIME') || 
            firstCell.toUpperCase().includes('PART-TIME') ||
            firstCell.toUpperCase().includes('PART TIME')) {
            
            // Determine the employment type based on the content
            let detectedType = 'FULL-TIME'; // default
            if (normalizedCell === 'PARTTIME' || 
                firstCell.toUpperCase().includes('PART-TIME') ||
                firstCell.toUpperCase().includes('PART TIME')) {
                detectedType = 'PART-TIME';
            }
            
            console.log('Found employment type indicator:', firstCell, '->', detectedType);
            currentEmploymentType = detectedType;
            return; // Skip this row, don't add it to organized data
        }

        // Then process regular data rows
        if (row.length >= 5) {
            let name = String((row && row[0]) || '').trim();
            const courseCode = String((row && row[1]) || '').trim();
            let subject = String((row && row[2]) || '').trim();
            let unit = String((row && row[3]) || '').trim();
            
            // Clean up unit field - remove any day information that might be mixed in
            if (unit.includes('M') || unit.includes('T') || unit.includes('W') || unit.includes('Th') || unit.includes('F')) {
                // Extract only the numeric part
                const unitMatch = unit.match(/(\d+)/);
                if (unitMatch) {
                    unit = unitMatch[1];
                    console.log(`Cleaned unit field from "${String(row[3] || '').trim()}" to "${unit}"`);
                }
            }
            const dept = String(row[4] || '').trim();
            
            // Handle "Bridging" courses - keep the (Bridging) text in the subject
            if (subject.includes('Bridging')) {
                // The subject already contains "(Bridging)" text, keep it as is
                console.log('Found bridging course:', subject);
            }

            // Handle merged cells: if name is empty, use the last instructor name
            if (!name && lastInstructorName) {
                name = lastInstructorName;
                console.log('Using last instructor name for merged cell:', name);
            } else if (name) {
                lastInstructorName = name; // Update the last instructor name
            }

            // Extract year level and block from department field
            let yearLevel = '1st Year'; // Default
            let blocks = []; // Default to empty
            
            // Clean department name - extract only the department part (e.g., "BSBA" from "BSBA IV A & B")
            let cleanDept = dept;
            if (dept) {
                console.log('Processing department field:', dept);
                
                // Extract only the department name (before year level and blocks)
                // Common department patterns: BSBA, BSCS, BSIT, etc.
                const deptMatch = dept.match(/^([A-Z]+)/);
                if (deptMatch) {
                    cleanDept = deptMatch[1];
                    console.log(`✓ Cleaned department from "${dept}" to "${cleanDept}"`);
                } else {
                    // If no match, try to extract department from common patterns
                    if (dept.includes('BSBA') || dept.includes('Business')) {
                        cleanDept = 'BSBA';
                    } else if (dept.includes('BSCS') || dept.includes('Computer Science')) {
                        cleanDept = 'BSCS';
                    } else if (dept.includes('BSIT') || dept.includes('Information Technology')) {
                        cleanDept = 'BSIT';
                    } else {
                        cleanDept = 'BSBA'; // Default fallback
                    }
                    console.log(`✓ Extracted department from "${dept}" to "${cleanDept}"`);
                }
                
                // Extract year level from the original department field
                const deptUpper = dept.toUpperCase().trim();
                
                // Check for Roman numerals in order of specificity (longest first)
                if (deptUpper.includes('IV') || deptUpper.includes('4TH')) {
                    yearLevel = '4th Year';
                } else if (deptUpper.includes('III') || deptUpper.includes('3RD')) {
                    yearLevel = '3rd Year';
                } else if (deptUpper.includes('II') || deptUpper.includes('2ND')) {
                    yearLevel = '2nd Year';
                } else if (deptUpper.includes('I') || deptUpper.includes('1ST')) {
                    yearLevel = '1st Year';
                }
                
                // Also check for numeric patterns
                if (deptUpper.includes('4') && !deptUpper.includes('4TH')) {
                    yearLevel = '4th Year';
                } else if (deptUpper.includes('3') && !deptUpper.includes('3RD')) {
                    yearLevel = '3rd Year';
                } else if (deptUpper.includes('2') && !deptUpper.includes('2ND')) {
                    yearLevel = '2nd Year';
                } else if (deptUpper.includes('1') && !deptUpper.includes('1ST')) {
                    yearLevel = '1st Year';
                }
                
                // Extract blocks from the original department field
                blocks = [];

                // Check if block contains separators (& or ,) - handles formats like "A & B & C", "A,B,C", "A&B&C", etc.
                // Look for pattern after year level (Roman numeral or "Year") - formats: "BSOA I A & B & C", "BSOA 1st Year A & B & C"
                
                // Strategy: Find the last occurrence of a multi-block pattern (to avoid matching "I A" before "A & B & C")
                // Look for patterns with 2+ letters separated by & or , - this ensures we get "A & B & C" not "I A"
                let blockStr = null;
                
                // Try patterns in order of specificity (more specific first)
                // Pattern 1: Three or more blocks: "A & B & C", "A, B, C", etc.
                const threePlusBlocks = /\b([A-Z](?:\s*[,&]\s*[A-Z]){2,})\b/gi;
                let matches = [...deptUpper.matchAll(threePlusBlocks)];
                if (matches.length > 0) {
                    // Take the LAST match (most likely to be the actual blocks, not year level like "I")
                    blockStr = matches[matches.length - 1][1];
                }
                
                // Pattern 2: If no 3+ blocks, try two blocks: "A & B", "A, B", etc.
                if (!blockStr) {
                    const twoBlocks = /\b([A-Z]\s*[,&]\s*[A-Z])\b/gi;
                    matches = [...deptUpper.matchAll(twoBlocks)];
                    if (matches.length > 0) {
                        // Take the LAST match (most likely to be the actual blocks after year level)
                        blockStr = matches[matches.length - 1][1];
                    }
                }
                
                if (blockStr) {
                    // Found blocks pattern - split by comma, ampersand, and whitespace
                    const blockTokens = blockStr.split(/[,&\s]+/);
                    const foundBlocks = [];
                    
                    for (const token of blockTokens) {
                        const trimmed = token.trim().toUpperCase();
                        // Validate block is a single letter A-Z
                        if (trimmed.length === 1 && /^[A-Z]$/.test(trimmed)) {
                            foundBlocks.push(trimmed);
                        }
                    }
                    
                    if (foundBlocks.length > 0) {
                        // Remove duplicates and sort
                        blocks = [...new Set(foundBlocks)].sort();
                    }
                }
                
                // Fallback: if no blocks found, try single block extraction
                if (blocks.length === 0) {
                    let blockCandidate = '';
                    const yearBlockMatch = deptUpper.match(/(?:YEAR|YR)\s+([A-Z])\b/);
                    if (yearBlockMatch) {
                        blockCandidate = yearBlockMatch[1].toUpperCase();
                    } else {
                        // Fallback: last standalone A-Z at the end
                        const endBlockMatch = deptUpper.match(/\b([A-Z])\s*$/);
                        if (endBlockMatch) {
                            blockCandidate = endBlockMatch[1].toUpperCase();
                        }
                    }

                    if (blockCandidate && /^[A-Z]$/.test(blockCandidate)) {
                        blocks = [blockCandidate];
                    }
                }
                
                console.log(`✓ Parsed department "${dept}": Clean Dept = ${cleanDept}, Year Level = ${yearLevel}, Blocks = ${blocks.join(', ')}`);
            }

            // Debug: Log all rows to see what's happening
            console.log('Processing row:', { name, courseCode, subject, unit, dept, yearLevel, blocks });
            
            // Special debug for BAC 8
            if (courseCode === 'BAC 8') {
                console.log('🔍 BAC 8 DEBUG:', {
                    rawDept: dept,
                    deptType: typeof dept,
                    deptLength: dept ? dept.length : 0,
                    deptTrimmed: dept ? dept.trim() : null,
                    deptUpper: dept ? dept.toUpperCase() : null,
                    rawRow: row,
                    col0: (row && row[0]) || '', // Name
                    col1: (row && row[1]) || '', // Course Code
                    col2: (row && row[2]) || '', // Subject
                    col3: (row && row[3]) || '', // Unit
                    col4: (row && row[4]) || ''  // Department
                });
            }

            // Skip empty rows or rows without proper data
            if (name && courseCode && subject) {
                instructors.add(name);
                subjects.add(subject);
                if (dept) departments.add(dept);

                // Categorize based on current employment type
                if (currentEmploymentType === 'FULL-TIME') {
                    fullTimeCount.count++;
                    if (!fullTimeCount.instructors.includes(name)) {
                        fullTimeCount.instructors.push(name);
                    }
                } else {
                    partTimeCount.count++;
                    if (!partTimeCount.instructors.includes(name)) {
                        partTimeCount.instructors.push(name);
                    }
                }

                // Debug: Log blocks array before processing
                console.log(`🔍 ${courseCode} blocks array:`, blocks, 'length:', blocks.length);
                
                // Calculate units per section - divide by number of sections for units 4 and above
                let unitsPerSection = parseInt(unit);
                if (blocks.length > 1 && unitsPerSection >= 4) {
                    unitsPerSection = Math.floor(unitsPerSection / blocks.length);
                    console.log(`📊 Dividing units for ${courseCode}: ${unit} units ÷ ${blocks.length} sections = ${unitsPerSection} units per section`);
                } else if (blocks.length > 1 && unitsPerSection < 4) {
                    console.log(`📊 Keeping original units for ${courseCode}: ${unit} units (less than 4, no division needed)`);
                }

                // Create one entry per subject per block. If no blocks, create single blockless entry
                if (blocks.length === 0) {
                    console.log(`⚠️ No blocks for ${courseCode}, creating blockless entry`);
                    organizedRows.push({
                        name: name,
                        courseCode: courseCode,
                        subject: subject,
                        unit: unitsPerSection,
                        dept: cleanDept,
                        yearLevel: yearLevel,
                        block: '',
                        employmentType: currentEmploymentType,
                        sessionType: 'Non-Lab session'
                    });
                } else {
                    console.log(`✅ Creating ${blocks.length} entries for ${courseCode} with blocks:`, blocks);
                    blocks.forEach(b => {
                        organizedRows.push({
                            name: name,
                            courseCode: courseCode,
                            subject: subject,
                            unit: unitsPerSection,
                            dept: cleanDept,
                            yearLevel: yearLevel,
                            block: b,
                            employmentType: currentEmploymentType,
                            sessionType: 'Non-Lab session'
                        });
                        console.log(`Created entry for ${courseCode} - ${yearLevel} ${b} with ${unitsPerSection} units`);
                    });
                }
                
                if (blocks.length > 1) {
                    console.log(`Multi-block subject: ${courseCode} will be scheduled for blocks: ${blocks.join(', ')} in ${yearLevel}`);
                }
                
                console.log('Added instructor:', name, 'as', currentEmploymentType, 'for', yearLevel, 'blocks:', blocks.join(', '));
            }
        }
    });

    console.log('Final results:');
    console.log('- Full-time instructors:', fullTimeCount.instructors);
    console.log('- Part-time instructors:', partTimeCount.instructors);
    console.log('- Total organized rows:', organizedRows.length);
    
    // Create a summary of subjects per instructor
    const instructorSummary = {};
    organizedRows.forEach(row => {
        if (!instructorSummary[row.name]) {
            instructorSummary[row.name] = [];
        }
        instructorSummary[row.name].push({
            courseCode: row.courseCode,
            subject: row.subject,
            unit: row.unit,
            yearLevel: row.yearLevel,
            block: row.block
        });
    });
    
    console.log('Instructor summary:');
    Object.keys(instructorSummary).forEach(instructor => {
        console.log(`${instructor}: ${instructorSummary[instructor].length} subjects`);
        instructorSummary[instructor].forEach(subject => {
            console.log(`  - ${subject.courseCode}: ${subject.subject} (${subject.unit} units) - ${subject.yearLevel} ${subject.block}`);
        });
    });
    
    // Store instructor data for filter modal
    storeInstructorDataForFilter(organizedRows);
    
    return {
        instructors: Array.from(instructors),
        subjects: Array.from(subjects),
        departments: Array.from(departments),
        fullTime: fullTimeCount,
        partTime: partTimeCount,
        rows: organizedRows
    };
}

// Helper function to convert 24-hour format to 12-hour format
function convertTo12HourFormat(time24Hour) {
    if (!time24Hour || time24Hour === '') return '';
    
    try {
        // Handle different time formats
        let timeStr = time24Hour.toString().trim();
        
        // Remove any extra dashes that might be present
        timeStr = timeStr.replace(/-+$/, '').replace(/^-+/, '');
        
        // If it's already in 12-hour format, return as is (but validate it first)
        if (timeStr.includes('AM') || timeStr.includes('PM')) {
            // Validate that it has a proper hour part
            const hourMatch = timeStr.match(/^(\d{1,2}):/);
            if (!hourMatch) {
                console.warn('Invalid 12-hour time format (missing hour):', time24Hour);
                return ''; // Return empty string for invalid format
            }
            return timeStr;
        }
        
        // Parse 24-hour format
        const parts = timeStr.split(':');
        if (parts.length < 2) {
            console.warn('Invalid time format (missing parts):', time24Hour);
            return '';
        }
        
        const hours = parts[0];
        const minutes = parts[1];
        
        if (!hours || !minutes) {
            console.warn('Invalid time format (empty parts):', time24Hour);
            return '';
        }
        
        const hour = parseInt(hours);
        const minute = parseInt(minutes);
        
        if (isNaN(hour) || isNaN(minute)) {
            console.warn('Invalid time values:', time24Hour, 'hour:', hours, 'minute:', minutes);
            return '';
        }
        
        // Convert to 12-hour format
        const period = hour >= 12 ? 'PM' : 'AM';
        const displayHour = hour === 0 ? 12 : (hour > 12 ? hour - 12 : hour);
        const displayMinute = minute.toString().padStart(2, '0');
        
        return `${displayHour}:${displayMinute} ${period}`;
    } catch (error) {
        console.error('Error converting time format:', time24Hour, error);
        return '';
    }
}

// Display organized preview with clean table
function displayOrganizedPreview(data) {
    // Store the data for schedule generation
    currentOrganizedData = data;
    
    // Create instructor summary for display
    const instructorSummary = {};
    data.rows.forEach(row => {
        if (!instructorSummary[row.name]) {
            instructorSummary[row.name] = [];
        }
        instructorSummary[row.name].push(row);
    });
    
    // Group rows by instructor, course code, and year level to consolidate A&B blocks
    const groupedRows = {};
    data.rows.forEach((row, index) => {
        const key = `${row.name}|${row.courseCode}|${row.yearLevel}`;
        if (!groupedRows[key]) {
            groupedRows[key] = {
                ...row,
                blocks: [],
                indices: []
            };
        }
        groupedRows[key].blocks.push(row.block);
        groupedRows[key].indices.push(index);
    });
    
    // Convert grouped data back to array for display
    const displayRows = Object.values(groupedRows);
    
    const tableHtml = `
        <div class="bg-white rounded-xl border border-[#a3c585]/30 overflow-hidden">
            <!-- Summary Section -->
            <div class="bg-[#f6fbf2] px-4 py-3 border-b border-[#a3c585]/30">
                <div class="flex flex-wrap gap-4 text-sm">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-[#75975e]">Total Instructors:</span>
                        <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs font-medium">${Object.keys(instructorSummary).length}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-[#75975e]">Total Subjects:</span>
                        <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs font-medium">${displayRows.length}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 bg-blue-500 rounded-full"></div>
                        <span class="font-medium text-[#75975e]">Full-time:</span>
                        <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs font-medium">${data.fullTime.instructors.length}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 bg-orange-500 rounded-full"></div>
                        <span class="font-medium text-[#75975e]">Part-time:</span>
                        <span class="bg-orange-100 text-orange-800 px-2 py-1 rounded-full text-xs font-medium">${data.partTime.instructors.length}</span>
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-[#f6fbf2]">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold text-[#75975e] border-b border-[#a3c585]/30">Instructor Name</th>
                            <th class="px-4 py-3 text-left font-bold text-[#75975e] border-b border-[#a3c585]/30">Code</th>
                            <th class="px-4 py-3 text-left font-bold text-[#75975e] border-b border-[#a3c585]/30">Subject</th>
                            <th class="px-4 py-3 text-left font-bold text-[#75975e] border-b border-[#a3c585]/30">Units</th>
                            <th class="px-4 py-3 text-left font-bold text-[#75975e] border-b border-[#a3c585]/30">Year Level</th>
                            <th class="px-4 py-3 text-left font-bold text-[#75975e] border-b border-[#a3c585]/30">Department</th>
                            <th class="px-4 py-3 text-left font-bold text-[#75975e] border-b border-[#a3c585]/30">Session Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${displayRows.map((row, index) => {
                            // Apply background color based on employment type
                            const rowBackgroundClass = row.employmentType === 'FULL-TIME' 
                                ? 'bg-blue-50 hover:bg-blue-100' 
                                : 'bg-orange-50 hover:bg-orange-100';
                            
                            // Use the first index for session type selection (they should all be the same)
                            const sessionTypeIndex = row.indices[0];
                            
                            return `
                                <tr class="${rowBackgroundClass} transition-colors">
                                    <td class="px-4 py-3 border-b border-[#a3c585]/20 font-medium text-gray-800">${row.name}</td>
                                    <td class="px-4 py-3 border-b border-[#a3c585]/20 text-gray-700">${row.courseCode}</td>
                                    <td class="px-4 py-3 border-b border-[#a3c585]/20 text-gray-700">${row.subject}</td>
                                    <td class="px-4 py-3 border-b border-[#a3c585]/20 text-gray-700">${row.unit}</td>
                                    <td class="px-4 py-3 border-b border-[#a3c585]/20 text-gray-700">${row.yearLevel}</td>
                                    <td class="px-4 py-3 border-b border-[#a3c585]/20 text-gray-700">${row.dept}</td>
                                    <td class="px-4 py-3 border-b border-[#a3c585]/20">
                                        <select class="session-type-select border border-gray-300 rounded-md px-2 py-1 text-xs" data-index="${sessionTypeIndex}">
                                            <option value="Non-Lab session">Non-Lab session</option>
                                            <option value="Lab session">Lab session</option>
                                        </select>
                                    </td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            </div>
        </div>
    `;

    modalFilePreview.innerHTML = tableHtml;

    // Initialize default session type and bind change handlers
    try {
        if (currentOrganizedData && Array.isArray(currentOrganizedData.rows)) {
            // Initialize session types for all original rows
            currentOrganizedData.rows.forEach((r, i) => {
                if (!r.sessionType) r.sessionType = 'Non-Lab session';
            });
            
            // Bind change handlers to the displayed dropdowns
            displayRows.forEach((displayRow) => {
                const sessionTypeIndex = displayRow.indices[0];
                const selectEl = modalFilePreview.querySelector(`select.session-type-select[data-index="${sessionTypeIndex}"]`);
                if (selectEl) {
                    selectEl.value = currentOrganizedData.rows[sessionTypeIndex].sessionType;
                    selectEl.addEventListener('change', (e) => {
                        // Update all related rows (A and B blocks) with the same session type
                        displayRow.indices.forEach(index => {
                            currentOrganizedData.rows[index].sessionType = e.target.value;
                        });
                    });
                }
            });
        }
    } catch (err) {
        console.warn('Failed to initialize session type dropdowns:', err);
    }
}

// Progressive Loader Control Functions
function showProgressiveLoader() {
    const loader = document.getElementById('schedule-generator-loader');
    const progressBar = document.querySelector('.progress-bar');
    const statusText = document.getElementById('loader-status-text');
    
    // Reset all steps
    document.querySelectorAll('.step-item').forEach((item, index) => {
        item.classList.remove('active', 'completed');
        item.querySelector('.step-check').classList.add('hidden');
    });
    
    // Reset progress
    progressBar.style.width = '0%';
    statusText.textContent = 'Initializing...';
    
    // Show loader
    loader.classList.remove('hidden');
    
    return { loader, progressBar, statusText };
}

function updateLoaderProgress(stepNumber, statusText, progressBar) {
    const steps = [
        { text: 'Analyzing instructor data...', progress: 25 },
        { text: 'Optimizing room assignments...', progress: 50 },
        { text: 'Resolving conflicts...', progress: 75 },
        { text: 'Finalizing schedule...', progress: 90 }
    ];
    
    const step = steps[stepNumber - 1];
    if (!step) return;
    
    // Update status text
    statusText.textContent = step.text;
    
    // Update progress bar
    progressBar.style.width = step.progress + '%';
    
    // Update step indicators
    document.querySelectorAll('.step-item').forEach((item, index) => {
        const stepIndicator = item.querySelector('.step-indicator');
        const stepDot = item.querySelector('.step-dot');
        const stepCheck = item.querySelector('.step-check');
        
        if (index < stepNumber - 1) {
            // Completed steps
            item.classList.add('completed');
            item.classList.remove('active');
            stepCheck.classList.remove('hidden');
        } else if (index === stepNumber - 1) {
            // Current step
            item.classList.add('active');
            item.classList.remove('completed');
            stepCheck.classList.add('hidden');
        } else {
            // Future steps
            item.classList.remove('active', 'completed');
            stepCheck.classList.add('hidden');
        }
    });
}

function hideProgressiveLoader() {
    const loader = document.getElementById('schedule-generator-loader');
    loader.classList.add('hidden');
}

// Schedule Generation Functions
function generateSchedule() {
    if (!currentOrganizedData || !currentOrganizedData.rows.length) {
        notificationManager.show('No data available to generate schedule', 'error');
        return;
    }
    
    // Show progressive loader
    const { loader, progressBar, statusText } = showProgressiveLoader();
    
    // Disable the button to prevent multiple clicks
    generateScheduleBtn.disabled = true;
    
    // Sync any live dropdown selections from the review modal back into data
    try {
        const sessionSelects = document.querySelectorAll('#review-modal select.session-type-select');
        sessionSelects.forEach(sel => {
            const idx = parseInt(sel.getAttribute('data-index'));
            if (!isNaN(idx) && currentOrganizedData.rows[idx]) {
                currentOrganizedData.rows[idx].sessionType = sel.value;
            }
        });
    } catch (e) { /* noop */ }

    // Prepare data for API
    const instructorData = currentOrganizedData.rows.map(row => {
        const apiRow = {
            name: row.name,
            courseCode: row.courseCode,
            subject: row.subject,
            unit: parseInt(row.unit) || 3,
            dept: row.dept || 'General',
            yearLevel: row.yearLevel || '1st Year',
            block: row.block || 'A',
            employmentType: normalizeEmploymentType(row.employmentType || 'FULL-TIME'),
            sessionType: row.sessionType || 'Non-Lab session'
        };
        
        // Debug: Log if block is being overridden
        if (row.block && row.block !== 'A') {
            console.log(`🔍 Preserving block ${row.block} for ${row.courseCode}`);
        } else if (!row.block) {
            console.log(`⚠️ No block found for ${row.courseCode}, defaulting to A`);
        }
        
        return apiRow;
    });
    
    // Debug: Log the data being sent to the API
    console.log('=== DATA BEING SENT TO API ===');
    instructorData.forEach((item, index) => {
        console.log(`Row ${index}: ${item.courseCode} - sessionType: ${item.sessionType}`);
        console.log(`${index + 1}. ${item.courseCode} (${item.subject}) - ${item.yearLevel} ${item.block} - ${item.name}`);
        
        // Special debug for BAC 8
        if (item.courseCode === 'BAC 8') {
            console.log('🔍 BAC 8 API DATA:', item);
            console.log('🔍 BAC 8 DEPARTMENT FIELD:', item.dept);
            console.log('🔍 BAC 8 YEAR LEVEL:', item.yearLevel);
            console.log('🔍 BAC 8 BLOCK:', item.block);
        }
    });
    console.log('=== END DATA LOG ===');
    
    // Get extracted School Year and Semester from the uploaded file
    const extractedSchoolYear = window.extractedSchoolYear || '2024-2025';
    const extractedSemester = window.extractedSemester || '2nd Semester';
    
    console.log('=== SCHEDULE GENERATION VALUES ===');
    console.log('Extracted School Year:', extractedSchoolYear);
    console.log('Extracted Semester:', extractedSemester);
    console.log('Window extractedSchoolYear:', window.extractedSchoolYear);
    console.log('Window extractedSemester:', window.extractedSemester);
    console.log('=== END SCHEDULE GENERATION VALUES ===');
    
    // Store instructor data for filter preferences (async, don't wait for response)
    fetch('/api/instructor-data/store', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({
            instructorData: instructorData
        })
    }).then(() => {
        console.log('Instructor data stored for filter preferences');
    }).catch(error => {
        console.warn('Failed to store instructor data for filters:', error);
    });

    // Get current filter preferences and include them in the request
    const filterPreferences = getCurrentFilterPreferences();
    console.log('Including filter preferences:', filterPreferences);

    const requestData = {
        instructorData: instructorData,
        semester: extractedSemester,
        schoolYear: extractedSchoolYear,
        filterPreferences: filterPreferences // Include filter preferences as soft constraints
    };
    
    // Simulate progressive loading with realistic timing
    let progressStep = 1;
    const progressInterval = setInterval(() => {
        updateLoaderProgress(progressStep, statusText, progressBar);
        progressStep++;
        
        if (progressStep > 4) {
            clearInterval(progressInterval);
        }
    }, 800); // Update every 800ms
    
    // Call the API to generate schedule
    fetch('/api/schedule/generate', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify(requestData)
    })
    .then(response => response.json())
    .then(data => {
        // Clear the progress interval
        clearInterval(progressInterval);
        
        // Complete the loading animation
        setTimeout(() => {
            statusText.textContent = 'Schedule generated successfully!';
            progressBar.style.width = '100%';
            
            // Mark all steps as completed
            document.querySelectorAll('.step-item').forEach((item) => {
                item.classList.add('completed');
                item.classList.remove('active');
                item.querySelector('.step-check').classList.remove('hidden');
            });
        }, 200);
        
        // Hide loader after a short delay
        setTimeout(() => {
            hideProgressiveLoader();
            
            if (data.success) {
                const message = 'Schedule Generated Successfully!';
                notificationManager.show(message, 'success');

                // Store group_id globally for draft saving
                currentScheduleGroupId = data.group_id;
                // Clear uploaded file and reset drop area so the file isn't left visible
                try {
                    lastUploadedFile = null;
                    if (fileElem) fileElem.value = '';
                    if (typeof resetDropArea === 'function') {
                        resetDropArea();
                    }
                } catch (e) { /* noop */ }
                
                // Fetch and display the generated schedule with a small delay to ensure DB commit
                setTimeout(() => {
                    fetchGeneratedSchedule();
                }, 500);
                
                // Close preview modal and show generated schedule
                reviewModal.classList.add('hidden');
                generatedScheduleModal.classList.remove('hidden');
            } else {
                notificationManager.show(data.message || 'Failed to generate schedule', 'error');
            }
        }, 1500);
    })
    .catch(error => {
        // Clear the progress interval
        clearInterval(progressInterval);
        
        // Hide loader immediately on error
        hideProgressiveLoader();
        
        console.error('Error generating schedule:', error);
        notificationManager.show('An error occurred while generating the schedule', 'error');
    })
    .finally(() => {
        // Reset button
        generateScheduleBtn.disabled = false;
    });
}


function fetchGeneratedSchedule() {
    // Use the group_id from the successful generation if available
    if (currentScheduleGroupId) {
        const url = `/api/schedule/get-by-group?group_id=${currentScheduleGroupId}`;
        console.log('Fetching schedule with group_id:', currentScheduleGroupId);
        
        fetch(url)
            .then(res => res.json())
            .then(data => {
                console.log('Schedule fetch response:', data);
                if (data && data.success) {
                    console.log('Schedule data type:', typeof data.data, 'Is array:', Array.isArray(data.data));
                    console.log('Schedule data length:', data.data ? data.data.length : 'undefined');
                    displayGeneratedSchedule(data.data || [], data.department || 'General');
                } else {
                    console.error('Schedule fetch failed:', data);
                    notificationManager.show('Failed to fetch generated schedule', 'error');
                }
            })
            .catch(error => {
                console.error('Error fetching schedule:', error);
                notificationManager.show('An error occurred while fetching the schedule', 'error');
            });
    } else {
        // Fallback to the old method if no group_id is available
    const params = new URLSearchParams();
    if (window.extractedSemester) params.set('semester', window.extractedSemester);
    if (window.extractedSchoolYear) params.set('schoolYear', window.extractedSchoolYear);

    const url = params.toString() ? `/api/schedule/get?${params.toString()}` : '/api/schedule/get';

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                displayGeneratedSchedule(data.data || {}, data.department || 'General');
            } else {
                // Retry without filters once (in case filters mismatched)
                if (params.toString()) {
                    return fetch('/api/schedule/get')
                        .then(r => r.json())
                        .then(fallback => {
                            if (fallback && fallback.success) {
                                displayGeneratedSchedule(fallback.data || {}, fallback.department || 'General');
                            } else {
                                notificationManager.show('Failed to fetch generated schedule', 'error');
                            }
                        });
                }
                notificationManager.show('Failed to fetch generated schedule', 'error');
            }
        })
        .catch(error => {
            console.error('Error fetching schedule:', error);
            notificationManager.show('An error occurred while fetching the schedule', 'error');
        });
    }
}



function displayGeneratedSchedule(scheduleData, department = 'General') {
    // Reset edit mode toggle when a new schedule is generated
    // Always ensure toggle is OFF and edit mode is disabled
    isEditMode = false;
    
    if (editModeToggle) {
        editModeToggle.checked = false;
        
        // Update visual state of main toggle
        const toggleTrack = editModeToggle.nextElementSibling;
        const toggleSlider = toggleTrack?.querySelector('div:last-child');
        const offLabel = toggleTrack?.querySelector('span:first-of-type');
        const onLabel = toggleTrack?.querySelector('span:last-of-type');
        
        if (toggleTrack) {
            toggleTrack.classList.remove('bg-[#a3c585]');
            toggleTrack.classList.add('bg-gray-300');
        }
        if (toggleSlider) {
            toggleSlider.style.transform = 'translateX(0)';
        }
        if (offLabel) {
            offLabel.style.opacity = '1';
        }
        if (onLabel) {
            onLabel.style.opacity = '0';
        }
    }
    
    // Reset view toggle as well
    if (editModeToggleView) {
        editModeToggleView.checked = false;
        const viewToggleTrack = editModeToggleView.nextElementSibling;
        const viewToggleSlider = viewToggleTrack?.querySelector('div');
        const viewOff = viewToggleTrack?.querySelector('[data-role="off"]');
        const viewOn = viewToggleTrack?.querySelector('[data-role="on"]');
        
        if (viewToggleSlider) {
            viewToggleSlider.style.transform = 'translateX(0)';
        }
        if (viewToggleTrack) {
            viewToggleTrack.style.backgroundColor = '';
            viewToggleTrack.style.borderColor = '';
        }
        if (viewOff && viewOn) {
            viewOn.style.opacity = '0';
            viewOff.style.opacity = '1';
        }
    }
    
    // Disable edit mode
    disableEditMode();
    
    // Store original data for reset functionality
    originalScheduleData = JSON.parse(JSON.stringify(scheduleData));
    currentScheduleData = JSON.parse(JSON.stringify(scheduleData));
    // Attach group_id if available
    if (currentScheduleGroupId) {
        currentScheduleData.group_id = currentScheduleGroupId;
    }
    
    // Debug: Log the schedule data structure
    console.log('Schedule data received:', scheduleData);
    console.log('Department received:', department);
    
    // Debug: Log first schedule entry to see structure
    if (scheduleData && scheduleData.length > 0) {
        console.log('First schedule entry:', scheduleData[0]);
        console.log('First entry day:', scheduleData[0].day);
        console.log('First entry start_time:', scheduleData[0].start_time);
        console.log('First entry end_time:', scheduleData[0].end_time);
        console.log('First entry employment_type:', scheduleData[0].employment_type);
        console.log('First entry instructor_name:', scheduleData[0].instructor_name);
    }
    
    // Get extracted School Year and Semester from the uploaded file
    const extractedSchoolYear = window.extractedSchoolYear || '2024-2025';
    const extractedSemester = window.extractedSemester || '2nd Semester';
    
    // Update header information with dynamic department and semester/year
    // Ensure department is properly formatted
    const formattedDepartment = department === 'General' ? 'BSBA' : department.toUpperCase();
    generatedDepartment.textContent = formattedDepartment;
    generatedSemester.textContent = `${extractedSemester}, S.Y ${extractedSchoolYear}`;
    generatedDate.textContent = 'AS OF JANUARY 2025 (REVISION: 3)';
    
    // Generate schedule tables
    generatedScheduleContent.innerHTML = '';
    
    // Process API data - scheduleData is now pre-grouped by year level and block
    if (typeof scheduleData !== 'object' || Array.isArray(scheduleData)) {
        console.error('Expected scheduleData to be a grouped object, got:', typeof scheduleData, scheduleData);
        return;
    }
    
    // scheduleData is already grouped by year level and block (e.g., "1st Year A", "2nd Year B")
    const groupedSchedules = scheduleData;
    
    // Sort section keys by year level first, then by block (A, B, C)
    const sortedSectionKeys = Object.keys(groupedSchedules).sort((a, b) => {
        // Extract year level (1st, 2nd, 3rd, etc.) and block (A, B, C, etc.)
        const yearLevelA = a.match(/(\d+)(?:st|nd|rd|th)\s+Year/i)?.[1] || '0';
        const yearLevelB = b.match(/(\d+)(?:st|nd|rd|th)\s+Year/i)?.[1] || '0';
        
        // Compare year levels first
        if (yearLevelA !== yearLevelB) {
            return parseInt(yearLevelA) - parseInt(yearLevelB);
        }
        
        // If same year level, compare blocks (A, B, C)
        const blockA = a.match(/\s+([A-Z])$/)?.[1] || '';
        const blockB = b.match(/\s+([A-Z])$/)?.[1] || '';
        
        return blockA.localeCompare(blockB);
    });
    
    sortedSectionKeys.forEach((sectionKey, sectionIndex) => {
        const schedules = groupedSchedules[sectionKey];
        if (schedules.length === 0) return;
        
        const sectionDiv = document.createElement('div');
        sectionDiv.className = 'mb-6';
        
        // Section title - now shows "Year Level - Block" format
        const titleDiv = document.createElement('div');
        titleDiv.className = 'bg-green-800 text-white font-bold text-center py-2 px-4 rounded-t-lg text-sm';
        // Format the section key to be more readable
        const formattedSectionKey = sectionKey.replace(' - ', ' ').replace('Year', 'Year');
        titleDiv.textContent = formattedSectionKey; // section code or year-block
        sectionDiv.appendChild(titleDiv);
        
        // Table
        const table = document.createElement('table');
        table.className = 'w-full border-collapse border border-gray-300';
        table.style.tableLayout = 'fixed';
        
        // Table header
        const thead = document.createElement('thead');
        thead.innerHTML = `
            <tr class="bg-gray-100">
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 12%;">Course Code</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 36%;">Course Description</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 7%;">Units</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 19%;">Instructor</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 10%;">Day</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 14%;">Time</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 8%;">Room</th>
            </tr>
        `;
        table.appendChild(thead);
        
        // Table body
        const tbody = document.createElement('tbody');
        schedules.forEach((schedule, index) => {
            const row = document.createElement('tr');
            // Debug: Log schedule data to see what we're receiving
            console.log('Schedule data:', schedule);
            console.log('Employment type:', schedule.employment_type);
            
            // Apply yellow background for part-time instructors, otherwise use alternating colors
            // Check both employment_type field and specific instructor names as fallback
            const isPartTime = schedule.employment_type === 'PART-TIME' || 
                              schedule.employment_type === 'part-time' ||
                              schedule.instructor_name === 'Ronee D. Quicho, MBA' ||
                              schedule.instructor_name === 'Lucia L. Torres' ||
                              schedule.instructor_name === 'Johmar V. Dagondon, LPT, DM' ||
                              schedule.instructor_name === 'Lhengen Josol' ||
                              schedule.instructor_name === 'Alfon Aisa';
            
            // Check if this is a lab session
            const isLabSession = schedule.is_lab === true || schedule.is_lab === '1';
            
            // Apply background colors: yellow for part-time, light green for lab sessions, alternating for others
            if (isPartTime) {
                row.className = 'bg-yellow-100';
                console.log('Applied yellow background for part-time instructor:', schedule.instructor_name, 'employment_type:', schedule.employment_type);
            } else if (isLabSession) {
                row.className = 'bg-green-50';
                console.log('Applied green background for lab session:', schedule.room_name);
            } else {
                row.className = index % 2 === 0 ? 'bg-white' : 'bg-gray-50';
            }
            
            row.innerHTML = `
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-800 font-medium">
                    <span data-section="${sectionIndex}" data-index="${index}" data-field="subject_code">${schedule.subject_code || ''}</span>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-700">
                    <span data-section="${sectionIndex}" data-index="${index}" data-field="subject_description">${schedule.subject_description || ''}</span>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-800 text-center">
                    <span data-section="${sectionIndex}" data-index="${index}" data-field="unit">${schedule.units || ''}</span>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-700">
                    <span data-section="${sectionIndex}" data-index="${index}" data-field="instructor">${schedule.instructor_name || ''}</span>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-700 text-center">
                    <span class="editable-field font-medium text-blue-600" data-section="${sectionIndex}" data-index="${index}" data-field="day" data-field-type="dropdown" data-original="${schedule.day || ''}">${schedule.day || ''}</span>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-700 text-center" style="white-space: nowrap;">
                    <span class="editable-field" data-section="${sectionIndex}" data-index="${index}" data-field="time" data-field-type="progressive" data-original-start="${schedule.start_time || ''}" data-original-end="${schedule.end_time || ''}">
                        ${schedule.time_range ? schedule.time_range : ((schedule.start_time ? convertTo12HourFormat(schedule.start_time) : '') + (schedule.end_time ? ' - ' + convertTo12HourFormat(schedule.end_time) : ''))}
                    </span>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-800 text-center">
                    <span class="editable-field" data-section="${sectionIndex}" data-index="${index}" data-field="room" data-field-type="dropdown" data-original="${schedule.room_name || 'N/A'}">${schedule.room_name || 'N/A'}</span>
                </td>
            `;
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        
        sectionDiv.appendChild(table);
        
        
        generatedScheduleContent.appendChild(sectionDiv);
    });
}

// Event Listeners for Generated Schedule Modal
generateScheduleBtn.addEventListener('click', generateSchedule);

closeGeneratedSchedule.addEventListener('click', () => {
    generatedScheduleModal.classList.add('hidden');
    // Cleanup: ensure uploaded file preview and data are reset after viewing generated schedule
    try {
        lastUploadedFile = null;
        if (fileElem) fileElem.value = '';
        if (typeof resetDropArea === 'function') {
            resetDropArea();
        }
        // Clear any review modal remnants
        if (reviewModal && !reviewModal.classList.contains('hidden')) {
            reviewModal.classList.add('hidden');
        }
        if (modalFilePreview) {
            modalFilePreview.innerHTML = '';
        }
        // Clear any in-memory organized data as it's no longer needed
        currentOrganizedData = null;
    } catch (e) { /* noop */ }
});

generatedScheduleModal.addEventListener('click', (e) => {
    if (e.target === generatedScheduleModal) {
        generatedScheduleModal.classList.add('hidden');
        // Same cleanup when closing by clicking outside
        try {
            lastUploadedFile = null;
            if (fileElem) fileElem.value = '';
            if (typeof resetDropArea === 'function') {
                resetDropArea();
            }
            if (reviewModal && !reviewModal.classList.contains('hidden')) {
                reviewModal.classList.add('hidden');
            }
            if (modalFilePreview) {
                modalFilePreview.innerHTML = '';
            }
            currentOrganizedData = null;
        } catch (e2) { /* noop */ }
    }
});

exportScheduleBtn.addEventListener('click', () => {
    // Check if we have a current schedule group ID
    if (!currentScheduleGroupId) {
        notificationManager.show('No schedule available to export. Please generate a schedule first.', 'error');
        return;
    }
    
    // Open export page in new window
    const exportUrl = `/export?group_id=${currentScheduleGroupId}`;
    window.open(exportUrl, '_blank', 'width=1200,height=800,scrollbars=yes,resizable=yes');
});

// Export button in schedule view modal
exportScheduleViewBtn.addEventListener('click', () => {
    // Check if we have a current viewed schedule ID
    if (!currentViewedScheduleId) {
        notificationManager.show('No schedule available to export.', 'error');
        return;
    }
    
    // Open export page in new window
    const exportUrl = `/export?group_id=${currentViewedScheduleId}`;
    window.open(exportUrl, '_blank', 'width=1200,height=800,scrollbars=yes,resizable=yes');
});

// Keep view toggle in sync with main toggle
if (editModeToggle && editModeToggleView) {
    const moveKnob = (toggleEl) => {
        const track = toggleEl.nextElementSibling;
        const knob = track?.querySelector('div');
        if (knob) knob.style.transform = toggleEl.checked ? 'translateX(24px)' : 'translateX(0)';
        const on = track?.querySelector('[data-role="on"]');
        const off = track?.querySelector('[data-role="off"]');
        if (off && on) {
            on.style.opacity = toggleEl.checked ? '1' : '0';
            off.style.opacity = toggleEl.checked ? '0' : '1';
        }
        // Track color: gray when off, green when on
        if (track) {
            track.style.backgroundColor = toggleEl.checked ? '#22c55e' /* green-500 */ : '';
            track.style.borderColor = toggleEl.checked ? '#16a34a' /* green-600 */ : '';
        }
    };

    // When main toggle changes (e.g., from other UI), mirror it in view toggle
    editModeToggle.addEventListener('change', () => {
        editModeToggleView.checked = editModeToggle.checked;
        moveKnob(editModeToggleView);
    });

    // When view toggle is changed, trigger existing edit mode logic via main toggle
    editModeToggleView.addEventListener('change', () => {
        editModeToggle.checked = editModeToggleView.checked;
        editModeToggle.dispatchEvent(new Event('change'));
        moveKnob(editModeToggleView);
    });

    // Initialize position on load
    moveKnob(editModeToggleView);
}

// Draft Name Modal Elements
const draftNameModal = document.getElementById('draft-name-modal');
const draftNameInput = document.getElementById('draft-name-input');
const confirmDraftNameBtn = document.getElementById('confirm-draft-name-btn');
const cancelDraftNameBtn = document.getElementById('cancel-draft-name-btn');
const closeDraftNameModal = document.getElementById('close-draft-name-modal');

let draftNameCallback = null;

saveDraftBtn.addEventListener('click', () => {
    draftNameInput.value = '';
    draftNameModal.classList.remove('hidden');
    draftNameInput.focus();
});

function closeDraftModal() {
    draftNameModal.classList.add('hidden');
}

confirmDraftNameBtn.addEventListener('click', async () => {
    const draftName = draftNameInput.value;
    if (!draftName || draftName.trim() === '') {
        notificationManager.show('Draft name is required.', 'error');
        return;
    }
    if (!currentScheduleData || !currentScheduleData.group_id) {
        notificationManager.show('No schedule group found to save as draft.', 'error');
        return;
    }
    try {
        const response = await fetch('/api/drafts/save', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                group_id: currentScheduleData.group_id,
                draft_name: draftName.trim()
            })
        });
        const result = await response.json();
        if (result.success) {
            notificationManager.show('Draft saved successfully!', 'success');
            closeDraftModal();
        } else {
            notificationManager.show(result.message || 'Failed to save draft.', 'error');
        }
    } catch (error) {
        notificationManager.show('An error occurred while saving the draft.', 'error');
    }
});

cancelDraftNameBtn.addEventListener('click', closeDraftModal);
closeDraftNameModal.addEventListener('click', closeDraftModal);
draftNameInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        confirmDraftNameBtn.click();
    }
});

editModeToggle.addEventListener('change', (e) => {
    isEditMode = e.target.checked;
    const toggleTrack = e.target.nextElementSibling;
    const toggleSlider = toggleTrack.querySelector('div:last-child');
    const offLabel = toggleTrack.querySelector('span:first-of-type');
    const onLabel = toggleTrack.querySelector('span:last-of-type');
    
    if (isEditMode) {
        toggleTrack.classList.remove('bg-gray-300');
        toggleTrack.classList.add('bg-[#a3c585]');
        toggleSlider.style.transform = 'translateX(2rem)';
        offLabel.style.opacity = '0';
        onLabel.style.opacity = '1';
        enableEditMode();
    } else {
        toggleTrack.classList.remove('bg-[#a3c585]');
        toggleTrack.classList.add('bg-gray-300');
        toggleSlider.style.transform = 'translateX(0)';
        offLabel.style.opacity = '1';
        onLabel.style.opacity = '0';
        disableEditMode();
    }
});

function enableEditMode() {
    const editableFields = document.querySelectorAll('.editable-field');
    editableFields.forEach(field => {
        const fieldType = field.dataset.fieldType;
        const fieldName = field.dataset.field;
        
        field.style.cursor = 'pointer';
        field.style.textDecoration = 'underline';
        field.style.textDecorationColor = '#3b82f6';
        field.style.textDecorationThickness = '2px';
        
        // Remove contentEditable for all fields - we'll handle editing differently
        field.contentEditable = false;
        
        // Add click handlers based on field type
        field.addEventListener('click', handleFieldClick);
        
        // Add specific handlers for different field types
        if (fieldType === 'dropdown') {
            field.addEventListener('click', handleDropdownClick);
        } else if (fieldType === 'progressive') {
            field.addEventListener('click', handleProgressiveClick);
        }
    });
}

function disableEditMode() {
    const editableFields = document.querySelectorAll('.editable-field');
    editableFields.forEach(field => {
        field.style.cursor = 'default';
        field.style.textDecoration = '';
        field.style.textDecorationColor = '';
        field.style.textDecorationThickness = '';
        field.contentEditable = false;
        
        // Remove all event listeners
        field.removeEventListener('click', handleFieldClick);
        field.removeEventListener('click', handleDropdownClick);
        field.removeEventListener('click', handleProgressiveClick);
    });
    
    // Remove any active dropdowns or progressive editors
    removeActiveDropdowns();
    removeActiveProgressiveEditor();
}

function handleFieldClick(e) {
    if (!isEditMode) return;
    e.stopPropagation();
}

function handleDropdownClick(e) {
    if (!isEditMode) return;
    e.stopPropagation();
    
    const field = e.target;
    const fieldName = field.dataset.field;
    
    // Remove any existing dropdowns
    removeActiveDropdowns();
    
    // Create dropdown based on field type
    if (fieldName === 'day') {
        createDayDropdown(field);
    } else if (fieldName === 'room') {
        createRoomDropdown(field);
    }
}

function handleProgressiveClick(e) {
    if (!isEditMode) return;
    e.stopPropagation();
    
    const field = e.target;
    
    // Remove any existing progressive editors
    removeActiveProgressiveEditor();
    
    createProgressiveTimeEditor(field);
}

// Helper functions for day parsing and combining
function parseCombinedDays(dayString) {
    if (!dayString || !dayString.trim()) return [];
    
    // Map between short and long formats
    const dayMap = {
        'M': 'Mon', 'Mon': 'Mon',
        'T': 'Tue', 'Tue': 'Tue',
        'W': 'Wed', 'Wed': 'Wed',
        'Th': 'Thu', 'Thu': 'Thu',
        'F': 'Fri', 'Fri': 'Fri',
        'S': 'Sat', 'Sat': 'Sat'
    };
    
    // Try to find day patterns (long format: Mon, Tue, Wed, Thu, Fri, Sat)
    const matches = [];
    const regex = /(Mon|Tue|Wed|Thu|Fri|Sat)/gi;
    let match;
    while ((match = regex.exec(dayString)) !== null) {
        const day = match[1];
        const normalized = day.charAt(0).toUpperCase() + day.slice(1).toLowerCase();
        if (dayMap[normalized] && !matches.includes(dayMap[normalized])) {
            matches.push(dayMap[normalized]);
        }
    }
    
    // If no matches found, check for short format (M, T, W, Th, F, S)
    if (matches.length === 0) {
        const shortDay = dayString.trim();
        if (dayMap[shortDay]) {
            matches.push(dayMap[shortDay]);
        }
    }
    
    // Sort days in weekly order
    const dayOrder = { 'Mon': 1, 'Tue': 2, 'Wed': 3, 'Thu': 4, 'Fri': 5, 'Sat': 6 };
    matches.sort((a, b) => (dayOrder[a] || 999) - (dayOrder[b] || 999));
    
    return matches;
}

function combineDays(days) {
    if (!days || days.length === 0) return '';
    if (days.length === 1) return days[0];
    return days.join('');
}

function isJointDay(dayString) {
    const parsed = parseCombinedDays(dayString);
    return parsed.length > 1;
}

function shortToLongDay(shortDay) {
    const map = {
        'M': 'Mon',
        'T': 'Tue',
        'W': 'Wed',
        'Th': 'Thu',
        'F': 'Fri',
        'S': 'Sat'
    };
    return map[shortDay] || shortDay;
}

function longToShortDay(longDay) {
    const map = {
        'Mon': 'M',
        'Tue': 'T',
        'Wed': 'W',
        'Thu': 'Th',
        'Fri': 'F',
        'Sat': 'S'
    };
    return map[longDay] || longDay;
}

// Helper function to check conflicts for a day value in real-time
async function checkDayConflict(field, dayValue) {
    try {
        const row = field.closest('tr');
        if (!row) return { ok: true, conflicts: [] };
        
        const tds = row.querySelectorAll('td');
        const subjectCode = tds[0]?.textContent.trim();
        const instructorName = tds[3]?.textContent.trim();
        const dayText = dayValue || row.querySelector('span[data-field="day"]')?.textContent.trim() || '';
        const timeText = row.querySelector('span[data-field="time"]')?.textContent.trim();
        const roomText = row.querySelector('span[data-field="room"]')?.textContent.trim();
        const sectionTitle = row.closest('table')?.previousSibling?.textContent.trim();

        // Parse timeText to HH:MM:SS
        const parseTo24 = (s) => {
            const parts = s.split('-');
            const to24 = (t) => {
                const d = new Date('1970-01-01 ' + t.trim());
                return d.toTimeString().slice(0,8);
            };
            if (parts.length === 2) { return { start: to24(parts[0]), end: to24(parts[1]) }; }
            return { start: '00:00:00', end: '00:00:00' };
        };
        const { start, end } = parseTo24(timeText.replace('–','-'));

        // Get original values for locating the meeting
        const dayField = row.querySelector('span[data-field="day"]');
        const timeField = row.querySelector('span[data-field="time"]');
        const roomField = row.querySelector('span[data-field="room"]');
        
        const origDay = dayField?.dataset.original || dayText;
        const origStart = timeField?.dataset.originalStart || start;
        const origEnd = timeField?.dataset.originalEnd || end;
        const origRoom = roomField?.dataset.original || roomText;
        
        // If the proposed day matches the original day, no conflict (reverting to original)
        // Parse and compare the days to handle combined days like "TueWed"
        const parsedOriginal = parseCombinedDays(origDay);
        const parsedProposed = parseCombinedDays(dayText || dayValue);
        
        // Normalize for comparison (sort both arrays)
        const normalizeDays = (days) => {
            const dayOrder = { 'Mon': 1, 'Tue': 2, 'Wed': 3, 'Thu': 4, 'Fri': 5, 'Sat': 6 };
            return [...days].sort((a, b) => (dayOrder[a] || 999) - (dayOrder[b] || 999));
        };
        
        const normalizedOriginal = normalizeDays(parsedOriginal);
        const normalizedProposed = normalizeDays(parsedProposed);
        
        // Compare if days match (same set of days, order doesn't matter)
        // If days match, it means user is reverting to original - no conflict
        const daysMatch = normalizedOriginal.length === normalizedProposed.length &&
                         normalizedOriginal.every((day, idx) => day === normalizedProposed[idx]);
        
        // If reverting to original day, no conflict (this is the original schedule)
        if (daysMatch) {
            return { ok: true, conflicts: [] };
        }

        const groupId = currentScheduleGroupId || window.currentViewedScheduleId;
        if (!groupId) return { ok: true, conflicts: [] };

        // Locate entry and meeting
        const locateRes = await fetch('/api/schedule/locate-entry', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                group_id: groupId,
                subject_code: subjectCode,
                instructor_name: instructorName,
                section_code: sectionTitle,
                day: origDay,
                start_time: origStart,
                end_time: origEnd
            })
        }).then(r => r.json()).catch(() => ({}));

        const instructorId = locateRes.instructor_id || null;
        const roomId = locateRes.room_id || null;
        const meetingId = locateRes.meeting_id || null;
        const entryId = locateRes.entry_id || null;

        let finalRoomId = roomId;
        let roomName = null;
        if (!finalRoomId && roomText && roomText !== 'TBA' && roomText !== 'N/A') {
            roomName = roomText;
        }

        // Validate conflict
        const validateRes = await fetch('/api/schedule/validate-edit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                group_id: groupId,
                meeting_id: meetingId,
                entry_id: entryId,
                instructor_id: instructorId,
                room_id: finalRoomId,
                room_name: roomName,
                day: dayValue || dayText,
                start_time: start,
                end_time: end
            })
        }).then(r => r.json()).catch(() => ({ ok: true, conflicts: [] }));

        return validateRes;
    } catch (error) {
        console.error('Error checking day conflict:', error);
        return { ok: true, conflicts: [] };
    }
}

function createDayDropdown(field) {
    const currentDayValue = field.textContent.trim() || (field.dataset.original || '');
    const parsedDays = parseCombinedDays(currentDayValue);
    const isJoint = parsedDays.length > 1;
    
    // Dropdown days in short format for display
    const dayOptions = [
        { short: 'M', long: 'Mon' },
        { short: 'T', long: 'Tue' },
        { short: 'W', long: 'Wed' },
        { short: 'Th', long: 'Thu' },
        { short: 'F', long: 'Fri' },
        { short: 'S', long: 'Sat' }
    ];
    
    const dropdown = document.createElement('div');
    dropdown.className = 'absolute z-50 bg-white border border-gray-300 rounded-md shadow-lg';
    dropdown.style.minWidth = isJoint ? '160px' : '80px'; // Wider for two columns
    dropdown.dataset.fieldRef = 'day-dropdown'; // Mark as day dropdown
    dropdown.dataset.originalValue = currentDayValue; // Store original value to detect changes
    
    // Track selected days separately for each column
    // For joint sessions: [firstDay, secondDay] or ['', '']
    // For single day: [day] or ['']
    let selectedDay1 = parsedDays[0] || '';
    let selectedDay2 = parsedDays[1] || '';
    let hasConflict = false; // Track conflict state
    let conflictCheckInProgress = false; // Prevent multiple simultaneous checks
    
    // Helper function to get selected days array
    const getSelectedDays = () => {
        const days = [];
        if (selectedDay1) days.push(selectedDay1);
        if (selectedDay2) days.push(selectedDay2);
        return days;
    };
    
    // Expose selectedDays for external access
    dropdown._selectedDays = getSelectedDays;
    dropdown._hasConflict = false;
    
    // Helper function to create a day column
    const createDayColumn = (columnLabel, selectedDay, columnIndex) => {
        const columnDiv = document.createElement('div');
        columnDiv.className = `day-column ${isJoint ? 'w-1/2' : 'w-full'} ${isJoint && columnIndex === 0 ? 'border-r border-gray-200' : ''}`;
        
        // Column header
        const header = document.createElement('div');
        header.className = 'px-2 py-1 text-xs font-semibold text-gray-700 bg-gray-50 border-b border-gray-200 text-center';
        header.textContent = columnLabel;
        columnDiv.appendChild(header);
        
        // Day options container
        const optionsContainer = document.createElement('div');
        optionsContainer.className = 'day-options-container';
        
        dayOptions.forEach((dayOpt) => {
        const option = document.createElement('div');
            const isSelected = selectedDay === dayOpt.long;
            option.className = `px-2 py-1.5 text-xs cursor-pointer hover:bg-blue-50 border-b border-gray-100 last:border-b-0 day-option text-center ${isSelected ? 'bg-blue-500 text-white font-semibold' : ''}`;
            option.textContent = dayOpt.short;
            option.setAttribute('data-day-short', dayOpt.short);
            option.setAttribute('data-day-long', dayOpt.long);
            option.setAttribute('data-column-index', columnIndex);
            option.title = dayOpt.long; // Tooltip showing full day name
            
            option.addEventListener('click', async (e) => {
                e.stopPropagation();
                
                if (isJoint) {
                    // Joint session: assign to the appropriate column
                    if (columnIndex === 0) {
                        // First column
                        if (selectedDay1 === dayOpt.long) {
                            // Deselect if already selected
                            selectedDay1 = '';
                        } else {
                            selectedDay1 = dayOpt.long;
                            // If same day selected in column 2, clear column 2
                            if (selectedDay2 === dayOpt.long) {
                                selectedDay2 = '';
                            }
                        }
                    } else {
                        // Second column
                        if (selectedDay2 === dayOpt.long) {
                            // Deselect if already selected
                            selectedDay2 = '';
                        } else {
                            selectedDay2 = dayOpt.long;
                            // If same day selected in column 1, clear column 1
                            if (selectedDay1 === dayOpt.long) {
                                selectedDay1 = '';
                            }
                        }
                    }
                } else {
                    // Single day session: only one column
                    if (selectedDay1 === dayOpt.long) {
                        // Deselect if already selected
                        selectedDay1 = '';
                    } else {
                        selectedDay1 = dayOpt.long;
                    }
                }
                
                // Update visual selection
                updateDayColumns();
                
                // Update selected days for conflict checking
                dropdown._selectedDays = getSelectedDays;
                
                // Check conflicts in real-time
                const selectedDays = getSelectedDays();
                if (selectedDays.length > 0) {
                    const combined = combineDays(selectedDays);
                    await checkConflictAndUpdate(combined);
                } else {
                    // No days selected, clear conflict
                    hasConflict = false;
                    updateStatusMessage(false, []);
                    updateSaveButton();
                    
                    // Remove visual indicators
                    field.classList.remove('ring-2', 'ring-red-500', 'bg-red-50');
                    field.closest('tr')?.classList.remove('bg-red-50');
                }
            });
            
            optionsContainer.appendChild(option);
        });
        
        columnDiv.appendChild(optionsContainer);
        return columnDiv;
    };
    
    // Function to update day column highlights
    const updateDayColumns = () => {
        dropdown.querySelectorAll('.day-option').forEach(opt => {
            const dayLong = opt.getAttribute('data-day-long');
            const columnIndex = parseInt(opt.getAttribute('data-column-index') || '0');
            
            opt.classList.remove('bg-blue-500', 'text-white', 'font-semibold', 'bg-red-100', 'border-red-500', 'text-red-700');
            opt.classList.add('hover:bg-blue-50');
            
            if (isJoint) {
                if (columnIndex === 0 && selectedDay1 === dayLong) {
                    opt.classList.add('bg-blue-500', 'text-white', 'font-semibold');
                    opt.classList.remove('hover:bg-blue-50');
                } else if (columnIndex === 1 && selectedDay2 === dayLong) {
                    opt.classList.add('bg-blue-500', 'text-white', 'font-semibold');
                    opt.classList.remove('hover:bg-blue-50');
                }
            } else {
                if (selectedDay1 === dayLong) {
                    opt.classList.add('bg-blue-500', 'text-white', 'font-semibold');
                    opt.classList.remove('hover:bg-blue-50');
                }
            }
        });
    };
    
    // Function to check conflicts and update UI
    const checkConflictAndUpdate = async (dayValue) => {
        if (conflictCheckInProgress) return;
        conflictCheckInProgress = true;
        
        try {
            const result = await checkDayConflict(field, dayValue);
            hasConflict = !result.ok;
            
            // Update visual indicator on field
            if (hasConflict) {
                field.classList.add('ring-2', 'ring-red-500', 'bg-red-50');
                field.closest('tr')?.classList.add('bg-red-50');
            } else {
                field.classList.remove('ring-2', 'ring-red-500', 'bg-red-50');
                field.closest('tr')?.classList.remove('bg-red-50');
            }
            
            // Store conflict state on dropdown
            dropdown._hasConflict = hasConflict;
            
            // Update dropdown selection indicators
            updateDayColumns();
            
            // Update status message (this will also update save button)
            updateStatusMessage(hasConflict, result.conflicts || []);
            
            // Show suggestions immediately when conflict detected
            if (hasConflict) {
                // Small delay to ensure dropdown is rendered, then show suggestions
                setTimeout(() => {
                    showSuggestionPopup(field, 'day', result.conflicts || []);
                }, 200);
            } else {
                // Remove suggestion popup if conflict is resolved
                const existingPopup = document.getElementById('suggestion-popup');
                if (existingPopup) {
                    existingPopup.remove();
                }
            }
        } catch (error) {
            console.error('Error in conflict check:', error);
        } finally {
            conflictCheckInProgress = false;
        }
    };
    
    // Function to update status message in dropdown
    const updateStatusMessage = (conflictState, conflicts, showMessage = true) => {
        // Update stored conflict state
        hasConflict = conflictState;
        dropdown._hasConflict = conflictState;
        
        let statusDiv = dropdown.querySelector('.day-dropdown-status');
        
        if (!showMessage) {
            // Hide status message (e.g., on initial load)
            if (statusDiv) {
                statusDiv.remove();
            }
            return;
        }
        
        if (!statusDiv) {
            statusDiv = document.createElement('div');
            statusDiv.className = 'day-dropdown-status px-3 py-1 text-xs border-t border-gray-200';
            dropdown.appendChild(statusDiv);
        }
        
        if (hasConflict) {
            statusDiv.className = 'day-dropdown-status px-3 py-1 text-xs border-t border-red-300 bg-red-50 text-red-700 font-semibold';
            let conflictText = 'Conflict detected';
            if (Array.isArray(conflicts) && conflicts.length > 0) {
                // Format conflict message nicely
                    const conflictLabels = conflicts.map(c => {
                        if (c === 'start_time') return 'Class must start at 7:00 AM or later';
                        if (c === 'lunch') return 'Lunch break';
                        if (c === 'cutoff') return 'Class cutoff (8:45 PM)';
                        if (c === 'duration') return 'Duration mismatch';
                        if (c === 'instructor') return 'Instructor';
                        if (c === 'room') return 'Room';
                        if (c === 'section') return 'Section';
                        return c;
                    });
                conflictText = `Conflict: ${conflictLabels.join(', ')}`;
            }
            statusDiv.textContent = conflictText;
        } else {
            statusDiv.className = 'day-dropdown-status px-3 py-1 text-xs border-t border-gray-200 bg-green-50 text-green-700';
            statusDiv.textContent = 'No conflicts';
        }
        
        // Update save button state when status changes
        updateSaveButton();
    };
    
    // Expose functions for external access
    dropdown._updateStatusMessage = updateStatusMessage;
    
    // Function to update save button state
    const updateSaveButton = () => {
        let saveButton = dropdown.querySelector('#save-day');
        if (!saveButton) return;
        
        const selectedDays = getSelectedDays();
        const hasSelection = selectedDays.length > 0;
        const canSave = hasSelection && !hasConflict;
        
        if (canSave) {
            saveButton.disabled = false;
            saveButton.classList.remove('opacity-50', 'cursor-not-allowed');
            saveButton.classList.add('hover:bg-blue-600', 'cursor-pointer');
            saveButton.title = 'Save changes';
        } else {
            saveButton.disabled = true;
            saveButton.classList.add('opacity-50', 'cursor-not-allowed');
            saveButton.classList.remove('hover:bg-blue-600', 'cursor-pointer');
            
            if (hasConflict) {
                saveButton.title = 'Conflict detected. Please select valid days.';
            } else if (!hasSelection) {
                saveButton.title = 'Please select at least one day.';
            }
        }
    };
    
    // Create columns container
    const columnsContainer = document.createElement('div');
    columnsContainer.className = `flex ${isJoint ? 'flex-row' : 'flex-col'}`;
    
    if (isJoint) {
        // Joint session: Two columns side by side
        const column1 = createDayColumn('Day 1', selectedDay1, 0);
        const column2 = createDayColumn('Day 2', selectedDay2, 1);
        columnsContainer.appendChild(column1);
        columnsContainer.appendChild(column2);
    } else {
        // Single day: One column
        const column1 = createDayColumn('Day', selectedDay1, 0);
        columnsContainer.appendChild(column1);
    }
    
    dropdown.appendChild(columnsContainer);
    
    // Initial highlight of current selection
    updateDayColumns();
    
    // Add status message container
    const statusDiv = document.createElement('div');
    statusDiv.className = 'day-dropdown-status px-3 py-1 text-xs border-t border-gray-200';
    dropdown.appendChild(statusDiv);
    
    // Add save and cancel buttons
    const buttonContainer = document.createElement('div');
    buttonContainer.className = 'flex space-x-2 px-2 py-2 border-t border-gray-200';
    buttonContainer.innerHTML = `
        <button class="day-dropdown-save-btn flex-1 px-3 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors" id="save-day">Save</button>
        <button class="flex-1 px-3 py-1 text-xs bg-gray-300 text-gray-700 rounded hover:bg-gray-400" id="cancel-day">Cancel</button>
    `;
    dropdown.appendChild(buttonContainer);
    
    // Save button click handler
    const saveBtn = dropdown.querySelector('#save-day');
    saveBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        
        if (saveBtn.disabled || hasConflict) {
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show('Cannot save. Conflict detected. Please select valid days.', 'error');
            }
            return;
        }
        
        const selectedDays = getSelectedDays();
        if (selectedDays.length === 0) {
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show('Please select at least one day.', 'warning');
            }
            updateSaveButton();
            return;
        }
        
        // Final conflict check
        const combined = combineDays(selectedDays);
        const result = await checkDayConflict(field, combined);
        hasConflict = !result.ok;
        
        if (hasConflict) {
            const conflictText = Array.isArray(result.conflicts) && result.conflicts.length > 0
                ? `Conflict detected: ${result.conflicts.join(', ')}. Please select valid days.`
                : 'Conflict detected. Please select valid days.';
            
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show(conflictText, 'error');
            }
            updateSaveButton();
            return;
        }
        
        // No conflict - proceed with save
        field.textContent = combined;
        
        if (typeof notificationManager !== 'undefined') {
            notificationManager.show('Saving...', 'info');
        }
        
        updateScheduleData(field, () => {
            // Success callback
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show('Day updated successfully!', 'success');
            }
            removeActiveDropdowns();
        }, () => {
            // Error callback
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show('Failed to save day. Please try again.', 'error');
            }
        });
    });
    
    // Cancel button handler
    dropdown.querySelector('#cancel-day').addEventListener('click', () => {
        removeActiveDropdowns();
    });
    
    // Initial save button state
    updateSaveButton();
    
    // Position dropdown
    const rect = field.getBoundingClientRect();
    dropdown.style.left = rect.left + 'px';
    dropdown.style.top = (rect.bottom + 2) + 'px';
    
    document.body.appendChild(dropdown);
    
    // Store field reference on dropdown for cleanup
    dropdown._fieldReference = field;
    
    // Close dropdown when clicking outside - use the shared function
    setTimeout(() => {
        document.addEventListener('click', closeDropdownOnOutsideClick);
    }, 0);
}

async function createRoomDropdown(field) {
    // Fetch rooms if not already loaded
    let roomsData = window.allRoomsData;
    
    if (!roomsData || roomsData.length === 0) {
        // Try to fetch rooms from API
        try {
            console.log('fetching rooms for dropdown...');
            const response = await fetch('/api/rooms/all');
            if (response.ok) {
                roomsData = await response.json();
                window.allRoomsData = roomsData; // Cache for future use
                console.log('Rooms fetched for dropdown:', roomsData.length);
            } else {
                console.error('Failed to fetch rooms:', response.status);
                notificationManager.show('Failed to load rooms. Please try again.', 'error');
                return;
            }
        } catch (error) {
            console.error('Error fetching rooms:', error);
            notificationManager.show('Failed to load rooms. Please try again.', 'error');
            return;
        }
    }
    
    if (!roomsData || roomsData.length === 0) {
        notificationManager.show('No rooms available. Please add rooms first.', 'error');
        return;
    }
    
    // Get original room value
    const row = field.closest('tr');
    const origRoomText = field.textContent.trim() || field.dataset.original || '';
    let selectedRoomName = origRoomText;
    let hasConflict = false;
    let conflictCheckInProgress = false;
    
    const dropdown = document.createElement('div');
    dropdown.className = 'absolute z-50 bg-white border border-blue-300 rounded-md shadow-lg';
    dropdown.style.minWidth = '200px';
    dropdown.dataset.fieldRef = 'room-dropdown'; // Mark as room dropdown
    
    // Add search input
    const searchDiv = document.createElement('div');
    searchDiv.className = 'sticky top-0 bg-white border-b border-gray-200 px-2 py-2';
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = 'Search rooms...';
    searchInput.className = 'w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:border-blue-500';
    searchDiv.appendChild(searchInput);
    dropdown.appendChild(searchDiv);
    
    // Room list container
    const roomList = document.createElement('div');
    roomList.className = 'room-list max-h-48 overflow-y-auto';
    
    // Status message for conflicts - will be inserted after room list
    const statusMessage = document.createElement('div');
    statusMessage.className = 'room-status-message mb-2 text-xs px-2 py-1 rounded hidden';
    
    // Function to update room conflict status
    const updateRoomConflictStatus = async () => {
        if (conflictCheckInProgress) return;
        conflictCheckInProgress = true;
        
        const row = field.closest('tr');
        const dayField = row.querySelector('span[data-field="day"]');
        const timeField = row.querySelector('span[data-field="time"]');
        
        const dayText = dayField?.textContent.trim() || '';
        const timeText = timeField?.textContent.trim() || '';
        
        // Get original values for conflict checking
        const origDay = dayField?.dataset.original || dayText;
        const origStart = timeField?.dataset.originalStart || '';
        const origEnd = timeField?.dataset.originalEnd || '';
        
        // If reverting to original room, no conflict
        if (selectedRoomName === origRoomText) {
            hasConflict = false;
            statusMessage.style.display = 'none';
            statusMessage.classList.add('hidden');
            const saveBtn = dropdown.querySelector('#save-room');
            if (saveBtn) {
                saveBtn.disabled = false;
            }
            conflictCheckInProgress = false;
            return;
        }
        
        // Parse time to 24-hour format
        const parseTo24 = (time12Hour) => {
            if (!time12Hour || typeof time12Hour !== 'string') return '';
            const match = time12Hour.trim().match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
            if (!match) {
                const malformedMatch = time12Hour.trim().match(/^:(\d{2})\s*(AM|PM)$/i);
                if (malformedMatch) {
                    const minute = parseInt(malformedMatch[1] || '0');
                    const period = malformedMatch[2].toUpperCase();
                    let hour = 12;
                    if (period === 'PM') hour = 12;
                    if (period === 'AM') hour = 0;
                    return `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}:00`;
                }
                return '';
            }
            let hour = parseInt(match[1]);
            const minute = parseInt(match[2] || '0');
            const period = match[3].toUpperCase();
            if (period === 'PM' && hour < 12) hour += 12;
            if (period === 'AM' && hour === 12) hour = 0;
            return `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}:00`;
        };
        
        // Parse time range
        const timeParts = timeText.split(/[-–]/).map(t => t.trim());
        let start = '', end = '';
        if (timeParts.length === 2) {
            start = parseTo24(timeParts[0]);
            end = parseTo24(timeParts[1]);
        }
        
        // If we don't have valid time, use original time from dataset
        if (!start || !end) {
            start = origStart;
            end = origEnd;
        }
        
        if (!start || !end || !dayText) {
            conflictCheckInProgress = false;
            return;
        }
        
        try {
            const tds = row.querySelectorAll('td');
            const subjectCode = tds[0]?.textContent.trim();
            const instructorName = tds[3]?.textContent.trim();
            const sectionTitle = row.closest('table')?.previousSibling?.textContent.trim();
            
            const groupIdForSave = currentScheduleGroupId || window.currentViewedScheduleId;
            
            const response = await fetch('/api/schedule/validate-edit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    group_id: groupIdForSave,
                    subject_code: subjectCode,
                    instructor_name: instructorName,
                    section_code: sectionTitle,
                    day: dayText,
                    start_time: start,
                    end_time: end,
                    room_name: selectedRoomName,
                    meeting_id: field.dataset.meetingId || null
                })
            });
            
            const result = await response.json();
            hasConflict = !result.ok;
            
            if (hasConflict && result.conflicts) {
                let conflictMsg = 'Conflict detected: ';
                const conflictLabels = result.conflicts.map(c => {
                    if (c === 'start_time') return 'Class must start at 7:00 AM or later';
                    if (c === 'lunch') return 'Lunch break';
                    if (c === 'cutoff') return 'Class cutoff (8:45 PM)';
                    if (c === 'duration') return result.details?.duration?.[0] || 'Duration mismatch';
                    if (c === 'instructor') return 'Instructor';
                    if (c === 'room') return 'Room';
                    if (c === 'section') return 'Section';
                    return c;
                });
                conflictMsg += conflictLabels.join(', ');
                
                statusMessage.textContent = conflictMsg;
                statusMessage.className = 'room-status-message mb-2 text-xs px-2 py-1 rounded bg-red-100 text-red-700 border border-red-300';
                statusMessage.style.display = 'block';
                statusMessage.classList.remove('hidden');
                
                const saveBtn = dropdown.querySelector('#save-room');
                if (saveBtn) {
                    saveBtn.disabled = true;
                }
            } else {
                statusMessage.style.display = 'none';
                statusMessage.classList.add('hidden');
                const saveBtn = dropdown.querySelector('#save-room');
                if (saveBtn) {
                    saveBtn.disabled = false;
                }
            }
        } catch (error) {
            console.error('Error checking room conflict:', error);
            hasConflict = false;
            statusMessage.style.display = 'none';
            statusMessage.classList.add('hidden');
            const saveBtn = dropdown.querySelector('#save-room');
            if (saveBtn) {
                saveBtn.disabled = false;
            }
        } finally {
            conflictCheckInProgress = false;
        }
    };
    
    // Function to render rooms
    const renderRooms = (rooms) => {
        roomList.innerHTML = '';
        if (rooms.length === 0) {
            const noRooms = document.createElement('div');
            noRooms.className = 'px-3 py-2 text-xs text-gray-500 text-center';
            noRooms.textContent = 'No rooms found';
            roomList.appendChild(noRooms);
            return;
        }
        
        rooms.forEach(room => {
        const option = document.createElement('div');
            const isSelected = room.room_name === selectedRoomName;
            option.className = `px-3 py-2 text-xs cursor-pointer hover:bg-blue-50 border-b border-gray-100 last:border-b-0 room-option ${isSelected ? 'bg-blue-100' : ''}`;
        option.textContent = room.room_name;
            option.dataset.roomName = room.room_name;
        option.addEventListener('click', () => {
                // Update selected room visually
                selectedRoomName = room.room_name;
                
                // Update selection highlight
                roomList.querySelectorAll('.room-option').forEach(opt => {
                    opt.classList.remove('bg-blue-100');
                    if (opt.dataset.roomName === selectedRoomName) {
                        opt.classList.add('bg-blue-100');
                    }
                });
                
                // Check for conflicts
                updateRoomConflictStatus();
            });
            roomList.appendChild(option);
        });
    };
    
    // Initial render
    renderRooms(roomsData);
    
    // Add search functionality
    searchInput.addEventListener('input', (e) => {
        const searchTerm = e.target.value.toLowerCase().trim();
        if (searchTerm === '') {
            renderRooms(roomsData);
        } else {
            const filtered = roomsData.filter(room => 
                room.room_name.toLowerCase().includes(searchTerm)
            );
            renderRooms(filtered);
        }
    });
    
    dropdown.appendChild(roomList);
    
    // Add status message after room list (before buttons)
    dropdown.appendChild(statusMessage);
    
    // Add save and cancel buttons
    const buttonContainer = document.createElement('div');
    buttonContainer.className = 'flex space-x-2 px-2 py-2 border-t border-gray-200';
    buttonContainer.innerHTML = `
        <button class="room-save-btn flex-1 px-3 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors" id="save-room">Save</button>
        <button class="flex-1 px-3 py-1 text-xs bg-gray-300 text-gray-700 rounded hover:bg-gray-400" id="cancel-room">Cancel</button>
    `;
    dropdown.appendChild(buttonContainer);
    
    // Position dropdown
    const rect = field.getBoundingClientRect();
    dropdown.style.left = rect.left + 'px';
    dropdown.style.top = (rect.bottom + 2) + 'px';
    
    // Adjust position if dropdown goes off screen
    const dropdownRect = dropdown.getBoundingClientRect();
    if (dropdownRect.right > window.innerWidth) {
        dropdown.style.left = (window.innerWidth - dropdownRect.width - 10) + 'px';
    }
    if (dropdownRect.bottom > window.innerHeight) {
        dropdown.style.top = (rect.top - dropdownRect.height - 2) + 'px';
    }
    
    document.body.appendChild(dropdown);
    
    // Save button handler
    const saveBtn = dropdown.querySelector('#save-room');
    saveBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        
        if (saveBtn.disabled || hasConflict) {
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show('Cannot save. Conflict detected. Please select a different room.', 'error');
            }
            return;
        }
        
        // Update field and save
        field.textContent = selectedRoomName;
        
        if (typeof notificationManager !== 'undefined') {
            notificationManager.show('Saving...', 'info');
        }
        
        updateScheduleData(field, () => {
            // Success callback - update row styling based on new room type
            const row = field.closest('tr');
            if (row) {
                // Find the new room in allRoomsData to check if it's a lab room
                const newRoom = roomsData.find(r => r.room_name === selectedRoomName);
                const isLabRoom = newRoom && (newRoom.is_lab === true || newRoom.is_lab === 1 || newRoom.is_lab === '1');
                
                // Check if row is part-time (yellow background takes priority)
                const tds = row.querySelectorAll('td');
                const instructorName = tds[3]?.textContent.trim();
                const isPartTime = instructorName === 'Ronee D. Quicho, MBA' ||
                                  instructorName === 'Lucia L. Torres' ||
                                  instructorName === 'Johmar V. Dagondon, LPT, DM' ||
                                  instructorName === 'Lhengen Josol' ||
                                  instructorName === 'Alfon Aisa';
                
                // Update row background color based on room type and employment type
                // Priority: part-time (yellow) > lab room (green) > alternating colors
                if (isPartTime) {
                    row.className = 'bg-yellow-100';
                } else if (isLabRoom) {
                    row.className = 'bg-green-50';
                } else {
                    // Get row index for alternating colors
                    const tbody = row.parentElement;
                    const rowIndex = Array.from(tbody.children).indexOf(row);
                    row.className = rowIndex % 2 === 0 ? 'bg-white' : 'bg-gray-50';
                }
            }
            
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show('Room updated successfully!', 'success');
            }
            removeActiveDropdowns();
        }, () => {
            // Error callback
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show('Failed to save room. Please try again.', 'error');
            }
        });
    });
    
    // Cancel button handler
    dropdown.querySelector('#cancel-room').addEventListener('click', () => {
        removeActiveDropdowns();
    });
    
    // Initial conflict check if room is already selected
    if (selectedRoomName && selectedRoomName !== origRoomText) {
        updateRoomConflictStatus();
    }
    
    // Focus search input
    setTimeout(() => {
        searchInput.focus();
    }, 0);
    
    // Close dropdown when clicking outside - use the shared function
    setTimeout(() => {
        document.addEventListener('click', closeDropdownOnOutsideClick);
    }, 0);
}

// Helper function to check time conflicts
async function checkTimeConflict(field, startTime12Hour, endTime12Hour) {
    try {
        const row = field.closest('tr');
        if (!row) return { ok: true, conflicts: [] };
        
        const tds = row.querySelectorAll('td');
        const subjectCode = tds[0]?.textContent.trim();
        const instructorName = tds[3]?.textContent.trim();
        const dayText = row.querySelector('span[data-field="day"]')?.textContent.trim() || '';
        const roomText = row.querySelector('span[data-field="room"]')?.textContent.trim();
        const sectionTitle = row.closest('table')?.previousSibling?.textContent.trim();

        // Convert 12-hour to 24-hour format
        const parseTo24 = (time12Hour) => {
            if (!time12Hour || typeof time12Hour !== 'string') {
                console.warn('parseTo24: Invalid input:', time12Hour);
                return '00:00:00';
            }
            
            // Match time with flexible minute format (1 or 2 digits)
            // Examples: "7:0 AM", "7:00 AM", "12:59 PM"
            // Also handle edge cases like ":00 PM" (missing hour) or "00:00 PM"
            const match = time12Hour.trim().match(/^(\d{1,2}):(\d{1,2})\s*(AM|PM)$/i);
            if (!match) {
                // Try to handle malformed times like ":00 PM"
                const malformedMatch = time12Hour.trim().match(/^:(\d{1,2})\s*(AM|PM)$/i);
                if (malformedMatch) {
                    console.warn('parseTo24: Missing hour part, defaulting to 12:', time12Hour);
                    const minute = parseInt(malformedMatch[1] || '0');
                    const period = malformedMatch[2].toUpperCase();
                    let hour = 12; // Default to 12 (noon/midnight) if hour is missing
                    if (period === 'PM') hour = 12;
                    if (period === 'AM') hour = 0;
                    return `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}:00`;
                }
                console.warn('parseTo24: Failed to parse time:', time12Hour);
                return '00:00:00';
            }
            
            let hour = parseInt(match[1]);
            const minute = parseInt(match[2] || '0'); // Default to 0 if minute is missing
            const period = match[3].toUpperCase();
            
            // Validate hour
            if (isNaN(hour) || hour < 1 || hour > 12) {
                console.warn('parseTo24: Invalid hour:', hour, 'in:', time12Hour);
                hour = 12; // Default to 12
            }
            
            // Validate minute
            if (isNaN(minute) || minute < 0 || minute > 59) {
                console.warn('parseTo24: Invalid minute:', minute, 'in:', time12Hour);
                minute = 0; // Default to 0
            }
            
            if (period === 'PM' && hour < 12) hour += 12;
            if (period === 'AM' && hour === 12) hour = 0;
            
            return `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}:00`;
        };
        
        const start = parseTo24(startTime12Hour);
        const end = parseTo24(endTime12Hour);

        // Get original values
        const timeField = row.querySelector('span[data-field="time"]');
        const dayField = row.querySelector('span[data-field="day"]');
        const roomField = row.querySelector('span[data-field="room"]');
        
        const origDay = dayField?.dataset.original || dayText;
        const origStart = timeField?.dataset.originalStart || '';
        const origEnd = timeField?.dataset.originalEnd || '';
        const origRoom = roomField?.dataset.original || roomText;

        // If reverting to original time, no conflict
        if (origStart && origEnd && start === origStart && end === origEnd) {
            return { ok: true, conflicts: [] };
        }

        const groupId = currentScheduleGroupId || window.currentViewedScheduleId;
        if (!groupId) return { ok: true, conflicts: [] };

        // Locate entry and meeting
        const locateRes = await fetch('/api/schedule/locate-entry', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                group_id: groupId,
                subject_code: subjectCode,
                instructor_name: instructorName,
                section_code: sectionTitle,
                day: origDay,
                start_time: origStart || start,
                end_time: origEnd || end
            })
        }).then(r => r.json()).catch(() => ({}));

        const instructorId = locateRes.instructor_id || null;
        const roomId = locateRes.room_id || null;
        const meetingId = locateRes.meeting_id || null;
        const entryId = locateRes.entry_id || null;

        let finalRoomId = roomId;
        let roomName = null;
        if (!finalRoomId && roomText && roomText !== 'TBA' && roomText !== 'N/A') {
            roomName = roomText;
        }

        // Validate conflict
        const validateRes = await fetch('/api/schedule/validate-edit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                group_id: groupId,
                meeting_id: meetingId,
                entry_id: entryId,
                instructor_id: instructorId,
                room_id: finalRoomId,
                room_name: roomName,
                day: dayText,
                start_time: start,
                end_time: end
            })
        }).then(r => r.json()).catch(() => ({ ok: true, conflicts: [] }));

        return validateRes;
    } catch (error) {
        console.error('Error checking time conflict:', error);
        return { ok: true, conflicts: [] };
    }
}

function createProgressiveTimeEditor(field) {
    const currentTime = field.textContent.trim();
    
    // Get original time values from dataset (24-hour format)
    const origStart = field.dataset.originalStart || '';
    const origEnd = field.dataset.originalEnd || '';
    
    // Create progressive editor container
    const editor = document.createElement('div');
    editor.className = 'absolute z-50 bg-white border border-blue-300 rounded-md shadow-lg p-2';
    editor.style.minWidth = '200px';
    editor.dataset.fieldRef = 'time-editor';
    
    // Helper function to convert 24-hour format to 12-hour format components
    const parse24to12 = (time24) => {
        if (!time24) return null;
        // Handle formats like "07:00:00" or "07:00"
        const parts = time24.split(':');
        let h = parseInt(parts[0]) || 7;
        const m = parseInt(parts[1]) || 0;
        const period = h >= 12 ? 'PM' : 'AM';
        if (h > 12) h -= 12;
        if (h === 0) h = 12;
        return { hour: h, minute: m, period: period };
    };
    
    // Parse current time - handle multiple formats:
    // "7:00 AM - 10:00 AM" (hyphen with spaces)
    // "7:00 AM–10:00 AM" (en dash without spaces)
    // "7:00 AM-10:00 AM" (hyphen without spaces)
    // "7:00AM-10:00AM" (no spaces at all)
    let timeMatch = null;
    
    // Try various patterns (with flexible spacing)
    timeMatch = currentTime.match(/(\d{1,2}):(\d{2})\s*(AM|PM)\s*[-–]\s*(\d{1,2}):(\d{2})\s*(AM|PM)/i);
    
    // If that fails, try without spaces
    if (!timeMatch) {
        timeMatch = currentTime.match(/(\d{1,2}):(\d{2})(AM|PM)[-–](\d{1,2}):(\d{2})(AM|PM)/i);
    }
    
    // Default values
    let startHour = 7, startMinute = 0, startPeriod = 'AM';
    let endHour = 10, endMinute = 0, endPeriod = 'AM';
    
    // Try to parse from textContent first
    if (timeMatch) {
        startHour = parseInt(timeMatch[1]);
        startMinute = parseInt(timeMatch[2]);
        startPeriod = timeMatch[3].toUpperCase();
        endHour = parseInt(timeMatch[4]);
        endMinute = parseInt(timeMatch[5]);
        endPeriod = timeMatch[6].toUpperCase();
        
        // Validate the parsed values
        if (isNaN(startHour) || startHour < 1 || startHour > 12) startHour = 7;
        if (isNaN(startMinute) || startMinute < 0 || startMinute > 59) startMinute = 0;
        if (isNaN(endHour) || endHour < 1 || endHour > 12) endHour = 10;
        if (isNaN(endMinute) || endMinute < 0 || endMinute > 59) endMinute = 0;
    }
    // If parsing from textContent failed, use dataset attributes (24-hour format)
    else if (origStart && origEnd) {
        const start = parse24to12(origStart);
        const end = parse24to12(origEnd);
        
        if (start && end) {
            startHour = start.hour;
            startMinute = start.minute;
            startPeriod = start.period;
            endHour = end.hour;
            endMinute = end.minute;
            endPeriod = end.period;
        }
    }
    // If both failed, try to parse any time-like pattern from textContent
    else if (currentTime) {
        // Try to extract any time pattern
        const timePattern = currentTime.match(/(\d{1,2}):?(\d{2})?\s*(AM|PM)?/gi);
        if (timePattern && timePattern.length >= 2) {
            // At least two time patterns found, try to use them
            const first = timePattern[0].match(/(\d{1,2}):?(\d{2})?\s*(AM|PM)?/i);
            const second = timePattern[1].match(/(\d{1,2}):?(\d{2})?\s*(AM|PM)?/i);
            
            if (first && second) {
                startHour = parseInt(first[1]) || 7;
                startMinute = parseInt(first[2]) || 0;
                startPeriod = (first[3] || 'AM').toUpperCase();
                endHour = parseInt(second[1]) || 10;
                endMinute = parseInt(second[2]) || 0;
                endPeriod = (second[3] || 'AM').toUpperCase();
            }
        }
    }
    
    // Store original values for conflict checking
    let originalStartTime = `${startHour}:${startMinute.toString().padStart(2, '0')} ${startPeriod}`;
    let originalEndTime = `${endHour}:${endMinute.toString().padStart(2, '0')} ${endPeriod}`;
    let hasConflict = false;
    let conflictCheckInProgress = false;
    
    // Track conflict state
    editor._hasConflict = false;
    editor._fieldReference = field;
    
    editor.innerHTML = `
        <div class="text-xs text-gray-600 mb-2 font-semibold">Edit Time Range</div>
        <div class="flex items-center space-x-2 mb-2">
            <span class="text-xs">Start:</span>
            <input type="number" min="1" max="12" value="${startHour}" class="time-input w-12 px-1 py-1 text-xs border border-gray-300 rounded" id="start-hour">
            <span class="text-xs">:</span>
            <input type="number" min="0" max="59" value="${startMinute}" class="time-input w-12 px-1 py-1 text-xs border border-gray-300 rounded" id="start-minute">
            <select class="time-input px-1 py-1 text-xs border border-gray-300 rounded" id="start-period">
                <option value="AM" ${startPeriod === 'AM' ? 'selected' : ''}>AM</option>
                <option value="PM" ${startPeriod === 'PM' ? 'selected' : ''}>PM</option>
            </select>
        </div>
        <div class="flex items-center space-x-2 mb-2">
            <span class="text-xs">End:</span>
            <input type="number" min="1" max="12" value="${endHour}" class="time-input w-12 px-1 py-1 text-xs border border-gray-300 rounded" id="end-hour">
            <span class="text-xs">:</span>
            <input type="number" min="0" max="59" value="${endMinute}" class="time-input w-12 px-1 py-1 text-xs border border-gray-300 rounded" id="end-minute">
            <select class="time-input px-1 py-1 text-xs border border-gray-300 rounded" id="end-period">
                <option value="AM" ${endPeriod === 'AM' ? 'selected' : ''}>AM</option>
                <option value="PM" ${endPeriod === 'PM' ? 'selected' : ''}>PM</option>
            </select>
        </div>
        <div class="time-status-message mb-2 text-xs px-2 py-1 rounded hidden"></div>
        <div class="flex space-x-2">
            <button class="time-save-btn px-3 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors" id="save-time">Save</button>
            <button class="px-3 py-1 text-xs bg-gray-300 text-gray-700 rounded hover:bg-gray-400" id="cancel-time">Cancel</button>
        </div>
    `;
    
    // Position editor
    const rect = field.getBoundingClientRect();
    editor.style.left = rect.left + 'px';
    editor.style.top = (rect.bottom + 2) + 'px';
    
    document.body.appendChild(editor);
    
    // Function to update time conflict status
    const updateTimeConflictStatus = async () => {
        if (conflictCheckInProgress) return;
        conflictCheckInProgress = true;
        
        const startHourEl = editor.querySelector('#start-hour');
        const startMinuteEl = editor.querySelector('#start-minute');
        const startPeriodEl = editor.querySelector('#start-period');
        const endHourEl = editor.querySelector('#end-hour');
        const endMinuteEl = editor.querySelector('#end-minute');
        const endPeriodEl = editor.querySelector('#end-period');
        
        // Ensure minutes are always 2 digits before creating time string
        const startMinPadded = String(startMinuteEl.value || '0').padStart(2, '0');
        const endMinPadded = String(endMinuteEl.value || '0').padStart(2, '0');
        const startTime = `${startHourEl.value}:${startMinPadded} ${startPeriodEl.value}`;
        const endTime = `${endHourEl.value}:${endMinPadded} ${endPeriodEl.value}`;
        
        try {
            const result = await checkTimeConflict(field, startTime, endTime);
            
            // Always update the conflict flag, even if it's false (to reset from previous conflicts)
            hasConflict = !result.ok;
            editor._hasConflict = hasConflict;
            // Clear the flag to allow future checks
            editor._conflictCheckInProgress = false;
            
            // Update status message
            const statusDiv = editor.querySelector('.time-status-message');
            const saveBtn = editor.querySelector('#save-time');
            
            if (hasConflict) {
                statusDiv.classList.remove('hidden', 'bg-green-50', 'text-green-700');
                statusDiv.classList.add('bg-red-50', 'text-red-700', 'font-semibold');
                let conflictText = 'Conflict detected';
                
                // Debug: Log the full result to see what we're getting
                console.log('Conflict check result:', {
                    ok: result.ok,
                    conflicts: result.conflicts,
                    conflictsType: typeof result.conflicts,
                    conflictsLength: result.conflicts ? result.conflicts.length : 'N/A',
                    details: result.details,
                    detailsKeys: result.details ? Object.keys(result.details) : 'N/A'
                });
                
                // Get conflicts from either conflicts array or details object keys
                let conflictTypes = [];
                
                // First, try to get conflicts from the conflicts array
                if (result && result.conflicts && Array.isArray(result.conflicts) && result.conflicts.length > 0) {
                    conflictTypes = result.conflicts;
                } 
                // Fallback: get conflicts from details object keys
                else if (result && result.details && typeof result.details === 'object') {
                    conflictTypes = Object.keys(result.details);
                }
                
                // Format conflict message nicely
                if (conflictTypes.length > 0) {
                    const conflictLabels = conflictTypes.map(c => {
                        if (c === 'start_time') return 'Class must start at 7:00 AM or later';
                        if (c === 'lunch') return 'Lunch break';
                        if (c === 'cutoff') return 'Class cutoff (8:45 PM)';
                        if (c === 'duration') {
                            // Extract duration message from details if available
                            if (result && result.details && result.details.duration && result.details.duration[0]) {
                                return result.details.duration[0].message || 'Duration mismatch';
                            }
                            return 'Duration mismatch';
                        }
                        if (c === 'instructor') return 'Instructor';
                        if (c === 'room') return 'Room';
                        if (c === 'section') return 'Section';
                        return c;
                    });
                    conflictText = `Conflict: ${conflictLabels.join(', ')}`;
                } else {
                    console.warn('No conflicts found in result:', result);
                }
                
                statusDiv.textContent = conflictText;
                
                // Disable save button
                saveBtn.disabled = true;
                saveBtn.classList.add('opacity-50', 'cursor-not-allowed');
                saveBtn.classList.remove('hover:bg-blue-600');
                
                // Show suggestions popup (delay to avoid covering time editor)
                // Only show if there isn't already a suggestion popup open
                setTimeout(() => {
                    const existingPopup = document.getElementById('suggestion-popup');
                    if (!existingPopup) {
                        showSuggestionPopup(field, 'time', result.conflicts || []);
                    }
                }, 300);
            } else {
                // No conflicts - reset all conflict indicators
                statusDiv.classList.remove('hidden', 'bg-red-50', 'text-red-700');
                statusDiv.classList.add('bg-green-50', 'text-green-700');
                statusDiv.textContent = 'No conflicts';
                
                // Enable save button
                saveBtn.disabled = false;
                saveBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                saveBtn.classList.add('hover:bg-blue-600');
                
                // Remove any existing conflict indicators on the row
                const row = field.closest('tr');
                if (row) {
                    row.classList.remove('bg-red-50');
                }
                field.classList.remove('ring-2', 'ring-red-500', 'bg-red-50');
                
                // Close any existing suggestion popups since there's no conflict
                const existingPopup = document.getElementById('suggestion-popup');
                if (existingPopup) {
                    existingPopup.remove();
                }
            }
        } catch (error) {
            console.error('Error checking time conflict:', error);
        } finally {
            conflictCheckInProgress = false;
            editor._conflictCheckInProgress = false;
        }
    };
    
    // Expose the conflict status update function for external use (e.g., from suggestion popup)
    editor._updateTimeConflictStatus = updateTimeConflictStatus;
    editor._hasConflict = false;
    editor._conflictCheckInProgress = false;
    
    // Add event listeners for real-time conflict checking
    editor.querySelectorAll('.time-input').forEach(input => {
        input.addEventListener('input', updateTimeConflictStatus);
        input.addEventListener('change', updateTimeConflictStatus);
    });
    
    // Add event listeners
    const saveBtn = editor.querySelector('#save-time');
    saveBtn.addEventListener('click', async () => {
        if (saveBtn.disabled) {
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show('Conflict detected. Please select valid time.', 'error');
            }
            return;
        }
        
        const startHour = editor.querySelector('#start-hour').value;
        const startMinute = editor.querySelector('#start-minute').value;
        const startPeriod = editor.querySelector('#start-period').value;
        const endHour = editor.querySelector('#end-hour').value;
        const endMinute = editor.querySelector('#end-minute').value;
        const endPeriod = editor.querySelector('#end-period').value;
        
        // Final conflict check before saving
        const startTime = `${startHour}:${startMinute.padStart(2, '0')} ${startPeriod}`;
        const endTime = `${endHour}:${endMinute.padStart(2, '0')} ${endPeriod}`;
        const result = await checkTimeConflict(field, startTime, endTime);
        
        if (!result.ok) {
            hasConflict = true;
            editor._hasConflict = true;
            updateTimeConflictStatus();
            return;
        }
        
        const newTime = `${startHour}:${startMinute.padStart(2, '0')} ${startPeriod} - ${endHour}:${endMinute.padStart(2, '0')} ${endPeriod}`;
        field.textContent = newTime;
        
        // Show saving indicator
        if (typeof notificationManager !== 'undefined') {
            notificationManager.show('Saving...', 'info');
        }
        
        updateScheduleData(field, () => {
            // Success callback
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show('Time updated successfully!', 'success');
            }
        removeActiveProgressiveEditor();
        }, () => {
            // Error callback
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show('Failed to save time. Please try again.', 'error');
            }
        });
    });
    
    editor.querySelector('#cancel-time').addEventListener('click', () => {
        removeActiveProgressiveEditor();
    });
    
    // Close editor when clicking outside
    setTimeout(() => {
        document.addEventListener('click', closeProgressiveEditorOnOutsideClick);
    }, 0);
}

function removeActiveDropdowns() {
    // Remove both gray and blue bordered dropdowns (day and room dropdowns)
    // Use data-field-ref attribute to find them reliably
    const dayDropdowns = document.querySelectorAll('[data-field-ref="day-dropdown"]');
    const roomDropdowns = document.querySelectorAll('[data-field-ref="room-dropdown"]');
    const dropdowns = [...dayDropdowns, ...roomDropdowns];
    
    dropdowns.forEach(dropdown => {
        // Check if this is a day dropdown and save data if field reference exists
        if (dropdown.dataset.fieldRef === 'day-dropdown' && dropdown._fieldReference) {
            const field = dropdown._fieldReference;
            const originalValue = dropdown.dataset.originalValue || '';
            const currentValue = field.textContent.trim();
            
            // Only save if value changed AND no conflict is detected
            if (currentValue !== originalValue) {
                // Check if there's a conflict state stored on the dropdown
                const hasConflict = dropdown._hasConflict || false;
                
                if (!hasConflict) {
                    // No conflict, proceed with save
                    updateScheduleData(field);
                } else {
                    // Has conflict, revert to original value
                    field.textContent = originalValue;
                    field.classList.remove('ring-2', 'ring-red-500', 'bg-red-50');
                    field.closest('tr')?.classList.remove('bg-red-50');
                    
                    // Show notification
                    if (typeof notificationManager !== 'undefined') {
                        notificationManager.show('Cannot save: Conflict detected. Changes reverted.', 'error');
                    }
                }
            }
        }
        
        // For room dropdowns, just remove (no auto-save needed since save/cancel buttons handle it)
        
        if (dropdown.parentNode) {
            dropdown.parentNode.removeChild(dropdown);
        }
    });
    
    document.removeEventListener('click', closeDropdownOnOutsideClick);
}

function removeActiveProgressiveEditor() {
    const editors = document.querySelectorAll('.absolute.z-50.bg-white.border.border-blue-300.rounded-md.shadow-lg');
    editors.forEach(editor => {
        if (editor.parentNode) {
            editor.parentNode.removeChild(editor);
        }
    });
    document.removeEventListener('click', closeProgressiveEditorOnOutsideClick);
}

function closeDropdownOnOutsideClick(e) {
    // Check if click is outside day or room dropdowns
    // Also check if clicking on the field that triggered the dropdown (don't close if clicking the field itself)
    const isClickInsideDayDropdown = e.target.closest('[data-field-ref="day-dropdown"]');
    const isClickInsideRoomDropdown = e.target.closest('[data-field-ref="room-dropdown"]');
    const isClickOnField = e.target.closest('.editable-field[data-field="room"]') || 
                           e.target.closest('.editable-field[data-field="day"]');
    
    if (!isClickInsideDayDropdown && !isClickInsideRoomDropdown && !isClickOnField) {
        removeActiveDropdowns();
    }
}

function closeProgressiveEditorOnOutsideClick(e) {
    if (!e.target.closest('.absolute.z-50.bg-white.border.border-blue-300.rounded-md.shadow-lg')) {
        removeActiveProgressiveEditor();
    }
}

// Function to show suggestion popup when conflicts are detected
async function showSuggestionPopup(field, editType, conflicts) {
    // Don't show multiple popups at once
    const existingPopup = document.getElementById('suggestion-popup');
    if (existingPopup) {
        existingPopup.remove();
    }
    
    const row = field.closest('tr');
    if (!row) return;
    
    const tds = row.querySelectorAll('td');
    const subjectCode = tds[0]?.textContent.trim();
    const instructorName = tds[3]?.textContent.trim();
    const dayText = row.querySelector('span[data-field="day"]')?.textContent.trim() || '';
    const timeText = row.querySelector('span[data-field="time"]')?.textContent.trim();
    const roomText = row.querySelector('span[data-field="room"]')?.textContent.trim();
    const sectionTitle = row.closest('table')?.previousSibling?.textContent.trim();
    
    // Parse time to get duration
    const parseTo24 = (s) => {
        const parts = s.split('-');
        const to24 = (t) => {
            const match = t.trim().match(/(\d{1,2}):(\d{2})\s*(AM|PM)/i);
            if (!match) return '00:00:00';
            let hour = parseInt(match[1]);
            const minute = parseInt(match[2]);
            const period = match[3].toUpperCase();
            if (period === 'PM' && hour < 12) hour += 12;
            if (period === 'AM' && hour === 12) hour = 0;
            return `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}:00`;
        };
        if (parts.length === 2) { 
            const start = to24(parts[0]);
            const end = to24(parts[1]);
            const startTime = new Date(`1970-01-01 ${start}`);
            const endTime = new Date(`1970-01-01 ${end}`);
            return Math.round((endTime - startTime) / 60000); // duration in minutes
        }
        return 90; // default 1.5 hours
    };
    
    const durationMin = parseTo24(timeText.replace('–','-'));
    const groupId = currentScheduleGroupId || window.currentViewedScheduleId;
    if (!groupId) return;
    
    // Check if original day is a joint session
    const originalDays = parseCombinedDays(dayText);
    const isJointSession = originalDays.length > 1;
    
    // Locate entry to get IDs
    const locateRes = await fetch('/api/schedule/locate-entry', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({
            group_id: groupId,
            subject_code: subjectCode,
            instructor_name: instructorName,
            section_code: sectionTitle,
            day: dayText,
            start_time: '',
            end_time: ''
        })
    }).then(r => r.json()).catch(() => ({}));
    
    const instructorId = locateRes.instructor_id || null;
    const roomId = locateRes.room_id || null;
    const sectionId = locateRes.section_id || null;
    const meetingId = locateRes.meeting_id || null;
    
    // Fetch suggestions
    // For time editing with joint sessions, pass the day to ensure suggestions work for all joint days
    // For day editing, DON'T pass preferred_day (pass null) to avoid biasing towards original day
    const suggestRes = await fetch('/api/schedule/suggest-alternatives', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({
            group_id: groupId,
            instructor_id: instructorId,
            room_id: roomId,
            section_id: sectionId,
            meeting_id: meetingId, // Exclude current meeting from suggestions
            preferred_day: editType === 'day' ? null : (editType === 'time' && isJointSession ? dayText : null), // Don't bias towards original day when editing day
            duration_minutes: durationMin,
            edit_type: editType, // Pass edit type to backend
            original_day: editType === 'day' ? dayText : null // Pass original day for day editing exclusion
        })
    }).then(r => r.json()).catch(() => ({ suggestions: [], count: 0 }));
    
    // Process suggestions - prioritize backend joint suggestions
    let processedSuggestions = suggestRes.suggestions || [];
    
    if (isJointSession && editType === 'day') {
        // Check if backend already provided joint suggestions (marked with is_joint: true)
        const backendJointSuggestions = processedSuggestions.filter(s => s.is_joint === true);
        
        if (backendJointSuggestions.length > 0) {
            // Backend already provided joint suggestions - use them
            processedSuggestions = backendJointSuggestions;
        } else {
            // Backend didn't provide joint suggestions - do frontend grouping as fallback
            // Group suggestions by time slot and room
            const suggestionsByTime = {};
            
            processedSuggestions.forEach(suggestion => {
                const timeKey = `${suggestion.start_time}-${suggestion.end_time}-${suggestion.room_id}`;
                if (!suggestionsByTime[timeKey]) {
                    suggestionsByTime[timeKey] = {
                        days: [],
                        start_time: suggestion.start_time,
                        end_time: suggestion.end_time,
                        room_id: suggestion.room_id,
                        room_name: suggestion.room_name
                    };
            }
                suggestionsByTime[timeKey].days.push(suggestion.day);
            });
            
            // Filter to only show suggestions where we have enough days for joint session
            processedSuggestions = [];
            
            // First, try to find slots available for 2+ days at the same time (ideal for joint sessions)
            Object.values(suggestionsByTime).forEach(slot => {
                const uniqueDays = [...new Set(slot.days)].sort();
                if (uniqueDays.length >= 2) {
                    // Combine days for joint session
                    const combinedDays = combineDays(uniqueDays.slice(0, 2)); // Take first 2 days
                    processedSuggestions.push({
                        day: combinedDays,
                        start_time: slot.start_time,
                        end_time: slot.end_time,
                        room_id: slot.room_id,
                        room_name: slot.room_name,
                        is_joint: true
                    });
                }
            });
            
            // If no joint suggestions found, show single-day suggestions
            if (processedSuggestions.length === 0) {
                processedSuggestions = suggestRes.suggestions.slice(0, 5);
            }
        }
    }
    
    // Create and show popup beside the field
    const popup = document.createElement('div');
    popup.id = 'suggestion-popup';
    popup.className = 'absolute z-50 bg-white border border-gray-300 rounded-md shadow-lg';
    popup.style.minWidth = '280px';
    popup.style.maxWidth = '320px';
    popup.style.maxHeight = '400px';
    
    const suggestions = processedSuggestions;
    let suggestionHTML = '';
    
    if (suggestions.length === 0) {
        suggestionHTML = '<div class="text-center text-gray-500 py-3 text-xs">No alternative suggestions available.</div>';
    } else {
        suggestionHTML = suggestions.slice(0, 5).map((suggestion, idx) => {
            // Only display the field being edited
            if (editType === 'day') {
                const day = suggestion.day || 'N/A';
                const isJoint = suggestion.is_joint || false;
                return `
                    <div class="suggestion-item border-b border-gray-100 last:border-b-0 px-3 py-2 hover:bg-blue-50 cursor-pointer transition-colors" data-suggestion-index="${idx}">
                        <div class="flex flex-col">
                            <div class="font-semibold text-xs text-gray-800">${day} ${isJoint ? '<span class="text-blue-600">(Joint)</span>' : ''}</div>
                        </div>
                    </div>
                `;
            } else if (editType === 'time') {
                const startTime = convertTo12HourFormat(suggestion.start_time || '');
                const endTime = convertTo12HourFormat(suggestion.end_time || '');
                const timeRange = startTime && endTime ? `${startTime} - ${endTime}` : 'N/A';
                return `
                    <div class="suggestion-item border-b border-gray-100 last:border-b-0 px-3 py-2 hover:bg-blue-50 cursor-pointer transition-colors" data-suggestion-index="${idx}">
                        <div class="flex flex-row items-center">
                            <div class="font-semibold text-xs text-gray-800">${timeRange}</div>
                        </div>
                    </div>
                `;
            } else if (editType === 'room') {
                const room = suggestion.room_name || 'TBA';
                return `
                    <div class="suggestion-item border-b border-gray-100 last:border-b-0 px-3 py-2 hover:bg-blue-50 cursor-pointer transition-colors" data-suggestion-index="${idx}">
                        <div class="flex flex-col">
                            <div class="font-semibold text-xs text-gray-800">${room}</div>
                        </div>
                    </div>
                `;
            } else {
                // Fallback: show all fields if editType is unknown
                const day = suggestion.day || 'N/A';
                const startTime = convertTo12HourFormat(suggestion.start_time || '');
                const endTime = convertTo12HourFormat(suggestion.end_time || '');
                const room = suggestion.room_name || 'TBA';
                const timeRange = startTime && endTime ? `${startTime} - ${endTime}` : 'N/A';
                const isJoint = suggestion.is_joint || false;
                return `
                    <div class="suggestion-item border-b border-gray-100 last:border-b-0 px-3 py-2 hover:bg-blue-50 cursor-pointer transition-colors" data-suggestion-index="${idx}">
                        <div class="flex flex-col">
                            <div class="font-semibold text-xs text-gray-800">${day} ${isJoint ? '<span class="text-blue-600">(Joint)</span>' : ''}</div>
                            <div class="text-xs text-gray-600">${timeRange}</div>
                            <div class="text-xs text-gray-500">Room: ${room}</div>
                        </div>
                    </div>
                `;
            }
        }).join('');
    }
    
    popup.innerHTML = `
        <div class="bg-blue-500 text-white px-3 py-2 rounded-t-md">
            <h3 class="text-xs font-semibold">Suggestions</h3>
            <p class="text-[10px] mt-0.5 opacity-90">Alternatives for ${editType === 'day' ? 'day' : editType === 'time' ? 'time' : 'room'}</p>
        </div>
        <div class="overflow-y-auto max-h-[280px]">
            ${suggestionHTML}
        </div>
        <div class="border-t border-gray-200 px-3 py-2 flex justify-end space-x-2 bg-gray-50 rounded-b-md">
            <button id="suggestion-dismiss" class="px-3 py-1 text-[10px] font-medium text-gray-700 bg-gray-200 rounded hover:bg-gray-300 transition-colors">Dismiss</button>
            <button id="suggestion-apply" class="px-3 py-1 text-[10px] font-medium text-white bg-blue-500 rounded hover:bg-blue-600 transition-colors ${suggestions.length === 0 ? 'opacity-50 cursor-not-allowed' : ''}" ${suggestions.length === 0 ? 'disabled' : ''}>Apply</button>
        </div>
    `;
    
    document.body.appendChild(popup);
    
    // Wait for popup to be in DOM before calculating positions
    setTimeout(() => {
        // Position popup beside the field, avoiding overlap with dropdown
        const fieldRect = field.getBoundingClientRect();
        
        // Find the active dropdown/editor - search more specifically
        let activeEditorRect = null;
        
        // Find all absolute positioned elements that could be the dropdown
        const allAbsoluteElements = document.querySelectorAll('.absolute.z-50');
        
        // Find the dropdown/editor that's currently visible and closest to field
        let closestDropdown = null;
        let closestDistance = Infinity;
        
        allAbsoluteElements.forEach(element => {
            // Skip the suggestion popup itself
            if (element.id === 'suggestion-popup') return;
            
            const rect = element.getBoundingClientRect();
            if (rect.width > 0 && rect.height > 0 && rect.top > 0 && rect.left > 0) {
                // Check if it's near the field (within 150px vertically and horizontally)
                const verticalDist = Math.abs(rect.top - fieldRect.bottom);
                const horizontalDist = Math.abs(rect.left - fieldRect.left);
                
                if (verticalDist < 150 && horizontalDist < 250) {
                    const distance = verticalDist + horizontalDist;
                    if (distance < closestDistance) {
                        closestDistance = distance;
                        closestDropdown = element;
                    }
                }
            }
        });
        
        if (closestDropdown) {
            activeEditorRect = closestDropdown.getBoundingClientRect();
        }
        
        // Get popup dimensions after it's rendered
        const popupRect = popup.getBoundingClientRect();
        const popupWidth = popupRect.width || 320;
        const popupHeight = popupRect.height || 400;
        
        // Strategy: Position to the RIGHT of dropdown with generous spacing, NEVER overlap
        if (activeEditorRect) {
            const spaceRight = window.innerWidth - activeEditorRect.right;
            const spaceBelow = window.innerHeight - activeEditorRect.bottom;
            
            // Priority 1: Position to the RIGHT of dropdown (needs 340px+ space, 20px gap)
            if (spaceRight >= 340) {
                popup.style.left = (activeEditorRect.right + 25) + 'px';
                popup.style.top = activeEditorRect.top + 'px';
                popup.style.zIndex = '60';
            }
            // Priority 2: Position BELOW dropdown with large gap (40px+ gap to ensure no overlap)
            else if (spaceBelow >= (activeEditorRect.height + popupHeight + 50)) {
                popup.style.left = activeEditorRect.left + 'px';
                popup.style.top = (activeEditorRect.bottom + 40) + 'px';
                popup.style.zIndex = '60';
            }
            // Priority 3: Position to the LEFT with large gap
            else if (activeEditorRect.left >= 340) {
                popup.style.left = (activeEditorRect.left - popupWidth - 25) + 'px';
                popup.style.top = activeEditorRect.top + 'px';
                popup.style.zIndex = '60';
            }
            // Last resort: Position FAR away to guarantee no overlap
            else {
                // Position far to the right (400px+) or far below (200px+)
                const targetLeft = Math.min(activeEditorRect.right + 400, window.innerWidth - popupWidth - 20);
                const targetTop = Math.max(activeEditorRect.bottom + 200, 20);
                
                // Choose position with most space
                if (targetLeft + popupWidth <= window.innerWidth - 20) {
                    // Can fit to the right, use it
                    popup.style.left = targetLeft + 'px';
                    popup.style.top = activeEditorRect.top + 'px';
                } else {
                    // Position far below
                    popup.style.left = Math.max(20, window.innerWidth - popupWidth - 20) + 'px';
                    popup.style.top = targetTop + 'px';
                }
                popup.style.zIndex = '60';
            }
        } else {
            // No dropdown found - position to the right of field
            const spaceRight = window.innerWidth - fieldRect.right;
            
            if (spaceRight >= 340) {
                popup.style.left = (fieldRect.right + 20) + 'px';
                popup.style.top = fieldRect.top + 'px';
            } else {
                // Position below field
                popup.style.left = fieldRect.left + 'px';
                popup.style.top = (fieldRect.bottom + 30) + 'px';
            }
            popup.style.zIndex = '60';
            
            // Ensure it doesn't go off-screen
            if (parseFloat(popup.style.left) + popupWidth > window.innerWidth) {
                popup.style.left = Math.max(10, window.innerWidth - popupWidth - 10) + 'px';
            }
            if (parseFloat(popup.style.top) + popupHeight > window.innerHeight) {
                popup.style.top = Math.max(10, window.innerHeight - popupHeight - 20) + 'px';
            }
        }
        
        // Final overlap check: Ensure popup NEVER overlaps with dropdown
        if (activeEditorRect) {
            // Re-measure after positioning
            const popupFinalRect = popup.getBoundingClientRect();
            const popupLeft = popupFinalRect.left;
            const popupTop = popupFinalRect.top;
            const popupRight = popupFinalRect.right;
            const popupBottom = popupFinalRect.bottom;
            
            const dropdownLeft = activeEditorRect.left;
            const dropdownTop = activeEditorRect.top;
            const dropdownRight = activeEditorRect.right;
            const dropdownBottom = activeEditorRect.bottom;
            
            // Check for any overlap - must have at least 10px gap
            const horizontalOverlap = !(popupRight + 10 < dropdownLeft || popupLeft - 10 > dropdownRight);
            const verticalOverlap = !(popupBottom + 10 < dropdownTop || popupTop - 10 > dropdownBottom);
            
            if (horizontalOverlap && verticalOverlap) {
                // CRITICAL: Force position far away to completely avoid overlap
                // Position at least 400px to the right, or if not enough space, far below
                const spaceRight = window.innerWidth - dropdownRight;
                
                if (spaceRight >= 400) {
                    // Position far to the right
                    popup.style.left = (dropdownRight + 400) + 'px';
                    popup.style.top = dropdownTop + 'px';
                } else {
                    // Position far below with horizontal offset
                    popup.style.left = Math.max(dropdownLeft + 50, dropdownRight - popupWidth + 50) + 'px';
                    popup.style.top = (dropdownBottom + 150) + 'px';
                }
                
                // Final safety check - ensure no overlap after repositioning
                const finalRect = popup.getBoundingClientRect();
                const stillOverlaps = !(finalRect.right + 10 < dropdownLeft || finalRect.left - 10 > dropdownRight ||
                                       finalRect.bottom + 10 < dropdownTop || finalRect.top - 10 > dropdownBottom);
                
                if (stillOverlaps) {
                    // Absolute fallback: position at viewport edges
                    popup.style.left = (window.innerWidth - popupWidth - 20) + 'px';
                    popup.style.top = Math.max(20, dropdownBottom + 200) + 'px';
                }
            }
        }
    }, 100); // Longer delay to ensure dropdown is fully rendered and measured
    
    // Store processed suggestions and original suggestions for apply
    popup._processedSuggestions = processedSuggestions;
    popup._originalSuggestions = suggestRes.suggestions || [];
    
    // Add event listeners
    let selectedSuggestionIndex = null;
    
    // Select suggestion on click
    popup.querySelectorAll('.suggestion-item').forEach(item => {
        item.addEventListener('click', (e) => {
            e.stopPropagation(); // Prevent any parent handlers
            
            // Skip if this item is already applied (has green background)
            if (item.classList.contains('bg-green-50')) {
                return; // Don't allow selecting already-applied items
            }
            
            // Remove selection highlight from all items (but keep applied state for applied items)
            popup.querySelectorAll('.suggestion-item').forEach(i => {
                // Only remove selection highlight if not applied
                if (!i.classList.contains('bg-green-50')) {
                    i.classList.remove('bg-blue-100', 'border-l-4', 'border-blue-500');
                }
            });
            
            // Add selection to clicked item (only if not already applied)
            if (!item.classList.contains('bg-green-50')) {
                item.classList.add('bg-blue-100', 'border-l-4', 'border-blue-500');
            }
            
            selectedSuggestionIndex = parseInt(item.dataset.suggestionIndex);
            
            // Get suggestions from popup storage
            const suggestionsToUse = popup._processedSuggestions || suggestions;
            console.log('Suggestion selected:', selectedSuggestionIndex, suggestionsToUse ? suggestionsToUse[selectedSuggestionIndex] : null);
        });
    });
    
    // Apply button
    popup.querySelector('#suggestion-apply').addEventListener('click', async (e) => {
        e.stopPropagation(); // Prevent any parent handlers
        
        const suggestionsToUse = popup._processedSuggestions || suggestions;
        console.log('Apply clicked:', {
            selectedIndex: selectedSuggestionIndex,
            suggestionsCount: suggestionsToUse ? suggestionsToUse.length : 0,
            suggestion: selectedSuggestionIndex !== null ? suggestionsToUse[selectedSuggestionIndex] : null
        });
        
        if (selectedSuggestionIndex !== null && suggestionsToUse && suggestionsToUse[selectedSuggestionIndex]) {
            const suggestion = suggestionsToUse[selectedSuggestionIndex];
            console.log('Applying suggestion:', suggestion);
            
            // Clear all previously applied states when applying a new suggestion
            popup.querySelectorAll('.suggestion-item').forEach(item => {
                if (item.classList.contains('bg-green-50')) {
                    // Reset previously applied item
                    item.style.opacity = '1';
                    item.style.pointerEvents = 'auto';
                    item.style.cursor = 'pointer';
                    item.classList.remove('bg-green-50', 'border-green-300');
                    const checkmark = item.querySelector('.applied-checkmark');
                    if (checkmark) {
                        checkmark.remove();
                    }
                    // Reset flex direction if it was changed
                    const flexContainer = item.querySelector('.flex');
                    if (flexContainer && flexContainer.classList.contains('flex-row')) {
                        flexContainer.classList.remove('flex-row', 'items-center');
                        flexContainer.classList.add('flex-col');
                    }
                }
            });
            
            // Mark the applied suggestion visually (don't remove popup)
            const appliedItem = popup.querySelector(`[data-suggestion-index="${selectedSuggestionIndex}"]`);
            console.log('Applied item found:', !!appliedItem);
            
            if (appliedItem) {
                // Reduce opacity
                appliedItem.style.opacity = '0.5';
                appliedItem.style.pointerEvents = 'none';
                appliedItem.style.cursor = 'default';
                
                // Add checkmark inline with the text (not in a new line)
                // Find the text element (font-semibold) and append checkmark to it or its parent
                const textElement = appliedItem.querySelector('.font-semibold.text-xs');
                if (textElement) {
                    // Check if checkmark already exists
                    let checkmark = textElement.parentElement.querySelector('.applied-checkmark');
                    if (!checkmark) {
                        checkmark = document.createElement('span');
                        checkmark.className = 'applied-checkmark text-green-600 font-bold ml-2 text-sm inline-block';
                        checkmark.textContent = '✓';
                        // Insert after the text element (on the same line)
                        textElement.parentElement.insertBefore(checkmark, textElement.nextSibling);
                    }
                } else {
                    // Fallback: find flex container and modify it to be flex-row
                    const flexContainer = appliedItem.querySelector('.flex');
                    if (flexContainer) {
                        flexContainer.classList.remove('flex-col');
                        flexContainer.classList.add('flex-row', 'items-center');
                        let checkmark = flexContainer.querySelector('.applied-checkmark');
                        if (!checkmark) {
                            checkmark = document.createElement('span');
                            checkmark.className = 'applied-checkmark text-green-600 font-bold ml-2 text-sm';
                            checkmark.textContent = '✓';
                            flexContainer.appendChild(checkmark);
                        }
                    }
                }
                
                // Add background color to show it's applied
                appliedItem.classList.remove('hover:bg-blue-50', 'bg-blue-100', 'border-l-4', 'border-blue-500');
                appliedItem.classList.add('bg-green-50', 'border-green-300', 'border-l-4');
                
                // Remove hover effect
                appliedItem.style.cursor = 'default';
            }
            
            // Apply suggestion based on edit type and update the edit field
            if (editType === 'day') {
                const dayField = row.querySelector('span[data-field="day"]');
                if (dayField) {
                    const newDayValue = suggestion.day || dayText;
                    dayField.textContent = newDayValue;
                    
                    // Update day dropdown if it's open
                    const activeDayDropdown = document.querySelector('[data-field-ref="day-dropdown"]');
                    if (activeDayDropdown) {
                        // Parse the new day value
                        const newParsedDays = parseCombinedDays(newDayValue);
                        const dayOptions = [
                            { short: 'M', long: 'Mon' },
                            { short: 'T', long: 'Tue' },
                            { short: 'W', long: 'Wed' },
                            { short: 'Th', long: 'Thu' },
                            { short: 'F', long: 'Fri' },
                            { short: 'S', long: 'Sat' }
                        ];
                        
                        // Update the selected days in the dropdown
                        activeDayDropdown._selectedDays = [...newParsedDays];
                        
                        // Update visual selection in dropdown
                        dayOptions.forEach((dayOpt, idx) => {
                            const option = activeDayDropdown.querySelector(`[data-day-idx="${idx}"]`);
                            if (option) {
                                option.classList.remove('bg-blue-500', 'text-white', 'font-semibold', 'bg-red-100', 'border-red-500', 'text-red-700', 'hover:bg-blue-50');
                                if (newParsedDays.includes(dayOpt.long)) {
                                    option.classList.add('bg-blue-500', 'text-white', 'font-semibold');
                                } else {
                                    option.classList.add('hover:bg-blue-50');
                                }
                            }
                        });
                        
                        // Update status message to show no conflict
                        const updateStatusMessageFn = activeDayDropdown._updateStatusMessage;
                        if (updateStatusMessageFn) {
                            updateStatusMessageFn(false, []);
                        }
                        
                        // Enable save button
                        const updateSaveButtonFn = activeDayDropdown._updateSaveButton;
                        if (updateSaveButtonFn) {
                            updateSaveButtonFn();
                        }
                        
                        // Trigger conflict check to verify the new selection is valid
                        setTimeout(async () => {
                            const result = await checkDayConflict(field, newDayValue);
                            const updateStatusMsgFn = activeDayDropdown._updateStatusMessage;
                            if (updateStatusMsgFn) {
                                updateStatusMsgFn(!result.ok, result.conflicts || []);
                            }
                        }, 100);
                    }
                    
                    // DON'T auto-save - just update the field visually
                    // User must click "Save" button to actually save
                    // This allows them to review and make additional changes before saving
                }
            } else if (editType === 'time') {
                const timeField = row.querySelector('span[data-field="time"]');
                if (timeField && suggestion.start_time && suggestion.end_time) {
                    // Suggestions from backend are already validated, so we can trust them
                    // Don't re-validate - just apply the suggestion
                    const startTime = convertTo12HourFormat(suggestion.start_time);
                    const endTime = convertTo12HourFormat(suggestion.end_time);
                    
                    // Validate that both times were successfully converted
                    if (!startTime || !endTime || startTime === '' || endTime === '') {
                        console.error('Failed to convert suggestion times:', {
                            start_time: suggestion.start_time,
                            end_time: suggestion.end_time,
                            converted_start: startTime,
                            converted_end: endTime
                        });
                        // Show error notification
                        try {
                            if (typeof notificationManager !== 'undefined' && notificationManager) {
                                notificationManager.show('Failed to apply suggestion: Invalid time format', 'error');
                            }
                        } catch(_e) {}
                        return; // Don't apply the suggestion if time conversion failed
                    }
                    
                    const timeRange = `${startTime} - ${endTime}`;
                    timeField.textContent = timeRange;
                    
                    // Update dataset attributes with the original 24-hour format times for future saves
                    timeField.dataset.originalStart = suggestion.start_time;
                    timeField.dataset.originalEnd = suggestion.end_time;
                    
                    // Update time editor if it's open
                    const activeTimeEditor = document.querySelector('[data-field-ref="time-editor"]');
                    if (activeTimeEditor) {
                        const startHourEl = activeTimeEditor.querySelector('#start-hour');
                        const startMinuteEl = activeTimeEditor.querySelector('#start-minute');
                        const startPeriodEl = activeTimeEditor.querySelector('#start-period');
                        const endHourEl = activeTimeEditor.querySelector('#end-hour');
                        const endMinuteEl = activeTimeEditor.querySelector('#end-minute');
                        const endPeriodEl = activeTimeEditor.querySelector('#end-period');
                        
                        if (startHourEl && startMinuteEl && startPeriodEl && endHourEl && endMinuteEl && endPeriodEl) {
                            // Parse the time and update the inputs
                            const parseTime = (timeStr) => {
                                const match = timeStr.match(/(\d{1,2}):(\d{2})\s*(AM|PM)/i);
                                if (match) {
                                    return {
                                        hour: parseInt(match[1]),
                                        minute: parseInt(match[2]),
                                        period: match[3].toUpperCase()
                                    };
                                }
                                return null;
                            };
                            
                            const startParsed = parseTime(startTime);
                            const endParsed = parseTime(endTime);
                            
                            if (startParsed && endParsed) {
                                startHourEl.value = startParsed.hour;
                                startMinuteEl.value = startParsed.minute.toString().padStart(2, '0');
                                startPeriodEl.value = startParsed.period;
                                endHourEl.value = endParsed.hour;
                                endMinuteEl.value = endParsed.minute.toString().padStart(2, '0');
                                endPeriodEl.value = endParsed.period;
                                
                                // Trigger conflict check with updated values after DOM updates
                                // This will validate the new suggested time (which should be conflict-free)
                                const updateFunc = activeTimeEditor._updateTimeConflictStatus;
                                if (updateFunc) {
                                    // Use longer delay to ensure all DOM updates are complete
                                    // The conflict check will read the new values from the input fields
                                    setTimeout(() => {
                                        // Force a new check by clearing the conflict check flag
                                        if (activeTimeEditor._conflictCheckInProgress !== undefined) {
                                            activeTimeEditor._conflictCheckInProgress = false;
                                        }
                                        // Also clear the hasConflict flag to reset it
                                        if (activeTimeEditor._hasConflict !== undefined) {
                                            activeTimeEditor._hasConflict = false;
                                        }
                                        updateFunc();
                                    }, 250);
                                }
                            }
                        }
                    }
                    
                    // DON'T auto-save - just update the field visually
                    // User must click "Save" button to actually save
                    // This allows them to review and make additional changes before saving
                }
            } else if (editType === 'room') {
                const roomField = row.querySelector('span[data-field="room"]');
                if (roomField) {
                    roomField.textContent = suggestion.room_name || roomText;
                    
                    // DON'T auto-save - just update the field visually
                    // User must click "Save" button to actually save
                    // This allows them to review and make additional changes before saving
                }
            }
            
            // Show success message that suggestion was applied (but not saved yet)
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show('Suggestion applied. Click "Save" to save changes.', 'info');
            }
            
            // Only apply what's being edited - don't change other fields automatically
        } else {
            console.warn('No suggestion selected or suggestion not found', {
                selectedIndex: selectedSuggestionIndex,
                availableSuggestions: suggestionsToUse ? suggestionsToUse.length : 0,
                suggestions: suggestionsToUse
            });
            
            if (typeof notificationManager !== 'undefined') {
                notificationManager.show('Please select a suggestion first', 'warning');
            }
        }
        
        // Don't remove the popup - keep it visible with the applied suggestion marked
    });
    
    // Dismiss button
    popup.querySelector('#suggestion-dismiss').addEventListener('click', () => {
        popup.remove();
    });
    
    // Close when clicking outside
    setTimeout(() => {
        const closeSuggestionPopup = (e) => {
            if (!popup.contains(e.target) && !field.closest('.absolute.z-50')?.contains(e.target)) {
                popup.remove();
                document.removeEventListener('click', closeSuggestionPopup);
            }
        };
        document.addEventListener('click', closeSuggestionPopup);
    }, 0);
}

function updateScheduleData(field, onSuccess = null, onError = null) {
    const sectionIndex = parseInt(field.dataset.section);
    const subjectIndex = parseInt(field.dataset.index);
    const fieldName = field.dataset.field;
    const newValue = field.textContent.trim();
    
    // Update the current data - safely check structure
    try {
        if (currentScheduleData && 
            currentScheduleData.sections && 
            Array.isArray(currentScheduleData.sections) &&
            currentScheduleData.sections[sectionIndex] && 
            currentScheduleData.sections[sectionIndex].subjects &&
            Array.isArray(currentScheduleData.sections[sectionIndex].subjects) &&
        currentScheduleData.sections[sectionIndex].subjects[subjectIndex]) {
        currentScheduleData.sections[sectionIndex].subjects[subjectIndex][fieldName] = newValue;
        }
    } catch (error) {
        console.warn('Error updating schedule data structure:', error);
        // Continue with validation even if data structure update fails
    }

    // Real-time validate with backend and show indicators; if OK, persist
    try {
        // Gather row context
        const row = field.closest('tr');
        const tds = row.querySelectorAll('td');
        const subjectCode = tds[0]?.textContent.trim();
        const subjectDescription = tds[1]?.textContent.trim();
        const instructorName = tds[3]?.textContent.trim();
        const dayField = row.querySelector('span[data-field="day"]');
        const timeField = row.querySelector('span[data-field="time"]');
        const roomField = row.querySelector('span[data-field="room"]');
        
        // Determine what field is being edited
        const fieldName = field.dataset.field;
        const isEditingDay = (fieldName === 'day');
        const isEditingTime = (fieldName === 'time');
        const isEditingRoom = (fieldName === 'room');
        
        // Get current values from DOM
        const dayText = dayField?.textContent.trim() || '';
        const timeText = timeField?.textContent.trim() || '';
        const roomText = roomField?.textContent.trim() || '';
        const sectionTitle = row.closest('table').previousSibling?.textContent.trim(); // e.g., "1st Year A"

        // Parse timeText to HH:MM:SS
        const parseTo24 = (s) => {
            const parts = s.split('-');
            const to24 = (t) => {
                const d = new Date('1970-01-01 ' + t.trim());
                return d.toTimeString().slice(0,8);
            };
            if (parts.length === 2) { return { start: to24(parts[0]), end: to24(parts[1]) }; }
            return { start: '00:00:00', end: '00:00:00' };
        };
        const { start, end } = parseTo24(timeText.replace('–','-'));

        // Get original values for locating the meeting
        // dayField, timeField, roomField already defined above
        
        const origDay = dayField?.dataset.original || dayText;
        
        // Get original time from dataset attributes (24-hour format)
        // These should be set when the schedule is rendered
        let origStart = timeField?.dataset.originalStart || '';
        let origEnd = timeField?.dataset.originalEnd || '';
        
        // If dataset attributes are empty, try to convert current time from 12-hour to 24-hour
        if (!origStart || !origEnd || origStart === '' || origEnd === '') {
            // Parse current time text and convert to 24-hour format
            const parseTo24 = (time12Hour) => {
                const match = time12Hour.match(/(\d{1,2}):(\d{1,2})\s*(AM|PM)/i);
                if (!match) return null;
                let hour = parseInt(match[1]);
                const minute = parseInt(match[2]);
                const period = match[3].toUpperCase();
                if (period === 'PM' && hour < 12) hour += 12;
                if (period === 'AM' && hour === 12) hour = 0;
                return `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}:00`;
            };
            
            const timeParts = timeText.replace('–','-').split('-');
            if (timeParts.length === 2) {
                const parsedStart = parseTo24(timeParts[0].trim());
                const parsedEnd = parseTo24(timeParts[1].trim());
                if (parsedStart) origStart = parsedStart;
                if (parsedEnd) origEnd = parsedEnd;
            }
        }
        
        const origRoom = roomField?.dataset.original || roomText;

        // Determine which group_id to use based on context
        // currentScheduleGroupId is for generated schedule, currentViewedScheduleId is for history view
        const groupId = currentScheduleGroupId || window.currentViewedScheduleId;
        
        if (!groupId) {
            console.error('No group_id available for validation');
            return;
        }

        // These will be updated with values from locate-entry response
        let finalOrigStart = origStart;
        let finalOrigEnd = origEnd;
        let finalOrigDay = origDay;

        // First, locate the entry and meeting to get instructor_id, room_id, and original time
        // This is needed for proper conflict checking and to ensure we have the correct original time
        fetch('/api/schedule/locate-entry', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                group_id: groupId,
                subject_code: subjectCode,
                instructor_name: instructorName,
                section_code: sectionTitle,
                day: origDay,
                start_time: origStart || '', // Use empty string if not available, backend will handle it
                end_time: origEnd || ''
            })
        }).then(r => r.json()).then(locateRes => {
            // Use located data for validation, or fallback to basic validation
            const instructorId = locateRes.instructor_id || null;
            const roomId = locateRes.room_id || null;
            const meetingId = locateRes.meeting_id || null;
            const entryId = locateRes.entry_id || null;
            
            // If locate-entry returned original time, use it (more reliable than dataset attributes)
            // This ensures we have the exact original time from the database
            if (locateRes.original_start_time && locateRes.original_end_time) {
                finalOrigStart = locateRes.original_start_time;
                finalOrigEnd = locateRes.original_end_time;
            } else if (locateRes.start_time && locateRes.end_time) {
                // Fallback to returned start_time/end_time if original_* not available
                finalOrigStart = locateRes.start_time;
                finalOrigEnd = locateRes.end_time;
            }
            
            if (locateRes.original_day) {
                finalOrigDay = locateRes.original_day;
            } else if (locateRes.day) {
                finalOrigDay = locateRes.day;
            }
            
            // Store the final original time in the field's dataset for future use
            if (timeField && finalOrigStart && finalOrigEnd) {
                timeField.dataset.originalStart = finalOrigStart;
                timeField.dataset.originalEnd = finalOrigEnd;
            }
            
            // Also update origDay if locate-entry returned original_day
            if (dayField && finalOrigDay) {
                dayField.dataset.original = finalOrigDay;
            }

            // Resolve room_id from room name if needed
            // If room was edited, we need to check conflicts with the new room
            let finalRoomId = roomId;
            let roomName = null;
            if (!finalRoomId && roomText && roomText !== 'TBA' && roomText !== 'N/A') {
                // Pass room name to let server resolve it
                roomName = roomText;
            }

            // Validate with all necessary information for conflict checking
            return fetch('/api/schedule/validate-edit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    group_id: groupId,
                    meeting_id: meetingId,
                    entry_id: entryId,
                    instructor_id: instructorId,
                    room_id: finalRoomId,
                    room_name: roomName, // For resolving room_id from room name
                    day: dayText,
                    start_time: start,
                    end_time: end
                })
            }).then(r => r.json());
        }).catch(() => {
            // Fallback: validate without instructor_id and room_id (server will try to resolve)
            return fetch('/api/schedule/validate-edit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    group_id: groupId,
                    day: dayText,
                    start_time: start,
                    end_time: end
                })
            }).then(r => r.json());
        }).then(res => {
            // Reset indicator styles
            field.classList.remove('ring-2','ring-red-500','bg-red-50');
            row.classList.remove('bg-red-50');
            // Remove existing conflict UI
            const oldBadge = row.querySelector('.conflict-indicator');
            if (oldBadge && oldBadge.parentNode) oldBadge.parentNode.removeChild(oldBadge);
            const oldSuggestions = row.querySelector('.conflict-suggestions');
            if (oldSuggestions && oldSuggestions.parentNode) oldSuggestions.parentNode.removeChild(oldSuggestions);
            // Remove suggestions row if exists
            let oldSuggestionsRow = row.nextElementSibling;
            if (oldSuggestionsRow && oldSuggestionsRow.classList.contains('conflict-suggestions-row')) {
                oldSuggestionsRow.remove();
            }
            if (!res.ok) {
                // Show conflict indicator
                field.classList.add('ring-2','ring-red-500','bg-red-50');
                row.classList.add('bg-red-50');
                const conflicts = Array.isArray(res.conflicts) && res.conflicts.length
                    ? ('Conflict: ' + res.conflicts.join(', '))
                    : 'Conflict detected';
                
                // Remove existing conflict UI
                const oldConflictUI = row.querySelector('.conflict-suggestions');
                if (oldConflictUI) oldConflictUI.remove();
                
                // Add conflict badge
                const badge = document.createElement('div');
                badge.className = 'conflict-indicator mt-1 text-[10px] font-semibold text-red-600';
                badge.textContent = conflicts;
                field.parentElement.classList.add('relative');
                field.parentElement.appendChild(badge);
                
                // Fetch suggestions
                const durationMin = Math.round((new Date('1970-01-01 ' + end) - new Date('1970-01-01 ' + start)) / 60000);
                const groupIdForSuggestions = currentScheduleGroupId || window.currentViewedScheduleId;
                console.log('Fetching suggestions with:', { group_id: groupIdForSuggestions, preferred_day: dayText, duration_minutes: durationMin });
                fetch('/api/schedule/suggest-alternatives', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        group_id: groupIdForSuggestions,
                        preferred_day: dayText,
                        duration_minutes: durationMin
                    })
                }).then(r => {
                    if (!r.ok) {
                        console.error('Suggestions API error:', r.status, r.statusText);
                        throw new Error(`HTTP ${r.status}`);
                    }
                    return r.json();
                }).then(suggestRes => {
                    console.log('Suggestions response:', suggestRes);
                    if (suggestRes.suggestions && suggestRes.suggestions.length > 0) {
                        // Remove existing suggestions row if any
                        let suggestionsRow = row.nextElementSibling;
                        if (suggestionsRow && suggestionsRow.classList.contains('conflict-suggestions-row')) {
                            suggestionsRow.remove();
                        }
                        
                        // Create a new row for suggestions (spans all columns)
                        suggestionsRow = document.createElement('tr');
                        suggestionsRow.className = 'conflict-suggestions-row';
                        const suggestionsCell = document.createElement('td');
                        suggestionsCell.colSpan = 7; // All 7 columns
                        suggestionsCell.className = 'p-2 bg-blue-50 border-t-2 border-blue-300';
                        
                        // Create suggestions panel
                        const suggestionsDiv = document.createElement('div');
                        suggestionsDiv.className = 'conflict-suggestions';
                        suggestionsDiv.innerHTML = `<div class="font-semibold text-blue-800 mb-2 text-sm">💡 Alternative Time Slots Available:</div>`;
                        const list = document.createElement('div');
                        list.className = 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2';
                        suggestRes.suggestions.slice(0, 6).forEach((sug, idx) => {
                            const sugBtn = document.createElement('button');
                            sugBtn.className = 'w-full text-left px-3 py-2 bg-white hover:bg-blue-100 border border-blue-300 rounded text-xs transition-colors shadow-sm';
                            const dayStr = sug.day;
                            const timeStr = convertTo12HourFormat(sug.start_time) + ' - ' + convertTo12HourFormat(sug.end_time);
                            sugBtn.innerHTML = `<div class="font-semibold text-blue-700">${dayStr}</div><div class="text-gray-600">${timeStr}</div><div class="text-gray-500 text-[10px]">Room: ${sug.room_name}</div>`;
                            sugBtn.onclick = () => {
                                // Get original values from data attributes
                                const dayField = row.querySelector('span[data-field="day"]');
                                const timeField = row.querySelector('span[data-field="time"]');
                                const roomField = row.querySelector('span[data-field="room"]');
                                
                                const origDay = dayField?.dataset.original || '';
                                const origStart = timeField?.dataset.originalStart || '';
                                const origEnd = timeField?.dataset.originalEnd || '';
                                const origRoom = roomField?.dataset.original || '';
                                
                                // Apply suggestion
                                if (dayField) {
                                    dayField.textContent = sug.day;
                                    dayField.dataset.original = sug.day; // Update original for future edits
                                }
                                if (timeField) {
                                    timeField.textContent = timeStr;
                                    timeField.dataset.originalStart = sug.start_time;
                                    timeField.dataset.originalEnd = sug.end_time;
                                }
                                if (roomField) {
                                    roomField.textContent = sug.room_name;
                                    roomField.dataset.original = sug.room_name;
                                }
                                
                                // Save immediately with original values
                                const groupIdForUpdate = currentScheduleGroupId || window.currentViewedScheduleId;
                                fetch('/api/schedule/update-by-locator', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                    body: JSON.stringify({
                                        group_id: groupIdForUpdate,
                                        subject_code: subjectCode,
                                        instructor_name: instructorName,
                                        section_code: sectionTitle,
                                        orig_day: origDay,
                                        orig_start_time: origStart,
                                        orig_end_time: origEnd,
                                        new_day: sug.day,
                                        new_start_time: sug.start_time,
                                        new_end_time: sug.end_time,
                                        new_room_name: sug.room_name
                                    })
                                }).then(r=>r.json()).then(res => {
                                    if (res.ok) {
                                        suggestionsRow.remove();
                                        badge.remove();
                                        field.classList.remove('ring-2','ring-red-500','bg-red-50');
                                        row.classList.remove('bg-red-50');
                                        try { notificationManager && notificationManager.show('Suggestion applied successfully', 'success'); } catch(_e) {}
                                    }
                                }).catch(err => {
                                    console.error('Error applying suggestion:', err);
                                });
                            };
                            list.appendChild(sugBtn);
                        });
                        suggestionsDiv.appendChild(list);
                        suggestionsCell.appendChild(suggestionsDiv);
                        suggestionsRow.appendChild(suggestionsCell);
                        
                        // Insert suggestions row right after the conflicting row
                        row.parentNode.insertBefore(suggestionsRow, row.nextSibling);
                    } else {
                        console.log('No suggestions available');
                    }
                }).catch(err => {
                    console.error('Error fetching suggestions:', err);
                });
                
                // Optional toast if notificationManager exists
                try { notificationManager && notificationManager.show(conflicts, 'error'); } catch(_e) {}

                // Force edit mode back ON and reopen the editor for quick correction
                try {
                    if (typeof EditableFields !== 'undefined' && EditableFields.enable) {
                        EditableFields.enable();
                    }
                    if (typeof editModeToggle !== 'undefined' && editModeToggle) {
                        editModeToggle.checked = true;
                        editModeToggle.dispatchEvent(new Event('change'));
                    }
                    if (typeof editModeToggleView !== 'undefined' && editModeToggleView) {
                        editModeToggleView.checked = true;
                        editModeToggleView.dispatchEvent(new Event('change'));
                    }
                    // Reopen the specific editor UI (simulate click)
                    setTimeout(() => { try { field.click(); } catch(_e) {} }, 50);
                } catch(_e) {}
            } else {
                // Auto-save by locator (best-effort)
                const groupIdForSave = currentScheduleGroupId || window.currentViewedScheduleId;
                
                // Ensure we have valid original time before saving
                // Use the values from locate-entry if available, otherwise use initial values
                const saveOrigStart = finalOrigStart || origStart;
                const saveOrigEnd = finalOrigEnd || origEnd;
                const saveOrigDay = finalOrigDay || origDay;
                
                if (!saveOrigStart || !saveOrigEnd || saveOrigStart === '' || saveOrigEnd === '') {
                    console.error('Cannot save: Missing original time', {
                        finalOrigStart: finalOrigStart,
                        finalOrigEnd: finalOrigEnd,
                        origStart: origStart,
                        origEnd: origEnd,
                        timeField: timeField ? {
                            hasDataset: !!timeField.dataset,
                            originalStart: timeField.dataset.originalStart,
                            originalEnd: timeField.dataset.originalEnd
                        } : 'not found'
                    });
                    if (onError) {
                        onError();
                    }
                    return;
                }
                
                // When editing time only, use original day (don't change day)
                // When editing day, use current dayText
                // When editing room, use original day (don't change day)
                const newDay = isEditingDay ? dayText : saveOrigDay;
                
                // Log the data being sent for debugging
                const saveData = {
                        group_id: groupIdForSave,
                        subject_code: subjectCode,
                        instructor_name: instructorName,
                        section_code: sectionTitle,
                    orig_day: saveOrigDay,
                    orig_start_time: saveOrigStart,
                    orig_end_time: saveOrigEnd,
                    new_day: newDay, // Only change day if editing day, otherwise keep original
                        new_start_time: start,
                        new_end_time: end,
                    new_room_name: isEditingRoom ? roomText : null // Only change room if editing room
                };
                console.log('Saving schedule data:', saveData);
                console.log('Save context:', {
                    'field_name': fieldName,
                    'is_editing_day': isEditingDay,
                    'is_editing_time': isEditingTime,
                    'is_editing_room': isEditingRoom,
                    'time_field': timeField ? {
                        'textContent': timeField.textContent,
                        'dataset_originalStart': timeField.dataset.originalStart,
                        'dataset_originalEnd': timeField.dataset.originalEnd
                    } : 'not found',
                    'day_field': dayField ? {
                        'textContent': dayField.textContent,
                        'dataset_original': dayField.dataset.original
                    } : 'not found'
                });
                
                fetch('/api/schedule/update-by-locator', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(saveData)
                }).then(r => {
                    if (!r.ok) {
                        // Try to get error message from response
                        return r.json().then(errData => {
                            console.error('Save failed - Response:', errData);
                            console.error('Save failed - Status:', r.status);
                            console.error('Save failed - Request data:', saveData);
                            if (errData.debug) {
                                console.error('Save failed - Debug info:', errData.debug);
                            }
                            throw new Error(`HTTP ${r.status}: ${errData.message || r.statusText}`);
                        }).catch((parseError) => {
                            console.error('Save failed - Could not parse error response:', parseError);
                            console.error('Save failed - Status:', r.status);
                            console.error('Save failed - Request data:', saveData);
                            throw new Error(`HTTP ${r.status}: ${r.statusText}`);
                        });
                    }
                    return r.json();
                }).then((result) => {
                    console.log('Save response:', result);
                    if (result && result.ok) {
                        if (result.debug) {
                            console.log('Save debug info:', result.debug);
                        }
                        if (onSuccess) {
                            onSuccess();
                        }
                        // Don't show notification here - let the callbacks handle it
                        // This prevents duplicate notifications
                    } else {
                        console.error('Save failed:', result);
                        const errorMsg = result?.message || 'Failed to save changes';
                        if (result?.debug) {
                            console.error('Save debug details:', result.debug);
                        }
                        if (onError) {
                            onError();
                        }
                        // Don't show error notification here - let callbacks handle it
                        // This prevents duplicate notifications
                    }
                }).catch((error) => {
                    console.error('Error saving schedule:', error);
                    if (onError) {
                        onError();
                    }
                    // Don't show error notification here - let callbacks handle it
                    // This prevents duplicate notifications
                });
            }
        }).catch((error) => {
            console.error('Error in updateScheduleData:', error);
            // Still try to save if validation passed
            if (onError) {
                onError();
            }
        });
    } catch (e) {
        // no-op
    }
}

// Tab modal logic
const tabModal = document.getElementById('tab-modal');
const closeTabModal = document.getElementById('close-tab-modal');
const tabModalTitle = document.getElementById('tab-modal-title');
const tabModalContent = document.getElementById('tab-modal-content');
document.getElementById('rooms-tab').onclick = function() {
    tabModalTitle.textContent = 'Rooms';
    tabModalContent.innerHTML = `
        <div class="w-full">
            <!-- Header with Search, Filter, and Add -->
            <div class="mb-3 pb-2 border-b border-gray-200">
                <!-- Title Row -->
                <div class="flex items-center space-x-2 mb-3">
                    <div class="w-6 h-6 bg-gradient-to-br from-[#75975e] to-[#a3c585] rounded-md flex items-center justify-center">
                        <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-gray-800">Room Management</h2>
                    </div>
                </div>
                
                <!-- Search, Filter, Add Row -->
                <div class="flex items-center space-x-2">
                    <!-- Search -->
                    <div class="flex-1 relative">
                        <input type="text" id="room-search" placeholder="Search by room name..." class="w-full px-3 py-1.5 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#a3c585] focus:border-transparent">
                        <svg class="absolute right-2 top-1/2 transform -translate-y-1/2 w-3 h-3 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    
                    <!-- Filter Button -->
                    <button type="button" onclick="toggleFilterPanel()" class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-xs font-medium hover:bg-gray-200 transition-colors border border-gray-300 relative">
                        <svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.207A1 1 0 013 6.5V4z"/>
                        </svg>
                        Filter
                        <span id="filter-count" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-4 h-4 flex items-center justify-center font-bold hidden">0</span>
                    </button>
                    
                    <!-- Add Room Button -->
                    <button type="button" onclick="openAddRoomModal()" class="px-3 py-1.5 bg-gradient-to-r from-[#75975e] to-[#a3c585] text-white rounded-lg text-xs font-medium hover:from-[#a3c585] hover:to-[#75975e] transition-all duration-200 shadow-md hover:shadow-lg transform hover:scale-105">
                        <svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                        </svg>
                        Add
                    </button>
                </div>
                
                <!-- Filter Panel (Hidden by default) -->
                <div id="filter-panel" class="hidden mt-3 p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <div class="grid grid-cols-3 gap-3">
                        <!-- Room Type Filter -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Room Type</label>
                            <select id="room-type-filter" class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-[#a3c585]">
                                <option value="">All Types</option>
                                <option value="lab">Lab</option>
                                <option value="regular">Regular</option>
                            </select>
                        </div>
                        
                        <!-- Building Filter -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Building</label>
                            <select id="building-filter" class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-[#a3c585]">
                                <option value="">All Buildings</option>
                                <option value="hs">HS</option>
                                <option value="shs">SHS</option>
                                <option value="annex">Annex</option>
                            </select>
                        </div>
                        
                        <!-- Capacity Filter -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Capacity</label>
                            <select id="capacity-filter" class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-[#a3c585]">
                                <option value="">All Capacities</option>
                                <option value="0-20">0-20</option>
                                <option value="21-50">21-50</option>
                                <option value="51-100">51-100</option>
                                <option value="100+">100+</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Filter Actions -->
                    <div class="flex items-center justify-end space-x-2 mt-3 pt-2 border-t border-gray-200">
                        <button type="button" onclick="clearFilters()" class="px-2 py-1 text-xs text-gray-600 hover:text-gray-800 transition-colors">
                            Clear
                        </button>
                        <button type="button" onclick="applyFilters()" class="px-3 py-1 text-xs bg-[#a3c585] text-white rounded hover:bg-[#75975e] transition-colors">
                            Apply
                        </button>
                    </div>
                </div>
            </div>

            <!-- Room Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-100 flex flex-col">
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-3 py-2 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <div class="w-5 h-5 bg-gradient-to-br from-[#75975e] to-[#a3c585] rounded-md flex items-center justify-center">
                                <svg class="w-2.5 h-2.5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xs font-bold text-gray-800">All Rooms</h3>
                            </div>
                        </div>
                        <span id="room-count-badge" class="bg-[#a3c585] text-white text-xs font-medium px-2 py-0.5 rounded-full">0</span>
                    </div>
                </div>
                                        <div class="p-2">
                    <div id="rooms-container" class="grid grid-cols-2 auto-rows-min gap-2 relative">
                        <!-- Vertical divider between columns -->
                        <div class="absolute left-1/2 top-0 bottom-0 w-px bg-gray-300 transform -translate-x-1/2"></div>
                        <!-- Loading state -->
                        <div id="rooms-loading" class="col-span-2 flex items-center justify-center py-8">
                            <div class="flex items-center space-x-2">
                                <svg class="w-5 h-5 animate-spin text-[#a3c585]" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                                </svg>
                                <span class="text-gray-600 text-sm">Loading rooms...</span>
                            </div>
                        </div>
                        <!-- Rooms will be dynamically loaded here -->
                    </div>
                </div>
                </div>
                
                <!-- Pagination -->
                <div class="bg-gray-50 px-3 py-2 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-xs text-gray-600">
                            Showing <span id="showing-start">0</span> to <span id="showing-end">0</span> of <span id="total-rooms">0</span> rooms
                        </div>
                        <div class="flex items-center space-x-1">
                            <button id="prev-page" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                Previous
                            </button>
                            <div id="page-buttons-container" class="flex items-center space-x-1">
                                <button class="page-btn px-2 py-1 text-xs bg-[#a3c585] text-white rounded border border-[#a3c585] hover:bg-[#75975e] transition-colors" data-page="1">1</button>
                                <button class="page-btn px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50 transition-colors" data-page="2">2</button>
                            </div>
                            <button id="next-page" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                Next
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    tabModal.classList.remove('hidden');
    
    // Load rooms from API after content is loaded
    setTimeout(() => {
        loadRooms();
    }, 100);
};
document.getElementById('drafts-tab').onclick = async function() {
    tabModalTitle.textContent = 'Drafts';
    tabModalContent.innerHTML = `
        <div class="w-full">
            <!-- Header with Search and Filter -->
            <div class="mb-3 pb-2 border-b border-gray-200">
                <!-- Title Row -->
                <div class="flex items-center space-x-2 mb-3">
                    <div class="w-6 h-6 bg-gradient-to-br from-[#75975e] to-[#a3c585] rounded-md flex items-center justify-center">
                        <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25V6.75A2.25 2.25 0 0017.25 4.5H6.75A2.25 2.25 0 004.5 6.75v10.5A2.25 2.25 0 006.75 19.5h6.75"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 7.5h6m-6 3h6m-6 3h3"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-gray-800">Draft Management</h2>
                    </div>
                </div>
                <!-- Search and Filter Row -->
                <div class="flex items-center space-x-2">
                    <div class="flex-1 relative">
                        <input type="text" id="draft-search" placeholder="Search by draft name..." class="w-full px-3 py-1.5 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#a3c585] focus:border-transparent">
                        <svg class="absolute right-2 top-1/2 transform -translate-y-1/2 w-3 h-3 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    <button type="button" onclick="toggleDraftFilterPanel()" class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-xs font-medium hover:bg-gray-200 transition-colors border border-gray-300 relative">
                        <svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.207A1 1 0 013 6.5V4z"/>
                        </svg>
                        Filter
                        <span id="draft-filter-count" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-4 h-4 flex items-center justify-center font-bold hidden">0</span>
                    </button>
                </div>
                <!-- Filter Panel (Hidden by default) -->
                <div id="draft-filter-panel" class="hidden mt-3 p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Department</label>
                            <select id="draft-department-filter" class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-[#a3c585]">
                                <option value="">All Departments</option>
                                <option value="Computer Science">Computer Science</option>
                                <option value="Information Technology">Information Technology</option>
                                <option value="Business Administration">Business Administration</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Date Range</label>
                            <select id="draft-date-filter" class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-[#a3c585]">
                                <option value="">All Dates</option>
                                <option value="today">Today</option>
                                <option value="week">This Week</option>
                                <option value="month">This Month</option>
                                <option value="year">This Year</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex items-center justify-end space-x-2 mt-3 pt-2 border-t border-gray-200">
                        <button type="button" onclick="clearDraftFilters()" class="px-2 py-1 text-xs text-gray-600 hover:text-gray-800 transition-colors">Clear</button>
                        <button type="button" onclick="applyDraftFilters()" class="px-3 py-1 text-xs bg-[#a3c585] text-white rounded hover:bg-[#75975e] transition-colors">Apply</button>
                    </div>
                </div>
            </div>
            <!-- Drafts Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-3 py-2 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <div class="w-5 h-5 bg-gradient-to-br from-[#75975e] to-[#a3c585] rounded-md flex items-center justify-center">
                                <svg class="w-2.5 h-2.5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25V6.75A2.25 2.25 0 0017.25 4.5H6.75A2.25 2.25 0 004.5 6.75v10.5A2.25 2.25 0 006.75 19.5h6.75"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 7.5h6m-6 3h6m-6 3h3"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xs font-bold text-gray-800">Saved Drafts</h3>
                            </div>
                        </div>
                        <span id="draft-count-badge" class="bg-[#a3c585] text-white text-xs font-medium px-2 py-0.5 rounded-full">0</span>
                    </div>
                </div>
                <div class="bg-gray-50 px-3 py-2 border-b border-gray-200">
                    <div class="grid grid-cols-4 gap-2 text-xs font-bold text-gray-700">
                        <div>Draft Name</div>
                        <div>Department</div>
                        <div>Date</div>
                        <div>Action</div>
                    </div>
                </div>
                <div class="p-2">
                    <div id="drafts-container" class="space-y-1"></div>
                            </div>
                <div class="bg-gray-50 px-3 py-2 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-xs text-gray-600">
                            Showing <span id="draft-showing-start">0</span> to <span id="draft-showing-end">0</span> of <span id="total-drafts">0</span> drafts
                        </div>
                        <div class="flex items-center space-x-1">
                            <button id="draft-prev-page" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">Previous</button>
                            <div class="flex items-center space-x-1" id="draft-page-buttons"></div>
                            <button id="draft-next-page" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">Next</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    tabModal.classList.remove('hidden');
    
    // Fetch drafts from backend and render
    try {
        const response = await fetch('/api/drafts');
        const drafts = await response.json();
        renderDrafts(drafts);
    } catch (error) {
        notificationManager.show('Failed to load drafts.', 'error');
    }

    // Initialize search, filter, and pagination after rendering
    setTimeout(() => {
        initializeDraftPagination();
    }, 100);
};

function renderDrafts(drafts) {
    const container = document.getElementById('drafts-container');
    container.innerHTML = '';
    if (!drafts.length) {
        container.innerHTML = `<div class="text-center text-gray-500 py-8">No drafts found.</div>`;
        document.getElementById('draft-count-badge').textContent = '0';
        document.getElementById('draft-showing-start').textContent = '0';
        document.getElementById('draft-showing-end').textContent = '0';
        document.getElementById('total-drafts').textContent = '0';
        return;
    }
    document.getElementById('draft-count-badge').textContent = drafts.length;
    document.getElementById('total-drafts').textContent = drafts.length;
    drafts.forEach(draft => {
        const group = draft.schedule_group || draft.scheduleGroup;
        const department = group ? (group.department || 'N/A') : 'N/A';
        const draftName = draft.draft_name || `Draft #${draft.draft_id}`;
        // Format date as 'Aug 07, 2025'
        let date = '';
        if (draft.created_at) {
            const d = new Date(draft.created_at);
            date = d.toLocaleString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        }
        const div = document.createElement('div');
        div.className = 'grid grid-cols-4 gap-2 p-2 bg-white rounded-md hover:bg-gray-50 transition-colors border border-gray-100';
        div.innerHTML = `
            <div class="text-xs font-medium text-gray-800">${draftName}</div>
            <div class="text-xs text-gray-600">${department}</div>
            <div class="text-xs text-gray-600">${date}</div>
            <div class="flex space-x-1">
                <button onclick="viewDraft('${draft.draft_id}')" class="px-2 py-1 text-xs bg-[#a3c585] text-white rounded hover:bg-[#75975e] transition-colors">View</button>
                <button onclick="deleteDraft('${draft.draft_id}')" class="px-2 py-1 text-xs bg-red-500 text-white rounded hover:bg-red-600 transition-colors">Delete</button>
            </div>
        `;
        container.appendChild(div);
    });
    // Update showing start/end
    document.getElementById('draft-showing-start').textContent = drafts.length > 0 ? 1 : 0;
    document.getElementById('draft-showing-end').textContent = drafts.length;
}

document.getElementById('reference-tab').onclick = function() {
    tabModalTitle.textContent = 'Reference';
    tabModalContent.innerHTML = `
        <div class="w-full">
            <!-- Header -->
            <div class="mb-6">
            </div>

            <!-- Upload Area -->
            <div class="flex flex-col items-center justify-center">
                <!-- Drop Area (Default State) -->
                <div id="reference-drop-area" class="flex flex-col items-center justify-center border-4 border-dashed border-[#a3c585]/60 rounded-3xl bg-white/90 min-h-[280px] py-12 px-8 transition-all duration-200 hover:border-[#75975e] hover:bg-[#ddead1]/80 cursor-pointer shadow-xl w-full">
                    <!-- Custom Document Upload Icon -->
                    <div class="flex items-center justify-center mb-4 relative">
                        <svg width="64" height="64" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <!-- Document -->
                            <rect x="8" y="8" width="36" height="48" rx="5" fill="#a3c585"/>
                            <!-- Folded corner -->
                            <polygon points="44,8 56,20 44,20" fill="#ddead1"/>
                            <!-- Document lines -->
                            <rect x="16" y="20" width="20" height="2" rx="1" fill="white"/>
                            <rect x="16" y="26" width="20" height="2" rx="1" fill="white"/>
                            <rect x="16" y="32" width="16" height="2" rx="1" fill="white"/>
                            <!-- Upload circle -->
                            <circle cx="50" cy="50" r="12" fill="#75975e" stroke="#fff" stroke-width="2"/>
                            <!-- Upload arrow -->
                            <path d="M50 56V44M50 44l-4 4M50 44l4 4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="text-[#75975e] font-extrabold text-xl mb-2">Upload Basic Education Schedule</div>
                    <div class="text-[#444] font-bold text-lg mb-2">Drag & drop to upload</div>
                    <div class="text-[#75975e] text-sm mb-2">(DOCX files only)</div>
                    <div class="text-[#75975e] text-sm">
                        or <button id="reference-browse-btn" type="button" class="font-bold text-[#75975e] underline hover:text-[#a3c585] focus:outline-none focus:ring-2 focus:ring-[#a3c585] transition">browse</button>
                    </div>
                    <input id="reference-file-elem" type="file" class="hidden" accept=".docx" />
                </div>
                
                <!-- File Review Section (Hidden by default) -->
                <div id="reference-review-section" class="hidden w-full">
                    <div id="reference-file-preview" class="w-full"></div>
                </div>
                
                <!-- Loader (Hidden by default) -->
                <div id="reference-loader" class="hidden flex justify-center items-center mt-6">
                    <div class="loader-con">
                        <div style="--i: 0;" class="pfile"></div>
                        <div style="--i: 1;" class="pfile"></div>
                        <div class="pfile" style="--i: 2;"></div>
                        <div class="pfile" style="--i: 3;"></div>
                        <div class="pfile" style="--i: 4;"></div>
                        <div class="pfile" style="--i: 5;"></div>
                    </div>
                </div>
                
                <!-- Upload Button -->
                <button id="reference-upload-btn" class="mt-6 px-6 py-3 rounded-xl bg-gradient-to-r from-[#75975e] to-[#a3c585] text-white font-bold text-lg shadow-lg transition-all hover:scale-105 hover:from-[#a3c585] hover:to-[#75975e] hidden">
                    Upload Schedule
                </button>
                
                <!-- Error Message -->
                <div id="reference-error-message" class="text-red-600 mt-4 hidden"></div>
            </div>
        </div>
    `;
    tabModal.classList.remove('hidden');
    
    // Initialize reference upload functionality
    initializeReferenceUpload();
};

document.getElementById('history-tab').onclick = function() {
    tabModalTitle.textContent = '';
    tabModalContent.innerHTML = `
        <div class="w-full">
            <!-- Header with Search and Filter -->
            <div class="mb-3 pb-2 border-b border-gray-200">
                <!-- Title Row -->
                <div class="flex items-center space-x-2 mb-3">
                    <div>
                        <h2 class="text-base font-bold text-gray-800"></h2>
                    </div>
                </div>
                
                <!-- Search and Filter Row -->
                <div class="flex items-center space-x-2">
                    <!-- Search -->
                    <div class="flex-1 relative">
                        <input type="text" id="history-search" placeholder="Search by department..." class="w-full px-3 py-1.5 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#a3c585] focus:border-transparent">
                        <svg class="absolute right-2 top-1/2 transform -translate-y-1/2 w-3 h-3 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    
                    <!-- Filter Button -->
                    <button type="button" onclick="toggleHistoryFilterPanel()" class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-xs font-medium hover:bg-gray-200 transition-colors border border-gray-300 relative">
                        <svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.207A1 1 0 013 6.5V4z"/>
                        </svg>
                        Filter
                        <span id="history-filter-count" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-4 h-4 flex items-center justify-center font-bold hidden">0</span>
                    </button>
                </div>
                
                <!-- Filter Panel (Hidden by default) -->
                <div id="history-filter-panel" class="hidden mt-3 p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <div class="grid grid-cols-3 gap-3">
                        <!-- School Year Filter -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">School Year</label>
                            <select id="school-year-filter" class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-[#a3c585]">
                                <option value="">All Years</option>
                                <option value="2024-2025">2024-2025</option>
                                <option value="2023-2024">2023-2024</option>
                                <option value="2022-2023">2022-2023</option>
                            </select>
                        </div>
                        
                        <!-- Year Level Filter -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Year Level</label>
                            <select id="year-level-filter" class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-[#a3c585]">
                                <option value="">All Levels</option>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                            </select>
                        </div>
                        
                        <!-- Semester Filter -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Semester</label>
                            <select id="semester-filter" class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-[#a3c585]">
                                <option value="">All Semesters</option>
                                <option value="1st Semester">1st Semester</option>
                                <option value="2nd Semester">2nd Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Filter Actions -->
                    <div class="flex items-center justify-end space-x-2 mt-3 pt-2 border-t border-gray-200">
                        <button type="button" onclick="clearHistoryFilters()" class="px-2 py-1 text-xs text-gray-600 hover:text-gray-800 transition-colors">
                            Clear
                        </button>
                        <button type="button" onclick="applyHistoryFilters()" class="px-3 py-1 text-xs bg-[#a3c585] text-white rounded hover:bg-[#75975e] transition-colors">
                            Apply
                        </button>
                    </div>
                </div>
            </div>

            <!-- History Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-3 py-2 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <div class="w-5 h-5 bg-gradient-to-br from-[#75975e] to-[#a3c585] rounded-md flex items-center justify-center">
                                <svg class="w-2.5 h-2.5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xs font-bold text-gray-800">Generated Schedules</h3>
                            </div>
                        </div>
                        <span id="history-count" class="bg-[#a3c585] text-white text-xs font-medium px-2 py-0.5 rounded-full">0</span>
                    </div>
                </div>
                
                <!-- Table Header -->
                <div class="bg-gray-50 px-3 py-2 border-b border-gray-200">
                    <div class="grid grid-cols-5 gap-2 text-xs font-bold text-gray-700">
                        <div>Department</div>
                        <div>School Year</div>
                        <div>Semester</div>
                        <div>Date</div>
                        <div>Action</div>
                    </div>
                </div>
                
                <!-- Table Body -->
                <div class="p-2">
                    <div id="history-container" class="space-y-1">
                        <!-- Loading state -->
                        <div id="history-loading" class="text-center py-4">
                            <div class="inline-flex items-center px-4 py-2 text-sm text-gray-500">
                                <svg class="animate-spin -ml-1 mr-3 h-4 w-4 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Loading schedules...
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Pagination -->
                <div class="bg-gray-50 px-3 py-2 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-xs text-gray-600">
                            Showing <span id="history-showing-start">0</span> to <span id="history-showing-end">0</span> of <span id="total-history">0</span> schedules
                        </div>
                        <div class="flex items-center space-x-1">
                            <button id="history-prev-page" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                Previous
                            </button>
                            <div id="history-pagination" class="flex items-center space-x-1">
                                <!-- Pagination buttons will be generated here -->
                            </div>
                            <button id="history-next-page" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                Next
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    tabModal.classList.remove('hidden');
    
    // Load real data from API
    loadHistoryData();
};
closeTabModal.onclick = function() {
    tabModal.classList.add('hidden');
};

// Close modal when clicking outside
tabModal.onclick = function(e) {
    if (e.target === tabModal) {
        tabModal.classList.add('hidden');
    }
};

// Add Room Modal functionality
const addRoomModal = document.getElementById('add-room-modal');
const closeAddRoomModal = document.getElementById('close-add-room-modal');
const cancelAddRoom = document.getElementById('cancel-add-room');
const addRoomForm = document.getElementById('add-room-form');
const roomTypeRadios = document.querySelectorAll('input[name="roomType"]');

// Function to open add room modal
function openAddRoomModal() {
    addRoomModal.classList.remove('hidden');
    // Reset form
    addRoomForm.reset();
    // Set default room type
    document.querySelector('input[value="lab"]').checked = true;
    updateRoomTypeSelection();
}

// Function to close add room modal
function closeAddRoomModalFunc() {
    addRoomModal.classList.add('hidden');
}

// Function to update room type selection styling
function updateRoomTypeSelection() {
    const roomNameSection = document.getElementById('roomNameSection');
    const roomNameInput = document.getElementById('roomName');
    const roomNamePrefix = document.getElementById('roomNamePrefix');
    const buildingSelection = document.getElementById('buildingSelection');
    const floorLevelSelection = document.getElementById('floorLevelSelection');
    const buildingInput = document.getElementById('building');
    
    roomTypeRadios.forEach(radio => {
        const label = radio.closest('label');
        const div = label.querySelector('div');
        if (radio.checked) {
            div.classList.add('border-blue-500', 'bg-blue-50');
            div.classList.remove('border-gray-200');
            
            // Show/hide building selection, floor level selection, and room name based on room type
            if (radio.value === 'lab') {
                buildingSelection.classList.add('hidden');
                floorLevelSelection.classList.add('hidden');
                roomNameSection.classList.remove('hidden');
                roomNamePrefix.classList.remove('hidden');
                roomNamePrefix.textContent = 'LAB |';
                roomNameInput.style.paddingLeft = '4rem';
                roomNameInput.placeholder = 'e.g., 102';
                
                // Automatically set building to HS for LAB rooms
                const buildingInput = document.getElementById('building');
                const buildingDisplay = document.getElementById('buildingDisplay');
                buildingInput.value = 'hs';
                buildingDisplay.textContent = 'HS Building';
                buildingDisplay.className = 'text-gray-800';
                
                // Automatically set floor level to Floor 1 for LAB rooms
                const floorLevelInput = document.getElementById('floorLevel');
                const floorLevelDisplay = document.getElementById('floorLevelDisplay');
                floorLevelInput.value = 'Floor 1';
                floorLevelDisplay.textContent = 'Floor 1';
                floorLevelDisplay.className = 'text-gray-800';
            } else {
                buildingSelection.classList.remove('hidden');
                floorLevelSelection.classList.remove('hidden');
                roomNameSection.classList.add('hidden');
                roomNamePrefix.classList.add('hidden');
                roomNameInput.style.paddingLeft = '0.75rem';
                roomNameInput.placeholder = 'e.g., Lecture Hall A';
                
                // Reset building selection for regular rooms
                const buildingInput = document.getElementById('building');
                const buildingDisplay = document.getElementById('buildingDisplay');
                buildingInput.value = '';
                buildingDisplay.textContent = 'Select a building';
                buildingDisplay.className = 'text-gray-500';
                
                // Reset floor level selection for regular rooms
                const floorLevelInput = document.getElementById('floorLevel');
                const floorLevelDisplay = document.getElementById('floorLevelDisplay');
                floorLevelInput.value = '';
                floorLevelDisplay.textContent = 'Select a floor';
                floorLevelDisplay.className = 'text-gray-500';
            }
        } else {
            div.classList.remove('border-blue-500', 'bg-blue-50');
            div.classList.add('border-gray-200');
        }
    });
}

// Function to update prefix based on building selection
function updateBuildingPrefix() {
    const roomNameSection = document.getElementById('roomNameSection');
    const buildingInput = document.getElementById('building');
    const roomNameInput = document.getElementById('roomName');
    const roomNamePrefix = document.getElementById('roomNamePrefix');
    const selectedRoomType = document.querySelector('input[name="roomType"]:checked');
    
    if (selectedRoomType && selectedRoomType.value === 'regular' && buildingInput.value) {
        roomNameSection.classList.remove('hidden');
        roomNamePrefix.classList.remove('hidden');
        roomNameInput.style.paddingLeft = '4rem';
        
        if (buildingInput.value === 'hs') {
            roomNamePrefix.textContent = 'HS |';
            roomNameInput.placeholder = 'e.g., 101';
        } else if (buildingInput.value === 'shs') {
            roomNamePrefix.textContent = 'SHS |';
            roomNameInput.placeholder = 'e.g., 201';
        } else if (buildingInput.value === 'annex') {
            roomNamePrefix.textContent = 'ANX |';
            roomNameInput.placeholder = 'e.g., 301';
        }
    } else if (selectedRoomType && selectedRoomType.value === 'regular') {
        roomNameSection.classList.add('hidden');
        roomNamePrefix.classList.add('hidden');
        roomNameInput.style.paddingLeft = '0.75rem';
        roomNameInput.placeholder = 'e.g., Lecture Hall A';
    }
}

// Event listeners for add room modal
closeAddRoomModal.onclick = closeAddRoomModalFunc;
cancelAddRoom.onclick = closeAddRoomModalFunc;

// Close modal when clicking outside
addRoomModal.onclick = function(e) {
    if (e.target === addRoomModal) {
        closeAddRoomModalFunc();
    }
};

// Handle room type selection
roomTypeRadios.forEach(radio => {
    radio.onchange = updateRoomTypeSelection;
});

// Handle building selection
const buildingButton = document.getElementById('buildingButton');
const buildingDropdown = document.getElementById('buildingDropdown');
const buildingDisplay = document.getElementById('buildingDisplay');
const buildingInput = document.getElementById('building');
const buildingOptions = document.querySelectorAll('#buildingDropdown button');

// Toggle dropdown
buildingButton.onclick = function(e) {
    e.stopPropagation();
    const isOpen = !buildingDropdown.classList.contains('hidden');
    
    if (isOpen) {
        buildingDropdown.classList.add('hidden');
        buildingButton.querySelector('svg').style.transform = 'rotate(0deg)';
    } else {
        buildingDropdown.classList.remove('hidden');
        buildingButton.querySelector('svg').style.transform = 'rotate(180deg)';
    }
};

// Handle option selection
buildingOptions.forEach(option => {
    option.onclick = function() {
        const value = this.getAttribute('data-value');
        const text = this.textContent;
        
        buildingInput.value = value;
        buildingDisplay.textContent = text;
        buildingDisplay.className = 'text-gray-800';
        
        buildingDropdown.classList.add('hidden');
        buildingButton.querySelector('svg').style.transform = 'rotate(0deg)';
        
        updateBuildingPrefix();
    };
});

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!buildingButton.contains(e.target) && !buildingDropdown.contains(e.target)) {
        buildingDropdown.classList.add('hidden');
        buildingButton.querySelector('svg').style.transform = 'rotate(0deg)';
    }
});

// Handle floor level selection
const floorLevelButton = document.getElementById('floorLevelButton');
const floorLevelDropdown = document.getElementById('floorLevelDropdown');
const floorLevelDisplay = document.getElementById('floorLevelDisplay');
const floorLevelInput = document.getElementById('floorLevel');
const floorLevelOptions = document.querySelectorAll('#floorLevelDropdown button');

// Toggle dropdown
floorLevelButton.onclick = function(e) {
    e.stopPropagation();
    const isOpen = !floorLevelDropdown.classList.contains('hidden');
    
    if (isOpen) {
        floorLevelDropdown.classList.add('hidden');
        floorLevelButton.querySelector('svg').style.transform = 'rotate(0deg)';
    } else {
        floorLevelDropdown.classList.remove('hidden');
        floorLevelButton.querySelector('svg').style.transform = 'rotate(180deg)';
    }
};

// Handle option selection
floorLevelOptions.forEach(option => {
    option.onclick = function() {
        const value = this.dataset.value;
        const text = this.textContent;
        
        floorLevelInput.value = value;
        floorLevelDisplay.textContent = text;
        floorLevelDisplay.className = 'text-gray-800';
        
        floorLevelDropdown.classList.add('hidden');
        floorLevelButton.querySelector('svg').style.transform = 'rotate(0deg)';
    };
});

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!floorLevelButton.contains(e.target) && !floorLevelDropdown.contains(e.target)) {
        floorLevelDropdown.classList.add('hidden');
        floorLevelButton.querySelector('svg').style.transform = 'rotate(0deg)';
    }
});

// Handle form submission
addRoomForm.onsubmit = function(e) {
    e.preventDefault();
    
    const formData = new FormData(addRoomForm);
    const roomType = formData.get('roomType');
    const roomNameInput = formData.get('roomName');
    const building = formData.get('building');
    
    // Add prefix to room name based on type and building
    let roomName;
    if (roomType === 'lab') {
        roomName = `LAB-${roomNameInput}`;
    } else if (roomType === 'regular' && building) {
        if (building === 'hs') {
            roomName = `HS-${roomNameInput}`;
        } else if (building === 'shs') {
            roomName = `SHS-${roomNameInput}`;
        } else if (building === 'annex') {
            roomName = `ANX-${roomNameInput}`;
        } else {
            roomName = roomNameInput;
        }
    } else {
        roomName = roomNameInput;
    }
    
    const roomData = {
        room_name: roomName,
        capacity: parseInt(formData.get('capacity')),
        is_lab: roomType === 'lab',
        building: roomType === 'lab' ? 'hs' : building, // Automatically set building for LAB rooms
        floor_level: roomType === 'lab' ? 'Floor 1' : formData.get('floorLevel') // Automatically set floor level for LAB rooms
    };

    // Validate form
    if (!roomData.room_name || !roomData.capacity) {
        notificationManager.show('Please fill in all required fields', 'error');
        return;
    }
    
    // Additional validation for regular rooms
    if (roomType === 'regular' && !building) {
        notificationManager.show('Please select a building for regular rooms', 'error');
        return;
    }
    
    if (roomType === 'regular' && !formData.get('floorLevel')) {
        notificationManager.show('Please select a floor level for regular rooms', 'error');
        return;
    }

    // Debug: Log the data being sent
    console.log('Sending room data:', roomData);

    // Make API call to store room in database
    fetch('/api/rooms', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify(roomData)
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(errorData => {
                throw new Error(errorData.message || 'Failed to add room');
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.room_id) {
            notificationManager.show('Room added successfully!', 'success');
            closeAddRoomModalFunc();
            // Refresh room list if loadRooms function exists
            if (typeof loadRooms === 'function') {
                loadRooms();
            }
        } else {
            notificationManager.show(data.message || 'Failed to add room', 'error');
        }
    })
    .catch(error => {
        console.error('Error adding room:', error);
        notificationManager.show('An error occurred while adding the room', 'error');
    });
};

// Animation for fade-in
const style = document.createElement('style');
style.innerHTML = `
    @keyframes fadeIn { 
        from { opacity: 0; transform: translateY(10px);} 
        to { opacity: 1; transform: none;} 
    } 
    .animate-fadeIn { animation: fadeIn 0.5s ease; }
    
    /* Custom dropdown styling */
    select#building {
        background-image: none !important;
    }
    
    /* Remove default dropdown styling */
    select#building::-ms-expand {
        display: none;
    }
    
    /* Style the dropdown list container */
    select#building:focus {
        outline: none;
    }
    
    /* Target the dropdown options list */
    select#building option {
        background-color: white !important;
        color: #374151 !important;
        padding: 8px 12px !important;
        border: none !important;
        border-radius: 8px !important;
        margin: 2px !important;
    }
    
    select#building option:hover {
        background-color: #f3f4f6 !important;
    }
    
    select#building option:checked {
        background-color: #d1fae5 !important;
        color: #065f46 !important;
        font-weight: 500 !important;
    }
    
    select#building:focus option:checked {
        background-color: #a3c585 !important;
        color: white !important;
    }
    
    /* Remove the dropdown list border */
    select#building:focus {
        border-color: #a3c585 !important;
    }
    
    /* Additional styling to remove browser defaults */
    select#building {
        -webkit-appearance: none !important;
        -moz-appearance: none !important;
        appearance: none !important;
    }
`;
document.head.appendChild(style);

// Pagination functionality
let currentPage = 1;
            const roomsPerPage = 12;

// Room management functions
let allRoomsData = [];

// Load rooms from API
async function loadRooms() {
    try {
        console.log('Loading rooms from API...');
        const response = await fetch('/api/rooms/all');
        console.log('Load rooms response status:', response.status);
        
        if (!response.ok) {
            const errorText = await response.text();
            console.error('Load rooms API Error:', errorText);
            throw new Error(`HTTP ${response.status}: ${errorText}`);
        }
        
        const rooms = await response.json();
        console.log('Rooms loaded:', rooms);
        allRoomsData = rooms;
        hideLoadingState();
        
        // Initialize pagination after rooms are loaded
        initializePagination();
        
        // Show first page of rooms
        const totalPages = Math.ceil(rooms.length / roomsPerPage);
        showPage(1, rooms, totalPages);
    } catch (error) {
        console.error('Error loading rooms:', error);
        showErrorState(`Failed to load rooms: ${error.message}`);
    }
}

// Render rooms in the container
function renderRooms(rooms) {
    const container = document.getElementById('rooms-container');
    const divider = container.querySelector('.absolute'); // Keep the vertical divider
    
    // Clear existing rooms but keep the divider and loading state
    const existingRooms = container.querySelectorAll('div:not(.absolute):not(#rooms-loading)');
    existingRooms.forEach(room => room.remove());
    
    // Hide loading state
    const loadingState = document.getElementById('rooms-loading');
    if (loadingState) {
        loadingState.style.display = 'none';
    }
    
    if (rooms.length === 0) {
        const noRoomsDiv = document.createElement('div');
        noRoomsDiv.className = 'col-span-2 flex items-center justify-center py-8';
        noRoomsDiv.innerHTML = `
            <div class="text-center">
                <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
                <p class="text-gray-600 text-sm">No rooms found</p>
            </div>
        `;
        container.appendChild(noRoomsDiv);
        return;
    }
    
    // Group rooms by type
    const labRooms = rooms.filter(room => room.is_lab);
    const regularRooms = rooms.filter(room => !room.is_lab);
    
    // Render lab rooms
    labRooms.forEach(room => {
        const roomElement = createRoomElement(room, 'lab');
        container.appendChild(roomElement);
    });
    
    // Render regular rooms
    regularRooms.forEach(room => {
        const roomElement = createRoomElement(room, 'regular');
        container.appendChild(roomElement);
    });
    
    // Update room count badge
    const roomCountBadge = document.getElementById('room-count-badge');
    if (roomCountBadge) {
        roomCountBadge.textContent = rooms.length;
    }
    
    // Note: Pagination info is updated in showPage function, not here
    // This function only renders the rooms for the current page
}

// Create individual room element with hover actions
function createRoomElement(room, type) {
    const roomDiv = document.createElement('div');
    roomDiv.className = 'room-item flex items-center justify-between p-2 bg-gray-50 rounded-md hover:bg-gray-100 transition-colors cursor-pointer group';
    roomDiv.dataset.roomId = room.room_id;
    
    const iconSvg = type === 'lab' ? 
        `<path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>` :
        `<path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>`;
    
    // Format room name and get building name
    const roomName = room.room_name.replace('-', ''); // Remove dash from COL-209 -> COL209
    const buildingName = roomName.startsWith('COL') ? 'College Building' : 
                        roomName.startsWith('ANX') ? 'Annex Building' : 
                        roomName.startsWith('LAB') ? 'College Building' : 'Building';
    
    roomDiv.innerHTML = `
        <div class="flex items-center space-x-3">
            <div class="w-4 h-4 bg-gray-400 rounded-full flex items-center justify-center">
                <svg class="w-2 h-2 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    ${iconSvg}
                </svg>
            </div>
            <div class="flex items-center space-x-2">
                <h4 class="font-medium text-gray-800 text-xs">${roomName}</h4>
                <span class="text-gray-500 text-xs">•</span>
                <p class="text-gray-500 text-xs">${buildingName}</p>
            </div>
        </div>
        <div class="text-right">
            <span class="text-xs font-bold text-gray-800">${room.capacity}</span>
            <p class="text-gray-500 text-xs">cap</p>
        </div>
        <div class="room-actions-overlay">
            <button class="room-action-btn view" onclick="viewRoom(${room.room_id})" title="View Details">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
            </button>
            <button class="room-action-btn edit" onclick="editRoom(${room.room_id})" title="Edit Room">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
            </button>
            <button class="room-action-btn delete" onclick="deleteRoom(${room.room_id})" title="Delete Room">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </button>
        </div>
    `;
    
    return roomDiv;
}

// Hide loading state
function hideLoadingState() {
    const loadingState = document.getElementById('rooms-loading');
    if (loadingState) {
        loadingState.style.display = 'none';
    }
}

// Show error state
function showErrorState(message) {
    const container = document.getElementById('rooms-container');
    const loadingState = document.getElementById('rooms-loading');
    if (loadingState) {
        loadingState.style.display = 'none';
    }
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'col-span-2 flex items-center justify-center py-8';
    errorDiv.innerHTML = `
        <div class="text-center">
            <svg class="w-12 h-12 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-red-600 text-sm">${message}</p>
            <button onclick="loadRooms()" class="mt-2 px-3 py-1 bg-blue-500 text-white text-xs rounded hover:bg-blue-600 transition-colors">
                Retry
            </button>
        </div>
    `;
    container.appendChild(errorDiv);
}

// Room action functions
async function viewRoom(roomId) {
    const room = allRoomsData.find(r => r.room_id === roomId);
    if (!room) return;
    
    try {
        console.log('Fetching room details for ID:', roomId);
        // Fetch room details with schedules
        const response = await fetch(`/api/rooms/${roomId}`);
        console.log('Response status:', response.status);
        
        if (!response.ok) {
            const errorText = await response.text();
            console.error('API Error:', errorText);
            throw new Error(`HTTP ${response.status}: ${errorText}`);
        }
        
        const roomData = await response.json();
        console.log('Room data received:', roomData);
        
        // Populate view modal
        document.getElementById('view-room-name').textContent = roomData.room_name;
        document.getElementById('view-room-id').textContent = `REG-${roomData.room_id.toString().padStart(3, '0')}`;
        document.getElementById('view-room-capacity').textContent = roomData.capacity;
        document.getElementById('view-room-type').textContent = roomData.is_lab ? 'Lab Room' : 'Regular Room';
        
        // Display schedules
        const schedulesContainer = document.getElementById('view-room-schedules');
        if (roomData.schedules && roomData.schedules.length > 0) {
            schedulesContainer.innerHTML = roomData.schedules.map(schedule => `
                <div class="bg-white border border-gray-200 rounded-lg p-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="font-medium text-gray-800">${schedule.subject || 'N/A'}</h4>
                            <p class="text-sm text-gray-600">${schedule.instructor_name || 'N/A'}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-gray-800">${schedule.day || 'N/A'}</p>
                            <p class="text-xs text-gray-600">${schedule.start_time || 'N/A'} - ${schedule.end_time || 'N/A'}</p>
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            schedulesContainer.innerHTML = `
                <div class="text-center py-8">
                    <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <p class="text-gray-600">No schedules associated with this room</p>
                </div>
            `;
        }
        
        // Show modal
        document.getElementById('view-room-modal').classList.remove('hidden');
        
    } catch (error) {
        console.error('Error fetching room details:', error);
        notificationManager.show(`Failed to load room details: ${error.message}`, 'error');
    }
}

// Function to update edit room type selection styling and UI
function updateEditRoomTypeSelection() {
    const editRoomTypeRadios = document.querySelectorAll('input[name="edit-room-type"]');
    const editBuildingSelection = document.getElementById('editBuildingSelection');
    const editRoomNameSection = document.getElementById('editRoomNameSection');
    const editRoomNameInput = document.getElementById('edit-room-name');
    const editRoomNamePrefix = document.getElementById('editRoomNamePrefix');
    
    editRoomTypeRadios.forEach(radio => {
        const div = radio.nextElementSibling;
        if (radio.checked) {
            // Remove all styling first
            editRoomTypeRadios.forEach(r => {
                r.nextElementSibling.classList.remove('border-blue-500', 'bg-blue-50', 'border-green-500', 'bg-green-50');
            });
            
            // Show/hide building selection and room name based on room type
            if (radio.value === 'lab') {
                editBuildingSelection.classList.add('hidden');
                editRoomNameSection.classList.remove('hidden');
                editRoomNamePrefix.classList.remove('hidden');
                editRoomNamePrefix.textContent = 'LAB |';
                editRoomNameInput.style.paddingLeft = '4rem';
                editRoomNameInput.placeholder = 'e.g., 102';
                
                // Automatically set building to college for LAB rooms
                const editBuildingInput = document.getElementById('editBuilding');
                const editBuildingDisplay = document.getElementById('editBuildingDisplay');
                editBuildingInput.value = 'college';
                editBuildingDisplay.textContent = 'College Building';
                editBuildingDisplay.className = 'text-gray-800';
            } else {
                editBuildingSelection.classList.remove('hidden');
                editRoomNameSection.classList.add('hidden');
                editRoomNamePrefix.classList.add('hidden');
                editRoomNameInput.style.paddingLeft = '0.75rem';
                editRoomNameInput.placeholder = 'e.g., Lecture Hall A';
                
                // Reset building selection for regular rooms
                const editBuildingInput = document.getElementById('editBuilding');
                const editBuildingDisplay = document.getElementById('editBuildingDisplay');
                editBuildingInput.value = '';
                editBuildingDisplay.textContent = 'Select a building';
                editBuildingDisplay.className = 'text-gray-500';
            }
            
            // Add styling to selected radio
            if (radio.value === 'lab') {
                div.classList.add('border-blue-500', 'bg-blue-50');
            } else {
                div.classList.add('border-green-500', 'bg-green-50');
            }
        } else {
            div.classList.remove('border-blue-500', 'bg-blue-50', 'border-green-500', 'bg-green-50');
        }
    });
}

// Function to update edit building prefix
function updateEditBuildingPrefix() {
    const editRoomNameSection = document.getElementById('editRoomNameSection');
    const editBuildingInput = document.getElementById('editBuilding');
    const editRoomNameInput = document.getElementById('edit-room-name');
    const editRoomNamePrefix = document.getElementById('editRoomNamePrefix');
    const selectedRoomType = document.querySelector('input[name="edit-room-type"]:checked');
    
    if (selectedRoomType && selectedRoomType.value === 'regular' && editBuildingInput.value) {
        editRoomNameSection.classList.remove('hidden');
        editRoomNamePrefix.classList.remove('hidden');
        editRoomNameInput.style.paddingLeft = '4rem';
        
        if (editBuildingInput.value === 'college') {
            editRoomNamePrefix.textContent = 'COL |';
            editRoomNameInput.placeholder = 'e.g., 101';
        } else if (editBuildingInput.value === 'annex') {
            editRoomNamePrefix.textContent = 'ANX |';
            editRoomNameInput.placeholder = 'e.g., 201';
        }
    } else if (selectedRoomType && selectedRoomType.value === 'regular') {
        editRoomNameSection.classList.add('hidden');
        editRoomNamePrefix.classList.add('hidden');
        editRoomNameInput.style.paddingLeft = '0.75rem';
        editRoomNameInput.placeholder = 'e.g., Lecture Hall A';
    }
}

async function editRoom(roomId) {
    const room = allRoomsData.find(r => r.room_id === roomId);
    if (!room) return;
    
    try {
        console.log('Fetching room details for editing ID:', roomId);
        // Fetch room details for editing
        const response = await fetch(`/api/rooms/${roomId}`);
        console.log('Response status:', response.status);
        
        if (!response.ok) {
            const errorText = await response.text();
            console.error('API Error:', errorText);
            throw new Error(`HTTP ${response.status}: ${errorText}`);
        }
        
        const roomData = await response.json();
        console.log('Room data for editing:', roomData);
        
        // Populate edit form
        document.getElementById('edit-room-id').value = roomData.room_id;
        document.getElementById('edit-room-capacity').value = roomData.capacity;
        
        // Store original room name for success message
        document.getElementById('edit-room-id').dataset.originalName = roomData.room_name;
        
        // Update the modal subtitle with the room name
        document.getElementById('edit-room-subtitle').textContent = `Update ${roomData.room_name}`;
        
        // Parse room name to determine type and building
        const roomName = roomData.room_name;
        let roomType, building, roomNameWithoutPrefix;
        
        if (roomName.startsWith('LAB-')) {
            roomType = 'lab';
            building = 'college';
            roomNameWithoutPrefix = roomName.replace('LAB-', '');
        } else if (roomName.startsWith('COL-')) {
            roomType = 'regular';
            building = 'college';
            roomNameWithoutPrefix = roomName.replace('COL-', '');
        } else if (roomName.startsWith('ANX-')) {
            roomType = 'regular';
            building = 'annex';
            roomNameWithoutPrefix = roomName.replace('ANX-', '');
        } else {
            roomType = 'regular';
            building = '';
            roomNameWithoutPrefix = roomName;
        }
        
        // Set room type radio button
        const labRadio = document.querySelector('input[name="edit-room-type"][value="lab"]');
        const regularRadio = document.querySelector('input[name="edit-room-type"][value="regular"]');
        
        if (roomType === 'lab') {
            labRadio.checked = true;
            labRadio.nextElementSibling.classList.add('border-blue-500', 'bg-blue-50');
            regularRadio.nextElementSibling.classList.remove('border-green-500', 'bg-green-50');
        } else {
            regularRadio.checked = true;
            regularRadio.nextElementSibling.classList.add('border-green-500', 'bg-green-50');
            labRadio.nextElementSibling.classList.remove('border-blue-500', 'bg-blue-50');
        }
        
        // Set building selection
        const editBuildingInput = document.getElementById('editBuilding');
        const editBuildingDisplay = document.getElementById('editBuildingDisplay');
        
        if (building) {
            editBuildingInput.value = building;
            editBuildingDisplay.textContent = building === 'hs' ? 'HS Building' : building === 'shs' ? 'SHS Building' : 'Annex Building';
            editBuildingDisplay.className = 'text-gray-800';
        } else {
            editBuildingInput.value = '';
            editBuildingDisplay.textContent = 'Select a building';
            editBuildingDisplay.className = 'text-gray-500';
        }
        
        // Set room name without prefix
        document.getElementById('edit-room-name').value = roomNameWithoutPrefix;
        
        // Update UI based on room type
        updateEditRoomTypeSelection();
        
        // Show modal
        document.getElementById('edit-room-modal').classList.remove('hidden');
        
    } catch (error) {
        console.error('Error fetching room details for editing:', error);
        notificationManager.show(`Failed to load room details for editing: ${error.message}`, 'error');
    }
}

async function deleteRoom(roomId) {
    const room = allRoomsData.find(r => r.room_id === roomId);
    if (!room) return;
    
    // Populate delete confirmation modal
    document.getElementById('delete-room-name').textContent = room.room_name;
    document.getElementById('delete-room-modal').classList.remove('hidden');
    
    // Store room ID for confirmation
    document.getElementById('delete-room-modal').dataset.roomId = roomId;
}

// Filter and search state
let filteredRooms = [];
let searchTerm = '';
let activeFilters = {
    roomType: '',
    building: '',
    capacity: ''
};

function initializePagination() {
    const totalPages = Math.ceil(allRoomsData.length / roomsPerPage);

    // Initialize pagination
    updatePageButtons(totalPages);

    // Event listeners for pagination
    document.getElementById('prev-page').addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            const filteredRooms = getVisibleRooms();
            const totalPages = Math.ceil(filteredRooms.length / roomsPerPage);
            showPage(currentPage, filteredRooms, totalPages);
        }
    });

    document.getElementById('next-page').addEventListener('click', () => {
        const filteredRooms = getVisibleRooms();
        const totalPages = Math.ceil(filteredRooms.length / roomsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            showPage(currentPage, filteredRooms, totalPages);
        }
    });

    // Initialize search and filter functionality
    initializeSearchAndFilters();
}

// Show specific page of rooms
function showPage(page, roomsToShow, totalPages) {
    const startIndex = (page - 1) * roomsPerPage;
    const endIndex = startIndex + roomsPerPage;
    
    // Get the rooms to display for this page
    const pageRooms = roomsToShow.slice(startIndex, endIndex);
    
    // Re-render only the rooms for this page
    renderRooms(pageRooms);
    
    // Update pagination info using helper function
    const start = roomsToShow.length > 0 ? startIndex + 1 : 0;
    const end = Math.min(endIndex, roomsToShow.length);
    updatePaginationInfo(start, end, roomsToShow.length);
    
    // Update page buttons
    updatePageButtons(totalPages);
    
    // Update navigation buttons
    document.getElementById('prev-page').disabled = page === 1;
    document.getElementById('next-page').disabled = page === totalPages;
}

// Helper function to update pagination info display
function updatePaginationInfo(start, end, total) {
    const showingStart = document.getElementById('showing-start');
    const showingEnd = document.getElementById('showing-end');
    const totalRooms = document.getElementById('total-rooms');
    
    if (showingStart && showingEnd && totalRooms) {
        showingStart.textContent = start.toString();
        showingEnd.textContent = end.toString();
        totalRooms.textContent = total.toString();
    }
}

// Helper function to get currently visible rooms
function getVisibleRooms() {
    // Filter rooms based on search term and filters
    const filteredRooms = allRoomsData.filter(room => {
        const roomName = room.room_name.toLowerCase();
        const formattedRoomName = roomName.replace('-', ''); // Remove dash for search
        const roomId = room.room_id.toString();
        const capacity = room.capacity;
        const isLab = room.is_lab;
        
        // Check search term (works with both original and formatted room names)
        const matchesSearch = searchTerm === '' || 
                            roomName.includes(searchTerm) || 
                            formattedRoomName.includes(searchTerm);
        
        // Check room type filter
        let matchesRoomType = true;
        if (activeFilters.roomType) {
            if (activeFilters.roomType === 'lab') {
                matchesRoomType = isLab;
            } else if (activeFilters.roomType === 'regular') {
                matchesRoomType = !isLab;
            }
        }
        
        // Check building filter
        let matchesBuilding = true;
        if (activeFilters.building) {
            if (activeFilters.building === 'hs') {
                matchesBuilding = roomName.startsWith('hs');
            } else if (activeFilters.building === 'shs') {
                matchesBuilding = roomName.startsWith('shs');
            } else if (activeFilters.building === 'annex') {
                matchesBuilding = roomName.startsWith('anx');
            }
        }
        
        // Check capacity filter
        let matchesCapacity = true;
        if (activeFilters.capacity) {
            const [min, max] = activeFilters.capacity.split('-').map(Number);
            if (activeFilters.capacity === '100+') {
                matchesCapacity = capacity >= 100;
            } else if (max) {
                matchesCapacity = capacity >= min && capacity <= max;
            } else {
                matchesCapacity = capacity >= min;
            }
        }
        
        return matchesSearch && matchesRoomType && matchesBuilding && matchesCapacity;
    });
    
    return filteredRooms;
}

// Function to update filter count indicator
function updateFilterCount() {
    const filterCountElement = document.getElementById('filter-count');
    let activeFilterCount = 0;
    
    // Count active filters (excluding search)
    if (activeFilters.roomType) activeFilterCount++;
    if (activeFilters.building) activeFilterCount++;
    if (activeFilters.capacity) activeFilterCount++;
    
    // Update the count display
    if (activeFilterCount > 0) {
        filterCountElement.textContent = activeFilterCount;
        filterCountElement.classList.remove('hidden');
    } else {
        filterCountElement.classList.add('hidden');
    }
}

// Search and Filter Functions
function initializeSearchAndFilters() {
    // Search functionality
    const searchInput = document.getElementById('room-search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            searchTerm = this.value.toLowerCase();
            applySearchAndFilters();
            updateFilterCount();
        });
    }
}

function toggleFilterPanel() {
    const filterPanel = document.getElementById('filter-panel');
    filterPanel.classList.toggle('hidden');
}

function clearFilters() {
    // Reset filter dropdowns
    document.getElementById('room-type-filter').value = '';
    document.getElementById('building-filter').value = '';
    document.getElementById('capacity-filter').value = '';
    
    // Reset search
    document.getElementById('room-search').value = '';
    
    // Clear state
    searchTerm = '';
    activeFilters = {
        roomType: '',
        building: '',
        capacity: ''
    };
    
    // Update room count badge to show total count
    const roomCountBadge = document.getElementById('room-count-badge');
    if (roomCountBadge) {
        roomCountBadge.textContent = allRoomsData.length;
    }
    
    // Reset pagination and show first page of all rooms
    const totalPages = Math.ceil(allRoomsData.length / roomsPerPage);
    currentPage = 1;
    
    // Update page buttons
    updatePageButtons(totalPages);
    
    // Show the first page using pagination
    if (allRoomsData.length > 0) {
        showPage(1, allRoomsData, totalPages);
    } else {
        // Show empty state
        renderRooms([]);
        updatePaginationInfo(0, 0, 0);
    }
    
    // Update navigation buttons
    document.getElementById('prev-page').disabled = true;
    document.getElementById('next-page').disabled = totalPages <= 1;
    
    // Update filter count
    updateFilterCount();
}

function applyFilters() {
    activeFilters.roomType = document.getElementById('room-type-filter').value;
    activeFilters.building = document.getElementById('building-filter').value;
    activeFilters.capacity = document.getElementById('capacity-filter').value;
    
    applySearchAndFilters();
    
    // Hide filter panel after applying
    document.getElementById('filter-panel').classList.add('hidden');
    
    // Update filter count
    updateFilterCount();
}

function applySearchAndFilters() {
    const filteredRooms = getVisibleRooms();
    
    // Update room count badge to show filtered count
    const roomCountBadge = document.getElementById('room-count-badge');
    if (roomCountBadge) {
        roomCountBadge.textContent = filteredRooms.length;
    }
    
    // Update pagination for filtered results
    const totalPages = Math.ceil(filteredRooms.length / roomsPerPage);
    currentPage = 1; // Reset to first page when filtering
    
    // Update page buttons
    updatePageButtons(totalPages);
    
    // Show the first page of filtered results using pagination
    if (filteredRooms.length > 0) {
        showPage(1, filteredRooms, totalPages);
    } else {
        // Show empty state
        renderRooms([]);
        updatePaginationInfo(0, 0, 0);
    }
    
    // Update navigation buttons
    document.getElementById('prev-page').disabled = true;
    document.getElementById('next-page').disabled = totalPages <= 1;
}

function updatePageButtons(totalPages) {
    const pageButtonsContainer = document.getElementById('page-buttons-container');
    if (pageButtonsContainer) {
        // Clear existing page buttons
        const existingButtons = pageButtonsContainer.querySelectorAll('.page-btn');
        existingButtons.forEach(btn => btn.remove());
        
        // Create new page buttons
        for (let i = 1; i <= totalPages; i++) {
            const button = document.createElement('button');
            button.className = `page-btn px-2 py-1 text-xs rounded border transition-colors ${i === currentPage ? 'bg-[#a3c585] text-white border-[#a3c585]' : 'bg-white border-gray-300 hover:bg-gray-50'}`;
            button.setAttribute('data-page', i);
            button.textContent = i;
            button.addEventListener('click', () => {
                currentPage = i;
                const filteredRooms = getVisibleRooms();
                showPage(i, filteredRooms, totalPages);
            });
            pageButtonsContainer.appendChild(button);
        }
    }
    
    // Update navigation buttons
    document.getElementById('prev-page').disabled = currentPage === 1;
    document.getElementById('next-page').disabled = currentPage === totalPages;
}

// History functionality
let currentHistoryPage = 1;
const historyPerPage = 8;
let historySearchTerm = '';
let historyActiveFilters = {
    schoolYear: '',
    yearLevel: '',
    semester: ''
};

function initializeHistoryPagination() {
    const allHistoryItems = document.querySelectorAll('#history-container > div');
    const totalHistoryItems = allHistoryItems.length;
    const totalHistoryPages = Math.ceil(totalHistoryItems / historyPerPage);

    // Initialize history pagination
    showHistoryPage(1, allHistoryItems, totalHistoryPages);

    // Event listeners for history pagination
    document.getElementById('history-prev-page').addEventListener('click', () => {
        if (currentHistoryPage > 1) {
            const visibleHistoryItems = getVisibleHistoryItems();
            const totalHistoryPages = Math.ceil(visibleHistoryItems.length / historyPerPage);
            showHistoryPage(currentHistoryPage - 1, visibleHistoryItems, totalHistoryPages);
        }
    });

    document.getElementById('history-next-page').addEventListener('click', () => {
        const visibleHistoryItems = getVisibleHistoryItems();
        const totalHistoryPages = Math.ceil(visibleHistoryItems.length / historyPerPage);
        if (currentHistoryPage < totalHistoryPages) {
            showHistoryPage(currentHistoryPage + 1, visibleHistoryItems, totalHistoryPages);
        }
    });

    // Initialize history search and filter functionality
    initializeHistorySearchAndFilters();
}

function showHistoryPage(page, visibleHistoryItems, totalHistoryPages) {
    const startIndex = (page - 1) * historyPerPage;
    const endIndex = startIndex + historyPerPage;
    
    // Hide all history items first
    const allHistoryItems = document.querySelectorAll('#history-container > div');
    allHistoryItems.forEach(item => {
        item.style.display = 'none';
    });
    
    // Show only history items for current page
    visibleHistoryItems.forEach((item, index) => {
        if (index >= startIndex && index < endIndex) {
            item.style.display = 'grid';
        }
    });
    
    // Update pagination info
    document.getElementById('history-showing-start').textContent = visibleHistoryItems.length > 0 ? startIndex + 1 : 0;
    document.getElementById('history-showing-end').textContent = Math.min(endIndex, visibleHistoryItems.length);
    document.getElementById('total-history').textContent = visibleHistoryItems.length;
    
    // Update page buttons
    updateHistoryPageButtons(totalHistoryPages);
    
    // Update navigation buttons
    document.getElementById('history-prev-page').disabled = page === 1;
    document.getElementById('history-next-page').disabled = page === totalHistoryPages;
    
    currentHistoryPage = page;
}

function getVisibleHistoryItems() {
    const allHistoryItems = document.querySelectorAll('#history-container > div');
    const visibleHistoryItems = [];
    
    allHistoryItems.forEach(item => {
        const department = item.querySelector('div:nth-child(1)').textContent.toLowerCase();
        const schoolYear = item.querySelector('div:nth-child(2)').textContent;
        const yearLevelSemester = item.querySelector('div:nth-child(3)').textContent;
        
        // Check search term
        const matchesSearch = historySearchTerm === '' || department.includes(historySearchTerm);
        
        // Check school year filter
        let matchesSchoolYear = true;
        if (historyActiveFilters.schoolYear) {
            matchesSchoolYear = schoolYear === historyActiveFilters.schoolYear;
        }
        
        // Check year level filter
        let matchesYearLevel = true;
        if (historyActiveFilters.yearLevel) {
            matchesYearLevel = yearLevelSemester.includes(historyActiveFilters.yearLevel);
        }
        
        // Check semester filter
        let matchesSemester = true;
        if (historyActiveFilters.semester) {
            matchesSemester = yearLevelSemester.includes(historyActiveFilters.semester);
        }
        
        if (matchesSearch && matchesSchoolYear && matchesYearLevel && matchesSemester) {
            visibleHistoryItems.push(item);
        }
    });
    
    return visibleHistoryItems;
}

function updateHistoryFilterCount() {
    const filterCountElement = document.getElementById('history-filter-count');
    let activeFilterCount = 0;
    
    // Count active filters (excluding search)
    if (historyActiveFilters.schoolYear) activeFilterCount++;
    if (historyActiveFilters.yearLevel) activeFilterCount++;
    if (historyActiveFilters.semester) activeFilterCount++;
    
    // Update the count display
    if (activeFilterCount > 0) {
        filterCountElement.textContent = activeFilterCount;
        filterCountElement.classList.remove('hidden');
    } else {
        filterCountElement.classList.add('hidden');
    }
}

function initializeHistorySearchAndFilters() {
    // Search functionality
    const searchInput = document.getElementById('history-search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            historySearchTerm = this.value.toLowerCase();
            applyHistorySearchAndFilters();
            updateHistoryFilterCount();
        });
    }
}

function toggleHistoryFilterPanel() {
    const filterPanel = document.getElementById('history-filter-panel');
    filterPanel.classList.toggle('hidden');
}

function clearHistoryFilters() {
    // Reset filter dropdowns
    document.getElementById('school-year-filter').value = '';
    document.getElementById('year-level-filter').value = '';
    document.getElementById('semester-filter').value = '';
    
    // Reset search
    document.getElementById('history-search').value = '';
    
    // Clear state
    historySearchTerm = '';
    historyActiveFilters = {
        schoolYear: '',
        yearLevel: '',
        semester: ''
    };
    
    // Get all history items and reset pagination
    const allHistoryItems = document.querySelectorAll('#history-container > div');
    const totalHistoryPages = Math.ceil(allHistoryItems.length / historyPerPage);
    
    // Use history pagination to show first page
    showHistoryPage(1, allHistoryItems, totalHistoryPages);
    
    // Update filter count
    updateHistoryFilterCount();
}

function applyHistoryFilters() {
    historyActiveFilters.schoolYear = document.getElementById('school-year-filter').value;
    historyActiveFilters.yearLevel = document.getElementById('year-level-filter').value;
    historyActiveFilters.semester = document.getElementById('semester-filter').value;
    
    applyHistorySearchAndFilters();
    
    // Hide filter panel after applying
    document.getElementById('history-filter-panel').classList.add('hidden');
    
    // Update filter count
    updateHistoryFilterCount();
}

function applyHistorySearchAndFilters() {
    const visibleHistoryItems = getVisibleHistoryItems();
    const totalHistoryPages = Math.ceil(visibleHistoryItems.length / historyPerPage);
    
    // Use history pagination to show first page of filtered results
    showHistoryPage(1, visibleHistoryItems, totalHistoryPages);
}

function updateHistoryPageButtons(totalHistoryPages) {
    const pageButtonsContainer = document.querySelector('#history-container').parentElement.parentElement.querySelector('.flex.items-center.space-x-1');
    if (pageButtonsContainer) {
        // Clear existing page buttons
        const existingButtons = pageButtonsContainer.querySelectorAll('.history-page-btn');
        existingButtons.forEach(btn => btn.remove());
        
        // Create new page buttons
        for (let i = 1; i <= totalHistoryPages; i++) {
            const button = document.createElement('button');
            button.className = `history-page-btn px-2 py-1 text-xs rounded border transition-colors ${i === 1 ? 'bg-[#a3c585] text-white border-[#a3c585]' : 'bg-white border-gray-300 hover:bg-gray-50'}`;
            button.setAttribute('data-page', i);
            button.textContent = i;
            button.addEventListener('click', () => {
                const visibleHistoryItems = getVisibleHistoryItems();
                showHistoryPage(i, visibleHistoryItems, totalHistoryPages);
            });
            pageButtonsContainer.appendChild(button);
        }
    }
    
    // Update navigation buttons
    document.getElementById('history-prev-page').disabled = currentHistoryPage === 1;
    document.getElementById('history-next-page').disabled = currentHistoryPage === totalHistoryPages;
}

// Load history data from API
function loadHistoryData() {
    const historyContainer = document.getElementById('history-container');
    const historyLoading = document.getElementById('history-loading');
    const historyCount = document.getElementById('history-count');
    
    // Show loading state
    historyLoading.style.display = 'block';
    historyContainer.innerHTML = `
        <div id="history-loading" class="text-center py-4">
            <div class="inline-flex items-center px-4 py-2 text-sm text-gray-500">
                <svg class="animate-spin -ml-1 mr-3 h-4 w-4 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Loading schedules...
            </div>
        </div>
    `;
    
    // Fetch data from API
    fetch('/api/history')
        .then(response => response.json())
        .then(data => {
            console.log('History data received:', data);
            
            // Hide loading state
            historyLoading.style.display = 'none';
            
            // Update count
            historyCount.textContent = data.total || 0;
            
            // Display data
            if (data.data && data.data.length > 0) {
                displayHistoryData(data.data);
            } else {
                historyContainer.innerHTML = `
                    <div class="text-center py-8 text-gray-500">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <p class="mt-2 text-sm">No schedules found</p>
                    </div>
                `;
            }
            
            // Update pagination info
            updateHistoryPaginationInfo(data.data ? data.data.length : 0, data.total || 0);
        })
        .catch(error => {
            console.error('Error loading history data:', error);
            historyLoading.style.display = 'none';
            historyContainer.innerHTML = `
                <div class="text-center py-8 text-red-500">
                    <svg class="mx-auto h-12 w-12 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <p class="mt-2 text-sm">Error loading schedules</p>
                </div>
            `;
        });
}

// Display history data in the table
function displayHistoryData(schedules) {
    const historyContainer = document.getElementById('history-container');
    
    historyContainer.innerHTML = schedules.map(schedule => `
        <div class="grid grid-cols-5 gap-2 p-2 bg-white rounded-md hover:bg-gray-50 transition-colors border border-gray-100">
            <div class="text-xs font-medium text-gray-800">${schedule.department}</div>
            <div class="text-xs text-gray-600">${schedule.school_year}</div>
            <div class="text-xs text-gray-600">${schedule.semester}</div>
            <div class="text-xs text-gray-600">${schedule.date}</div>
            <div>
                <button onclick="viewSchedule(${schedule.group_id})" class="px-2 py-1 text-xs bg-[#a3c585] text-white rounded hover:bg-[#75975e] transition-colors">
                    View
                </button>
            </div>
        </div>
    `).join('');
}

// Update pagination information
function updateHistoryPaginationInfo(displayed, total) {
    document.getElementById('history-showing-start').textContent = displayed > 0 ? 1 : 0;
    document.getElementById('history-showing-end').textContent = displayed;
    document.getElementById('total-history').textContent = total;
}

function viewSchedule(scheduleId) {
    // Close the history modal first
    tabModal.classList.add('hidden');
    
    // Set the current viewed schedule ID for export functionality
    currentViewedScheduleId = scheduleId;
    window.currentViewedScheduleId = scheduleId; // Also set on window for updateScheduleData
    
    // Show the schedule view modal
    scheduleViewModal.classList.remove('hidden');
    
    // Load schedule data based on the ID
    loadScheduleData(scheduleId);
}

function loadScheduleData(groupId) {
    // Show loading state
    const scheduleContent = document.getElementById('schedule-content');
    scheduleContent.innerHTML = `
        <div class="text-center py-8">
            <div class="inline-flex items-center px-4 py-2 text-sm text-gray-500">
                <svg class="animate-spin -ml-1 mr-3 h-4 w-4 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Loading schedule...
            </div>
        </div>
    `;
    
    // Fetch schedule data from API using group_id
    fetch(`/api/schedule/get-by-group?group_id=${groupId}`)
        .then(response => response.json())
        .then(data => {
            console.log('Schedule data received:', data);
            
            if (data.success && data.data) {
                displayScheduleView(data.data, data.department);
            } else {
                // Show error message
                scheduleContent.innerHTML = `
                    <div class="text-center py-8 text-red-500">
                        <svg class="mx-auto h-12 w-12 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                        </svg>
                        <p class="mt-2 text-sm">Schedule not found</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading schedule data:', error);
            scheduleContent.innerHTML = `
                <div class="text-center py-8 text-red-500">
                    <svg class="mx-auto h-12 w-12 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <p class="mt-2 text-sm">Error loading schedule</p>
                </div>
            `;
        });
}

// Display schedule view with real data
function displayScheduleView(scheduleData, department) {
    // Debug: Log the schedule data structure
    console.log('displayScheduleView - Schedule data received:', scheduleData);
    console.log('displayScheduleView - Department received:', department);
    
    // Debug: Log first schedule entry to see structure
    if (scheduleData && typeof scheduleData === 'object') {
        const firstKey = Object.keys(scheduleData)[0];
        if (firstKey && scheduleData[firstKey] && scheduleData[firstKey].length > 0) {
            console.log('displayScheduleView - First schedule entry:', scheduleData[firstKey][0]);
            console.log('displayScheduleView - First entry employment_type:', scheduleData[firstKey][0].employment_type);
            console.log('displayScheduleView - First entry instructor_name:', scheduleData[firstKey][0].instructor_name);
        }
    }
    
    // Update header information
    const scheduleDepartment = document.getElementById('schedule-department');
    const scheduleSemester = document.getElementById('schedule-semester');
    const scheduleDate = document.getElementById('schedule-date');
    const scheduleContent = document.getElementById('schedule-content');
    
    scheduleDepartment.textContent = department.toUpperCase();
    scheduleSemester.textContent = 'Second SEM., S.Y 2024-2025';
    scheduleDate.textContent = 'AS OF JANUARY 2025 (REVISION: 3)';

    // Helper: aggregate joint sessions by identical time window
    function aggregateJointSessions(rows) {
        const dayOrder = { Mon:1, Tue:2, Wed:3, Thu:4, Fri:5, Sat:6 };
        // Normalize time to HH:MM to make equal-times merge even if seconds differ
        const toHm = (t) => {
            if (!t) return '';
            const parts = String(t).split(':');
            return parts[0].padStart(2,'0') + ':' + parts[1].padStart(2,'0');
        };
        // Normalize comparable strings (trim, lowercase, collapse spaces)
        const norm = (s) => (s || '')
            .toString()
            .trim()
            .replace(/\s+/g,' ')
            .toLowerCase();
        // Merge by subject + instructor + year level + TIME ONLY (exclude block)
        // so identical time windows within a section aggregate their days
        const keyOf = (r) => [
            norm(r.subject_code),
            norm(r.instructor_name),
            norm(r.year_level),
            toHm(r.start_time || ''),
            toHm(r.end_time || '')
        ].join('|');
        const grouped = {};
        for (const r of rows) {
            const k = keyOf(r);
            if (!grouped[k]) {
                grouped[k] = { ...r, __days: new Set(), __rooms: [], meeting_count: 0 };
            }
            const day = (r.day || '').trim();
            if (day) grouped[k].__days.add(day);
            if (r.room_name) grouped[k].__rooms.push(r.room_name);
            grouped[k].meeting_count = (grouped[k].meeting_count || 0) + 1;
        }
        const merged = [];
        for (const g of Object.values(grouped)) {
            const days = Array.from(g.__days);
            days.sort((a,b) => (dayOrder[a]||99) - (dayOrder[b]||99));
            const dayLabel = days.join(''); // e.g., MonSat
            const room = g.__rooms && g.__rooms.length ? g.__rooms[0] : (g.room_name || 'TBA');
            merged.push({
                subject_code: g.subject_code,
                subject_description: g.subject_description,
                units: g.units,
                instructor_name: g.instructor_name,
                day: dayLabel,
                start_time: toHm(g.start_time),
                end_time: toHm(g.end_time),
                time_range: (g.start_time ? convertTo12HourFormat(g.start_time) : '') + (g.end_time ? '–' + convertTo12HourFormat(g.end_time) : ''),
                room_name: room,
                year_level: g.year_level,
                block: g.block,
                department: g.department,
                employment_type: g.employment_type,
                meeting_count: g.meeting_count
            });
        }
        return merged;
    }

    // Generate schedule tables
    scheduleContent.innerHTML = '';
    
    // Process the API data structure - grouped by year level and block
    // Sort section keys by year level first, then by block (A, B, C)
    const sortedSectionKeys = Object.keys(scheduleData).sort((a, b) => {
        // Extract year level (1st, 2nd, 3rd, etc.) and block (A, B, C, etc.)
        const yearLevelA = a.match(/(\d+)(?:st|nd|rd|th)\s+Year/i)?.[1] || '0';
        const yearLevelB = b.match(/(\d+)(?:st|nd|rd|th)\s+Year/i)?.[1] || '0';
        
        // Compare year levels first
        if (yearLevelA !== yearLevelB) {
            return parseInt(yearLevelA) - parseInt(yearLevelB);
        }
        
        // If same year level, compare blocks (A, B, C)
        const blockA = a.match(/\s+([A-Z])$/)?.[1] || '';
        const blockB = b.match(/\s+([A-Z])$/)?.[1] || '';
        
        return blockA.localeCompare(blockB);
    });
    
    sortedSectionKeys.forEach((yearLevelBlock, sectionIndex) => {
        // Aggregate joint sessions within this section
        const schedulesRaw = scheduleData[yearLevelBlock];
        const schedules = aggregateJointSessions(Array.isArray(schedulesRaw) ? schedulesRaw : []);
        if (schedules.length === 0) return;
        
        const sectionDiv = document.createElement('div');
        sectionDiv.className = 'mb-4';
        
        // Section title
        const titleDiv = document.createElement('div');
        titleDiv.className = 'bg-green-800 text-white font-bold text-center py-1 px-3 rounded-t-lg text-sm';
        titleDiv.textContent = yearLevelBlock;
        sectionDiv.appendChild(titleDiv);
        
        // Table
        const table = document.createElement('table');
        table.className = 'w-full border-collapse border border-gray-300';
        table.style.tableLayout = 'fixed';
        
        // Table header
        const thead = document.createElement('thead');
        thead.innerHTML = `
            <tr class="bg-gray-100">
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 12%;">Course Code</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 36%;">Course Description</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 7%;">Units</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 19%;">Instructor</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 10%;">Day</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 14%;">Time</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-bold text-gray-700" style="width: 8%;">Room</th>
            </tr>
        `;
        table.appendChild(thead);
        
        // Table body
        const tbody = document.createElement('tbody');
        schedules.forEach((schedule, index) => {
            const row = document.createElement('tr');
            // Debug: Log schedule data to see what we're receiving
            console.log('Schedule data:', schedule);
            console.log('Employment type:', schedule.employment_type);
            
            // Apply yellow background for part-time instructors, otherwise use alternating colors
            // Check both employment_type field and specific instructor names as fallback
            const isPartTime = schedule.employment_type === 'PART-TIME' || 
                              schedule.employment_type === 'part-time' ||
                              schedule.instructor_name === 'Ronee D. Quicho, MBA' ||
                              schedule.instructor_name === 'Lucia L. Torres' ||
                              schedule.instructor_name === 'Johmar V. Dagondon, LPT, DM' ||
                              schedule.instructor_name === 'Lhengen Josol' ||
                              schedule.instructor_name === 'Alfon Aisa';
            
            // Check if this is a lab session
            const isLabSession = schedule.is_lab === true || schedule.is_lab === '1';
            
            // Apply background colors: yellow for part-time, light green for lab sessions, alternating for others
            if (isPartTime) {
                row.className = 'bg-yellow-100';
                console.log('Applied yellow background for part-time instructor:', schedule.instructor_name, 'employment_type:', schedule.employment_type);
            } else if (isLabSession) {
                row.className = 'bg-green-50';
                console.log('Applied green background for lab session:', schedule.room_name);
            } else {
                row.className = (index % 2 === 0) ? 'bg-white' : 'bg-gray-50';
            }
            
            row.innerHTML = `
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-700 truncate">${schedule.subject_code || ''}</td>
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-700 truncate">${schedule.subject_description || ''}</td>
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-700 text-center">${schedule.units ?? ''}</td>
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-700 truncate">${schedule.instructor_name || ''}</td>
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-700 text-center">
                    <span class="editable-field font-medium text-blue-600" data-section="${sectionIndex}" data-index="${index}" data-field="day" data-field-type="dropdown" data-original="${schedule.day || ''}">${schedule.day || ''}</span>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-700 text-center" style="white-space: nowrap;">
                    <span class="editable-field" data-section="${sectionIndex}" data-index="${index}" data-field="time" data-field-type="progressive" data-original-start="${schedule.start_time || ''}" data-original-end="${schedule.end_time || ''}">
                        ${schedule.time_range ? schedule.time_range : ((schedule.start_time ? convertTo12HourFormat(schedule.start_time) : '') + (schedule.end_time ? ' - ' + convertTo12HourFormat(schedule.end_time) : ''))}
                    </span>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-xs text-gray-800 text-center">
                    <span class="editable-field" data-section="${sectionIndex}" data-index="${index}" data-field="room" data-field-type="dropdown" data-original="${schedule.room_name || 'TBA'}">${schedule.room_name || 'TBA'}</span>
                </td>
            `;
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        sectionDiv.appendChild(table);
        scheduleContent.appendChild(sectionDiv);
    });

    // Ensure edit helpers are available and apply current edit state
    (function ensureEditableHelpers(cb){
        if (window.EditableFields) { cb(); return; }
        var s = document.createElement('script');
        s.src = '/JS/editableFields.js';
        s.onload = cb;
        document.head.appendChild(s);
    })(function(){
        try { window.EditableFields && window.EditableFields.refresh(); } catch(e) {}
    });
}

// Draft functionality
let currentDraftPage = 1;
const draftsPerPage = 8;
let draftSearchTerm = '';
let draftActiveFilters = {
    department: '',
    dateRange: ''
};

function initializeDraftPagination() {
    const allDraftItems = document.querySelectorAll('#drafts-container > div');
    const totalDraftItems = allDraftItems.length;
    const totalDraftPages = Math.ceil(totalDraftItems / draftsPerPage);

    // Initialize draft pagination
    showDraftPage(1, allDraftItems, totalDraftPages);

    // Event listeners for draft pagination
    document.getElementById('draft-prev-page').addEventListener('click', () => {
        if (currentDraftPage > 1) {
            const visibleDraftItems = getVisibleDraftItems();
            const totalDraftPages = Math.ceil(visibleDraftItems.length / draftsPerPage);
            showDraftPage(currentDraftPage - 1, visibleDraftItems, totalDraftPages);
        }
    });

    document.getElementById('draft-next-page').addEventListener('click', () => {
        const visibleDraftItems = getVisibleDraftItems();
        const totalDraftPages = Math.ceil(visibleDraftItems.length / draftsPerPage);
        if (currentDraftPage < totalDraftPages) {
            showDraftPage(currentDraftPage + 1, visibleDraftItems, totalDraftPages);
        }
    });

    // Initialize draft search and filter functionality
    initializeDraftSearchAndFilters();
}

function showDraftPage(page, visibleDraftItems, totalDraftPages) {
    const startIndex = (page - 1) * draftsPerPage;
    const endIndex = startIndex + draftsPerPage;
    
    // Hide all draft items first
    const allDraftItems = document.querySelectorAll('#drafts-container > div');
    allDraftItems.forEach(item => {
        item.style.display = 'none';
    });
    
    // Show only draft items for current page
    visibleDraftItems.forEach((item, index) => {
        if (index >= startIndex && index < endIndex) {
            item.style.display = 'grid';
        }
    });
    
    // Update pagination info
    document.getElementById('draft-showing-start').textContent = visibleDraftItems.length > 0 ? startIndex + 1 : 0;
    document.getElementById('draft-showing-end').textContent = Math.min(endIndex, visibleDraftItems.length);
    document.getElementById('total-drafts').textContent = visibleDraftItems.length;
    
    // Update page buttons
    updateDraftPageButtons(totalDraftPages);
    
    // Update navigation buttons
    document.getElementById('draft-prev-page').disabled = page === 1;
    document.getElementById('draft-next-page').disabled = page === totalDraftPages;
    
    currentDraftPage = page;
}

function getVisibleDraftItems() {
    const allDraftItems = document.querySelectorAll('#drafts-container > div');
    const visibleDraftItems = [];
    
    allDraftItems.forEach(item => {
        const draftName = item.querySelector('div:nth-child(1)').textContent.toLowerCase();
        const department = item.querySelector('div:nth-child(2)').textContent;
        const date = item.querySelector('div:nth-child(3)').textContent;
        
        // Check search term
        const matchesSearch = draftSearchTerm === '' || draftName.includes(draftSearchTerm);
        
        // Check department filter
        let matchesDepartment = true;
        if (draftActiveFilters.department) {
            matchesDepartment = department === draftActiveFilters.department;
        }
        
        // Check date range filter
        let matchesDateRange = true;
        if (draftActiveFilters.dateRange) {
            const draftDate = new Date(date);
            const today = new Date();
            const diffTime = Math.abs(today - draftDate);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            
            switch (draftActiveFilters.dateRange) {
                case 'today':
                    matchesDateRange = diffDays === 0;
                    break;
                case 'week':
                    matchesDateRange = diffDays <= 7;
                    break;
                case 'month':
                    matchesDateRange = diffDays <= 30;
                    break;
                case 'year':
                    matchesDateRange = diffDays <= 365;
                    break;
            }
        }
        
        if (matchesSearch && matchesDepartment && matchesDateRange) {
            visibleDraftItems.push(item);
        }
    });
    
    return visibleDraftItems;
}

function updateDraftFilterCount() {
    const filterCountElement = document.getElementById('draft-filter-count');
    let activeFilterCount = 0;
    
    // Count active filters (excluding search)
    if (draftActiveFilters.department) activeFilterCount++;
    if (draftActiveFilters.dateRange) activeFilterCount++;
    
    // Update the count display
    if (activeFilterCount > 0) {
        filterCountElement.textContent = activeFilterCount;
        filterCountElement.classList.remove('hidden');
    } else {
        filterCountElement.classList.add('hidden');
    }
}

function initializeDraftSearchAndFilters() {
    // Search functionality
    const searchInput = document.getElementById('draft-search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            draftSearchTerm = this.value.toLowerCase();
            applyDraftSearchAndFilters();
            updateDraftFilterCount();
        });
    }
}

function toggleDraftFilterPanel() {
    const filterPanel = document.getElementById('draft-filter-panel');
    filterPanel.classList.toggle('hidden');
}

function clearDraftFilters() {
    // Reset filter dropdowns
    document.getElementById('draft-department-filter').value = '';
    document.getElementById('draft-date-filter').value = '';
    
    // Reset search
    document.getElementById('draft-search').value = '';
    
    // Clear state
    draftSearchTerm = '';
    draftActiveFilters = {
        department: '',
        dateRange: ''
    };
    
    // Get all draft items and reset pagination
    const allDraftItems = document.querySelectorAll('#drafts-container > div');
    const totalDraftPages = Math.ceil(allDraftItems.length / draftsPerPage);
    
    // Use draft pagination to show first page
    showDraftPage(1, allDraftItems, totalDraftPages);
    
    // Update filter count
    updateDraftFilterCount();
}

function applyDraftFilters() {
    draftActiveFilters.department = document.getElementById('draft-department-filter').value;
    draftActiveFilters.dateRange = document.getElementById('draft-date-filter').value;
    
    applyDraftSearchAndFilters();
    
    // Hide filter panel after applying
    document.getElementById('draft-filter-panel').classList.add('hidden');
    
    // Update filter count
    updateDraftFilterCount();
}

function applyDraftSearchAndFilters() {
    const visibleDraftItems = getVisibleDraftItems();
    const totalDraftPages = Math.ceil(visibleDraftItems.length / draftsPerPage);
    
    // Use draft pagination to show first page of filtered results
    showDraftPage(1, visibleDraftItems, totalDraftPages);
}

function updateDraftPageButtons(totalDraftPages) {
    const pageButtonsContainer = document.querySelector('#drafts-container').parentElement.parentElement.querySelector('.flex.items-center.space-x-1');
    if (pageButtonsContainer) {
        // Clear existing page buttons
        const existingButtons = pageButtonsContainer.querySelectorAll('.draft-page-btn');
        existingButtons.forEach(btn => btn.remove());
        
        // Create new page buttons
        for (let i = 1; i <= totalDraftPages; i++) {
            const button = document.createElement('button');
            button.className = `draft-page-btn px-2 py-1 text-xs rounded border transition-colors ${i === 1 ? 'bg-[#a3c585] text-white border-[#a3c585]' : 'bg-white border-gray-300 hover:bg-gray-50'}`;
            button.setAttribute('data-page', i);
            button.textContent = i;
            button.addEventListener('click', () => {
                const visibleDraftItems = getVisibleDraftItems();
                showDraftPage(i, visibleDraftItems, totalDraftPages);
            });
            pageButtonsContainer.appendChild(button);
        }
    }
    
    // Update navigation buttons
    document.getElementById('draft-prev-page').disabled = currentDraftPage === 1;
    document.getElementById('draft-next-page').disabled = currentDraftPage === totalDraftPages;
}

function viewDraft(draftId) {
    // Close the drafts modal first
    tabModal.classList.add('hidden');
    
    // Set the current viewed schedule ID for export functionality
    // For drafts, we need to get the group_id from the draft data
    currentViewedScheduleId = draftId; // This will be updated when we load real draft data
    window.currentViewedScheduleId = draftId; // Also set on window for updateScheduleData
    
    // Show the schedule view modal
    scheduleViewModal.classList.remove('hidden');
    
    // Load draft data based on the ID
    loadDraftData(draftId);
}

function loadDraftData(draftId) {
    // Show loading state
    const scheduleContent = document.getElementById('schedule-content');
    scheduleContent.innerHTML = `
        <div class="text-center py-8">
            <div class="inline-flex items-center px-4 py-2 text-sm text-gray-500">
                <svg class="animate-spin -ml-1 mr-3 h-4 w-4 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Loading draft...
            </div>
        </div>
    `;
    
    // Fetch draft data from API
    fetch(`/api/drafts/${draftId}`)
        .then(response => response.json())
        .then(data => {
            console.log('Draft data received:', data);
            
            if (data.success && data.groupedSchedules) {
                // Update the current viewed schedule ID for export functionality
                currentViewedScheduleId = data.scheduleGroup.group_id;
                window.currentViewedScheduleId = data.scheduleGroup.group_id; // Also set on window for updateScheduleData

    // Update header information
                const scheduleDepartment = document.getElementById('schedule-department');
                const scheduleSemester = document.getElementById('schedule-semester');
                const scheduleDate = document.getElementById('schedule-date');
                
                scheduleDepartment.textContent = (data.department || 'General').toUpperCase();
                scheduleSemester.textContent = `${data.scheduleGroup.semester ?? 'N/A'} Semester, S.Y ${data.scheduleGroup.school_year ?? 'N/A'}`;
                scheduleDate.textContent = 'AS OF JANUARY 2025 (REVISION: 3)';
                
                // Display the schedule using the same function as regular schedules
                displayScheduleView(data.groupedSchedules, data.department || 'General');
            } else {
                // Show error message
                scheduleContent.innerHTML = `
                    <div class="text-center py-8 text-red-500">
                        <svg class="mx-auto h-12 w-12 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                        </svg>
                        <p class="mt-2 text-sm">Draft not found</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading draft data:', error);
            scheduleContent.innerHTML = `
                <div class="text-center py-8 text-red-500">
                    <svg class="mx-auto h-12 w-12 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <p class="mt-2 text-sm">Error loading draft</p>
                </div>
            `;
    });
}

function deleteDraft(draftId) {
    // Store the draft ID for deletion
    window.draftToDelete = draftId;
    
    // Show the delete confirmation modal
    const deleteModal = document.getElementById('delete-confirmation-modal');
    deleteModal.classList.remove('hidden');
}

// Delete confirmation modal event listeners
document.addEventListener('DOMContentLoaded', function() {
    const deleteModal = document.getElementById('delete-confirmation-modal');
    const cancelDelete = document.getElementById('cancel-delete');
    const confirmDelete = document.getElementById('confirm-delete');

    // Cancel button
    cancelDelete.addEventListener('click', function() {
        deleteModal.classList.add('hidden');
        window.draftToDelete = null;
    });

    // Confirm delete button
    confirmDelete.addEventListener('click', function() {
        const draftId = window.draftToDelete;
        if (draftId) {
            // Hide the modal first
            deleteModal.classList.add('hidden');
            window.draftToDelete = null;
            
            // Show loading notification
            const loadingNotification = notificationManager.show('Deleting draft...', 'info', 0);
            
            // In a real application, this would send a request to the backend to delete the draft
            console.log('Deleting draft:', draftId);
            
            // Simulate API call
            setTimeout(() => {
                // Remove loading notification
                notificationManager.remove(loadingNotification);
                
                // Remove the draft item from the DOM
                const draftItem = document.querySelector(`[onclick="deleteDraft('${draftId}')"]`).closest('.grid');
                if (draftItem) {
                    draftItem.remove();
                    
                    // Update the count
                    const countElement = document.querySelector('.bg-\\[\\#a3c585\\] .text-xs');
                    if (countElement) {
                        const currentCount = parseInt(countElement.textContent);
                        countElement.textContent = currentCount - 1;
                    }
                    
                    // Reinitialize pagination
                    setTimeout(() => {
                        initializeDraftPagination();
                    }, 100);
                    
                    // Show success message
                    notificationManager.show('Draft deleted successfully!', 'success');
                } else {
                    // Show error if draft item not found
                    notificationManager.show('Failed to delete draft', 'error');
                }
                
                // In real implementation, you would make an API call here:
                // fetch(`/api/drafts/${draftId}`, {
                //     method: 'DELETE',
                //     headers: {
                //         'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                //     }
                // })
                // .then(response => response.json())
                // .then(data => {
                //     notificationManager.remove(loadingNotification);
                //     if (data.success) {
                //         // Remove from DOM and show success
                //         notificationManager.show('Draft deleted successfully!', 'success');
                //     } else {
                //         notificationManager.show(data.message || 'Failed to delete draft', 'error');
                //     }
                // })
                // .catch(error => {
                //     notificationManager.remove(loadingNotification);
                //     notificationManager.show('An error occurred while deleting the draft', 'error');
                // });
            }, 1000);
        }
    });

    // Close modal when clicking outside
    deleteModal.addEventListener('click', function(e) {
        if (e.target === deleteModal) {
            deleteModal.classList.add('hidden');
            window.draftToDelete = null;
        }
    });
});

function showSuccessMessage(message) {
    // Use the notification system instead
    notificationManager.show(message, 'success');
}

// Room modal event handlers
document.addEventListener('DOMContentLoaded', function() {
    // View Room Modal
    const viewRoomModal = document.getElementById('view-room-modal');
    const closeViewRoomModal = document.getElementById('close-view-room-modal');
    const closeViewRoomBtn = document.getElementById('close-view-room-btn');

    closeViewRoomModal.addEventListener('click', () => {
        viewRoomModal.classList.add('hidden');
    });

    closeViewRoomBtn.addEventListener('click', () => {
        viewRoomModal.classList.add('hidden');
    });

    viewRoomModal.addEventListener('click', (e) => {
        if (e.target === viewRoomModal) {
            viewRoomModal.classList.add('hidden');
        }
    });

    // Edit Room Modal
    const editRoomModal = document.getElementById('edit-room-modal');
    const closeEditRoomModal = document.getElementById('close-edit-room-modal');
    const cancelEditRoom = document.getElementById('cancel-edit-room');
    const editRoomForm = document.getElementById('edit-room-form');

    closeEditRoomModal.addEventListener('click', () => {
        editRoomModal.classList.add('hidden');
    });

    cancelEditRoom.addEventListener('click', () => {
        editRoomModal.classList.add('hidden');
    });

    editRoomModal.addEventListener('click', (e) => {
        if (e.target === editRoomModal) {
            editRoomModal.classList.add('hidden');
        }
    });

    // Edit form submission
    editRoomForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const formData = new FormData(editRoomForm);
        const roomId = formData.get('room_id');
        const roomNameInput = formData.get('room_name');
        const capacity = formData.get('capacity');
        const roomType = document.querySelector('input[name="edit-room-type"]:checked').value;
        const building = document.getElementById('editBuilding').value;
        
        // Add prefix to room name based on type and building
        let roomName;
        if (roomType === 'lab') {
            roomName = `LAB-${roomNameInput}`;
        } else if (roomType === 'regular' && building) {
            if (building === 'hs') {
                roomName = `HS-${roomNameInput}`;
            } else if (building === 'shs') {
                roomName = `SHS-${roomNameInput}`;
            } else if (building === 'annex') {
                roomName = `ANX-${roomNameInput}`;
            } else {
                roomName = roomNameInput;
            }
        } else {
            roomName = roomNameInput;
        }
        
        try {
            const response = await fetch(`/api/rooms/${roomId}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                },
                body: JSON.stringify({
                    room_name: roomName,
                    capacity: parseInt(capacity),
                    is_lab: roomType === 'lab'
                })
            });
            
            if (response.ok) {
                const updatedRoom = await response.json();
                // Get the original room name from the stored data
                const originalRoomName = document.getElementById('edit-room-id').dataset.originalName;
                notificationManager.show(`Room "${originalRoomName}" updated successfully`, 'success');
                editRoomModal.classList.add('hidden');
                loadRooms(); // Reload the room list
            } else {
                const error = await response.json();
                notificationManager.show(error.message || 'Failed to update room', 'error');
            }
        } catch (error) {
            console.error('Error updating room:', error);
            notificationManager.show('Failed to update room', 'error');
        }
    });

    // Room type radio button styling for edit modal
    const editRoomTypeRadios = document.querySelectorAll('input[name="edit-room-type"]');
    editRoomTypeRadios.forEach(radio => {
        radio.addEventListener('change', updateEditRoomTypeSelection);
    });

    // Handle edit building selection
    const editBuildingButton = document.getElementById('editBuildingButton');
    const editBuildingDropdown = document.getElementById('editBuildingDropdown');
    const editBuildingDisplay = document.getElementById('editBuildingDisplay');
    const editBuildingInput = document.getElementById('editBuilding');
    const editBuildingOptions = document.querySelectorAll('#editBuildingDropdown button');

    // Toggle dropdown
    editBuildingButton.onclick = function(e) {
        e.stopPropagation();
        const isOpen = !editBuildingDropdown.classList.contains('hidden');
        
        if (isOpen) {
            editBuildingDropdown.classList.add('hidden');
            editBuildingButton.querySelector('svg').style.transform = 'rotate(0deg)';
        } else {
            editBuildingDropdown.classList.remove('hidden');
            editBuildingButton.querySelector('svg').style.transform = 'rotate(180deg)';
        }
    };

    // Handle option selection
    editBuildingOptions.forEach(option => {
        option.onclick = function() {
            const value = this.getAttribute('data-value');
            const text = this.textContent;
            
            editBuildingInput.value = value;
            editBuildingDisplay.textContent = text;
            editBuildingDisplay.className = 'text-gray-800';
            
            editBuildingDropdown.classList.add('hidden');
            editBuildingButton.querySelector('svg').style.transform = 'rotate(0deg)';
            
            updateEditBuildingPrefix();
        };
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!editBuildingButton.contains(e.target) && !editBuildingDropdown.contains(e.target)) {
            editBuildingDropdown.classList.add('hidden');
            editBuildingButton.querySelector('svg').style.transform = 'rotate(0deg)';
        }
    });

    // Delete Room Modal
    const deleteRoomModal = document.getElementById('delete-room-modal');
    const cancelDeleteRoom = document.getElementById('cancel-delete-room');
    const confirmDeleteRoom = document.getElementById('confirm-delete-room');

    cancelDeleteRoom.addEventListener('click', () => {
        deleteRoomModal.classList.add('hidden');
    });

    confirmDeleteRoom.addEventListener('click', async () => {
        const roomId = deleteRoomModal.dataset.roomId;
        if (!roomId) return;
        
        try {
            const response = await fetch(`/api/rooms/${roomId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                }
            });
            
            if (response.ok) {
                const result = await response.json();
                notificationManager.show(result.message || 'Room deleted successfully', 'error');
                deleteRoomModal.classList.add('hidden');
                loadRooms(); // Reload the room list
            } else {
                const error = await response.json();
                notificationManager.show(error.message || 'Failed to delete room', 'error');
            }
        } catch (error) {
            console.error('Error deleting room:', error);
            notificationManager.show('Failed to delete room', 'error');
        }
    });

    deleteRoomModal.addEventListener('click', (e) => {
        if (e.target === deleteRoomModal) {
            deleteRoomModal.classList.add('hidden');
        }
    });
});

// =======================
// FILTER PREFERENCES MODAL
// =======================

// Global variables for filter preferences
let currentInstructorData = [];
let selectedInstructors = new Set(); // Track selected instructors
let filterPreferences = {
    instructors: {},
    timePreferences: {
        morning: false,
        afternoon: false,
        evening: false
    }
};

/**
 * Open the filter preferences modal
 */
function openFilterModal() {
    const modal = document.getElementById('filter-preferences-modal');
    if (modal) {
        modal.classList.remove('hidden');
        loadInstructorPreferences();
    }
}

/**
 * Close the filter preferences modal
 */
function closeFilterModal() {
    const modal = document.getElementById('filter-preferences-modal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

/**
 * Load instructor data for filter dropdowns
 */
async function loadInstructorPreferences() {
    const loadingDiv = document.getElementById('filter-loading');
    const instructorList = document.getElementById('instructor-list');
    
    try {
        // Show loading state
        loadingDiv.classList.remove('hidden');
        
        // Load instructors
        if (lastUploadedFile && currentInstructorData.length > 0) {
            populateInstructorDropdown(currentInstructorData);
        } else {
            // Try to fetch from API if no data available
            const response = await fetch('/api/instructor-data/current');
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.instructors) {
                    currentInstructorData = data.instructors;
                    populateInstructorDropdown(currentInstructorData);
                } else {
                    instructorList.innerHTML = '<div class="p-4 text-center text-gray-500">No instructors available - upload a file first</div>';
                }
            } else {
                instructorList.innerHTML = '<div class="p-4 text-center text-gray-500">No instructors available - upload a file first</div>';
            }
        }
        
        // Apply stored preferences if any
        applyStoredPreferencesToUI();
        
    } catch (error) {
        console.error('Error loading filter data:', error);
        instructorList.innerHTML = '<div class="p-4 text-center text-red-500">Error loading instructors</div>';
    } finally {
        loadingDiv.classList.add('hidden');
    }
}

/**
 * Populate instructor multi-select with unique instructors
 */
function populateInstructorDropdown(instructorData) {
    const instructorList = document.getElementById('instructor-list');
    const instructorSearch = document.getElementById('instructor-search');
    
    // Get unique instructors and store globally
    const uniqueInstructors = [...new Set(instructorData.map(item => item.name))];
    window.allInstructors = uniqueInstructors.sort(); // Store for search functionality
    
    // Clear existing content
    instructorList.innerHTML = '';
    
    // Populate instructor list
    renderInstructorList(uniqueInstructors);
    
    // Setup search functionality (remove old listener first if exists)
    const newSearchInput = instructorSearch.cloneNode(true);
    instructorSearch.parentNode.replaceChild(newSearchInput, instructorSearch);
    
    newSearchInput.addEventListener('input', (e) => {
        const searchTerm = e.target.value.toLowerCase();
        const filtered = uniqueInstructors.filter(instructor => 
            instructor.toLowerCase().includes(searchTerm)
        );
        renderInstructorList(filtered);
    });
}

/**
 * Render instructor list in the multi-select
 */
function renderInstructorList(instructors) {
    const instructorList = document.getElementById('instructor-list');
    const emptyMessage = document.getElementById('instructor-list-empty');
    
    // Clear existing content
    instructorList.innerHTML = '';
    
    if (instructors.length === 0) {
        emptyMessage.classList.remove('hidden');
        return;
    }
    
    emptyMessage.classList.add('hidden');
    
    instructors.forEach(instructor => {
        const isSelected = selectedInstructors.has(instructor);
        const item = document.createElement('div');
        item.className = 'instructor-item';
        item.innerHTML = `
            <div class="instructor-item-checkbox ${isSelected ? 'checked' : ''}">
                ${isSelected ? '✓' : ''}
            </div>
            <label class="flex-1 cursor-pointer">${instructor}</label>
            <input type="checkbox" value="${instructor}" ${isSelected ? 'checked' : ''}>
        `;
        
        // Handle click
        item.addEventListener('click', (e) => {
            if (e.target.tagName === 'INPUT') return; // Prevent double toggle
            
            const checkbox = item.querySelector('input[type="checkbox"]');
            const checkboxVisual = item.querySelector('.instructor-item-checkbox');
            checkbox.checked = !checkbox.checked;
            
            // Update visual state
            if (checkbox.checked) {
                checkboxVisual.classList.add('checked');
                checkboxVisual.innerHTML = '✓';
            } else {
                checkboxVisual.classList.remove('checked');
                checkboxVisual.innerHTML = '';
            }
            
            toggleInstructorSelection(instructor, checkbox.checked);
        });
        
        instructorList.appendChild(item);
    });
}

/**
 * Toggle instructor selection
 */
function toggleInstructorSelection(instructor, isSelected) {
    if (isSelected) {
        selectedInstructors.add(instructor);
    } else {
        selectedInstructors.delete(instructor);
    }
    updateSelectedInstructorsDisplay();
    updateFilterButtonState(true); // Update badge
}

/**
 * Update the selected instructors chips display
 */
function updateSelectedInstructorsDisplay() {
    const chipsContainer = document.getElementById('selected-instructors');
    chipsContainer.innerHTML = '';
    
    selectedInstructors.forEach(instructor => {
        const chip = document.createElement('div');
        chip.className = 'instructor-chip';
        chip.innerHTML = `
            <span>${instructor}</span>
            <span class="instructor-chip-remove" data-instructor="${instructor}">×</span>
        `;
        
        // Add remove handler
        chip.querySelector('.instructor-chip-remove').addEventListener('click', (e) => {
            e.stopPropagation();
            removeInstructor(instructor);
        });
        
        chipsContainer.appendChild(chip);
    });
    
    // Re-render list to update checked states
    if (window.allInstructors) {
        renderInstructorList(window.allInstructors);
    }
}

/**
 * Remove an instructor from selection
 */
function removeInstructor(instructor) {
    selectedInstructors.delete(instructor);
    updateSelectedInstructorsDisplay();
    updateFilterButtonState(true); // Update badge
}

/**
 * Apply filter preferences
 */
function applyFilterPreferences() {
    try {
        // Get selected instructors as array
        const selectedInstructorArray = Array.from(selectedInstructors);
        const selectedTime = document.getElementById('preferred-time-dropdown').value;
        
        // Store preferences
        filterPreferences = {
            instructors: selectedInstructorArray, // Changed to array
            preferredTime: selectedTime
        };
        
        // Store in localStorage for persistence
        localStorage.setItem('scheduleFilterPreferences', JSON.stringify(filterPreferences));
        
        // Count applied preferences
        let appliedCount = 0;
        if (selectedInstructorArray.length > 0) appliedCount++;
        if (selectedTime) appliedCount++;
        
        // Show success message
        if (appliedCount > 0) {
            const instructorText = selectedInstructorArray.length > 0 
                ? `${selectedInstructorArray.length} instructor(s)` 
                : '';
            const message = `Applied ${appliedCount} preference(s) ${instructorText ? `- ${instructorText}` : ''} successfully!`;
            notificationManager.show(message, 'success', 3000);
        } else {
            notificationManager.show('No preferences selected', 'info', 3000);
        }
        
        // Close modal
        closeFilterModal();
        
        // Update filter button to show preferences are applied
        updateFilterButtonState(appliedCount > 0);
        
        console.log('Applied filter preferences:', filterPreferences);
        
    } catch (error) {
        console.error('Error applying filter preferences:', error);
        notificationManager.show('Error applying preferences', 'error', 3000);
    }
}

/**
 * Clear all filter preferences
 */
function clearFilterPreferences() {
    // Clear instructor selections
    selectedInstructors.clear();
    document.getElementById('preferred-time-dropdown').value = '';
    document.getElementById('instructor-search').value = '';
    
    // Clear stored preferences
    filterPreferences = {
        instructors: [],
        preferredTime: ''
    };
    
    localStorage.removeItem('scheduleFilterPreferences');
    
    // Update displays
    updateSelectedInstructorsDisplay();
    if (window.allInstructors) {
        renderInstructorList(window.allInstructors);
    }
    
    // Update filter button state
    updateFilterButtonState(false);
    
    notificationManager.show('All preferences cleared', 'info', 3000);
}


/**
 * Update filter button visual state
 */
function updateFilterButtonState(hasPreferences) {
    const filterBtn = document.getElementById('filter-btn');
    const filterBadge = document.getElementById('filter-badge');
    
    if (filterBtn) {
        if (hasPreferences) {
            filterBtn.classList.add('bg-[#75975e]', 'text-white');
            filterBtn.classList.remove('bg-white', 'text-[#75975e]');
        } else {
            filterBtn.classList.remove('bg-[#75975e]', 'text-white');
            filterBtn.classList.add('bg-white', 'text-[#75975e]');
        }
    }
    
    // Update badge count
    if (filterBadge) {
        const count = getActiveFilterCount();
        if (count > 0) {
            filterBadge.textContent = count;
            filterBadge.classList.remove('hidden');
        } else {
            filterBadge.classList.add('hidden');
        }
    }
}

/**
 * Get count of active filters
 */
function getActiveFilterCount() {
    let count = 0;
    
    // Count selected instructors
    if (selectedInstructors.size > 0) {
        count++;
    }
    
    // Check time preference
    const timeDropdown = document.getElementById('preferred-time-dropdown');
    if (timeDropdown && timeDropdown.value) {
        count++;
    }
    
    return count;
}

/**
 * Load filter preferences from localStorage
 * NOTE: Currently disabled - filters reset on page refresh for a clean slate each time
 */
function loadStoredFilterPreferences() {
    // Clear all preferences on page load for fresh start
    selectedInstructors.clear();
    filterPreferences = {
        instructors: [],
        preferredTime: ''
    };
    localStorage.removeItem('scheduleFilterPreferences');
    updateFilterButtonState(false);
    
    /* DISABLED - auto-loading preferences on refresh
    try {
        const stored = localStorage.getItem('scheduleFilterPreferences');
        if (stored) {
            const parsed = JSON.parse(stored);
            
            // Handle legacy single instructor format
            if (parsed.instructor) {
                filterPreferences = {
                    instructors: [parsed.instructor],
                    preferredTime: parsed.preferredTime || ''
                };
            } else {
                filterPreferences = parsed;
            }
            
            // Load instructor selections into Set immediately
            if (filterPreferences.instructors && Array.isArray(filterPreferences.instructors)) {
                selectedInstructors.clear();
                filterPreferences.instructors.forEach(instructor => {
                    selectedInstructors.add(instructor);
                });
            }
            
            // Update UI if modal is open
            const modal = document.getElementById('filter-preferences-modal');
            if (modal && !modal.classList.contains('hidden')) {
                applyStoredPreferencesToUI();
            }
            
            // Update filter button state
            const hasPreferences = filterPreferences.instructors?.length > 0 || 
                                 filterPreferences.preferredTime;
            updateFilterButtonState(hasPreferences);
        }
    } catch (error) {
        console.error('Error loading stored filter preferences:', error);
    }
    */
}

/**
 * Apply stored preferences to UI elements
 */
function applyStoredPreferencesToUI() {
    // Apply stored values to instructor selections
    if (filterPreferences.instructors && Array.isArray(filterPreferences.instructors)) {
        selectedInstructors.clear();
        filterPreferences.instructors.forEach(instructor => {
            selectedInstructors.add(instructor);
        });
        updateSelectedInstructorsDisplay();
        
        // Re-render the list to show selected state
        if (window.allInstructors) {
            renderInstructorList(window.allInstructors);
        }
    }
    
    if (filterPreferences.preferredTime) {
        const timeDropdown = document.getElementById('preferred-time-dropdown');
        if (timeDropdown) timeDropdown.value = filterPreferences.preferredTime;
    }
}

/**
 * Get current filter preferences for use in schedule generation
 */
function getCurrentFilterPreferences() {
    try {
        // Get live values from UI
        const selectedInstructorArray = Array.from(selectedInstructors);
        const timeEl = document.getElementById('preferred-time-dropdown');

        const live = {
            instructors: selectedInstructorArray.length > 0 ? selectedInstructorArray : [],
            preferredTime: timeEl ? timeEl.value : ''
        };

        // If any live selection exists, use it; else use stored preferences
        if (live.instructors.length > 0 || live.preferredTime) {
            return live;
        }
        return filterPreferences;
    } catch (e) {
        // Fallback to stored
        return filterPreferences;
    }
}

/**
 * Store instructor data when file is processed for use in filter modal
 */
function storeInstructorDataForFilter(instructorData) {
    currentInstructorData = instructorData || [];
}

// Enhanced event listeners for filter modal
document.addEventListener('DOMContentLoaded', function() {
    // Close filter modal button
    const closeFilterBtn = document.getElementById('close-filter-modal');
    if (closeFilterBtn) {
        closeFilterBtn.addEventListener('click', closeFilterModal);
    }
    
    // Apply preferences button
    const applyBtn = document.getElementById('apply-preferences-btn');
    if (applyBtn) {
        applyBtn.addEventListener('click', applyFilterPreferences);
    }
    
    // Clear preferences button
    const clearBtn = document.getElementById('clear-preferences-btn');
    if (clearBtn) {
        clearBtn.addEventListener('click', clearFilterPreferences);
    }
    
    // Load stored preferences on page load
    loadStoredFilterPreferences();
    
    // Close modal when clicking outside
    const modal = document.getElementById('filter-preferences-modal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeFilterModal();
            }
        });
    }
    
    // Update badge when time preference changes
    const timeDropdown = document.getElementById('preferred-time-dropdown');
    if (timeDropdown) {
        timeDropdown.addEventListener('change', function() {
            updateFilterButtonState(true);
        });
    }
});

/**
 * Initialize reference upload functionality
 */
function initializeReferenceUpload() {
    const dropArea = document.getElementById('reference-drop-area');
    const fileInput = document.getElementById('reference-file-elem');
    const browseBtn = document.getElementById('reference-browse-btn');
    const uploadBtn = document.getElementById('reference-upload-btn');
    const reviewSection = document.getElementById('reference-review-section');
    const filePreview = document.getElementById('reference-file-preview');
    const errorMessage = document.getElementById('reference-error-message');
    
    let selectedFile = null;
    
    // Browse button click
    if (browseBtn) {
        browseBtn.addEventListener('click', () => {
            fileInput.click();
        });
    }
    
    // File input change
    if (fileInput) {
        fileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                handleReferenceFile(file);
            }
        });
    }
    
    // Drag and drop events
    if (dropArea) {
        dropArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropArea.classList.add('border-[#75975e]', 'bg-[#ddead1]/80');
            dropArea.classList.remove('border-[#a3c585]/60');
            // Reset any validation error states when dragging over
            dropArea.classList.remove('border-red-500', 'bg-red-50');
        });
        
        dropArea.addEventListener('dragleave', (e) => {
            e.preventDefault();
            dropArea.classList.remove('border-[#75975e]', 'bg-[#ddead1]/80');
            dropArea.classList.add('border-[#a3c585]/60');
            // Don't reset validation error states on drag leave
        });
        
        dropArea.addEventListener('drop', (e) => {
            e.preventDefault();
            dropArea.classList.remove('border-[#75975e]', 'bg-[#ddead1]/80');
            dropArea.classList.add('border-[#a3c585]/60');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleReferenceFile(files[0]);
            }
        });
        
        // Click to browse
        dropArea.addEventListener('click', () => {
            fileInput.click();
        });
    }
    
    // Upload button click
    if (uploadBtn) {
        uploadBtn.addEventListener('click', () => {
            if (selectedFile) {
                uploadReferenceFile(selectedFile);
            }
        });
    }
    
    
    function handleReferenceFile(file) {
        // Validate file type - only allow DOCX files
        const allowedTypes = ['.docx'];
        const fileExtension = '.' + file.name.split('.').pop().toLowerCase();
        
        if (!allowedTypes.includes(fileExtension)) {
            showReferenceValidationError('Please upload only DOCX files');
            return;
        }
        
        // Validate file size (max 10MB)
        if (file.size > 10 * 1024 * 1024) {
            showReferenceError('File size must be less than 10MB');
            return;
        }
        
        selectedFile = file;
        hideReferenceError();
        
        // Hide drop area and show review section (same as landing page)
        dropArea.classList.add('hidden');
        reviewSection.classList.remove('hidden');
        showReferencePreview(file);
        uploadBtn.classList.remove('hidden');
    }
    
    function uploadReferenceFile(file) {
        console.log('Uploading reference file:', file.name);
        
        // Get loader element
        const loader = document.getElementById('reference-loader');
        
        // Show loading state - hide button and show loader
        const originalText = uploadBtn.textContent;
        uploadBtn.classList.add('hidden');
        if (loader) {
            loader.classList.remove('hidden');
        }
        
        // Create FormData
        const formData = new FormData();
        formData.append('file', file);
        
        // Upload file
        fetch('/api/reference-schedules/upload', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success notification using the existing notification system
                notificationManager.show(data.message, 'success');
                
                // Close the modal
                tabModal.classList.add('hidden');
                
                // Reset form
                resetReferenceForm();
            } else {
                showReferenceError(data.message || 'Upload failed. Please try again.');
            }
        })
        .catch(error => {
            console.error('Upload error:', error);
            showReferenceError('An error occurred during upload. Please try again.');
        })
        .finally(() => {
            // Reset button state - show button and hide loader
            uploadBtn.classList.remove('hidden');
            uploadBtn.textContent = originalText;
            if (loader) {
                loader.classList.add('hidden');
            }
        });
    }
    
    function showReferenceError(message) {
        errorMessage.textContent = message;
        errorMessage.classList.remove('hidden');
    }
    
    function showReferenceValidationError(message) {
        // Add red border animation to reference drop area
        dropArea.classList.add('border-red-500', 'bg-red-50');
        dropArea.classList.remove('border-[#a3c585]/60', 'bg-white/90');
        
        // Show error message
        errorMessage.textContent = message;
        errorMessage.classList.remove('hidden');
        
        // Reset visual state after 5 seconds
        setTimeout(() => {
            dropArea.classList.remove('border-red-500', 'bg-red-50');
            dropArea.classList.add('border-[#a3c585]/60', 'bg-white/90');
            errorMessage.classList.add('hidden');
        }, 5000);
    }
    
    function hideReferenceError() {
        errorMessage.classList.add('hidden');
    }
    
    function showReferenceSuccess(message) {
        // Create a temporary success message
        const successDiv = document.createElement('div');
        successDiv.className = 'text-green-600 mt-4 text-center';
        successDiv.textContent = message;
        
        const container = document.getElementById('reference-error-message').parentNode;
        container.insertBefore(successDiv, container.lastElementChild);
        
        // Remove after 3 seconds
        setTimeout(() => {
            successDiv.remove();
        }, 3000);
    }
    
    function showReferencePreview(file) {
        filePreview.innerHTML = '';
        const preview = document.createElement('div');
        preview.className = 'flex items-center w-full bg-[#eaf6e3] rounded-2xl p-4 shadow mb-2';
        preview.innerHTML = getReferenceFileIcon(file);
        
        // Info and remove
        const info = document.createElement('div');
        info.className = 'flex-1 ml-4 min-w-0';
        info.innerHTML = `<div class='font-semibold text-[#75975e] truncate' title='${file.name}'>${file.name}</div><div class='text-sm text-[#75975e]'>${file.type} &middot; ${formatFileSize(file.size)}</div>`;
        preview.appendChild(info);
        
        // Remove button
        const removeBtn = document.createElement('button');
        removeBtn.className = 'ml-4 p-2 rounded-full bg-[#ddead1] hover:bg-[#a3c585] transition text-[#75975e] hover:text-white focus:outline-none focus:ring-2 focus:ring-[#a3c585]';
        removeBtn.innerHTML = `<svg xmlns='http://www.w3.org/2000/svg' class='h-5 w-5' fill='none' viewBox='0 0 24 24' stroke='currentColor'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M6 18L18 6M6 6l12 12'/></svg>`;
        removeBtn.title = 'Remove';
        removeBtn.onclick = () => {
            resetReferenceForm();
            fileInput.value = '';
        };
        preview.appendChild(removeBtn);
        filePreview.appendChild(preview);
    }
    
    function resetReferenceForm() {
        selectedFile = null;
        fileInput.value = '';
        reviewSection.classList.add('hidden');
        dropArea.classList.remove('hidden');
        filePreview.innerHTML = '';
        uploadBtn.classList.add('hidden');
        hideReferenceError();
        
        // Hide loader if it exists
        const loader = document.getElementById('reference-loader');
        if (loader) {
            loader.classList.add('hidden');
        }
        
        // Reset validation states
        dropArea.classList.remove('border-red-500', 'bg-red-50');
        dropArea.classList.add('border-[#a3c585]/60', 'bg-white/90');
    }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];

        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function getReferenceFileIcon(file) {
        if (file.type.includes('pdf')) {
            return `<svg class='w-12 h-12 text-red-400' fill='none' viewBox='0 0 24 24' stroke='currentColor'><rect width='100%' height='100%' rx='8' fill='#fee2e2'/><text x='50%' y='60%' text-anchor='middle' fill='#b91c1c' font-size='1.2em' font-family='Arial' dy='.3em'>PDF</text></svg>`;
        } else if (file.type.includes('word')) {
            return `<svg class='w-12 h-12 text-blue-400' fill='none' viewBox='0 0 24 24' stroke='currentColor'><rect width='100%' height='100%' rx='8' fill='#dbeafe'/><text x='50%' y='60%' text-anchor='middle' fill='#1d4ed8' font-size='1.2em' font-family='Arial' dy='.3em'>DOCX</text></svg>`;
        } else if (file.type.includes('sheet')) {
            return `<svg class='w-12 h-12 text-green-400' fill='none' viewBox='0 0 24 24' stroke='currentColor'><rect width='100%' height='100%' rx='8' fill='#dcfce7'/><text x='50%' y='60%' text-anchor='middle' fill='#166534' font-size='1.2em' font-family='Arial' dy='.3em'>XLSX</text></svg>`;
        }
        return '';
    }
}