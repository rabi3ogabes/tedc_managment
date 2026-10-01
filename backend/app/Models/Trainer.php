<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name_en', 'name_ar', 'title_en', 'title_ar', 'email', 'phone', 'bio_en', 'bio_ar', 'specializations', 'photo_path', 'is_external', 'organization', 'rating', 'status',
    'source', 'school_id', 'employee_id', 'partner_id', 'country', 'city', 'languages', 'experience_years', 'hourly_rate', 'currency', 'notes'])]
class Trainer extends Model
{
    use Auditable, HasTranslations, HasUuids;

    public const CENTER = 'center';

    public const SCHOOL = 'school';

    public const MINISTRY = 'ministry';

    public const PARTNER = 'partner';

    public const EXTERNAL = 'external';

    public const INTERNATIONAL = 'international';

    /** source => [ar, en] */
    public const SOURCES = [
        self::CENTER => ['مدرب من المركز', 'Center trainer'],
        self::SCHOOL => ['مدرب من المدارس', 'School trainer'],
        self::MINISTRY => ['من الوزارة', 'Ministry'],
        self::PARTNER => ['جهة شريكة', 'Partner organization'],
        self::EXTERNAL => ['من خارج المؤسسة', 'External (outside the organization)'],
        self::INTERNATIONAL => ['من خارج الدولة', 'International (outside the country)'],
    ];

    /** Sources that are not part of the education system. */
    public const OUTSIDE = [self::PARTNER, self::EXTERNAL, self::INTERNATIONAL];

    protected $casts = ['specializations' => 'array', 'languages' => 'array', 'is_external' => 'boolean', 'rating' => 'float', 'experience_years' => 'float', 'hourly_rate' => 'float'];

    protected static function booted(): void
    {
        // `is_external` stays in step with the source so older screens keep working.
        static::saving(function (Trainer $trainer) {
            $trainer->is_external = in_array($trainer->source, self::OUTSIDE, true);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganization::class, 'partner_id');
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class)->withPivot('role');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(TrainerCertificate::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ProgramSession::class);
    }

    public function sourceLabel(?string $locale = null): string
    {
        return self::SOURCES[$this->source][($locale ?? app()->getLocale()) === 'en' ? 1 : 0] ?? $this->source;
    }
}
