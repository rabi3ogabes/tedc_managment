<?php

namespace App\Migration\Importers;

use App\Migration\Cleanser;
use App\Models\Trainer;

class TrainersImporter extends Importer
{
    public function kind(): string
    {
        return 'trainers';
    }

    public function fields(): array
    {
        return [
            'email' => $this->f('البريد الإلكتروني', 'E-mail', true, ['mail'], 'trainer@example.com'), 'name_ar' => $this->f('الاسم بالعربية', 'Name (Arabic)', true, ['الاسم'], 'سعد'), 'name_en' => $this->f('الاسم بالإنجليزية', 'Name (English)', true, ['name'], 'Saad'),
            'phone' => $this->f('الجوال', 'Phone', false, ['mobile']), 'organization' => $this->f('الجهة', 'Organisation', false, ['company']), 'specializations' => $this->f('التخصصات', 'Specialisations', false, ['skills'], 'Leadership; Assessment'),
            'is_external' => $this->f('مدرب خارجي', 'External trainer', false, ['external'], 'yes'),
        ];
    }

    public function key(array $m): ?string
    {
        return Cleanser::email($m['email'] ?? '');
    }

    public function clean(array $m): array
    {
        $c = ['email' => Cleanser::email($m['email'] ?? ''), 'name_ar' => Cleanser::name($m['name_ar'] ?? ''), 'name_en' => Cleanser::name($m['name_en'] ?? ''), 'phone' => Cleanser::phone($m['phone'] ?? ''), 'organization' => Cleanser::name($m['organization'] ?? '') ?: null,
            'specializations' => array_values(array_filter(array_map('trim', preg_split('/[;,؛،]/u', (string) ($m['specializations'] ?? '')) ?: []))), 'is_external' => in_array(mb_strtolower(trim((string) ($m['is_external'] ?? ''))), ['1', 'yes', 'y', 'true', 'نعم', 'خارجي'], true)];
        $e = $this->missing($c, ['email', 'name_ar', 'name_en']);
        if (filled($m['email'] ?? null) && $c['email'] === null) {
            $e[] = 'invalid:email';
        }

        return [$c, array_values(array_unique($e))];
    }

    /** Whatever the model calls a trainer from inside the Ministry. */
    private function internalSource(): string
    {
        return Trainer::CENTER;
    }

    public function exists(array $c): bool
    {
        return Trainer::whereRaw('lower(email) = ?', [$c['email']])->exists();
    }

    public function apply(array $c): array
    {
        $t = Trainer::whereRaw('lower(email) = ?', [$c['email']])->first();
        $fields = ['name_ar' => $c['name_ar'], 'name_en' => $c['name_en'], 'phone' => $c['phone'], 'organization' => $c['organization'], 'specializations' => $c['specializations'], 'source' => $c['is_external'] ? Trainer::EXTERNAL : $this->internalSource()];
        if ($t) {
            $before = $t->only(array_keys($fields));
            $t->fill($fields)->save();

            return ['action' => 'updated', 'id' => $t->id, 'before' => $before, 'created' => []];
        }
        $t = Trainer::create($fields + ['email' => $c['email'], 'status' => 'active']);

        return ['action' => 'created', 'id' => $t->id, 'before' => null, 'created' => [['table' => 'trainers', 'id' => $t->id]]];
    }
}
