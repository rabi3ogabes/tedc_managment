<?php

namespace App\Services;

use App\Models\LogisticsRequest;
use App\Models\User;

/** Requests to the logistics team: routed to their queue, tracked to completion, escalated when overdue. */
class LogisticsService
{
    public const ITEMS = ['equipment', 'catering', 'printing', 'it', 'arrangement', 'other'];

    public function __construct(private readonly NotificationService $notifications) {}

    public function create(User $by, array $data): LogisticsRequest
    {
        $request = LogisticsRequest::create($data + ['requested_by' => $by->id, 'status' => 'new']);
        $team = $this->team();
        $needed = $request->needed_by ? $request->needed_by->toDateString() : '—';
        $team && $this->notifications->broadcast($team, 'logistics.request_new', ['ar' => 'طلب لوجستي جديد', 'en' => 'New logistics request'],
            ['ar' => 'طلب من '.$by->displayName('ar').' — المطلوب بحلول '.$needed.'.', 'en' => 'Request from '.$by->displayName('en').' — needed by '.$needed.'.'], ['detail_ar' => 'طلب من '.$by->displayName('ar').' — المطلوب بحلول '.$needed, 'detail_en' => 'Request from '.$by->displayName('en').' — needed by '.$needed, 'logistics_id' => $request->id, 'route' => '/admin/logistics']);

        return $request;
    }

    public function update(LogisticsRequest $request, User $by, array $data): LogisticsRequest
    {
        $before = $request->status;
        if (($data['status'] ?? null) === 'done') {
            $data['completed_at'] = now();
        }
        $request->update($data);
        if ($request->requested_by && ($request->status !== $before || isset($data['comment']))) {
            $label = ['new' => ['جديد', 'new'], 'in_progress' => ['قيد التنفيذ', 'in progress'], 'done' => ['منجز', 'done'], 'rejected' => ['مرفوض', 'rejected']][$request->status];
            $this->notifications->send($request->requested_by, 'logistics.request_updated', ['ar' => 'تحديث على طلبك اللوجستي', 'en' => 'Update on your logistics request'],
                ['ar' => "حالة طلبك: {$label[0]}".($request->comment ? " — {$request->comment}" : '').'.', 'en' => "Your request is {$label[1]}".($request->comment ? " — {$request->comment}" : '').'.'], ['detail_ar' => "حالة طلبك: {$label[0]}", 'detail_en' => "Your request is {$label[1]}", 'logistics_id' => $request->id]);
        }

        return $request;
    }

    /** @return int requests escalated */
    public function escalateOverdue(): int
    {
        $rows = LogisticsRequest::whereIn('status', ['new', 'in_progress'])->whereNotNull('needed_by')->where('needed_by', '<', now())->whereNull('overdue_notified_at')->get();
        foreach ($rows as $r) {
            $ids = array_values(array_unique(array_merge($this->team(), array_filter([$r->requested_by, $r->assignee_id]))));
            $ids && $this->notifications->broadcast($ids, 'logistics.request_overdue', ['ar' => 'طلب لوجستي متأخر', 'en' => 'A logistics request is overdue'],
                ['ar' => 'تجاوز طلب لوجستي موعده ولم يُنجز بعد.', 'en' => 'A logistics request passed its due time and is not done yet.'], ['detail_ar' => 'تجاوز طلب لوجستي موعده ولم يُنجز بعد', 'detail_en' => 'A logistics request passed its due time and is not done yet', 'logistics_id' => $r->id, 'route' => '/admin/logistics']);
            $r->update(['overdue_notified_at' => now()]);
        }

        return $rows->count();
    }

    /** @return list<string> */
    private function team(): array
    {
        return User::whereHas('roles.permissions', fn ($q) => $q->where('slug', 'logistics.manage'))->pluck('id')->all();
    }
}
