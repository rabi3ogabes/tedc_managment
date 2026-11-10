<?php

namespace App\Gamification;

use App\Models\Badge;
use App\Models\GamificationRule;
use App\Models\Level;

/** The starting rules, levels and badges. Safe to run again: it only adds what is missing, so edits made in the studio stay. */
class Defaults
{
    /** event => [points, caps, ar, en] */
    public const RULES = [
        'lesson_completed' => [10, null, 'إكمال درس', 'Complete a lesson'],
        'course_completed' => [50, null, 'إكمال برنامج', 'Complete a program'],
        'assessment_passed' => [20, null, 'اجتياز اختبار', 'Pass an assessment'],
        'post_created' => [5, ['day' => 3], 'نشر موضوع', 'Start a discussion'],
        'comment_posted' => [2, ['day' => 10], 'تعليق', 'Write a comment'],
        'answer_accepted' => [15, null, 'إجابة معتمدة', 'Have an answer accepted'],
        'reaction_received' => [1, ['day' => 20], 'تفاعل على مشاركتك', 'Receive a reaction'],
        'pd_approved' => [20, null, 'اعتماد نشاط تطوير مهني', 'Approved PD activity'],
        'daily_login' => [2, ['day' => 1], 'دخول يومي', 'Daily visit'],
        'challenge_completed' => [0, null, 'إكمال تحدٍّ', 'Complete a challenge'],
    ];

    public const LEVELS = [
        [1, 'مبتدئ', 'Newcomer', 0], [2, 'متعلم', 'Learner', 100], [3, 'ممارس', 'Practitioner', 300], [4, 'متقدم', 'Advanced', 700], [5, 'خبير', 'Expert', 1500], [6, 'رائد', 'Pioneer', 3000],
    ];

    /** code => [ar, en, tier, criteria] */
    public const BADGES = [
        'first_lesson' => ['أول خطوة', 'First step', 'bronze', ['event' => 'lesson_completed', 'count' => 1]],
        'ten_lessons' => ['متعلم مثابر', 'Steady learner', 'silver', ['event' => 'lesson_completed', 'count' => 10]],
        'course_finisher' => ['أتمّ البرنامج', 'Programme finisher', 'silver', ['event' => 'course_completed', 'count' => 1]],
        'conversation_starter' => ['بادئ النقاش', 'Conversation starter', 'bronze', ['event' => 'post_created', 'count' => 3]],
        'helper' => ['عون الزملاء', 'Colleague helper', 'gold', ['event' => 'answer_accepted', 'count' => 3]],
        'weekly_streak' => ['حضور مستمر', 'Streak', 'silver', ['event' => 'daily_login', 'count' => 7, 'within_days' => 7]],
    ];

    public static function seed(): void
    {
        foreach (self::RULES as $event => [$points, $caps]) {
            GamificationRule::firstOrCreate(['event' => $event], ['points' => $points, 'caps' => $caps, 'is_active' => true]);
        }
        foreach (self::LEVELS as [$no, $ar, $en, $min]) {
            Level::firstOrCreate(['level_no' => $no], ['name_ar' => $ar, 'name_en' => $en, 'min_points' => $min]);
        }
        foreach (self::BADGES as $code => [$ar, $en, $tier, $criteria]) {
            Badge::firstOrCreate(['code' => $code], ['name_ar' => $ar, 'name_en' => $en, 'tier' => $tier, 'criteria' => $criteria, 'icon_svg' => self::icon($tier), 'is_active' => true]);
        }
    }

    public static function icon(string $tier): string
    {
        $c = ['bronze' => '#b4743b', 'silver' => '#8d99a6', 'gold' => '#c9a227'][$tier] ?? '#8d99a6';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><circle cx="32" cy="32" r="28" fill="'.$c.'"/><circle cx="32" cy="32" r="22" fill="none" stroke="#fff" stroke-opacity=".7" stroke-width="2"/><path d="M32 17l4.6 9.4 10.4 1.5-7.5 7.3 1.8 10.3L32 40.6l-9.3 4.9 1.8-10.3-7.5-7.3 10.4-1.5z" fill="#fff"/></svg>';
    }
}
