<!DOCTYPE html>
<html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Generate Schedule</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
        <style>
        .glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
        }
        .truncate {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* Notification animations */
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(0);
                opacity: 1;
            }
            to {
                transform: translateY(-100%);
                opacity: 0;
            }
        }
        
        .notification-enter {
            animation: slideInRight 0.3s ease-out forwards;
        }
        
        .notification-exit {
            animation: slideOutRight 0.3s ease-in forwards;
        }
        
        .notification-slide-up {
            animation: slideUp 0.3s ease-in forwards;
        }
        
        .notification-item {
            pointer-events: auto;
            max-width: 280px;
            min-width: 240px;
            width: 100%;
            box-sizing: border-box;
        }
        
        /* Make loading notifications more subtle */
        .notification-item.loading-notification {
            opacity: 0.8;
            background-color: rgba(59, 130, 246, 0.9) !important;
        }
        
        /* Room hover action buttons */
        .room-item {
            position: relative;
            overflow: hidden;
        }
        
        .room-actions-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(2px);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease-in-out;
            z-index: 10;
        }
        
        .room-item:hover .room-actions-overlay {
            opacity: 1;
            visibility: visible;
        }
        
        .room-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 6px;
            margin: 0 2px;
            transition: all 0.2s ease-in-out;
            border: none;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
        }
        
        .room-action-btn.view {
            background: rgba(59, 130, 246, 0.9);
            color: white;
        }
        
        .room-action-btn.view:hover {
            background: rgba(59, 130, 246, 1);
            transform: scale(1.05);
        }
        
        .room-action-btn.edit {
            background: rgba(34, 197, 94, 0.9);
            color: white;
        }
        
        .room-action-btn.edit:hover {
            background: rgba(34, 197, 94, 1);
            transform: scale(1.05);
        }
        
        .room-action-btn.delete {
            background: rgba(239, 68, 68, 0.9);
            color: white;
        }
        
        .room-action-btn.delete:hover {
            background: rgba(239, 68, 68, 1);
            transform: scale(1.05);
        }
        
        /* Ensure notifications don't overflow the viewport */
        #notification-container {
            max-width: calc(100vw - 36rem);
            right: 18rem;
        }
        
        @media (max-width: 768px) {
            #notification-container {
                right: 9rem;
                max-width: calc(100vw - 18rem);
            }
            
            .notification-item {
                max-width: calc(100vw - 18rem);
                min-width: auto;
            }
        }

        /* Typewriter Animation Styles */
        /* From Uiverse.io by chase2k25 */ 
        .typewriter-alt {
            --green: #75975e;
            --green-dark: #5a6b4a;
            --key: #e0e0e0;
            --paper: #f5f5f5;
            --text: #b0bec5;
            --tool: #ffca28;
            --duration: 2.5s;
            position: relative;
            animation: bounce-alt var(--duration) ease-in-out infinite;
        }

        .typewriter-alt .slide {
            width: 100px;
            height: 18px;
            border-radius: 4px;
            margin-left: 10px;
            transform: translateX(10px);
            background: linear-gradient(var(--green), var(--green-dark));
            animation: slide-alt var(--duration) ease infinite;
        }

        .typewriter-alt .slide:before,
        .typewriter-alt .slide:after,
        .typewriter-alt .slide i:before {
            content: "";
            position: absolute;
            background: var(--tool);
        }

        .typewriter-alt .slide:before {
            width: 3px;
            height: 10px;
            top: 4px;
            left: 100%;
        }

        .typewriter-alt .slide:after {
            left: 102px;
            top: 2px;
            height: 12px;
            width: 5px;
            border-radius: 2px;
        }

        .typewriter-alt .slide i {
            display: block;
            position: absolute;
            right: 100%;
            width: 5px;
            height: 5px;
            top: 3px;
            background: var(--tool);
        }

        .typewriter-alt .slide i:before {
            right: 100%;
            top: -3px;
            width: 3px;
            border-radius: 1px;
            height: 12px;
        }

        .typewriter-alt .paper {
            position: absolute;
            left: 20px;
            top: -30px;
            width: 45px;
            height: 50px;
            border-radius: 6px;
            background: var(--paper);
            transform: translateY(50px);
            animation: paper-alt var(--duration) linear infinite;
        }

        .typewriter-alt .paper:before {
            content: "";
            position: absolute;
            left: 5px;
            right: 5px;
            top: 8px;
            border-radius: 1px;
            height: 3px;
            transform: scaleY(0.9);
            background: var(--text);
            box-shadow:
                0 10px 0 var(--text),
                0 20px 0 var(--text),
                0 30px 0 var(--text);
        }

        .typewriter-alt .keyboard {
            width: 130px;
            height: 60px;
            margin-top: -8px;
            z-index: 1;
            position: relative;
        }

        .typewriter-alt .keyboard:before,
        .typewriter-alt .keyboard:after {
            content: "";
            position: absolute;
        }

        .typewriter-alt .keyboard:before {
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--green), var(--green-dark));
            transform: perspective(12px) rotateX(3deg);
            transform-origin: 50% 100%;
        }

        .typewriter-alt .keyboard:after {
            left: 3px;
            top: 28px;
            width: 10px;
            height: 3px;
            border-radius: 1px;
            box-shadow:
                16px 0 0 var(--key),
                32px 0 0 var(--key),
                48px 0 0 var(--key),
                64px 0 0 var(--key),
                80px 0 0 var(--key),
                96px 0 0 var(--key),
                24px 8px 0 var(--key),
                40px 8px 0 var(--key),
                56px 8px 0 var(--key),
                64px 8px 0 var(--key),
                72px 8px 0 var(--key),
                88px 8px 0 var(--key);
            animation: keyboard-alt var(--duration) linear infinite;
        }

        @keyframes bounce-alt {
            0%,
            80%,
            100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-6px);
            }
            60% {
                transform: translateY(3px);
            }
        }

        @keyframes slide-alt {
            0%,
            100% {
                transform: translateX(10px);
            }
            25% {
                transform: translateX(4px);
            }
            50% {
                transform: translateX(-2px);
            }
            75% {
                transform: translateX(-8px);
            }
        }

        @keyframes paper-alt {
            0%,
            100% {
                transform: translateY(50px);
            }
            25% {
                transform: translateY(40px);
            }
            50% {
                transform: translateY(25px);
            }
            75% {
                transform: translateY(10px);
            }
        }

        @keyframes keyboard-alt {
            0%,
            20%,
            40%,
            60%,
            80% {
                box-shadow:
                    16px 0 0 var(--key),
                    32px 0 0 var(--key),
                    48px 0 0 var(--key),
                    64px 0 0 var(--key),
                    80px 0 0 var(--key),
                    96px 0 0 var(--key),
                    24px 8px 0 var(--key),
                    40px 8px 0 var(--key),
                    56px 8px 0 var(--key),
                    64px 8px 0 var(--key),
                    72px 8px 0 var(--key),
                    88px 8px 0 var(--key);
            }
            10% {
                box-shadow:
                    16px 2px 0 var(--key),
                    32px 0 0 var(--key),
                    48px 0 0 var(--key),
                    64px 0 0 var(--key),
                    80px 0 0 var(--key),
                    96px 0 0 var(--key),
                    24px 8px 0 var(--key),
                    40px 8px 0 var(--key),
                    56px 8px 0 var(--key),
                    64px 8px 0 var(--key),
                    72px 8px 0 var(--key),
                    88px 8px 0 var(--key);
            }
            30% {
                box-shadow:
                    16px 0 0 var(--key),
                    32px 0 0 var(--key),
                    48px 0 0 var(--key),
                    64px 0 0 var(--key),
                    80px 2px 0 var(--key),
                    96px 0 0 var(--key),
                    24px 8px 0 var(--key),
                    40px 8px 0 var(--key),
                    56px 8px 0 var(--key),
                    64px 8px 0 var(--key),
                    72px 8px 0 var(--key),
                    88px 8px 0 var(--key);
            }
            50% {
                box-shadow:
                    16px 0 0 var(--key),
                    32px 0 0 var(--key),
                    48px 0 0 var(--key),
                    64px 0 0 var(--key),
                    80px 0 0 var(--key),
                    96px 0 0 var(--key),
                    24px 10px 0 var(--key),
                    40px 8px 0 var(--key),
                    56px 8px 0 var(--key),
                    64px 8px 0 var(--key),
                    72px 8px 0 var(--key),
                    88px 8px 0 var(--key);
            }
            70% {
                box-shadow:
                    16px 0 0 var(--key),
                    32px 0 0 var(--key),
                    48px 0 0 var(--key),
                    64px 0 0 var(--key),
                    80px 0 0 var(--key),
                    96px 2px 0 var(--key),
                    24px 8px 0 var(--key),
                    40px 8px 0 var(--key),
                    56px 8px 0 var(--key),
                    64px 8px 0 var(--key),
                    72px 8px 0 var(--key),
                    88px 8px 0 var(--key);
            }
        }


        .step-item {
            transition: all 0.3s ease-in-out;
        }

        .step-item.active .step-indicator {
            background: linear-gradient(135deg, #75975e, #a3c585);
            animation: stepPulse 1s ease-in-out infinite;
        }

        .step-item.active .step-dot {
            background: white;
            animation: stepDotPulse 1s ease-in-out infinite;
        }

        .step-item.completed .step-indicator {
            background: #10b981;
        }

        .step-item.completed .step-dot {
            display: none;
        }

        .step-item.completed .step-check {
            display: block;
            animation: checkBounce 0.5s ease-out;
        }

        @keyframes stepPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes stepDotPulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.8; }
        }

        @keyframes checkBounce {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .progress-bar {
            position: relative;
            overflow: hidden;
        }

        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            animation: progressShimmer 2s ease-in-out infinite;
        }

        @keyframes progressShimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        /* Loader entrance animation */
        #schedule-generator-loader {
            animation: loaderFadeIn 0.3s ease-out;
        }

        @keyframes loaderFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        #schedule-generator-loader .bg-white {
            animation: loaderSlideIn 0.4s ease-out;
        }

        @keyframes loaderSlideIn {
            from { 
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to { 
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* File validation error styles */
        #drop-area, #reference-drop-area {
            transition: all 0.3s ease-in-out;
        }

        #drop-area.border-red-500, #reference-drop-area.border-red-500 {
            animation: validationShake 0.5s ease-in-out;
        }

        @keyframes validationShake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        /* Reference Modal Loader - Green Version */
        .loader-con {
            position: relative;
            width: 50%;
            height: 100px;
            overflow: hidden;
        }

        .pfile {
            position: absolute;
            bottom: 25px;
            width: 40px;
            height: 50px;
            background: linear-gradient(90deg, #22c55e, #16a34a);
            border-radius: 4px;
            transform-origin: center;
            animation: flyRight 3s ease-in-out infinite;
            animation-delay: calc(var(--i) * 0.6s);
            opacity: 0;
        }

        .pfile::before {
            content: "";
            position: absolute;
            top: 6px;
            left: 6px;
            width: 28px;
            height: 4px;
            background-color: #ffffff;
            border-radius: 2px;
        }

        .pfile::after {
            content: "";
            position: absolute;
            top: 13px;
            left: 6px;
            width: 18px;
            height: 4px;
            background-color: #ffffff;
            border-radius: 2px;
        }

        @keyframes flyRight {
            0% {
                left: -10%;
                transform: scale(0);
                opacity: 0;
            }
            50% {
                left: 45%;
                transform: scale(1.2);
                opacity: 1;
            }
            100% {
                left: 100%;
                transform: scale(0);
                opacity: 0;
            }
        }

        /* Multi-select Instructor Filter Styles */
        .instructor-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: linear-gradient(135deg, #75975e, #a3c585);
            color: white;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 500;
            animation: chipSlideIn 0.2s ease-out;
        }

        .instructor-chip-remove {
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transition: all 0.2s;
        }

        .instructor-chip-remove:hover {
            background: rgba(255, 255, 255, 0.5);
            transform: scale(1.1);
        }

        .instructor-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 6px;
        }

        .instructor-item:hover {
            background: #ddead1;
        }

        .instructor-item-checkbox {
            width: 18px;
            height: 18px;
            border: 2px solid #9ca3af;
            border-radius: 4px;
            margin-right: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
        }

        .instructor-item-checkbox.checked {
            background: linear-gradient(135deg, #75975e, #a3c585);
            border-color: #75975e;
            color: white;
        }

        .instructor-item input[type="checkbox"] {
            opacity: 0;
            position: absolute;
        }

        @keyframes chipSlideIn {
            from {
                opacity: 0;
                transform: scale(0.8);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        </style>    
    </head>
<body class="min-h-screen bg-[#ddead1] flex items-center justify-center">
    <div class="fixed inset-0 bg-[#ddead1] bg-opacity-90 flex items-center justify-center z-10">
        <div class="glass rounded-2xl shadow-2xl max-w-xl w-full p-0">
            <!-- Top Info Row -->
            <div class="flex items-center justify-center px-8 pt-10 pb-6 w-full">
                <div class="flex items-center space-x-6">
                    <div class="flex items-center space-x-2 cursor-pointer group" id="rooms-tab">
                        <!-- Users/door icon for Rooms -->
                        <svg class="w-6 h-6 text-[#75975e] group-hover:text-[#a3c585] transition" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 21V7.5a4.5 4.5 0 10-9 0V21M3 21h18"/></svg>
                        <div class="font-semibold text-[#75975e] group-hover:text-[#a3c585] transition">Rooms</div>
                    </div>
                    <div class="text-[#75975e] opacity-50">|</div>
                    <div class="flex items-center space-x-2 cursor-pointer group" id="drafts-tab">
                        <!-- Document-text icon for Drafts -->
                        <svg class="w-6 h-6 text-[#75975e] group-hover:text-[#a3c585] transition" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25V6.75A2.25 2.25 0 0017.25 4.5H6.75A2.25 2.25 0 004.5 6.75v10.5A2.25 2.25 0 006.75 19.5h6.75"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 7.5h6m-6 3h6m-6 3h3"/></svg>
                        <div class="font-semibold text-[#75975e] group-hover:text-[#a3c585] transition">Drafts</div>
                    </div>
                    <div class="text-[#75975e] opacity-50">|</div>
                    <div class="flex items-center space-x-2 cursor-pointer group" id="reference-tab">
                        <!-- Reference/book icon for Reference -->
                        <svg class="w-6 h-6 text-[#75975e] group-hover:text-[#a3c585] transition" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A9.967 9.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                        <div class="font-semibold text-[#75975e] group-hover:text-[#a3c585] transition">Reference</div>
                    </div>
                    <div class="text-[#75975e] opacity-50">|</div>
                    <div class="flex items-center space-x-2 cursor-pointer group" id="history-tab">
                        <!-- Clock/history icon for History -->
                        <svg class="w-6 h-6 text-[#75975e] group-hover:text-[#a3c585] transition" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/><circle cx="12" cy="12" r="9" stroke="#a3c585" stroke-width="1.5"/></svg>
                        <div class="font-semibold text-[#75975e] group-hover:text-[#a3c585] transition">History</div>
                    </div>
                </div>
            </div>
            <!-- Drop Area -->
            <div class="px-8 pb-4">
                <div id="drop-area" class="flex flex-col items-center justify-center border-4 border-dashed border-[#a3c585]/60 rounded-3xl bg-white/90 min-h-[320px] py-14 px-8 transition-all duration-200 hover:border-[#75975e] hover:bg-[#ddead1]/80 cursor-pointer shadow-xl animate-fadeIn">
                    <!-- Custom Document Upload Icon -->
                    <div class="flex items-center justify-center mb-4 relative animate-fadeIn">
                        <svg width="72" height="72" viewBox="0 0 72 72" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <!-- Document -->
                            <rect x="10" y="10" width="40" height="52" rx="6" fill="#a3c585"/>
                            <!-- Folded corner -->
                            <polygon points="50,10 62,22 50,22" fill="#ddead1"/>
                            <!-- Document lines -->
                            <rect x="18" y="22" width="24" height="3" rx="1.5" fill="white"/>
                            <rect x="18" y="30" width="24" height="3" rx="1.5" fill="white"/>
                            <rect x="18" y="38" width="18" height="3" rx="1.5" fill="white"/>
                            <!-- Upload circle -->
                            <circle cx="54" cy="54" r="15" fill="#75975e" stroke="#fff" stroke-width="3"/>
                            <!-- Upload arrow -->
                            <path d="M54 62V48M54 48l-5 5M54 48l5 5" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="text-[#75975e] font-extrabold text-2xl mb-3 animate-fadeIn">Upload Instructors Load</div>
                    <div class="text-[#444] font-bold text-xl mb-2 animate-fadeIn">Drag & drop to upload</div>
                    <div class="text-[#75975e] text-sm mb-2 animate-fadeIn">(XLSX, XLS, or CSV files only)</div>
                    <div class="text-[#75975e] text-base animate-fadeIn">
                        or <button id="browse-btn" type="button" class="font-bold text-[#75975e] underline hover:text-[#a3c585] focus:outline-none focus:ring-2 focus:ring-[#a3c585] transition">browse</button>
                    </div>
                    <input id="fileElem" type="file" class="hidden" accept=".xlsx,.xls,.csv" />
                </div>
                <div id="error-message" class="text-red-600 mt-4 hidden"></div>
                <div id="review-section" class="w-full flex flex-col items-center mt-6 hidden animate-fadeIn">
                    <div id="file-preview" class="w-full"></div>
                    <button id="review-btn" class="mt-8 w-full px-6 py-3 rounded-xl bg-gradient-to-r from-[#75975e] to-[#a3c585] text-white font-bold text-lg shadow-lg transition-all hover:scale-105 hover:from-[#a3c585] hover:to-[#75975e]">Review</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Modal for Review -->
    <div id="review-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-6xl w-full p-8 relative animate-fadeIn max-h-[80vh] flex flex-col">
            <button id="close-modal" class="absolute top-4 right-4 text-[#75975e] text-2xl font-bold opacity-60 hover:opacity-100 transition">&times;</button>
            <div class="flex-1 flex flex-col items-center justify-start w-full overflow-y-auto">
                <div id="modal-file-preview" class="w-full min-w-[1100px]"></div>
            </div>
            <!-- Fixed button group at the bottom -->
            <div class="sticky bottom-0 left-0 w-full bg-white pt-6 pb-2 flex justify-center gap-4 z-20 border-t border-[#a3c585]/40">
                <button id="generate-schedule-btn" class="px-6 py-3 rounded-lg bg-gradient-to-r from-[#75975e] to-[#a3c585] text-white font-bold shadow hover:from-[#a3c585] hover:to-[#75975e] transition">Generate Schedule</button>
                <button id="filter-btn" onclick="openFilterModal()" class="px-6 py-3 rounded-lg border-2 border-[#a3c585] text-[#75975e] font-bold bg-white hover:bg-[#ddead1] transition relative">
                    Filter
                    <span id="filter-badge" class="absolute -top-2 -right-2 hidden bg-red-500 text-white text-xs font-bold rounded-full w-6 h-6 flex items-center justify-center">0</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Simple modal for tab info -->
    <div id="tab-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full p-4 relative animate-fadeIn">
            <button id="close-tab-modal" class="absolute top-3 right-3 text-[#75975e] text-xl font-bold opacity-60 hover:opacity-100 transition">&times;</button>
            <div class="flex flex-col items-center">
                <div id="tab-modal-title" class="font-bold text-xl text-[#75975e] mb-3"></div>
                <div id="tab-modal-content" class="text-[#75975e] text-base text-center w-full px-2">
                    <!-- The table/list inside here will keep its width and can scroll horizontally if needed -->
                </div>
            </div>
        </div>
    </div>

    <!-- Schedule View Modal -->
    <div id="schedule-view-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-7xl w-full p-6 relative animate-fadeIn max-h-[90vh] flex flex-col">
            <!-- Sticky Header and X Button -->
            <div class="sticky top-0 left-0 right-0 z-20 bg-white rounded-t-2xl pt-2 pb-2 px-2 flex flex-col items-center border-b border-gray-200" style="box-shadow: 0 2px 8px 0 rgba(0,0,0,0.02);">
                <button id="close-schedule-modal" class="absolute top-4 right-4 text-gray-400 text-2xl font-bold opacity-60 hover:opacity-100 transition">&times;</button>
                
                <!-- Export Buttons -->
                <div class="absolute top-4 left-4 flex items-center space-x-3">
                    <button id="export-schedule-view-btn" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-lg text-sm flex items-center space-x-1 transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span>Export</span>
                    </button>
                    <div class="flex items-center space-x-2">
                        <span class="text-xs font-medium text-gray-700">Edit</span>
                        <label class="relative cursor-pointer">
                            <input type="checkbox" id="edit-mode-toggle-view" class="sr-only">
                            <div class="w-12 h-6 bg-gray-300 rounded-full border border-gray-400 transition-all duration-300 ease-in-out relative overflow-hidden">
                                <span data-role="on" class="absolute left-1.5 top-1/2 transform -translate-y-1/2 text-[10px] font-semibold text-white opacity-0 transition-opacity duration-300">ON</span>
                                <span data-role="off" class="absolute right-1.5 top-1/2 transform -translate-y-1/2 text-[10px] font-semibold text-gray-600 transition-opacity duration-300">OFF</span>
                                <div class="absolute left-0.5 top-0.5 bg-white w-5 h-5 rounded-full shadow border border-gray-200 transition-transform duration-300 ease-in-out transform"></div>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div class="text-center">
                    <h1 class="text-xl font-bold text-gray-800 mb-1">GINGOOG CITY COLLEGES, INC.</h1>
                    <p class="text-gray-600 text-sm mb-1">Gingoog City, Misamis Oriental</p>
                    <div id="schedule-department" class="text-lg font-bold text-gray-800 mb-1"></div>
                    <div id="schedule-semester" class="text-base font-semibold text-gray-700 mb-1"></div>
                    <div class="text-right text-xs text-gray-600" id="schedule-date"></div>
                </div>
            </div>
            <!-- Scrollable Schedule Content -->
            <div id="schedule-content" class="space-y-4 overflow-y-auto flex-1 pt-2">
                <!-- Schedule tables will be dynamically generated here -->
            </div>
        </div>
    </div>

    

    <!-- Add Room Modal -->
    <div id="add-room-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 relative animate-fadeIn">
            <button id="close-add-room-modal" class="absolute top-4 right-4 text-gray-400 text-2xl font-bold opacity-60 hover:opacity-100 transition">&times;</button>
            
            <!-- Header -->
            <div class="flex items-center mb-6">
                <div class="w-10 h-10 bg-gradient-to-br from-[#75975e] to-[#a3c585] rounded-xl flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Add New Room</h2>
                    <p class="text-gray-500 text-sm">Create a new room in your facility</p>
                </div>
            </div>

            <!-- Form -->
            <form id="add-room-form" class="space-y-4">
                <!-- Room Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Room Type</label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="relative cursor-pointer">
                            <input type="radio" name="roomType" value="lab" class="sr-only" checked>
                            <div class="border-2 border-gray-200 rounded-lg p-3 text-center transition-all hover:border-blue-300 radio-selected:border-blue-500 radio-selected:bg-blue-50">
                                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-lg flex items-center justify-center mx-auto mb-2">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                                <span class="text-sm font-medium text-gray-700">Lab Room</span>
                            </div>
                        </label>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="roomType" value="regular" class="sr-only">
                            <div class="border-2 border-gray-200 rounded-lg p-3 text-center transition-all hover:border-green-300 radio-selected:border-green-500 radio-selected:bg-green-50">
                                <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center mx-auto mb-2">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                    </svg>
                                </div>
                                <span class="text-sm font-medium text-gray-700">Regular Room</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Building Selection (for Regular Rooms) -->
                <div id="buildingSelection" class="hidden">
                    <label for="building" class="block text-sm font-medium text-gray-700 mb-2">Building</label>
                    <div class="relative">
                        <button type="button" id="buildingButton" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#a3c585] focus:border-[#a3c585] transition-colors bg-white text-left flex items-center justify-between">
                            <span id="buildingDisplay" class="text-gray-500">Select a building</span>
                            <svg class="w-4 h-4 text-gray-400 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div id="buildingDropdown" class="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-10 hidden">
                            <div class="py-1">
                                <button type="button" class="w-full px-3 py-2 text-left hover:bg-[#ddead1] hover:text-[#75975e] transition-all duration-200 rounded-lg mx-1 cursor-pointer" data-value="hs">HS Building</button>
                                <button type="button" class="w-full px-3 py-2 text-left hover:bg-[#ddead1] hover:text-[#75975e] transition-all duration-200 rounded-lg mx-1 cursor-pointer" data-value="shs">SHS Building</button>
                                <button type="button" class="w-full px-3 py-2 text-left hover:bg-[#ddead1] hover:text-[#75975e] transition-all duration-200 rounded-lg mx-1 cursor-pointer" data-value="annex">Annex Building</button>
                            </div>
                        </div>
                        <input type="hidden" id="building" name="building" value="">
                    </div>
                </div>

                <!-- Floor Level Selection -->
                <div id="floorLevelSelection" class="hidden">
                    <label for="floorLevel" class="block text-sm font-medium text-gray-700 mb-2">Floor Level</label>
                    <div class="relative">
                        <button type="button" id="floorLevelButton" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#a3c585] focus:border-[#a3c585] transition-colors bg-white text-left flex items-center justify-between">
                            <span id="floorLevelDisplay" class="text-gray-500">Select a floor</span>
                            <svg class="w-4 h-4 text-gray-400 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div id="floorLevelDropdown" class="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-10 hidden">
                            <div class="py-1">
                                <button type="button" class="w-full px-3 py-2 text-left hover:bg-[#ddead1] hover:text-[#75975e] transition-all duration-200 rounded-lg mx-1 cursor-pointer" data-value="Floor 1">Floor 1</button>
                                <button type="button" class="w-full px-3 py-2 text-left hover:bg-[#ddead1] hover:text-[#75975e] transition-all duration-200 rounded-lg mx-1 cursor-pointer" data-value="Floor 2">Floor 2</button>
                                <button type="button" class="w-full px-3 py-2 text-left hover:bg-[#ddead1] hover:text-[#75975e] transition-all duration-200 rounded-lg mx-1 cursor-pointer" data-value="Floor 3">Floor 3</button>
                                <button type="button" class="w-full px-3 py-2 text-left hover:bg-[#ddead1] hover:text-[#75975e] transition-all duration-200 rounded-lg mx-1 cursor-pointer" data-value="Floor 4">Floor 4</button>
                            </div>
                        </div>
                        <input type="hidden" id="floorLevel" name="floorLevel" value="">
                    </div>
                </div>

                <!-- Room Name -->
                <div id="roomNameSection" class="hidden">
                    <label for="roomName" class="block text-sm font-medium text-gray-700 mb-2">Room Name</label>
                    <div class="relative">
                        <div id="roomNamePrefix" class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500 font-medium hidden"></div>
                        <input type="text" id="roomName" name="roomName" placeholder="e.g., Computer Lab 102" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#a3c585] focus:border-[#a3c585] transition-colors">
                    </div>
                </div>

                <!-- Capacity -->
                <div>
                    <label for="capacity" class="block text-sm font-medium text-gray-700 mb-2">Capacity</label>
                    <input type="number" id="capacity" name="capacity" placeholder="e.g., 30" min="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#a3c585] focus:border-[#a3c585] transition-colors">
                </div>

                <!-- Buttons -->
                <div class="flex space-x-3 pt-4">
                    <button type="button" id="cancel-add-room" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-gradient-to-r from-[#75975e] to-[#a3c585] text-white rounded-lg hover:from-[#a3c585] hover:to-[#75975e] transition-all font-medium">
                        Add Room
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Generated Schedule Modal -->
    <div id="generated-schedule-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-7xl w-full p-6 relative animate-fadeIn max-h-[90vh] flex flex-col">
            <button id="close-generated-schedule" class="absolute top-4 right-4 text-[#75975e] text-2xl font-bold opacity-60 hover:opacity-100 transition">&times;</button>
            
            <!-- Header with Edit Toggle -->
            <div class="flex items-center justify-between mb-4 pb-4 border-b border-[#a3c585]/30">
                <div class="text-center flex-1">
                    <h1 class="text-2xl font-bold text-gray-800 mb-1">GINGOOG CITY COLLEGES, INC.</h1>
                    <p class="text-gray-600 text-sm mb-1">Gingoog City, Misamis Oriental</p>
                    <div id="generated-department" class="text-lg font-bold text-gray-800 mb-1">COLLEGE OF EDUCATION</div>
                    <div id="generated-semester" class="text-base font-semibold text-gray-700 mb-1">Second SEM., S.Y 2024-2025</div>
                    <div class="text-right text-xs text-gray-600" id="generated-date">AS OF JANUARY 2025 (REVISION: 3)</div>
                </div>
                <div class="flex items-center space-x-3">
                    <div class="flex flex-col items-end space-y-2">
                        <div class="flex items-center space-x-2">
                            <span class="text-sm font-medium text-gray-700">Edit</span>
                            <label class="relative cursor-pointer">
                                <input type="checkbox" id="edit-mode-toggle" class="sr-only">
                                <div class="w-16 h-8 bg-gray-300 rounded-full border border-gray-400 transition-all duration-300 ease-in-out relative overflow-hidden">
                                    <span class="absolute left-2 top-1/2 transform -translate-y-1/2 text-xs font-semibold text-gray-600 transition-opacity duration-300">OFF</span>
                                    <span class="absolute right-2 top-1/2 transform -translate-y-1/2 text-xs font-semibold text-white opacity-0 transition-opacity duration-300">ON</span>
                                    <div class="absolute left-0.5 top-0.5 bg-white w-7 h-7 rounded-full shadow-lg border border-gray-200 transition-transform duration-300 ease-in-out transform"></div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Schedule Content -->
            <div id="generated-schedule-content" class="flex-1 overflow-y-auto">
                <!-- Schedule tables will be dynamically generated here -->
            </div>
            
            <!-- Action Buttons -->
            <div class="flex justify-center gap-4 pt-4 border-t border-[#a3c585]/30">
                <button id="export-schedule-btn" class="px-6 py-3 rounded-lg bg-gradient-to-r from-blue-600 to-blue-700 text-white font-bold shadow hover:from-blue-700 hover:to-blue-800 transition">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Export
                </button>
                <button id="save-draft-btn" class="px-6 py-3 rounded-lg bg-gradient-to-r from-[#75975e] to-[#a3c585] text-white font-bold shadow hover:from-[#a3c585] hover:to-[#75975e] transition">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
                    </svg>
                    Save Draft
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="delete-confirmation-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 relative animate-fadeIn">
            <!-- Header -->
            <div class="flex items-center mb-4">
                <div class="w-10 h-10 bg-gradient-to-br from-red-500 to-red-600 rounded-xl flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Delete Draft</h2>
                    <p class="text-gray-500 text-sm">Confirm deletion</p>
                </div>
            </div>

            <!-- Content -->
            <div class="mb-6">
                <p class="text-gray-700 mb-2">Are you sure you want to delete this draft?</p>
                <p class="text-sm text-gray-500">This action cannot be undone.</p>
            </div>

            <!-- Buttons -->
            <div class="flex space-x-3">
                <button id="cancel-delete" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">
                    Cancel
                </button>
                <button id="confirm-delete" class="flex-1 px-4 py-2 bg-gradient-to-r from-red-500 to-red-600 text-white rounded-lg hover:from-red-600 hover:to-red-700 transition-all font-medium">
                    Delete
                </button>
            </div>
        </div>
    </div>

    <!-- View Room Details Modal -->
    <div id="view-room-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full p-6 relative animate-fadeIn max-h-[85vh] flex flex-col">
            <button id="close-view-room-modal" class="absolute top-4 right-4 text-gray-400 text-2xl font-bold opacity-60 hover:opacity-100 transition">&times;</button>
            
            <!-- Header -->
            <div class="flex items-center mb-6">
                <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Room Details</h2>
                    <p class="text-gray-500 text-sm">View room information and schedules</p>
                </div>
            </div>

            <!-- Room Information -->
            <div class="bg-gray-50 rounded-lg p-4 mb-6">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Room Name</label>
                        <p id="view-room-name" class="text-gray-900 font-semibold"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Room ID</label>
                        <p id="view-room-id" class="text-gray-900"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Capacity</label>
                        <p id="view-room-capacity" class="text-gray-900"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Room Type</label>
                        <p id="view-room-type" class="text-gray-900"></p>
                    </div>
                </div>
            </div>

            <!-- Schedules Section -->
            <div class="flex-1 overflow-y-auto">
                <div class="mb-4">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3">Associated Schedules</h3>
                    <div id="view-room-schedules" class="space-y-3">
                        <!-- Schedules will be loaded here -->
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="flex justify-end pt-4 border-t border-gray-200">
                <button id="close-view-room-btn" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Room Modal -->
    <div id="edit-room-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 relative animate-fadeIn">
            <button id="close-edit-room-modal" class="absolute top-4 right-4 text-gray-400 text-2xl font-bold opacity-60 hover:opacity-100 transition">&times;</button>
            
            <!-- Header -->
            <div class="flex items-center mb-6">
                <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Edit Room</h2>
                    <p id="edit-room-subtitle" class="text-gray-500 text-sm">Update room information</p>
                </div>
            </div>

            <!-- Form -->
            <form id="edit-room-form" class="space-y-4">
                <input type="hidden" id="edit-room-id" name="room_id">
                
                <!-- Room Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Room Type</label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="relative cursor-pointer">
                            <input type="radio" name="edit-room-type" value="lab" class="sr-only">
                            <div class="border-2 border-gray-200 rounded-lg p-3 text-center transition-all hover:border-blue-300 radio-selected:border-blue-500 radio-selected:bg-blue-50">
                                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-lg flex items-center justify-center mx-auto mb-2">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                                <span class="text-sm font-medium text-gray-700">Lab Room</span>
                            </div>
                        </label>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="edit-room-type" value="regular" class="sr-only">
                            <div class="border-2 border-gray-200 rounded-lg p-3 text-center transition-all hover:border-green-300 radio-selected:border-green-500 radio-selected:bg-green-50">
                                <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center mx-auto mb-2">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                    </svg>
                                </div>
                                <span class="text-sm font-medium text-gray-700">Regular Room</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Building Selection (for Regular Rooms) -->
                <div id="editBuildingSelection" class="hidden">
                    <label for="editBuilding" class="block text-sm font-medium text-gray-700 mb-2">Building</label>
                    <div class="relative">
                        <button type="button" id="editBuildingButton" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#a3c585] focus:border-[#a3c585] transition-colors bg-white text-left flex items-center justify-between">
                            <span id="editBuildingDisplay" class="text-gray-500">Select a building</span>
                            <svg class="w-4 h-4 text-gray-400 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div id="editBuildingDropdown" class="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-10 hidden">
                            <div class="py-1">
                                <button type="button" class="w-full px-3 py-2 text-left hover:bg-[#ddead1] hover:text-[#75975e] transition-all duration-200 rounded-lg mx-1 cursor-pointer" data-value="college">College Building</button>
                                <button type="button" class="w-full px-3 py-2 text-left hover:bg-[#ddead1] hover:text-[#75975e] transition-all duration-200 rounded-lg mx-1 cursor-pointer" data-value="annex">Annex Building</button>
                            </div>
                        </div>
                        <input type="hidden" id="editBuilding" name="editBuilding" value="">
                    </div>
                </div>

                <!-- Room Name -->
                <div id="editRoomNameSection" class="hidden">
                    <label for="edit-room-name" class="block text-sm font-medium text-gray-700 mb-2">Room Name</label>
                    <div class="relative">
                        <div id="editRoomNamePrefix" class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500 font-medium hidden"></div>
                        <input type="text" id="edit-room-name" name="room_name" placeholder="e.g., Computer Lab 102" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#a3c585] focus:border-[#a3c585] transition-colors">
                    </div>
                </div>

                <!-- Capacity -->
                <div>
                    <label for="edit-room-capacity" class="block text-sm font-medium text-gray-700 mb-2">Capacity</label>
                    <input type="number" id="edit-room-capacity" name="capacity" placeholder="e.g., 30" min="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#a3c585] focus:border-[#a3c585] transition-colors">
                </div>

                <!-- Buttons -->
                <div class="flex space-x-3 pt-4">
                    <button type="button" id="cancel-edit-room" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-gradient-to-r from-[#75975e] to-[#a3c585] text-white rounded-lg hover:from-[#a3c585] hover:to-[#75975e] transition-all font-medium">
                        Update Room
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Room Confirmation Modal -->
    <div id="delete-room-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 relative animate-fadeIn">
            <!-- Header -->
            <div class="flex items-center mb-4">
                <div class="w-10 h-10 bg-gradient-to-br from-red-500 to-red-600 rounded-xl flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Delete Room</h2>
                    <p class="text-gray-500 text-sm">Confirm deletion</p>
                </div>
            </div>

            <!-- Content -->
            <div class="mb-6">
                <p class="text-gray-700 mb-2">Are you sure you want to delete room "<span id="delete-room-name"></span>"?</p>
                <p class="text-sm text-gray-500">This action cannot be undone. If the room has associated schedules, they will also be affected.</p>
            </div>

            <!-- Buttons -->
            <div class="flex space-x-3">
                <button id="cancel-delete-room" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">
                    Cancel
                </button>
                <button id="confirm-delete-room" class="flex-1 px-4 py-2 bg-gradient-to-r from-red-500 to-red-600 text-white rounded-lg hover:from-red-600 hover:to-red-700 transition-all font-medium">
                    Delete Room
                </button>
            </div>
        </div>
    </div>

    <!-- Progressive Schedule Generation Loader -->
    <div id="schedule-generator-loader" class="fixed inset-0 z-[10000] bg-black/80 backdrop-blur-sm hidden">
        <div class="flex items-center justify-center min-h-screen">
            <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-md w-full mx-4 relative">
                <!-- Header -->
                <div class="text-center mb-6">
                    <div class="w-20 h-20 flex items-center justify-center mx-auto mb-4">
                        <!-- Typewriter Animation -->
                        <div class="typewriter-alt">
                            <div class="slide"><i></i></div>
                            <div class="paper"></div>
                            <div class="keyboard"></div>
                        </div>
                    </div>
                    <h2 class="text-xl font-bold text-gray-800 mb-2">Generating Schedule</h2>
                    <p class="text-gray-600 text-sm">Please wait while the system creates your schedule...</p>
                </div>

                <!-- Progress Steps -->
                <div class="space-y-4 mb-6">
                    <div class="flex items-center space-x-3 step-item" data-step="1">
                        <div class="step-indicator w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">
                            <div class="w-2 h-2 bg-white rounded-full step-dot"></div>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-700">Analyzing instructor data</p>
                            <p class="text-xs text-gray-500">Processing course information and preferences</p>
                        </div>
                        <div class="step-check hidden">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                    </div>

                    <div class="flex items-center space-x-3 step-item" data-step="2">
                        <div class="step-indicator w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">
                            <div class="w-2 h-2 bg-white rounded-full step-dot"></div>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-700">Optimizing room assignments</p>
                            <p class="text-xs text-gray-500">Finding the best room and time combinations</p>
                        </div>
                        <div class="step-check hidden">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                    </div>

                    <div class="flex items-center space-x-3 step-item" data-step="3">
                        <div class="step-indicator w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">
                            <div class="w-2 h-2 bg-white rounded-full step-dot"></div>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-700">Resolving conflicts</p>
                            <p class="text-xs text-gray-500">Ensuring no scheduling overlaps or conflicts</p>
                        </div>
                        <div class="step-check hidden">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                    </div>

                    <div class="flex items-center space-x-3 step-item" data-step="4">
                        <div class="step-indicator w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">
                            <div class="w-2 h-2 bg-white rounded-full step-dot"></div>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-700">Finalizing schedule</p>
                            <p class="text-xs text-gray-500">Generating the final schedule layout</p>
                        </div>
                        <div class="step-check hidden">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Progress Bar -->
                <div class="w-full bg-gray-200 rounded-full h-2 mb-4">
                    <div class="bg-gradient-to-r from-[#75975e] to-[#a3c585] h-2 rounded-full progress-bar transition-all duration-500 ease-out" style="width: 0%"></div>
                </div>

                <!-- Status Text -->
                <div class="text-center">
                    <p id="loader-status-text" class="text-sm text-gray-600">Initializing...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Container for Top-Side Modals -->
    <div id="notification-container" class="fixed top-4 z-[9999] space-y-2 pointer-events-none" style="right: 18rem; max-width: calc(100vw - 36rem);">
        <!-- Notifications will be dynamically added here -->
    </div>

    <!-- SheetJS CDN for Excel preview -->
    <script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
    <!-- Filter Preferences Modal -->
    <div id="filter-preferences-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full p-6 relative animate-fadeIn max-h-[85vh] flex flex-col">
            <button id="close-filter-modal" class="absolute top-4 right-4 text-gray-400 text-2xl font-bold opacity-60 hover:opacity-100 transition">&times;</button>
            
            <!-- Header -->
            <div class="flex items-center mb-6">
                <div class="w-10 h-10 bg-gradient-to-br from-[#75975e] to-[#a3c585] rounded-xl flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.207A1 1 0 013 6.5V4z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Schedule Preferences</h2>
                    <p class="text-gray-500 text-sm">Set instructor preferences (soft constraints)</p>
                </div>
            </div>

            <!-- Content -->
            <div class="flex-1 overflow-y-auto">
                <div class="grid grid-cols-1 gap-6">
                    <!-- Instructor Multi-Select -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Instructors <span class="text-xs text-gray-500">(Select one or more)</span></label>
                        
                        <!-- Search Input -->
                        <div class="relative mb-3">
                            <input 
                                type="text" 
                                id="instructor-search" 
                                placeholder="Search instructors..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#a3c585] focus:border-[#a3c585] transition-colors"
                            >
                            <svg class="absolute right-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        
                        <!-- Selected Instructors Chips -->
                        <div id="selected-instructors" class="flex flex-wrap gap-2 mb-3 min-h-[32px]"></div>
                        
                        <!-- Instructor List Container -->
                        <div id="instructor-list-container" class="border border-gray-300 rounded-lg max-h-48 overflow-y-auto bg-white">
                            <div id="instructor-list" class="p-2">
                                <!-- Instructors will be loaded here -->
                            </div>
                        </div>
                        <div id="instructor-list-empty" class="text-center py-4 text-gray-500 text-sm hidden">
                            No instructors found
                        </div>
                    </div>

                    <!-- Preferred Time Dropdown -->
                    <div>
                        <label for="preferred-time-dropdown" class="block text-sm font-medium text-gray-700 mb-2">Preferred Time</label>
                        <select id="preferred-time-dropdown" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#a3c585] focus:border-[#a3c585] transition-colors">
                            <option value="">No preference</option>
                            <option value="morning">Morning (7:30 AM - 12:00 PM)</option>
                            <option value="afternoon">Afternoon (1:00 PM - 5:00 PM)</option>
                            <option value="evening">Evening (6:00 PM - 8:45 PM)</option>
                        </select>
                    </div>
                </div>

                <!-- Loading state -->
                <div id="filter-loading" class="text-center py-8 hidden">
                    <div class="inline-flex items-center space-x-2">
                        <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-[#75975e]"></div>
                        <span class="text-gray-600">Loading data...</span>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                <button id="clear-preferences-btn" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">Clear All</button>
                <button id="apply-preferences-btn" class="px-4 py-2 bg-gradient-to-r from-[#75975e] to-[#a3c585] text-white rounded-lg hover:from-[#a3c585] hover:to-[#75975e] transition-all font-medium">Apply Preferences</button>
            </div>
        </div>
    </div>

    <!-- Draft Name Modal -->
    <div id="draft-name-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 hidden">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 relative animate-fadeIn">
            <button id="close-draft-name-modal" class="absolute top-4 right-4 text-gray-400 text-2xl font-bold opacity-60 hover:opacity-100 transition">&times;</button>
            <h2 class="text-xl font-bold text-gray-800 mb-4">Save as Draft</h2>
            <input id="draft-name-input" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 mb-4 focus:ring-2 focus:ring-[#a3c585] focus:border-[#a3c585] transition-colors" placeholder="Enter draft name...">
            <div class="flex justify-end space-x-3">
                <button id="cancel-draft-name-btn" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">Cancel</button>
                <button id="confirm-draft-name-btn" class="px-4 py-2 bg-gradient-to-r from-[#75975e] to-[#a3c585] text-white rounded-lg hover:from-[#a3c585] hover:to-[#75975e] transition-all font-medium">Save</button>
            </div>
        </div>
    </div>
    <script src="{{ asset('JS/GenerateSched.js') }}"></script>
</body>
</html>
