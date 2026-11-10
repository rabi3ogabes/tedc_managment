<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A certificate design: a background plus positioned elements (see CertificateTemplateService). */
#[Fillable(['name_ar', 'name_en', 'kind', 'width_mm', 'height_mm', 'background_path', 'source_pdf_path', 'elements', 'is_default', 'status', 'created_by'])]
class CertificateTemplate extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    public const TRAINEE = 'trainee';

    public const TRAINER = 'trainer';

    protected $casts = ['elements' => 'array', 'is_default' => 'boolean', 'width_mm' => 'float', 'height_mm' => 'float'];

    public function displayName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' ? $this->name_en : $this->name_ar;
    }
}
