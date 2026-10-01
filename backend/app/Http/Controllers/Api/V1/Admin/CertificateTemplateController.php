<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CertificateTemplate;
use App\Models\Program;
use App\Services\CertificateTemplateService;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The certificate designer: administrators upload a PDF (or a picture) as the background of a template, place text
 * with {{placeholders}}, the verification QR, logos and signatures on it, and save it as a reusable template that
 * programs are assigned.
 */
class CertificateTemplateController extends Controller
{
    public function __construct(private readonly CertificateTemplateService $templates, private readonly FileStorage $storage) {}

    public function index(Request $request): JsonResponse
    {
        $this->templates->ensureDefaults();

        $rows = CertificateTemplate::query()
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->orderByDesc('is_default')->orderBy('name_ar')->get();

        $used = collect(['certificate_template_id', 'trainer_certificate_template_id'])
            ->flatMap(fn ($col) => Program::whereIn($col, $rows->pluck('id'))->pluck($col))->countBy();

        return response()->json([
            'data' => $rows->map(fn (CertificateTemplate $t) => $this->present($t) + ['programs_count' => $used[$t->id] ?? 0]),
            'meta' => ['tokens' => CertificateTemplateService::TOKENS, 'fonts' => CertificateTemplateService::FONTS, 'sample' => $this->templates->sampleTokens()],
        ]);
    }

    public function show(CertificateTemplate $template): JsonResponse
    {
        return response()->json(['data' => $this->present($template), 'meta' => ['tokens' => CertificateTemplateService::TOKENS, 'fonts' => CertificateTemplateService::FONTS, 'sample' => $this->templates->sampleTokens()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $source = $request->filled('duplicate_of') ? CertificateTemplate::findOrFail($request->input('duplicate_of')) : null;

        $template = CertificateTemplate::create($data + [
            'width_mm' => $source?->width_mm ?? 297, 'height_mm' => $source?->height_mm ?? 210,
            'elements' => $source?->elements ?? $this->templates->stockElements($data['kind']),
            'created_by' => $this->user()->id,
        ]);

        // A copy keeps the picture and assets of the original (they are copied under the new template).
        if ($source) {
            $this->copyFiles($source, $template);
        }

        return response()->json(['data' => $this->present($template->fresh())], 201);
    }

    public function update(Request $request, CertificateTemplate $template): JsonResponse
    {
        $data = $this->validated($request, true);
        $template->update($data);

        return response()->json(['data' => $this->present($template->fresh())]);
    }

    public function destroy(CertificateTemplate $template): JsonResponse
    {
        abort_if($template->is_default, 422, __('messages.template.default_in_use'));
        Program::where('certificate_template_id', $template->id)->update(['certificate_template_id' => null]);
        Program::where('trainer_certificate_template_id', $template->id)->update(['trainer_certificate_template_id' => null]);
        $template->delete();

        return response()->json(['message' => 'ok']);
    }

    /** Makes it the design used by programs that do not choose one. */
    public function makeDefault(CertificateTemplate $template): JsonResponse
    {
        CertificateTemplate::where('kind', $template->kind)->update(['is_default' => false]);
        $template->update(['is_default' => true, 'status' => 'active']);

        return response()->json(['data' => $this->present($template->fresh())]);
    }

    /** The page background: a picture, optionally with the PDF it was made from (the browser renders the PDF page to a picture). */
    public function background(Request $request, CertificateTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:12288'],
            'source_pdf' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'width_mm' => ['nullable', 'numeric', 'min:50', 'max:1000'],
            'height_mm' => ['nullable', 'numeric', 'min:50', 'max:1000'],
        ]);

        $this->forget($template->background_path);
        $this->forget($template->source_pdf_path);
        $path = $this->storage->upload($request->file('image'), 'certificates', "templates/{$template->id}");
        $pdf = $request->file('source_pdf') ? $this->storage->upload($request->file('source_pdf'), 'certificates', "templates/{$template->id}/source") : null;

        $template->update(array_filter([
            'background_path' => $path, 'source_pdf_path' => $pdf,
            'width_mm' => $data['width_mm'] ?? null, 'height_mm' => $data['height_mm'] ?? null,
        ], fn ($v) => $v !== null) + ['source_pdf_path' => $pdf]);

        return response()->json(['data' => $this->present($template->fresh())]);
    }

    public function removeBackground(CertificateTemplate $template): JsonResponse
    {
        $this->forget($template->background_path);
        $this->forget($template->source_pdf_path);
        $template->update(['background_path' => null, 'source_pdf_path' => null]);

        return response()->json(['data' => $this->present($template->fresh())]);
    }

    /** A logo / signature / stamp image for an `image` element. */
    public function asset(Request $request, CertificateTemplate $template): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096']]);
        $name = Str::uuid().'.'.strtolower($request->file('image')->getClientOriginalExtension() ?: 'png');
        $this->storage->put('certificates', "templates/{$template->id}/assets/{$name}", (string) file_get_contents($request->file('image')->getRealPath()), $request->file('image')->getMimeType() ?? 'image/png');

        return response()->json(['data' => ['name' => $name]], 201);
    }

    /** Streams the background (`background`) or an asset to the designer. */
    public function file(CertificateTemplate $template, string $name): Response
    {
        $path = match (true) {
            $name === 'background' => $template->background_path,
            $name === 'source' => $template->source_pdf_path,
            ! str_contains($name, '/') && ! str_contains($name, '..') => "templates/{$template->id}/assets/{$name}",
            default => null,
        };
        abort_unless($path, 404);

        try {
            $bytes = $this->templates->readFile($path);
        } catch (Throwable) {
            abort(404);
        }

        return response($bytes, 200, ['Content-Type' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream', 'Cache-Control' => 'private, max-age=300']);
    }

    /** Renders the (possibly unsaved) design with example values, so the real PDF can be checked before saving. */
    public function preview(Request $request): Response
    {
        $data = $request->validate([
            'template_id' => ['nullable', 'uuid', 'exists:certificate_templates,id'],
            'width_mm' => ['required', 'numeric', 'min:50', 'max:1000'],
            'height_mm' => ['required', 'numeric', 'min:50', 'max:1000'],
        ] + $this->elementRules());

        $template = ($data['template_id'] ?? null) ? CertificateTemplate::find($data['template_id']) : new CertificateTemplate;
        $template->width_mm = $data['width_mm'];
        $template->height_mm = $data['height_mm'];

        $pdf = $this->templates->render($template, $this->templates->sampleTokens(), rtrim(config('tedc.web_url'), '/').'/verify/SAMPLE', 'preview', $data['elements'] ?? []);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="preview.pdf"']);
    }

    // ------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function present(CertificateTemplate $t): array
    {
        return [
            'id' => $t->id, 'name_ar' => $t->name_ar, 'name_en' => $t->name_en, 'name' => $t->displayName(), 'kind' => $t->kind,
            'width_mm' => $t->width_mm, 'height_mm' => $t->height_mm, 'elements' => $t->elements ?? [],
            'has_background' => (bool) $t->background_path, 'has_source_pdf' => (bool) $t->source_pdf_path,
            'is_default' => $t->is_default, 'status' => $t->status, 'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function elementRules(): array
    {
        return [
            'elements' => ['nullable', 'array', 'max:80'],
            'elements.*.id' => ['required', 'string', 'max:40'],
            'elements.*.type' => ['required', Rule::in(['text', 'qr', 'image', 'rect'])],
            'elements.*.x' => ['required', 'numeric', 'between:-50,150'], 'elements.*.y' => ['required', 'numeric', 'between:-50,150'],
            'elements.*.w' => ['required', 'numeric', 'between:0,200'], 'elements.*.h' => ['required', 'numeric', 'between:0,200'],
            'elements.*.text' => ['nullable', 'string', 'max:3000'],
            'elements.*.font' => ['nullable', Rule::in(array_keys(CertificateTemplateService::FONTS))],
            'elements.*.size' => ['nullable', 'numeric', 'between:4,200'],
            'elements.*.color' => ['nullable', 'string', 'max:9'], 'elements.*.stroke' => ['nullable', 'string', 'max:9'], 'elements.*.fill' => ['nullable', 'string', 'max:9'],
            'elements.*.bold' => ['nullable', 'boolean'],
            'elements.*.align' => ['nullable', Rule::in(['left', 'right', 'center', 'justify'])],
            'elements.*.valign' => ['nullable', Rule::in(['top', 'middle', 'bottom'])],
            'elements.*.dir' => ['nullable', Rule::in(['rtl', 'ltr'])],
            'elements.*.line' => ['nullable', 'numeric', 'between:0.8,3'],
            'elements.*.stroke_width' => ['nullable', 'numeric', 'between:0,20'], 'elements.*.radius' => ['nullable', 'numeric', 'between:0,100'],
            'elements.*.src' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';
        $rules = [
            'name_ar' => [$sometimes, 'string', 'max:120'], 'name_en' => [$sometimes, 'string', 'max:120'],
            'kind' => [$partial ? 'sometimes' : 'required', Rule::in([CertificateTemplate::TRAINEE, CertificateTemplate::TRAINER])],
            'width_mm' => ['sometimes', 'numeric', 'min:50', 'max:1000'], 'height_mm' => ['sometimes', 'numeric', 'min:50', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'duplicate_of' => ['nullable', 'uuid', 'exists:certificate_templates,id'],
        ] + $this->elementRules();

        return collect($request->validate($rules))->except('duplicate_of')->all();
    }

    private function copyFiles(CertificateTemplate $from, CertificateTemplate $to): void
    {
        try {
            if ($from->background_path) {
                $name = basename($from->background_path);
                $path = "templates/{$to->id}/{$name}";
                $this->storage->put('certificates', $path, $this->templates->readFile($from->background_path), 'image/png');
                $to->update(['background_path' => $path]);
            }
            foreach (collect($from->elements ?? [])->where('type', 'image')->pluck('src')->filter()->unique() as $src) {
                $this->storage->put('certificates', "templates/{$to->id}/assets/{$src}", $this->templates->readFile("templates/{$from->id}/assets/{$src}"), 'image/png');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function forget(?string $path): void
    {
        if ($path) {
            try {
                $this->storage->delete('certificates', $path);
            } catch (Throwable) {
            }
        }
    }
}
