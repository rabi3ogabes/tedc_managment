<?php

namespace App\Migration\Importers;

use App\Migration\Cleanser;
use App\Models\Program;
use App\Models\ProgramCategory;

/** Past programs, kept for the record: they come in as completed and are not offered for registration. */
class ProgramsImporter extends Importer
{
    public function kind(): string
    {
        return 'programs';
    }

    public function fields(): array
    {
        return [
            'code' => $this->f('رمز البرنامج', 'Program code', true, ['program code'], 'PRG-2023-01'), 'title_ar' => $this->f('العنوان بالعربية', 'Title (Arabic)', true, ['عنوان البرنامج']), 'title_en' => $this->f('العنوان بالإنجليزية', 'Title (English)', true, ['title']),
            'category_slug' => $this->f('رمز الفئة', 'Category', false, ['category', 'الفئة']), 'total_hours' => $this->f('الساعات', 'Hours', false, ['hours', 'عدد الساعات'], '20'), 'delivery_mode' => $this->f('نمط التقديم', 'Delivery mode', false, ['mode'], 'in_person'),
            'start_date' => $this->f('تاريخ البداية', 'Start date', false, ['start']), 'end_date' => $this->f('تاريخ النهاية', 'End date', false, ['end']),
        ];
    }

    public function key(array $m): ?string
    {
        $k = Cleanser::code($m['code'] ?? '');

        return $k !== '' ? $k : null;
    }

    public function clean(array $m): array
    {
        $c = ['code' => Cleanser::code($m['code'] ?? ''), 'title_ar' => Cleanser::name($m['title_ar'] ?? ''), 'title_en' => Cleanser::name($m['title_en'] ?? ''), 'category_slug' => Cleanser::code($m['category_slug'] ?? '') ?: null,
            'total_hours' => Cleanser::number($m['total_hours'] ?? ''), 'delivery_mode' => in_array(strtolower(trim((string) ($m['delivery_mode'] ?? ''))), ['in_person', 'online', 'blended', 'self_paced'], true) ? strtolower(trim($m['delivery_mode'])) : 'in_person',
            'start_date' => Cleanser::date($m['start_date'] ?? null), 'end_date' => Cleanser::date($m['end_date'] ?? null)];
        $e = $this->missing($c, ['code', 'title_ar', 'title_en']);
        foreach (['start_date', 'end_date'] as $d) {
            if (filled($m[$d] ?? null) && $c[$d] === null) {
                $e[] = "invalid:{$d}";
            }
        }
        if (filled($m['total_hours'] ?? null) && $c['total_hours'] === null) {
            $e[] = 'invalid:total_hours';
        }
        if ($c['category_slug'] && ! ProgramCategory::whereRaw('upper(slug) = ?', [$c['category_slug']])->exists()) {
            $e[] = 'unknown:category_slug';
        }
        if ($c['start_date'] && $c['end_date'] && $c['end_date'] < $c['start_date']) {
            $e[] = 'invalid:dates_reversed';
        }

        return [$c, $e];
    }

    public function exists(array $c): bool
    {
        return Program::where('code', $c['code'])->exists();
    }

    public function apply(array $c): array
    {
        $cat = $c['category_slug'] ? ProgramCategory::whereRaw('upper(slug) = ?', [$c['category_slug']])->value('id') : ProgramCategory::orderBy('name_ar')->value('id');
        $fields = array_filter(['title_ar' => $c['title_ar'], 'title_en' => $c['title_en'], 'category_id' => $cat, 'total_hours' => $c['total_hours'], 'delivery_mode' => $c['delivery_mode'], 'start_date' => $c['start_date'], 'end_date' => $c['end_date']], fn ($v) => $v !== null);
        $p = Program::where('code', $c['code'])->first();
        if ($p) {
            $before = $p->only(array_keys($fields));
            $p->fill($fields)->save();

            return ['action' => 'updated', 'id' => $p->id, 'before' => $before, 'created' => []];
        }
        $p = Program::create($fields + ['code' => $c['code'], 'status' => Program::STATUS_COMPLETED, 'total_hours' => $c['total_hours'] ?? 0, 'capacity' => 30, 'summary_ar' => $c['title_ar'], 'summary_en' => $c['title_en'], 'registration_modes' => ['center_nomination']]);

        return ['action' => 'created', 'id' => $p->id, 'before' => null, 'created' => [['table' => 'programs', 'id' => $p->id]]];
    }
}
