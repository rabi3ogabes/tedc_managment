<?php

namespace App\Integrations\Teams;

/**
 * The Microsoft Graph permissions each Teams feature needs, for the Ministry's admin consent (Settings → Integrations →
 * Teams shows each with a tick once the tenant granted it). Application permissions belong to TEDC's app registration;
 * delegated ones to the organiser account connected for town halls.
 */
class GraphPermissions
{
    /** @return list<array{permission: string, type: string, feature: string, ar: string, en: string}> */
    public static function all(): array
    {
        $p = fn (string $permission, string $type, string $feature, string $ar, string $en) => compact('permission', 'type', 'feature', 'ar', 'en');

        return [
            $p('OnlineMeetings.ReadWrite.All', 'application', 'meetings', 'إنشاء اجتماعات الجلسات وتحديثها وإلغاؤها (مع سياسة وصول التطبيق لحساب المنظِّم)', 'Create, update and cancel session meetings (with an application access policy for the organiser account)'),
            $p('OnlineMeetingArtifact.Read.All', 'application', 'meetings', 'قراءة تقارير حضور الاجتماعات', 'Read meeting attendance reports'),
            $p('User.Read.All', 'application', 'townhalls', 'العثور على المتدربين في المستأجر لدعوتهم إلى اللقاءات المفتوحة', 'Find trainees in the tenant to invite them to town halls'),
            $p('VirtualEvent.ReadWrite', 'delegated', 'townhalls', 'إنشاء اللقاءات المفتوحة ونشرها وتحديثها وإلغاؤها بحساب المنظِّم', 'Create, publish, update and cancel town halls as the organiser'),
            $p('OnlineMeetingArtifact.Read.All', 'delegated', 'townhalls', 'قراءة حضور اللقاءات المفتوحة', 'Read town hall attendance'),
            $p('Team.Create', 'application', 'teams', 'إنشاء فريق لكل مجموعة تدريبية', 'Create a team for each training group'),
            $p('TeamMember.ReadWrite.All', 'application', 'teams', 'مواءمة أعضاء الفريق مع التسجيلات', 'Keep team members in line with the registrations'),
            $p('Channel.ReadBasic.All', 'application', 'teams', 'قراءة القناة العامة للفريق', 'Read the team\'s general channel'),
            $p('Files.ReadWrite.All', 'application', 'files', 'نسخ مواد المجموعة إلى مجلد القناة', 'Copy the group\'s materials to the channel folder'),
            $p('TeamsActivity.Send', 'application', 'activity_feed', 'إرسال إشعارات المنصة إلى موجز النشاط في Teams', 'Send TEDC notifications to the Teams activity feed'),
        ];
    }

    /**
     * Each permission with whether the tenant granted it.
     *
     * @param  array{application: list<string>, delegated: list<string>|null}  $granted
     * @return list<array<string, mixed>>
     */
    public static function checklist(array $granted): array
    {
        $app = array_map('strtolower', $granted['application']);
        $delegated = $granted['delegated'] === null ? null : array_map('strtolower', $granted['delegated']);

        return array_map(fn (array $p) => $p + ['granted' => $p['type'] === 'application' ? in_array(strtolower($p['permission']), $app, true)
            : ($delegated !== null && in_array(strtolower($p['permission']), $delegated, true))], self::all());
    }
}
