<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final readonly class ParksInactiveRecipientsTransport implements TransportInterface
{
    // Postmark's "inactive recipient" code, forwarded by the Mailcoach transport.
    private const int INACTIVE_RECIPIENT_STATUS = 406;

    public function __construct(private TransportInterface $inner) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return $this->inner->send($message, $envelope);
        } catch (HttpTransportException $exception) {
            throw_if($exception->getResponse()->getInfo('http_code') !== self::INACTIVE_RECIPIENT_STATUS, $exception);

            $this->park($message);

            return null;
        }
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    private function park(RawMessage $message): void
    {
        $recipients = $message instanceof Email ? $message->getTo() : [];

        $emails = array_map(
            fn (Address $recipient): string => EmailAddress::canonicalize($recipient->getAddress()),
            $recipients,
        );

        User::query()->whereIn('email', $emails)->update(['email_bounced_at' => now()]);

        Log::info('Mail provider rejected an inactive recipient.', [
            'recipients' => $emails,
            'subject' => $message instanceof Email ? $message->getSubject() : null,
        ]);
    }
}
