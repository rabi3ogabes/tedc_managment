<?php

return [
    'registration' => [
        'mode_not_allowed' => 'This registration method is not available for this program.',
        'closed' => 'Registration for this program is currently closed.',
        'duplicate' => 'The employee is already registered in this program.',
        'not_eligible' => 'The employee is not eligible for this program.',
        'invalid_transition' => 'Cannot change registration status from :from to :to.',
        'outside_school' => 'You can only nominate employees of your own school.',
    ],
    'attendance' => [
        'invalid_qr' => 'The attendance code is invalid or has expired.',
        'not_registered' => 'You are not an approved participant of this program.',
        'not_open' => 'Check-in for this session has not opened yet.',
        'closed' => 'Check-in for this session is closed.',
        'checked_in' => 'Check-in recorded successfully.',
        'checked_out' => 'Check-out recorded successfully.',
        'already_checked_out' => 'You have already checked out.',
    ],
    'tasks' => [
        'type_not_allowed' => 'This file type is not accepted for this task.',
        'locked' => 'This task was approved and can no longer be changed.',
    ],
    'certificate' => [
        'too_many' => 'You can send at most :max certificates at once. Narrow the filter.',
        'blocked' => 'The certificate cannot be issued because requirements are not met.',
        'registration' => 'Approved program registration',
        'attendance' => 'Attendance :actual% (required :required%)',
        'tasks' => 'All required tasks approved (:done/:total)',
        'evaluation' => 'Program evaluation submitted',
        'title' => 'Certificate of Completion',
        'certify' => ':center certifies that',
        'completed' => 'has successfully completed the training program',
        'hours' => 'comprising :hours training hours',
        'verify' => 'Scan to verify this certificate',
    ],
    'survey' => [
        'not_due' => 'This survey is not due yet.',
        'completed' => 'This survey has already been completed.',
    ],
    'import' => [
        'invalid_file' => 'The file could not be read. Please use the official template.',
        'row_employee_missing' => 'Row :row: no employee found with number :no',
    ],
    'calendar' => [
        'closed_for_training' => 'Training cannot be scheduled on :dates because they are closed for training. Ask for approval first.',
        'already_approved' => 'This day is already approved for training.',
        'not_closed' => 'This day is already open for training and needs no approval.',
        'kind' => [
            'workday' => 'Working day',
            'weekend' => 'Weekend',
            'vacation' => 'Vacation',
            'exam' => 'Exam day',
            'normal' => 'Normal day (closed for training)',
        ],
    ],
    'room' => [
        'unavailable' => 'Room ":room" is not available (maintenance or inactive).',
        'booked' => 'Room ":room" is already booked at this time.',
        'capacity' => 'Room ":room" seats :capacity, fewer than the program needs (:needed).',
    ],
    'trainer' => [
        'inactive' => 'Trainer ":trainer" is inactive.',
        'busy' => 'Trainer ":trainer" already has another session at this time.',
    ],
    'program_builder' => [
        'nomination_note' => 'Automatic nomination within the program target audience.',
        'publish_first' => 'Publish the program before nominating its audience.',
    ],
    'task_answer_required' => 'Write your answer or attach a file before submitting the task.',
    'not_found' => 'The requested item was not found.',
];
