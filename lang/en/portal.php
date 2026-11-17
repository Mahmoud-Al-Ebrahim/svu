<?php

return [
    'not_your_class' => 'This class is not assigned to you.',
    'auth' => [
        'failed' => 'The username or password is incorrect.',
        'code_sent' => 'If the address is registered, a verification code has been sent to it.',
        'code_expired' => 'The code has expired. Ask for a new one.',
        'code_invalid' => 'The code is incorrect.',
        'wrong_password' => 'The current password is incorrect.',
    ],
    'uploads' => [
        'not_ready' => 'The file has not finished uploading or has already been used.',
        'bad_type' => 'This file type is not allowed. Allowed types: :types',
        'too_big' => 'The file is larger than the limit (:mb MB).',
        'closed' => 'This upload is already finished.',
        'wrong_chunk' => 'Expected chunk number :n.',
        'bad_chunk' => 'The chunk size is wrong.',
        'blocked_content' => 'This file\'s content is not allowed (program, script or web page).',
        'incomplete' => 'Not all parts of the file have arrived yet.',
        'expired' => 'This upload has expired. Please upload the file again.',
    ],
    'sessions' => [
        'not_editable' => 'A session that has been held cannot be changed or cancelled.',
        'bad_transition' => 'The session cannot move to that status from its current one.',
        'not_joinable' => 'This is not an upcoming live session.',
        'not_held' => 'A recording can only be added to a live session that has been held.',
        'has_recording' => 'This session already has a recording.',
        'recording_closed' => 'A recording can no longer be added once the session has been approved for payment.',
        'clash' => 'This class already has a session at that time: :title',
        'tutor_clash' => 'You have a session for another class at the same time: :title',
    ],
    'files' => [
        'bad_session' => 'The chosen session does not belong to this course or class.',
        'not_uploader' => 'Only the person who uploaded a file can delete it.',
    ],
    'exams' => [
        'not_cancellable' => 'An exam date can only be cancelled before it is approved.',
        'overlap' => 'Class :class already has an exam overlapping this time (at :time).',
        'same_day_exam' => 'Class :class has another exam that day at :time',
        'same_day_session' => 'Class :class has a session that day: :title',
        'already_decided' => 'This exam date has already been decided.',
    ],
    'accounts' => [
        'already_decided' => 'This request has already been decided.',
        'taken' => 'The e-mail or employee ID is already used by another account.',
        'approved_subject' => 'Your Faculty Portal account is active',
        'approved_body' => 'Your account is active. Username: :username — sign in with the password you chose in your request.',
        'rejected_subject' => 'Your Faculty Portal account request',
    ],
    'email' => [
        'no_students' => 'No students are enrolled in this class.',
        'not_your_student' => 'This address does not belong to a student in any of your classes.',
        'attachments_too_big' => 'Attachments add up to more than 25 MB.',
    ],
    'status' => [
        'session' => [
            'upcoming' => 'Scheduled',
            'notReported' => 'Not added to the finance report',
            'pendingApproval' => 'Awaiting program manager approval',
            'rejected' => 'Rejected by the program manager',
            'approvedUnpaid' => 'Approved, dues not paid yet',
            'paid' => 'Dues paid',
        ],
        'exam' => [
            'pending' => 'Awaiting program approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'done' => 'Held',
        ],
    ],
];
