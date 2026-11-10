<?php

namespace App\Mail;

use App\Services\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A platform notification as an e-mail, in the recipient's language. */
class NotificationMail extends Mailable
{
    public function __construct(public readonly string $heading, public readonly ?string $text, public readonly string $language = 'ar') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.notification', with: [
            'title' => $this->heading, 'body' => $this->text, 'locale' => $this->language === 'en' ? 'en' : 'ar',
            'center' => app(ThemeService::class)->centerName(), 'url' => rtrim((string) config('app.url'), '/').'/portal',
        ]);
    }
}
