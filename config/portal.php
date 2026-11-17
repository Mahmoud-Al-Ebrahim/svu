<?php

// Business settings for the faculty portal.
return [
    // Rules such as "in the future" or "tomorrow or later" are judged in this timezone; storage is UTC.
    'timezone' => env('BUSINESS_TIMEZONE', 'Asia/Damascus'),

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'sessions' => [
        'min_duration' => 15,
        'max_duration' => 240,
        'reminder_minutes' => 60,
    ],

    'exams' => [
        'min_duration' => 30,
        'max_duration' => 240,
        'earliest_start' => '08:00',
        'latest_start' => '20:00',
    ],

    'uploads' => [
        'chunk_size' => 8 * 1024 * 1024,
        // POST /uploads/direct; keep at or below PHP's upload_max_filesize (40M in XAMPP), bigger files use chunks.
        'direct_max_kb' => (int) env('DIRECT_UPLOAD_MAX_KB', 40 * 1024),
        'max_file_bytes' => 500 * 1024 * 1024,
        'max_recording_bytes' => 2 * 1024 * 1024 * 1024,
        'expires_hours' => 24,
        'file_extensions' => ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'mp4', 'txt'],
        'recording_extensions' => ['mp4', 'webm', 'lrec'],
        // Deleted files stay restorable this long, then portal:purge-deleted-files removes them from disk.
        'keep_deleted_days' => 30,
    ],

    'email' => [
        'domain' => 'svuonline.org',
        'max_attachments_bytes' => 25 * 1024 * 1024,
        'per_hour' => 20,
        'batch_size' => 50,
    ],

    'download_url_minutes' => 5,

    'files' => [
        // true: files for "all classes" of a course are seen by every tutor of that course (SVU's normal rule).
        // false (the default during the doctors' trial): only by the tutor who uploaded them, so each doctor's
        // demo classes stay private.
        'share_course_wide' => (bool) env('PORTAL_SHARE_COURSE_FILES', false),
    ],

    // Self sign-up. With `instant` on, the sign-up form creates an active Tutor account at once (university e-mail
    // only), signs the person in and gives them their own demo classes in `demo_courses` of the current term.
    // Turn it off (PORTAL_SIGNUP_INSTANT=false) to go back to account requests that an admin approves.
    'signup' => [
        'instant' => (bool) env('PORTAL_SIGNUP_INSTANT', true),
        'demo_courses' => ['ADB', 'WAP', 'AE'],
    ],
];
