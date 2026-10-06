<?php

namespace App\Services\Notifications;

use App\Models\Employee;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What happened to every notification, per person and channel: in the app (delivered, read) and by push, e-mail and SMS
 * (queued, sent, delivered, read, failed, skipped with the reason). "Read" for a message sent outside the app means the person opened the same notification in the app.
 */
class DeliveryReport
{
    public const STATUSES = ['queued', 'sent', 'delivered', 'read', 'failed', 'skipped'];

    public const CHANNELS = ['in_app', 'push', 'email', 'sms'];

    /** @param  array{campaign_id?: ?string, type?: ?string, channel?: ?string, status?: ?string, from?: ?string, to?: ?string, q?: ?string}  $f */
    public function query(array $f, ?User $by = null): Builder
    {
        $inApp = DB::table('notifications as n')->select([
            'n.id', 'n.user_id', DB::raw("'in_app' as channel"), 'n.type', 'n.campaign_id', 'n.title_ar', 'n.title_en',
            DB::raw("case when n.read_at is not null then 'read' else 'delivered' end as status"), DB::raw('null as reason'), 'n.created_at', 'n.read_at',
        ]);
        $out = DB::table('notification_deliveries as d')->leftJoin('notifications as n', 'n.id', '=', 'd.notification_id')->select([
            'd.id', 'd.user_id', 'd.channel', 'd.type', DB::raw('coalesce(d.campaign_id, n.campaign_id) as campaign_id'), 'n.title_ar', 'n.title_en',
            DB::raw("case when d.status in ('sent','delivered') and n.read_at is not null then 'read' else d.status end as status"), 'd.reason', 'd.created_at', 'n.read_at',
        ]);

        $apply = function (Builder $q, string $alias, string $campaign) use ($f, $by) {
            $q->when($f['type'] ?? null, fn ($w, $v) => $w->where("{$alias}.type", $v))
                ->when($f['campaign_id'] ?? null, fn ($w, $v) => $w->whereRaw($campaign.' = ?', [$v]))
                ->when($f['from'] ?? null, fn ($w, $v) => $w->where("{$alias}.created_at", '>=', $v))
                ->when($f['to'] ?? null, fn ($w, $v) => $w->where("{$alias}.created_at", '<=', $v.' 23:59:59'));
            if ($by && ! ($scope = AccessScope::current($by))->isMinistryWide()) {
                $users = $scope->constrainEmployees(Employee::query())->select('user_id');
                $q->whereIn("{$alias}.user_id", $users->toBase());
            }
            if (filled($f['q'] ?? null)) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower((string) $f['q'])).'%';
                $q->whereIn("{$alias}.user_id", DB::table('users')->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like])->select('id'));
            }
        };
        $apply($inApp, 'n', 'n.campaign_id');
        $apply($out, 'd', 'coalesce(d.campaign_id, n.campaign_id)');

        $channel = $f['channel'] ?? null;
        $union = match (true) {
            $channel === 'in_app' => $inApp,
            $channel !== null && $channel !== '' => $out->where('d.channel', $channel),
            default => $inApp->unionAll($out),
        };
        $outer = DB::query()->fromSub($union, 'x');
        if (filled($f['status'] ?? null)) {
            $outer->where('x.status', $f['status']);
        }

        return $outer;
    }

    public function paginate(array $f, ?User $by, int $perPage = 30): LengthAwarePaginator
    {
        $page = $this->query($f, $by)->orderByDesc('x.created_at')->paginate($perPage);
        $users = User::whereIn('id', collect($page->items())->pluck('user_id')->unique())->get()->keyBy('id');
        $page->setCollection($page->getCollection()->map(fn ($r) => (array) $r + ['user_name' => $users->get($r->user_id)?->displayName()]));

        return $page;
    }

    /** @return array{total: int, by_status: array<string, int>, by_channel: array<string, array<string, int>>} */
    public function summary(array $f, ?User $by = null): array
    {
        $rows = $this->query($f, $by)->selectRaw('x.channel, x.status, count(*) as n')->groupBy('x.channel', 'x.status')->get();
        $byStatus = array_fill_keys(self::STATUSES, 0);
        $byChannel = [];
        foreach ($rows as $r) {
            $byStatus[$r->status] = ($byStatus[$r->status] ?? 0) + (int) $r->n;
            $byChannel[$r->channel][$r->status] = (int) $r->n;
        }

        return ['total' => array_sum($byStatus), 'by_status' => $byStatus, 'by_channel' => $byChannel];
    }

    /** The document for the Excel / PDF export. @return array<string, mixed> */
    public function document(array $f, ?User $by, string $lang): array
    {
        $ar = $lang === 'ar';
        $summary = $this->summary($f, $by);
        $label = fn (string $s) => ['ar' => ['queued' => 'في الانتظار', 'sent' => 'أُرسل', 'delivered' => 'وصل', 'read' => 'قُرئ', 'failed' => 'فشل', 'skipped' => 'تم تخطيه', 'in_app' => 'داخل المنصة', 'push' => 'إشعار فوري', 'email' => 'بريد إلكتروني', 'sms' => 'رسالة نصية'],
            'en' => ['queued' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'read' => 'Read', 'failed' => 'Failed', 'skipped' => 'Skipped', 'in_app' => 'In the app', 'push' => 'Push', 'email' => 'E-mail', 'sms' => 'SMS']][$ar ? 'ar' : 'en'][$s] ?? $s;

        $summaryRows = [];
        foreach ($summary['by_channel'] as $channel => $statuses) {
            $summaryRows[] = array_merge([$label($channel)], array_map(fn ($s) => $statuses[$s] ?? 0, self::STATUSES), [array_sum($statuses)]);
        }
        $rows = $this->query($f, $by)->orderByDesc('x.created_at')->limit(5000)->get();
        $users = User::whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');

        return [
            'title' => $ar ? 'تقرير تسليم الإشعارات' : 'Notification delivery report',
            'subtitle' => $ar ? 'الإجمالي: '.$summary['total'] : 'Total: '.$summary['total'],
            'sections' => [
                ['heading' => $ar ? 'الملخص حسب القناة' : 'Summary by channel', 'table' => ['head' => array_merge([$ar ? 'القناة' : 'Channel'], array_map($label, self::STATUSES), [$ar ? 'المجموع' : 'Total']), 'rows' => $summaryRows]],
                ['heading' => $ar ? 'التفاصيل' : 'Details', 'table' => [
                    'head' => $ar ? ['المستلم', 'القناة', 'النوع', 'الحالة', 'السبب', 'التاريخ'] : ['Recipient', 'Channel', 'Type', 'Status', 'Reason', 'Date'],
                    'rows' => $rows->map(fn ($r) => [$users->get($r->user_id)?->displayName() ?? '—', $label($r->channel), $r->type, $label($r->status), $r->reason ?? '', substr((string) $r->created_at, 0, 16)])->all(),
                ]],
            ],
        ];
    }
}
