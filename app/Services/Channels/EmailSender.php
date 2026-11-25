<?php

namespace App\Services\Channels;

use App\Mail\NotificationMail;
use App\Services\ThemeService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends one notification e-mail through the mail server chosen in the settings. Never throws: it returns the outcome. */
class EmailSender
{
    public function __construct(private readonly ChannelSettings $settings) {}

    /** @return array{ok: bool, error: ?string} */
    public function send(string $to, string $heading, ?string $text, string $locale = 'ar'): array
    {
        $s = $this->settings->all()['email'];
        $center = app(ThemeService::class)->centerName();

        try {
            $mailer = match ($s['driver']) {
                'smtp' => $this->smtp($s),
                'log' => 'log',
                default => null,
            };
            if ($mailer === null) {
                return ['ok' => false, 'error' => 'not_configured'];
            }
            Config::set('mail.from', ['address' => $s['from_address'] ?: config('mail.from.address'), 'name' => $s['from_name'] ?: $center[$locale === 'en' ? 'en' : 'ar']]);
            $message = new NotificationMail($heading, $text, $locale);
            filled($s['reply_to']) && $message->replyTo($s['reply_to']);
            Mail::mailer($mailer)->to($to)->send($message);

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 280)];
        }
    }

    /** Registers a mailer for the saved SMTP details and returns its name. */
    private function smtp(array $s): string
    {
        Config::set('mail.mailers.tedc_smtp', [
            'transport' => 'smtp', 'host' => $s['smtp_host'], 'port' => (int) $s['smtp_port'],
            'scheme' => $s['smtp_encryption'] === 'ssl' ? 'smtps' : 'smtp',   // STARTTLS is negotiated on its own; "ssl" means implicit TLS (port 465)
            'username' => $s['smtp_username'] ?: null, 'password' => $this->settings->secrets('email')['smtp_password'] ?? null, 'timeout' => 15,
        ]);
        Mail::purge('tedc_smtp');

        return 'tedc_smtp';
    }
}
