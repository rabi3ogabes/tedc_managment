<?php

namespace App\Services\Surveys;

use App\Exceptions\BusinessRuleException;
use App\Models\Survey;
use App\Models\SurveyDistribution;
use App\Models\SurveyInvitation;
use App\Models\User;
use App\Services\Channels\ChannelSettings;
use App\Services\Channels\EmailSender;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * How a survey reaches people beyond the signed-in audience: one public link per survey (slug, QR, a duplicate rule and
 * a ceiling), single-use invitation tokens by e-mail, e-mail verification codes, reminders. Links are revoked by
 * regenerating them; tokens and codes are stored hashed; a public respondent is pseudonymised with the survey's own key
 * so that one-answer-per-person never needs the person's identity.
 */
class SurveyDistributor
{
    public function __construct(private readonly EmailSender $mail, private readonly ChannelSettings $channels) {}

    public function webUrl(): string
    {
        return rtrim((string) config('tedc.web_url'), '/');
    }

    public function publicLink(SurveyDistribution $d): string
    {
        return $this->webUrl().'/s/'.$d->slug;
    }

    public function invitationLink(string $token): string
    {
        return $this->webUrl().'/s/i/'.$token;
    }

    /** Creates (or updates) the survey's public link. */
    public function publicLinkFor(Survey $survey, User $by, string $rule = 'device', ?int $max = null): SurveyDistribution
    {
        $rule = in_array($rule, SurveyDistribution::RULES, true) ? $rule : 'device';
        $d = $survey->hasMany(SurveyDistribution::class)->where('channel', 'public')->first();
        if ($d) {
            $d->update(['duplicate_rule' => $rule, 'max_responses' => $max]);
            SurveyEvents::record($survey, 'distribution_updated', ['rule' => $rule, 'max' => $max], $by);

            return $d;
        }
        $d = SurveyDistribution::create(['survey_id' => $survey->id, 'channel' => 'public', 'slug' => $this->slug(), 'duplicate_rule' => $rule, 'max_responses' => $max, 'created_by' => $by->id]);
        SurveyEvents::record($survey, 'distribution_created', ['rule' => $rule, 'max' => $max], $by);

        return $d;
    }

    public function update(SurveyDistribution $d, User $by, array $data): SurveyDistribution
    {
        $patch = [];
        if (array_key_exists('is_active', $data)) {
            $patch['is_active'] = (bool) $data['is_active'];
        }
        if (isset($data['duplicate_rule']) && in_array($data['duplicate_rule'], SurveyDistribution::RULES, true)) {
            $patch['duplicate_rule'] = $data['duplicate_rule'];
        }
        if (array_key_exists('max_responses', $data)) {
            $patch['max_responses'] = $data['max_responses'] !== null ? max(1, (int) $data['max_responses']) : null;
        }
        $d->update($patch);
        SurveyEvents::record($d->survey, 'distribution_updated', $patch, $by);

        return $d;
    }

    /** A new address: every printed QR and shared link stops working at once. */
    public function regenerate(SurveyDistribution $d, User $by): SurveyDistribution
    {
        $d->update(['slug' => $this->slug(), 'qr_version' => $d->qr_version + 1, 'revoked_at' => null, 'is_active' => true]);
        SurveyEvents::record($d->survey, 'distribution_regenerated', ['qr_version' => $d->qr_version], $by);

        return $d;
    }

    /** The QR of the public link as SVG markup or PNG bytes. */
    public function qr(SurveyDistribution $d, string $format = 'svg'): string
    {
        $options = ['eccLevel' => EccLevel::M, 'scale' => 8, 'outputBase64' => false, 'quietzoneSize' => 2, 'addLogoSpace' => false];
        $options['outputInterface'] = $format === 'png' ? QRGdImagePNG::class : QRMarkupSVG::class;
        if ($format === 'png' && ! function_exists('imagecreatetruecolor')) {
            throw new BusinessRuleException(__('messages.surveys.qr_png_unavailable'), 'survey_qr_png_unavailable');
        }

        return (string) (new QRCode(new QROptions($options)))->render($this->publicLink($d));
    }

    /** The distribution behind a public address, or a business error saying why it cannot be answered. */
    public function resolvePublic(string $slug): SurveyDistribution
    {
        $d = SurveyDistribution::where('slug', $slug)->where('channel', 'public')->with('survey.currentVersion')->first();
        if (! $d || $d->revoked_at) {
            abort(404);
        }
        $s = $d->survey;
        if (! $d->is_active || ! $s->isOpen()) {
            throw new BusinessRuleException(__('messages.surveys.link_closed'), 'survey_link_closed');
        }
        if ($d->isFull()) {
            throw new BusinessRuleException(__('messages.surveys.link_full'), 'survey_link_full');
        }

        return $d;
    }

    public function resolveInvitation(string $token): SurveyInvitation
    {
        $i = SurveyInvitation::where('token_hash', hash('sha256', $token))->with('survey.currentVersion')->first();
        if (! $i) {
            abort(404);
        }
        if (! $i->survey->isOpen()) {
            throw new BusinessRuleException(__('messages.surveys.link_closed'), 'survey_link_closed');
        }
        if ($i->expires_at && $i->expires_at->isPast()) {
            throw new BusinessRuleException(__('messages.surveys.invitation_expired'), 'survey_invitation_expired');
        }

        return $i;
    }

    /** The pseudonymous key of a public respondent (device id, verified e-mail or invitation token), never the identity itself. */
    public function respondentKey(Survey $survey, string $seed): string
    {
        $key = $survey->pseudonym_key ?: throw new \LogicException('The survey has no pseudonym key; publish it first.');

        return hash_hmac('sha256', $seed, $key);
    }

    // E-mail verification -----------------------------------------------------------------------------------------

    /** Sends a six-digit code to the address; the hash lives fifteen minutes in the cache. */
    public function sendCode(SurveyDistribution $d, string $email): array
    {
        $email = Str::lower(trim($email));
        $code = (string) random_int(100000, 999999);
        Cache::put($this->codeKey($d, $email), hash('sha256', $code), now()->addMinutes(15));
        $result = $this->channels->ready('email')
            ? $this->mail->send($email, 'رمز التحقق · Verification code', "رمز التحقق الخاص بك: {$code}\nYour verification code: {$code}\n(15 min)")
            : ['ok' => false, 'error' => 'not_configured'];
        if (! ($result['ok'] ?? false)) {
            Cache::forget($this->codeKey($d, $email));
            throw new BusinessRuleException(__('messages.surveys.mail_off'), 'survey_mail_off');
        }

        return ['sent' => true];
    }

    /** Exchanges a correct code for a short-lived respondent token the client sends with drafts, files and the submission. */
    public function verifyCode(SurveyDistribution $d, string $email, string $code): array
    {
        $email = Str::lower(trim($email));
        $stored = Cache::get($this->codeKey($d, $email));
        if (! $stored || ! hash_equals($stored, hash('sha256', trim($code)))) {
            throw new BusinessRuleException(__('messages.surveys.code_invalid'), 'survey_code_invalid');
        }
        Cache::forget($this->codeKey($d, $email));
        $token = Str::random(40);
        Cache::put($this->tokenKey($d, $token), $email, now()->addHours(3));

        return ['respondent_token' => $token, 'expires_in' => 3 * 3600];
    }

    /** The verified e-mail behind a respondent token, or null. */
    public function emailForToken(SurveyDistribution $d, ?string $token): ?string
    {
        return $token ? Cache::get($this->tokenKey($d, $token)) : null;
    }

    // Invitations ---------------------------------------------------------------------------------------------------

    /**
     * Creates single-use invitations and sends them by e-mail when the channel is ready; otherwise the links are returned
     * once, for manual sharing, and the invitations are marked `manual`. An address with a usable invitation is reused.
     *
     * @param  list<array{email: string, name?: string|null}>  $people
     * @return array{created: int, sent: int, manual: int, failed: int, links: list<array{email: string, link: string}>}
     */
    public function invite(Survey $survey, User $by, array $people): array
    {
        if (! $survey->isOpen()) {
            throw new BusinessRuleException(__('messages.surveys.not_open'), 'survey_not_open');
        }
        $dist = $survey->hasMany(SurveyDistribution::class)->where('channel', 'invitation')->first()
            ?? SurveyDistribution::create(['survey_id' => $survey->id, 'channel' => 'invitation', 'slug' => $this->slug(), 'duplicate_rule' => 'open', 'created_by' => $by->id]);
        $mailReady = $this->channels->ready('email');
        $expires = $survey->closes_at ?? now()->addDays((int) config('tedc.surveys.invitation_retention_days', 90));
        $out = ['created' => 0, 'sent' => 0, 'manual' => 0, 'failed' => 0, 'links' => []];
        $seen = [];
        foreach ($people as $p) {
            $email = Str::lower(trim((string) ($p['email'] ?? '')));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $existing = SurveyInvitation::where('survey_id', $survey->id)->where('email', $email)->whereNull('responded_at')->first();
            $token = Str::random(48);
            $inv = DB::transaction(function () use ($existing, $survey, $dist, $email, $p, $token, $expires, $by) {
                $attrs = ['token_hash' => hash('sha256', $token), 'expires_at' => $expires, 'name' => Str::limit(trim((string) ($p['name'] ?? '')), 120, '') ?: null, 'send_status' => 'pending', 'send_error' => null];

                return $existing ? tap($existing)->update($attrs) : SurveyInvitation::create($attrs + ['survey_id' => $survey->id, 'distribution_id' => $dist->id, 'email' => $email, 'created_by' => $by->id]);
            });
            $out['created']++;
            $link = $this->invitationLink($token);
            if ($mailReady) {
                $r = $this->mail->send($email, 'دعوة للإجابة عن استبانة · Survey invitation', "دُعيت للإجابة عن استبانة «{$survey->title_ar}».\nالرابط الخاص بك (لا تشاركه): {$link}\n\nYou are invited to answer \"".($survey->title_en ?: $survey->title_ar)."\".\nYour personal link (do not share it): {$link}");
                if ($r['ok'] ?? false) {
                    $inv->update(['send_status' => 'sent', 'sent_at' => now()]);
                    $out['sent']++;
                } else {
                    $inv->update(['send_status' => 'failed', 'send_error' => Str::limit((string) ($r['error'] ?? 'send_failed'), 300, '')]);
                    $out['failed']++;
                    $out['links'][] = ['email' => $email, 'link' => $link];
                }
            } else {
                $inv->update(['send_status' => 'manual']);
                $out['manual']++;
                $out['links'][] = ['email' => $email, 'link' => $link];
            }
        }
        SurveyEvents::record($survey, 'invitations_sent', ['created' => $out['created'], 'sent' => $out['sent'], 'manual' => $out['manual'], 'failed' => $out['failed']], $by);

        return $out;
    }

    /** Re-sends the e-mail to invitations that were sent but not answered. Manual ones cannot be reminded by the system. */
    public function remind(Survey $survey, User $by): array
    {
        $pending = SurveyInvitation::where('survey_id', $survey->id)->whereNull('responded_at')->where('send_status', 'sent')->get();
        $sent = 0;
        if ($this->channels->ready('email')) {
            foreach ($pending as $inv) {
                // The original token is not stored, so a reminder carries a fresh single-use token.
                $token = Str::random(48);
                $inv->update(['token_hash' => hash('sha256', $token)]);
                $r = $this->mail->send($inv->email, 'تذكير بالاستبانة · Survey reminder', "لم نستلم إجابتك عن «{$survey->title_ar}» بعد.\n{$this->invitationLink($token)}");
                if ($r['ok'] ?? false) {
                    $inv->update(['reminded_at' => now()]);
                    $sent++;
                }
            }
        }
        SurveyEvents::record($survey, 'invitations_reminded', ['pending' => $pending->count(), 'sent' => $sent], $by);

        return ['pending' => $pending->count(), 'sent' => $sent, 'mail_ready' => $this->channels->ready('email')];
    }

    public function present(SurveyDistribution $d): array
    {
        return ['id' => $d->id, 'channel' => $d->channel, 'slug' => $d->slug, 'link' => $d->channel === 'public' ? $this->publicLink($d) : null, 'duplicate_rule' => $d->duplicate_rule,
            'max_responses' => $d->max_responses, 'is_active' => $d->is_active, 'qr_version' => $d->qr_version, 'submissions' => $d->submissions_count, 'full' => $d->isFull(), 'created_at' => $d->created_at];
    }

    public function presentInvitation(SurveyInvitation $i): array
    {
        return ['id' => $i->id, 'email' => $i->email, 'name' => $i->name, 'send_status' => $i->send_status, 'send_error' => $i->send_error, 'sent_at' => $i->sent_at, 'reminded_at' => $i->reminded_at, 'opened_at' => $i->opened_at, 'responded_at' => $i->responded_at, 'expires_at' => $i->expires_at];
    }

    private function slug(): string
    {
        do {
            $slug = Str::lower(Str::random(10));
        } while (SurveyDistribution::where('slug', $slug)->exists());

        return $slug;
    }

    private function codeKey(SurveyDistribution $d, string $email): string
    {
        return 'survey-code:'.$d->id.':'.hash('sha256', $email);
    }

    private function tokenKey(SurveyDistribution $d, string $token): string
    {
        return 'survey-token:'.$d->id.':'.hash('sha256', $token);
    }
}
