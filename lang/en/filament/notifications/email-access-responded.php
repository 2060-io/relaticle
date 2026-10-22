<?php

declare(strict_types=1);

return [
    'approved' => [
        'title' => 'Email access approved',
        'body' => ':owner approved your request.',
        'body_without_owner' => 'Your request was approved.',
    ],

    'denied' => [
        'title' => 'Email access denied',
        'body' => ':owner denied your request.',
        'body_without_owner' => 'Your request was denied.',
    ],
];
