<?php

declare(strict_types=1);

return [
    'title' => 'Notifications',

    'digest' => [
        'heading' => 'Daily digest',
        'title' => 'Enable daily digest',
        'description' => 'Includes tasks overdue and due today. Sent every morning if any tasks are due or overdue.',
    ],

    'collaboration' => [
        'heading' => 'Collaboration notifications',
        'notify_me_about' => 'Notify me about',
    ],

    'channels' => [
        'in_app' => 'App',
        'email' => 'Email',
    ],

    'types' => [
        'task_assigned' => [
            'label' => 'Task Assignments',
            'description' => 'Notify me when I\'m assigned a task.',
        ],
        'email_access' => [
            'label' => 'Email access',
            'description' => 'Notify me when someone asks to read an email I own, and when my own request is approved or denied.',
        ],
        'email_shared' => [
            'label' => 'Shared emails',
            'description' => 'Notify me when someone shares an email with me.',
        ],
        'task_digest' => [
            'label' => 'Daily Digest',
            'description' => 'Notify me every morning about tasks overdue and due today.',
        ],
    ],

    'events' => [
        'task_assigned' => [
            'title' => ':actor assigned you a task',
            'title_without_actor' => 'You have a new task',
            'action' => 'View task',
        ],
    ],

    'saved' => 'Notification preferences updated.',
];
