<?php

namespace App\Social;

use App\Exceptions\BusinessRuleException;
use App\Models\CourseLesson;
use App\Models\CourseQuestion;
use App\Models\Employee;
use App\Models\LessonNote;
use App\Models\Post;
use App\Models\Program;
use App\Models\Registration;
use App\Models\User;
use App\Services\Cms\HtmlSanitizer;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** What sits around a lesson: private notes, questions to the trainer, and the lesson discussion thread. */
class CourseSocial
{
    public function __construct(private readonly SpaceService $spaces, private readonly NotificationService $notifications, private readonly SocialSettings $settings, private readonly PostService $posts) {}

    /** The person's registration in the program (any live status) — what entitles them to ask and to take part. */
    public function registrationFor(User $user, string $programId): ?Registration
    {
        $emp = Employee::where('user_id', $user->id)->value('id');

        return $emp ? Registration::where('employee_id', $emp)->where('program_id', $programId)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED, Registration::STATUS_PENDING])->latest()->first() : null;
    }

    public function staffOfProgram(User $user, string $programId): bool
    {
        return $user->hasPermission('forums.moderate')
            || DB::table('group_trainers as gt')->join('training_groups as g', 'g.id', '=', 'gt.group_id')->join('trainers as t', 't.id', '=', 'gt.trainer_id')->where('gt.status', 'approved')->where('g.program_id', $programId)->where('t.user_id', $user->id)->exists()
            || Program::whereKey($programId)->where('coordinator_id', $user->id)->exists();
    }

    // ---- notes ---------------------------------------------------------------------------------------

    /** @return Collection<int, LessonNote> */
    public function notes(User $user, CourseLesson $lesson)
    {
        return LessonNote::where(['user_id' => $user->id, 'lesson_id' => $lesson->id])->latest()->get();
    }

    public function addNote(User $user, CourseLesson $lesson, string $body): LessonNote
    {
        $this->assertTakesPart($user, $lesson->program_id);
        $body = trim(strip_tags($body));
        if ($body === '') {
            throw new BusinessRuleException('Write a note first.', 'empty');
        }

        return LessonNote::create(['user_id' => $user->id, 'lesson_id' => $lesson->id, 'body' => Str::limit($body, 4000, '')]);
    }

    public function deleteNote(User $user, LessonNote $note): void
    {
        abort_unless($note->user_id === $user->id, 403);
        $note->delete();
    }

    private function assertTakesPart(User $user, string $programId): void
    {
        if (! $this->registrationFor($user, $programId) && ! $this->staffOfProgram($user, $programId)) {
            abort(403);
        }
    }

    // ---- ask the trainer -----------------------------------------------------------------------------

    /** @param  array{subject: string, body: string, visibility?: string, lesson_id?: string|null}  $d */
    public function ask(User $user, Program $program, array $d): CourseQuestion
    {
        $reg = $this->registrationFor($user, $program->id);
        if (! $reg) {
            abort(403);
        }
        $subject = Str::limit(trim(strip_tags((string) $d['subject'])), 190, '');
        $body = HtmlSanitizer::clean((string) $d['body']);
        if ($subject === '' || trim(strip_tags($body)) === '') {
            throw new BusinessRuleException('Add a subject and your question.', 'empty');
        }
        $lessonId = ! empty($d['lesson_id']) && Str::isUuid($d['lesson_id']) ? CourseLesson::where('program_id', $program->id)->whereKey($d['lesson_id'])->value('id') : null;
        $vis = ($d['visibility'] ?? 'private') === 'group' && $reg->training_group_id ? 'group' : 'private';

        return DB::transaction(function () use ($user, $program, $reg, $subject, $body, $lessonId, $vis) {
            $q = CourseQuestion::create(['asker_id' => $user->id, 'program_id' => $program->id, 'group_id' => $reg->training_group_id, 'lesson_id' => $lessonId, 'visibility' => $vis, 'subject' => $subject, 'body' => $body, 'status' => 'open',
                'due_at' => now()->addHours((int) $this->settings->all()['trainer_sla_hours'])]);
            if ($vis === 'group') {
                $space = $this->spaces->groupForum($reg->trainingGroup);
                $post = $this->posts->create($space, $user, ['kind' => 'question', 'title' => $subject, 'body' => $body]);
                $q->update(['post_id' => $post->id]);
            }
            $this->notifications->broadcast($this->trainerIds($program, $reg->training_group_id), 'course.question', ['ar' => 'سؤال جديد من متدرب', 'en' => 'A trainee asked a question'], ['ar' => $subject, 'en' => $subject],
                ['question_id' => $q->id, 'program_id' => $program->id, 'route' => '/admin/trainer-inbox'], raw: true);

            return $q;
        });
    }

    /** Trainers of the person's group, else of the whole program, else the program coordinator. @return list<string> */
    public function trainerIds(Program $program, ?string $groupId): array
    {
        $q = DB::table('group_trainers as gt')->join('trainers as t', 't.id', '=', 'gt.trainer_id')->where('gt.status', 'approved')->whereNotNull('t.user_id');
        $ids = (clone $q)->when($groupId, fn ($w) => $w->where('gt.group_id', $groupId))->pluck('t.user_id')->all();
        if (! $ids) {
            $ids = $q->join('training_groups as g', 'g.id', '=', 'gt.group_id')->where('g.program_id', $program->id)->pluck('t.user_id')->all();
        }
        if (! $ids && $program->coordinator_id) {
            $ids = [$program->coordinator_id];
        }

        return array_values(array_unique($ids));
    }

    public function answer(CourseQuestion $q, User $by, string $answer): CourseQuestion
    {
        if (! $this->staffOfProgram($by, $q->program_id)) {
            abort(403);
        }
        $answer = HtmlSanitizer::clean($answer);
        if (trim(strip_tags($answer)) === '') {
            throw new BusinessRuleException('Write the answer first.', 'empty');
        }
        $q->update(['answer' => $answer, 'answered_by' => $by->id, 'answered_at' => now(), 'status' => 'answered']);
        $this->notifications->send($q->asker_id, 'course.question_answered', ['ar' => 'تمت الإجابة عن سؤالك', 'en' => 'Your question was answered'], ['ar' => $q->subject, 'en' => $q->subject], ['question_id' => $q->id, 'route' => '/portal/questions'], raw: true);
        if ($q->post_id && ($post = Post::find($q->post_id))) {
            $this->posts->comment($post, $by, ['body' => $answer]);
        }

        return $q;
    }

    public function close(CourseQuestion $q, User $by): CourseQuestion
    {
        abort_unless($q->asker_id === $by->id || $this->staffOfProgram($by, $q->program_id), 403);
        $q->update(['status' => 'closed']);

        return $q;
    }

    /** Questions the trainer can see: those of their groups or programs; @param  array<string, mixed>  $f */
    public function inbox(User $user, array $f = [])
    {
        $q = CourseQuestion::with('asker:id,name,name_ar', 'program:id,title_ar,title_en')->latest();
        if (! $user->hasPermission('forums.moderate')) {
            $programIds = DB::table('group_trainers as gt')->join('training_groups as g', 'g.id', '=', 'gt.group_id')->join('trainers as t', 't.id', '=', 'gt.trainer_id')->where('gt.status', 'approved')->where('t.user_id', $user->id)->pluck('g.program_id')
                ->merge(Program::where('coordinator_id', $user->id)->pluck('id'))->unique();
            $q->whereIn('program_id', $programIds);
        }
        if (! empty($f['status']) && in_array($f['status'], ['open', 'answered', 'closed'], true)) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['overdue'])) {
            $q->where('status', 'open')->where('due_at', '<', now());
        }

        return $q;
    }

    /** Questions past the service-level target: the trainers are reminded once and the coordinator is told. */
    public function chaseOverdue(): int
    {
        $n = 0;
        CourseQuestion::with('program')->where('status', 'open')->where('due_at', '<', now())->whereNull('answered_at')->get()->each(function (CourseQuestion $q) use (&$n) {
            $key = 'sla-chased:'.$q->id;
            if (! Cache::add($key, 1, now()->addDay())) {
                return;
            }
            $ids = array_unique(array_merge($this->trainerIds($q->program, $q->group_id), array_filter([$q->program->coordinator_id])));
            $n += $this->notifications->broadcast($ids, 'course.question_overdue', ['ar' => 'سؤال متأخر عن الرد', 'en' => 'A question is overdue'], ['ar' => $q->subject, 'en' => $q->subject], ['question_id' => $q->id, 'route' => '/admin/trainer-inbox'], raw: true);
        });

        return $n;
    }
}
