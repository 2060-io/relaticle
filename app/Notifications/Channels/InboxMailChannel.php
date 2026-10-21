<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Notifications\InboxNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

final class InboxMailChannel extends MailChannel
{
    public function send(mixed $notifiable, Notification $notification): mixed
    {
        if (! $notification instanceof InboxNotification || ! method_exists($notification, 'toMail')) {
            return parent::send($notifiable, $notification);
        }

        $message = $notification->toMail($notifiable);

        if (! $message instanceof Mailable) {
            return parent::send($notifiable, $notification);
        }

        return $this->mailer->mailer($message->mailer)->send($message);
    }
}
