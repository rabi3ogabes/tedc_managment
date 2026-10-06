<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Channels\ChannelSettings;
use App\Services\Channels\EmailSender;
use App\Services\Channels\NotificationChannels;
use App\Services\Channels\SmsGateway;
use App\Services\ThemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings of the e-mail and SMS channels, a way to test them, and what was sent. */
class NotificationChannelsController extends Controller
{
    public function __construct(private readonly ChannelSettings $settings, private readonly NotificationChannels $channels) {}

    /** Which channels are on and usable: for the pickers shown next to "send" buttons. */
    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->channels->status()]);
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => ['settings' => $this->settings->forAdmin(), 'status' => $this->channels->status()] + $this->channels->report()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'defaults' => ['sometimes', 'array'], 'defaults.*' => ['boolean'],
            'email' => ['sometimes', 'array'],
            'email.enabled' => ['sometimes', 'boolean'], 'email.driver' => ['sometimes', Rule::in(['none', 'log', 'smtp'])],
            'email.from_name' => ['nullable', 'string', 'max:120'], 'email.from_address' => ['nullable', 'email', 'max:190'], 'email.reply_to' => ['nullable', 'email', 'max:190'],
            'email.smtp_host' => ['nullable', 'string', 'max:190'], 'email.smtp_port' => ['nullable', 'integer', 'between:1,65535'], 'email.smtp_encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'email.smtp_username' => ['nullable', 'string', 'max:190'], 'email.smtp_password' => ['nullable', 'string', 'max:300'], 'email.clear' => ['sometimes', 'array'],
            'sms' => ['sometimes', 'array'],
            'sms.enabled' => ['sometimes', 'boolean'], 'sms.driver' => ['sometimes', Rule::in(['none', 'log', 'hudhud', 'twilio', 'unifonic', 'http'])],
            'sms.sender' => ['nullable', 'string', 'max:20'], 'sms.default_country_code' => ['nullable', 'string', 'max:5', 'regex:/^\+?\d{1,4}$/'],
            'sms.twilio_sid' => ['nullable', 'string', 'max:100'], 'sms.twilio_from' => ['nullable', 'string', 'max:30'], 'sms.twilio_token' => ['nullable', 'string', 'max:200'],
            'sms.unifonic_app_sid' => ['nullable', 'string', 'max:200'],
            'sms.hudhud_base_url' => ['nullable', 'url:https,http', 'max:300'], 'sms.hudhud_send_path' => ['nullable', 'string', 'max:120'], 'sms.hudhud_username' => ['nullable', 'string', 'max:120'],
            'sms.hudhud_receipt_url' => ['nullable', 'url:https,http', 'max:300'], 'sms.hudhud_api_key' => ['nullable', 'string', 'max:300'], 'sms.hudhud_password' => ['nullable', 'string', 'max:300'], 'sms.hudhud_receipt_secret' => ['nullable', 'string', 'max:200'],
            'sms.http_url' => ['nullable', 'url:http,https', 'max:500'], 'sms.http_method' => ['nullable', Rule::in(['GET', 'POST'])], 'sms.http_format' => ['nullable', Rule::in(['json', 'form'])],
            'sms.http_headers' => ['nullable', 'string', 'max:1000'], 'sms.http_body' => ['nullable', 'string', 'max:1000'], 'sms.http_auth' => ['nullable', 'string', 'max:300'], 'sms.clear' => ['sometimes', 'array'],
        ]);
        $this->settings->update($data, $request->user());

        return $this->show();
    }

    /** Sends a test message right now so the settings can be checked before anything real goes out. */
    public function test(Request $request, EmailSender $email, SmsGateway $sms): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(['email', 'sms'])], 'to' => ['required', 'string', 'max:120']]);
        $center = app(ThemeService::class)->centerName();

        if ($data['channel'] === 'email') {
            abort_unless(filter_var($data['to'], FILTER_VALIDATE_EMAIL), 422, __('validation.email', ['attribute' => 'to']));
            $result = $email->send($data['to'], 'رسالة تجريبية · Test message', 'وصلتك هذه الرسالة لأن إعدادات البريد تعمل بنجاح. This message confirms that e-mail delivery works.', 'ar');
        } else {
            $number = $sms->normalise($data['to']);
            abort_unless($number, 422, __('validation.regex', ['attribute' => 'to']));
            $result = $sms->send($number, $center['ar'].': رسالة تجريبية — الرسائل النصية تعمل بنجاح.');
        }

        return response()->json(['data' => $result]);
    }
}
