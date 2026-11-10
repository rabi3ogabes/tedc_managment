<?php

namespace App\Ai\Assistant;

use App\Ai\AiGuard;
use App\Ai\Rag\Retriever;
use App\Exceptions\BusinessRuleException;
use App\Integrations\Ministry\SaeedTickets;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Program;
use App\Models\User;
use App\Services\Assessment\ArabicText;
use App\Social\CourseSocial;
use Illuminate\Support\Str;

/**
 * The trainee's assistant. It answers from the platform's own content (retrieval) and from the person's own records (tools), says where each answer
 * came from, declines anything that is not about their training, and hands over to the trainer or to support when it is not sure.
 */
class TraineeAssistant
{
    /** Words that mark a question as being about training on this platform, its programmes and its own features. */
    private const DOMAIN = ['تدريب', 'برنامج', 'دوره', 'دورات', 'شهاد', 'تسجيل', 'حضور', 'اختبار', 'مدرب', 'درس', 'منصه', 'ساعات', 'انسحاب', 'جلسه', 'تقييم', 'مكتبه', 'ترخيص', 'رخصه', 'مسار',
        'بوابه', 'اشعار', 'تنبيه', 'حساب', 'كلمه المرور', 'استرداد', 'تذكره', 'مساعد', 'مجتمع', 'نقاط', 'شارات', 'لوحه الشرف', 'بياناتي', 'خصوصيه', 'غياب', 'عذر', 'مقعد', 'مقاعد', 'قائمه الانتظار', 'مقرر',
        'training', 'course', 'program', 'certificate', 'register', 'attendance', 'exam', 'assessment', 'trainer', 'lesson', 'platform', 'hours', 'withdraw', 'session', 'library', 'licence', 'license', 'path',
        'portal', 'notification', 'alert', 'account', 'password', 'two-step', 'refund', 'ticket', 'assistant', 'communit', 'badge', 'leaderboard', 'my data', 'privacy', 'absence', 'excuse', 'seat', 'waiting list', 'enrol'];

    public function __construct(private readonly Retriever $retriever, private readonly AiGuard $guard, private readonly AssistantTools $tools, private readonly CourseSocial $course, private readonly SaeedTickets $tickets) {}

    public function conversation(User $user, ?string $id, string $locale): AssistantConversation
    {
        if ($id && Str::isUuid($id) && ($c = AssistantConversation::where('user_id', $user->id)->find($id))) {
            return $c;
        }

        return AssistantConversation::create(['user_id' => $user->id, 'locale' => $locale === 'en' ? 'en' : 'ar']);
    }

    /**
     * Answers a question in a conversation and keeps both. With $onDelta the answer is passed on piece by piece as it is
     * written (a model that streams, or the rule-based answer in short pieces); when $stopped says so it ends early and
     * what was said so far is kept, marked as cancelled.
     */
    public function ask(User $user, AssistantConversation $c, string $text, ?callable $onDelta = null, ?callable $stopped = null): AssistantMessage
    {
        $text = trim(mb_substr(strip_tags($text), 0, 2000));
        if ($text === '') {
            throw new BusinessRuleException('Write your question first.', 'empty');
        }
        $history = AssistantMessage::where('conversation_id', $c->id)->orderByDesc('created_at')->limit(6)->get()->reverse()->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all();
        AssistantMessage::create(['conversation_id' => $c->id, 'role' => 'user', 'content' => $text]);
        if (! $c->title) {
            $c->update(['title' => Str::limit($text, 80, '')]);
        }
        $r = $this->compose($user, $text, $history, $onDelta, $stopped, true, $c->locale);

        return $this->reply($c, $r['answer'], $r['citations'], $r['meta']);
    }

    /**
     * The answer to one question, without keeping anything (the golden-set evaluation uses it directly).
     *
     * @param  list<array{role: string, content: string}>  $history  the conversation so far
     * @return array{answer: string, citations: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function compose(User $user, string $text, array $history = [], ?callable $onDelta = null, ?callable $stopped = null, bool $useModel = true, string $locale = 'ar'): array
    {
        $ar = $locale !== 'en' && preg_match('/\p{Arabic}/u', $text) || ($locale === 'ar' && ! preg_match('/[A-Za-z]{3,}/', $text));

        $toolKeys = $user->exists ? $this->tools->detect($text) : [];
        $data = [];
        foreach ($toolKeys as $k) {
            $data += $this->tools->run($k, $user);
        }
        $hits = $this->retriever->search($user, $text, 4);
        // A weak match alone does not make a question about training: it needs the person's own records, the words of
        // the platform, or a passage that clearly answers it.
        $onTopic = $toolKeys || ($hits && $hits[0]['strong']) || $this->mentionsTraining($text);
        $meta = ['tools' => $toolKeys, 'refused' => false, 'source' => 'rules', 'confident' => true, 'escalate' => null];

        if (! $onTopic) {
            $meta['refused'] = true;
            $meta['confident'] = false;
            $answer = $ar ? 'أستطيع مساعدتك في أمور تدريبك على المنصة فقط: البرامج والدروس والجدول والشهادات والتسجيل. جرّب سؤالًا من هذا النوع، أو اسأل مدربك مباشرة.' : 'I can only help with your training on the platform: programmes, lessons, your schedule, certificates and registration. Try a question like that, or ask your trainer directly.';
            $meta['escalate'] = $this->escalation($user);

            return ['answer' => $this->emit($answer, $onDelta, $stopped, $meta), 'citations' => [], 'meta' => $meta];
        }

        $toolText = $data ? $this->tools->describe($data, $ar) : '';
        $citations = array_map(fn ($h, $i) => ['n' => $i + 1, 'title' => $h['title'], 'type' => $h['source_type'], 'source_id' => $h['source_id'], 'route' => $h['route'], 'score' => $h['score']], $hits, array_keys($hits));
        $strong = $hits && $hits[0]['strong'];
        if (! $toolText && ! $strong) {
            $meta['confident'] = false;
            $meta['escalate'] = $this->escalation($user);
        }

        $answer = null;
        if ($useModel && ($toolText || $hits)) {
            $context = implode("\n\n", array_map(fn ($h, $i) => '['.($i + 1)."] {$h['title']}\n{$h['content']}", $hits, array_keys($hits)));
            $system = 'You are the assistant of a teacher-training platform of the Ministry of Education. Answer ONLY from the CONTEXT and MY DATA below. '
                .'If they do not contain the answer, say you are not sure and suggest asking the trainer. Cite sources as [1], [2]. Do not invent dates, rules or links. '
                .'Refuse anything unrelated to the person\'s training. Reply in '.($ar ? 'Arabic' : 'English').' in at most 6 sentences.'
                ."\n\nCONTEXT:\n".($context ?: '(none)')."\n\nMY DATA:\n".($toolText ?: '(none)');
            $messages = [...$history, ['role' => 'user', 'content' => $text]];
            $names = array_values(array_filter([$user->name, $user->name_ar]));
            if ($onDelta) {
                $res = $this->guard->stream('assistant', $system, $messages, $user, true, $names, 700, $onDelta, $stopped);
                if ($res && trim($res['text']) !== '') {
                    $answer = $res['text'];   // exactly what was shown
                    $meta['source'] = 'ai';
                    $meta += array_filter(['cancelled' => $res['cancelled'], 'interrupted' => $res['interrupted']]);
                }
            } else {
                $res = $this->guard->text('assistant', $system, $messages, $user, true, $names, 700);
                if ($res && trim($res['text']) !== '') {
                    $answer = trim($res['text']);
                    $meta['source'] = 'ai';
                }
            }
        }
        $answer ??= $this->emit($this->extractive($toolText, $hits, $ar, $strong), $onDelta, $stopped, $meta);

        return ['answer' => $answer, 'citations' => $strong ? $citations : array_slice($citations, 0, 2), 'meta' => $meta];
    }

    /** Passes a finished answer on in short pieces; stopping keeps what was passed. @param  array<string, mixed>  $meta */
    private function emit(string $answer, ?callable $onDelta, ?callable $stopped, array &$meta): string
    {
        if (! $onDelta) {
            return $answer;
        }
        $said = '';
        foreach (preg_split('/(\s+)/u', $answer, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            $onDelta($piece);
            $said .= $piece;
            if ($stopped && $stopped()) {
                $meta['cancelled'] = true;

                return $said;
            }
        }

        return $answer;
    }

    private function mentionsTraining(string $q): bool
    {
        $n = ArabicText::normalize($q);
        foreach (self::DOMAIN as $w) {
            if (str_contains($n, $w)) {
                return true;
            }
        }

        return false;
    }

    /** Without a model: the best passages, quoted, with their numbers. @param  list<array<string, mixed>>  $hits */
    private function extractive(string $toolText, array $hits, bool $ar, bool $strong): string
    {
        $parts = [];
        if ($toolText) {
            $parts[] = $toolText;
        }
        if ($strong) {
            foreach (array_slice($hits, 0, 2) as $i => $h) {
                $parts[] = Str::limit(trim(preg_replace('/\s+/u', ' ', $h['content']) ?? ''), 320).' ['.($i + 1).']';
            }
        } elseif (! $toolText) {
            $parts[] = $ar ? 'لم أجد إجابة واضحة في محتوى المنصة. يمكنك سؤال مدربك أو التواصل مع الدعم.' : 'I could not find a clear answer in the platform content. You can ask your trainer or contact support.';
        }

        return implode("\n\n", $parts);
    }

    /** @param  list<array<string, mixed>>  $citations @param  array<string, mixed>  $meta */
    private function reply(AssistantConversation $c, string $answer, array $citations, array $meta): AssistantMessage
    {
        $c->touch();

        return AssistantMessage::create(['conversation_id' => $c->id, 'role' => 'assistant', 'content' => $answer, 'citations' => $citations, 'meta' => $meta]);
    }

    /** @return array{trainer_programs: list<array{id: string, title_ar: string, title_en: string}>, support: bool} */
    private function escalation(User $user): array
    {
        $programs = Program::whereIn('id', $this->retriever->programsOf($user))->get(['id', 'title_ar', 'title_en'])->map(fn ($p) => ['id' => $p->id, 'title_ar' => $p->title_ar, 'title_en' => $p->title_en])->all();

        return ['trainer_programs' => $programs, 'support' => true];
    }

    /** Hands the question over: to the trainer (a question in Ask the trainer) or to support (a ticket). @return array<string, mixed> */
    public function escalate(User $user, AssistantMessage $answer, string $target, ?string $programId): array
    {
        $c = $answer->conversation;
        abort_unless($c->user_id === $user->id && $answer->role === 'assistant', 403);
        $question = AssistantMessage::where('conversation_id', $c->id)->where('role', 'user')->where('created_at', '<=', $answer->created_at)->orderByDesc('created_at')->value('content') ?? '';
        if ($target === 'trainer') {
            $program = $programId && Str::isUuid($programId) ? Program::find($programId) : null;
            if (! $program) {
                throw new BusinessRuleException('Choose the programme to ask about.', 'program_required');
            }
            $q = $this->course->ask($user, $program, ['subject' => Str::limit($question, 120, ''), 'body' => '<p>'.e($question).'</p>', 'visibility' => 'private']);
            $answer->update(['meta' => ($answer->meta ?? []) + ['escalated' => 'trainer', 'question_id' => $q->id]]);

            return ['target' => 'trainer', 'id' => $q->id];
        }
        $t = $this->tickets->create($user, ['category' => 'request', 'priority' => 'normal', 'subject' => Str::limit($question, 120, ''), 'description' => "Asked the assistant: {$question}\n\nAnswer given: {$answer->content}"]);
        $answer->update(['meta' => ($answer->meta ?? []) + ['escalated' => 'support', 'ticket_id' => $t->id]]);

        return ['target' => 'support', 'id' => $t->id];
    }
}
