<?php

declare(strict_types=1);

return [
    'title' => '{1}Email not sent|[2,*]:count emails not sent',
    'body_one' => '":subject" was not sent. Review it on the Failed tab and try again.',
    'body_many' => 'Review them on the Failed tab and try again.',
    'no_subject' => '(no subject)',
    'action' => 'View failed emails',
    'reasons' => [
        'mailbox_needs_reconnect' => 'The mailbox needs reconnecting before this email can be sent.',
    ],
];
