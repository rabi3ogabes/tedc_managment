<?php

namespace App\Mail;

use App\Models\Certificate;
use App\Services\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The certificate PDF, e-mailed to its holder. */
class CertificateMail extends Mailable
{
    public function __construct(public readonly Certificate $certificate, private readonly string $pdf) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'شهادتك من '.app(ThemeService::class)->centerName()['ar'].' · Your certificate from '.app(ThemeService::class)->centerName()['en']);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.certificate', with: [
            'name_ar' => $this->certificate->employee->user->name_ar ?: $this->certificate->employee->user->name,
            'name_en' => $this->certificate->employee->user->name,
            'program' => $this->certificate->program,
            'center' => app(ThemeService::class)->centerName(),
            'verifyUrl' => $this->certificate->verificationUrl(),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->certificate->certificate_no.'.pdf')->withMime('application/pdf')];
    }
}
