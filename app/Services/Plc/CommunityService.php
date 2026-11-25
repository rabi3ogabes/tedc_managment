<?php

namespace App\Services\Plc;

use App\Models\AuditLog;
use App\Models\PlcCommunity;
use App\Models\School;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/** Professional learning communities: the frame that gathers schools around a domain. */
class CommunityService
{
    public function query(User $user): Builder
    {
        $scope = AccessScope::current($user);
        $q = PlcCommunity::query()->withCount(['schools', 'workshops'])->with(['lead:id,name,name_ar', 'hostSchool:id,name_ar,name_en']);
        if ($scope->isMinistryWide()) {
            return $q;
        }
        $ids = $scope->schoolIds() ?? [];

        return $q->where(fn ($w) => $w->whereIn('host_school_id', $ids)->orWhereHas('schools', fn ($s) => $s->whereIn('schools.id', $ids)));
    }

    public function create(User $by, array $data): PlcCommunity
    {
        return DB::transaction(function () use ($by, $data) {
            $c = PlcCommunity::create([
                'code' => 'PLC-C-'.Str::upper(Str::random(6)),
                'title_ar' => $data['title_ar'], 'title_en' => $data['title_en'] ?? null,
                'description_ar' => $data['description_ar'] ?? null, 'description_en' => $data['description_en'] ?? null,
                'domain' => $data['domain'] ?? null, 'status' => 'active',
                'lead_user_id' => $data['lead_user_id'] ?? $by->id, 'host_school_id' => $data['host_school_id'] ?? null, 'space_id' => $data['space_id'] ?? null,
                'created_by' => $by->id,
            ]);
            $this->syncSchools($c, (array) ($data['school_ids'] ?? []), $data['host_school_id'] ?? null);
            $this->audit($by, 'plc.community.created', $c);

            return $c;
        });
    }

    public function update(PlcCommunity $c, User $by, array $data): PlcCommunity
    {
        $c->update(array_intersect_key($data, array_flip(['title_ar', 'title_en', 'description_ar', 'description_en', 'domain', 'lead_user_id', 'host_school_id', 'space_id'])));
        if (array_key_exists('school_ids', $data)) {
            $this->syncSchools($c, (array) $data['school_ids'], $c->host_school_id);
        }
        $this->audit($by, 'plc.community.updated', $c);

        return $c->refresh();
    }

    public function archive(PlcCommunity $c, User $by): PlcCommunity
    {
        $c->update(['status' => 'archived']);
        $this->audit($by, 'plc.community.archived', $c);

        return $c;
    }

    private function syncSchools(PlcCommunity $c, array $schoolIds, ?string $hostId): void
    {
        $ids = School::whereIn('id', array_values(array_unique(array_filter(array_merge($schoolIds, $hostId ? [$hostId] : [])))))->pluck('id');
        $sync = [];
        foreach ($ids as $id) {
            $sync[$id] = ['id' => (string) Str::uuid(), 'role' => $id === $hostId ? 'host' : 'member', 'joined_at' => now()];
        }
        $existing = $c->schools()->pluck('schools.id')->all();
        foreach ($existing as $id) {
            if (isset($sync[$id])) {
                unset($sync[$id]['id'], $sync[$id]['joined_at']);
            }
        }
        $c->schools()->sync($sync);
    }

    public function present(PlcCommunity $c): array
    {
        return [
            'id' => $c->id, 'code' => $c->code, 'title_ar' => $c->title_ar, 'title_en' => $c->title_en, 'description_ar' => $c->description_ar, 'description_en' => $c->description_en,
            'domain' => $c->domain, 'status' => $c->status, 'lead' => $c->lead?->displayName(), 'lead_user_id' => $c->lead_user_id,
            'host_school' => $c->hostSchool ? ['id' => $c->hostSchool->id, 'name' => $c->hostSchool->name_ar, 'name_en' => $c->hostSchool->name_en] : null,
            'space_id' => $c->space_id, 'schools' => $c->schools_count ?? $c->schools()->count(), 'workshops' => $c->workshops_count ?? $c->workshops()->count(),
            'school_list' => $c->relationLoaded('schools') ? $c->schools->map(fn ($s) => ['id' => $s->id, 'name' => $s->name_ar, 'name_en' => $s->name_en, 'gender' => $s->gender, 'region' => $s->region, 'role' => $s->pivot->role])->values() : null,
            'created_at' => $c->created_at, 'updated_at' => $c->updated_at,
        ];
    }

    private function audit(User $by, string $action, PlcCommunity $c): void
    {
        AuditLog::create([
            'user_id' => $by->id, 'action' => $action, 'auditable_type' => PlcCommunity::class, 'auditable_id' => $c->id,
            'old_values' => null, 'new_values' => ['title' => $c->title_ar, 'status' => $c->status],
            'ip_address' => Request::ip(), 'user_agent' => substr((string) Request::userAgent(), 0, 255), 'url' => substr((string) Request::fullUrl(), 0, 255),
        ]);
    }
}
