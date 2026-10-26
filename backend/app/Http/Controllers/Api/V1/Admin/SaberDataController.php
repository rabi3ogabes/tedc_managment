<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Program;
use App\Models\Role;
use App\Models\SaberSurveyLink;
use App\Models\SiteSetting;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\Saber\ArabicMatch;
use App\Services\Saber\CalendarImport;
use App\Services\Saber\ProgramListImport;
use App\Services\Saber\TrainerRoster;
use App\Services\Saber\WorkbookReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * سابر+ data tools (docs/saber/04-data-tools.md, D1–D5): the rooms-calendar import, the programme list import, the survey links workbook,
 * the trainers manager and the supervisors page.
 */
class SaberDataController extends Controller
{
    private const XLSX = ['required', 'file', 'max:20480', 'mimes:xlsx,xls'];

    // ── D1 / D2 · imports with any layout, into drafts ──────────────────────────────────────────────────────────

    /** Step 1 — the workbook's sheets with a preview of their first rows, and the header row guessed for each. */
    public function inspect(Request $request, WorkbookReader $reader, ProgramListImport $programs): JsonResponse
    {
        $this->need('saber.import');

        return response()->json(['data' => ['sheets' => $reader->inspect($this->source($request, $programs))]]);
    }

    /**
     * Step 3 — what the import would do with the administrator's layout: a rooms × dates grid, one training day per row, or a programme list.
     * Nothing is saved.
     */
    public function preview(Request $request, CalendarImport $calendar, ProgramListImport $programs): JsonResponse
    {
        $this->need('saber.import');
        $d = $this->layout($request);
        $path = $this->source($request, $programs);

        return response()->json(['data' => match ($d['type']) {
            'grid' => $calendar->plan($calendar->gridCells($path, ['sheet' => $d['sheet'] ?? null, 'header_row' => $d['header_row'] ?? null, 'date_col' => $d['date_col'] ?? null, 'first_room_col' => $d['first_room_col'] ?? null])),
            'rows' => $calendar->plan($calendar->rowCells($path, $d['sheet'] ?? null, (int) ($d['header_row'] ?? 1), $d['columns'] ?? [])),
            'programs' => $programs->preview($path, ['sheet' => $d['sheet'] ?? null, 'header_row' => $d['header_row'] ?? null, 'columns' => $d['columns'] ?? []]),
        } + ['type' => $d['type']]]);
    }

    /** Step 4 — writes the previewed plan into the programmes' SABER+ drafts (never the live programmes) and, if asked, sends them for review. */
    public function apply(Request $request, CalendarImport $calendar, ProgramListImport $programs): JsonResponse
    {
        $this->need('saber.import');
        $type = $request->validate(['type' => ['required', Rule::in(['grid', 'rows', 'programs'])], 'submit' => ['sometimes', 'boolean']])['type'];
        $submit = $request->boolean('submit');
        if ($type === 'programs') {
            $d = $request->validate([
                'rows' => ['required', 'array', 'min:1', 'max:1000'], 'rows.*.name' => ['required', 'string', 'max:255'], 'rows.*.program_id' => ['nullable', 'uuid'],
                'rows.*.type' => ['nullable', 'string', 'max:120'], 'rows.*.method' => ['nullable', 'string', 'max:120'], 'rows.*.audience' => ['nullable', 'string', 'max:500'],
                'rows.*.hours' => ['nullable', 'numeric', 'min:0', 'max:2000'], 'rows.*.days' => ['nullable', 'integer', 'min:1', 'max:200'], 'rows.*.objective' => ['nullable', 'string', 'max:2000'],
                'rows.*.groups' => ['nullable', 'integer', 'min:1', 'max:30'], 'rows.*.kit' => ['nullable', 'string', 'max:120'], 'rows.*.date' => ['nullable', 'date_format:Y-m-d'], 'rows.*.notes' => ['nullable', 'string', 'max:2000'],
            ]);

            return response()->json(['data' => $programs->apply($d['rows'], $this->user(), $submit)]);
        }
        $d = $request->validate([
            'tracks' => ['required', 'array', 'min:1', 'max:500'], 'tracks.*.program_id' => ['required', 'uuid', 'exists:programs,id'], 'tracks.*.group_id' => ['nullable', 'uuid'],
            'tracks.*.group_name' => ['required', 'string', 'max:160'], 'tracks.*.supervisor' => ['nullable', 'string', 'max:160'],
            'tracks.*.days' => ['required', 'array', 'min:1', 'max:200'], 'tracks.*.days.*.date' => ['required', 'date_format:Y-m-d'], 'tracks.*.days.*.room' => ['nullable', 'string', 'max:255'],
            'tracks.*.days.*.capacity' => ['nullable', 'integer', 'min:1', 'max:5000'], 'tracks.*.days.*.trainer' => ['nullable', 'string', 'max:255'],
            'tracks.*.days.*.start_time' => ['nullable', 'date_format:H:i'], 'tracks.*.days.*.end_time' => ['nullable', 'date_format:H:i'],
        ]);

        return response()->json(['data' => $calendar->apply($d['tracks'], $submit, $this->user(), $type === 'grid' ? 'calendar_import' : 'flexible_import')]);
    }

    /** The saved column layouts, so a workbook the centre sends every month maps itself. */
    public function presets(): JsonResponse
    {
        $this->need('saber.import');

        return response()->json(['data' => (object) (SiteSetting::find('saber.import_presets')?->value ?? [])]);
    }

    public function savePreset(Request $request): JsonResponse
    {
        $this->need('saber.import');
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'layout' => ['required', 'array']]);
        $all = (array) (SiteSetting::find('saber.import_presets')?->value ?? []);
        $all[$d['name']] = array_intersect_key($d['layout'], array_flip(['type', 'header_row', 'date_col', 'first_room_col', 'columns']));
        SiteSetting::updateOrCreate(['key' => 'saber.import_presets'], ['value' => $all, 'updated_by' => $this->user()->id]);

        return response()->json(['data' => $all]);
    }

    /** @return array<string, mixed> */
    private function layout(Request $request): array
    {
        $d = $request->validate([
            'type' => ['required', Rule::in(['grid', 'rows', 'programs'])], 'sheet' => ['nullable', 'string', 'max:120'], 'header_row' => ['nullable', 'integer', 'min:1', 'max:200'],
            'date_col' => ['nullable', 'integer', 'min:1', 'max:500'], 'first_room_col' => ['nullable', 'integer', 'min:1', 'max:500'], 'columns' => ['nullable'],
        ]);
        $columns = is_string($d['columns'] ?? null) ? json_decode($d['columns'], true) : ($d['columns'] ?? []);
        $d['columns'] = collect(is_array($columns) ? $columns : [])->map(fn ($v) => $v === null || $v === '' ? null : (int) $v)->filter(fn ($v) => $v === null || ($v >= 1 && $v <= 500))->all();
        if ($d['type'] === 'rows') {
            abort_if(empty($d['columns']['program']) || empty($d['columns']['date']), 422, __('messages.saber.import.map_required'));
        }
        if ($d['type'] === 'programs' && ! empty($d['columns']) && empty($d['columns']['name'])) {
            abort(422, __('messages.saber.import.map_name'));
        }

        return $d;
    }

    /** The uploaded workbook, or one downloaded from a shared OneDrive / SharePoint link. */
    private function source(Request $request, ProgramListImport $programs): string
    {
        $d = $request->validate(['file' => ['required_without:url', 'file', 'max:20480', 'mimes:xlsx,xls,csv,txt'], 'url' => ['required_without:file', 'nullable', 'url:https', 'max:2048']]);

        return $request->hasFile('file') ? $request->file('file')->getRealPath() : $programs->download($d['url']);
    }

    // ── D3 · survey links ────────────────────────────────────────────────────────────────────────────────────────

    public function surveyLinks(): JsonResponse
    {
        $this->need('saber.survey_links.manage');
        $rows = SaberSurveyLink::with('program:id,title_ar,title_en,code')->get()->sortBy(fn ($l) => [is_numeric($l->serial) ? (int) $l->serial : PHP_INT_MAX, $l->serial])->values();

        return response()->json(['data' => $rows->map(fn ($l) => [
            'id' => $l->id, 'serial' => $l->serial, 'survey_link' => $l->survey_link, 'survey_edit_link' => $l->survey_edit_link,
            'program' => $l->program ? ['id' => $l->program->id, 'title' => $l->program->title_ar ?: $l->program->title_en, 'code' => $l->program->code] : null,
        ])]);
    }

    public function uploadSurveyLinks(Request $request): JsonResponse
    {
        $this->need('saber.survey_links.manage');
        $d = $request->validate(['file' => self::XLSX, 'replace' => ['sometimes', 'boolean']]);
        $data = IOFactory::createReaderForFile($request->file('file')->getRealPath())->setReadDataOnly(true)->load($request->file('file')->getRealPath())->getSheet(0)->toArray(null, true, false, false);
        $norm = fn ($v) => ArabicMatch::normalize((string) $v);
        $headerAt = collect($data)->take(10)->search(fn ($r) => collect($r)->contains(fn ($v) => in_array($norm($v), [$norm('م'), $norm('رقم'), 'serial', 'no', '#'], true)));
        abort_if($headerAt === false, 422, __('messages.saber.links.no_header'));
        $find = fn (array $names) => collect($data[$headerAt])->search(fn ($v) => in_array($norm($v), array_map($norm, $names), true));
        $cSerial = $find(['م', 'رقم', 'Serial', 'No', '#']);
        $cLink = $find(['رابط الإستمارة', 'رابط الاستمارة', 'Survey', 'Survey Link']);
        $cEdit = $find(['رابط تعديل الإستمارة', 'رابط تعديل الاستمارة', 'Edit', 'Edit Link']);
        $rows = [];
        foreach (array_slice($data, $headerAt + 1) as $r) {
            $serial = trim((string) ($r[$cSerial] ?? ''));
            $link = $cLink !== false ? trim((string) ($r[$cLink] ?? '')) : '';
            $edit = $cEdit !== false ? trim((string) ($r[$cEdit] ?? '')) : '';
            if ($serial === '' || ($link === '' && $edit === '')) {
                continue;
            }
            $rows[] = ['serial' => mb_substr($serial, 0, 32), 'survey_link' => filter_var($link, FILTER_VALIDATE_URL) ? $link : null, 'survey_edit_link' => filter_var($edit, FILTER_VALIDATE_URL) ? $edit : null];
        }
        abort_if($rows === [], 422, __('messages.saber.links.empty'));
        DB::transaction(function () use ($rows, $d) {
            if (! empty($d['replace'])) {
                SaberSurveyLink::query()->delete();
            }
            foreach ($rows as $r) {
                SaberSurveyLink::updateOrCreate(['serial' => $r['serial']], $r);
            }
        });

        return response()->json(['data' => ['uploaded' => count($rows)]], 201);
    }

    /** Gives a link to a programme (or takes it back): the programme's survey and edit links follow. */
    public function assignSurveyLink(Request $request, SaberSurveyLink $link): JsonResponse
    {
        $this->need('saber.survey_links.manage');
        $d = $request->validate(['program_id' => ['nullable', 'uuid', 'exists:programs,id']]);
        DB::transaction(function () use ($link, $d) {
            if ($link->program_id && $link->program_id !== ($d['program_id'] ?? null)) {
                Program::whereKey($link->program_id)->update(['survey_link' => null, 'survey_edit_link' => null]);
            }
            if (! empty($d['program_id'])) {
                SaberSurveyLink::where('program_id', $d['program_id'])->where('id', '!=', $link->id)->update(['program_id' => null]);
                Program::whereKey($d['program_id'])->update(['survey_link' => $link->survey_link, 'survey_edit_link' => $link->survey_edit_link]);
            }
            $link->update(['program_id' => $d['program_id'] ?? null]);
        });

        return response()->json(['data' => ['assigned' => (bool) ($d['program_id'] ?? null)]]);
    }

    public function deleteSurveyLink(SaberSurveyLink $link): JsonResponse
    {
        $this->need('saber.survey_links.manage');
        $link->delete();

        return response()->json(null, 204);
    }

    public function clearSurveyLinks(): JsonResponse
    {
        $this->need('saber.survey_links.manage');

        return response()->json(['data' => ['deleted' => SaberSurveyLink::query()->delete()]]);
    }

    public function surveyLinksTemplate(): BinaryFileResponse
    {
        $this->need('saber.survey_links.manage');
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('روابط')->setRightToLeft(true);
        $sheet->fromArray([['م', 'رابط الإستمارة', 'رابط تعديل الإستمارة'], ['1', 'https://forms.example.com/s/1', 'https://forms.example.com/e/1']]);
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        foreach (['A', 'B', 'C'] as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        $path = tempnam(sys_get_temp_dir(), 'links').'.xlsx';
        (new Xlsx($book))->save($path);

        return response()->download($path, 'survey-links-template.xlsx')->deleteFileAfterSend();
    }

    // ── D4 · trainers manager ────────────────────────────────────────────────────────────────────────────────────

    public function trainers(Request $request, TrainerRoster $roster): JsonResponse
    {
        $this->need('trainers.manage');
        $f = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'type' => ['nullable', 'string', 'max:16'], 'pending' => ['nullable', 'boolean']]);
        $base = Trainer::query()->where('status', '!=', 'inactive');
        $counts = (clone $base)->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source');
        $rows = (clone $base)
            ->when($f['q'] ?? null, function ($q, $t) {
                $digits = preg_replace('/\D/', '', $t);
                $q->where(fn ($w) => $w->whereLike('name_ar', "%{$t}%")->orWhereLike('name_en', "%{$t}%")->orWhereLike('email', "%{$t}%")->orWhereLike('phone', "%{$t}%")
                    ->when(strlen((string) $digits) >= 8, fn ($x) => $x->orWhere('national_id_hash', Employee::nationalIdHash($digits))));
            })
            ->when($f['type'] ?? null, fn ($q, $v) => $v === '__none__' ? $q->whereNull('source') : $q->where('source', $v))
            ->when(! empty($f['pending']), fn ($q) => $q->whereNull('validated_at'))
            ->orderByRaw('case when validated_at is null then 0 else 1 end')->orderBy('name_ar')->limit(1000)->get();

        return response()->json(['data' => $rows->map(fn ($t) => $roster->row($t))->values(), 'meta' => [
            'counts' => $counts, 'total' => $counts->sum(), 'pending' => (clone $base)->whereNull('validated_at')->count(), 'types' => TrainerRoster::TYPES,
            'can_validate' => $this->user()->hasPermission('trainers.validate'),
        ]]);
    }

    public function saveTrainer(Request $request, TrainerRoster $roster, ?Trainer $trainer = null): JsonResponse
    {
        $this->need('trainers.manage');
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'national_id' => ['nullable', 'string', 'regex:/^\d{8,15}$/'], 'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?\d{8,15}$/'], 'type' => ['nullable', Rule::in(array_keys(TrainerRoster::TYPES))],
        ]);

        return response()->json(['data' => $roster->row($roster->save($trainer, $d, $this->user()))], $trainer ? 200 : 201);
    }

    /** A trainer who never taught leaves; one with sessions is deactivated so the history keeps its name. */
    public function deleteTrainer(Trainer $trainer): JsonResponse
    {
        $this->need('trainers.manage');
        if ($trainer->sessions()->exists()) {
            $trainer->update(['status' => 'inactive']);

            return response()->json(['data' => ['deactivated' => true]]);
        }
        $trainer->delete();

        return response()->json(null, 204);
    }

    public function validateTrainer(Trainer $trainer, TrainerRoster $roster): JsonResponse
    {
        $this->need('trainers.validate');
        $trainer->update($trainer->validated_at ? ['validated_at' => null, 'validated_by' => null] : ['validated_at' => now(), 'validated_by' => $this->user()->id]);

        return response()->json(['data' => $roster->row($trainer->fresh())]);
    }

    public function trainersImportPreview(Request $request, TrainerRoster $roster): JsonResponse
    {
        $this->need('trainers.manage');
        $request->validate(['file' => self::XLSX]);

        return response()->json(['data' => $roster->previewImport($request->file('file')->getRealPath())]);
    }

    public function trainersImportApply(Request $request, TrainerRoster $roster): JsonResponse
    {
        $this->need('trainers.manage');
        $d = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:2000'], 'rows.*.name' => ['required', 'string', 'max:255'], 'rows.*.trainer_id' => ['nullable', 'uuid'],
            'rows.*.national_id_raw' => ['nullable', 'string', 'regex:/^\d{8,15}$/'], 'rows.*.email' => ['nullable', 'email'], 'rows.*.phone' => ['nullable', 'string', 'max:20'],
            'rows.*.type' => ['nullable', Rule::in(array_keys(TrainerRoster::TYPES))],
        ]);

        return response()->json(['data' => $roster->applyImport($d['rows'], $this->user())]);
    }

    public function trainersFill(TrainerRoster $roster): JsonResponse
    {
        $this->need('trainers.manage');

        return response()->json(['data' => $roster->fillFromStaff()]);
    }

    public function trainersExport(Request $request, TrainerRoster $roster): BinaryFileResponse
    {
        $this->need('trainers.manage');
        $template = $request->boolean('template');

        return response()->download($roster->workbook($template), $template ? 'trainers-template.xlsx' : 'trainers-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }

    // ── D5 · supervisors ─────────────────────────────────────────────────────────────────────────────────────────

    /** «المشرفون»: every supervisor with the programmes in their care and, per programme, approved and pending groups. */
    public function supervisors(): JsonResponse
    {
        $users = User::whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::COORDINATOR, Role::TRAINING_HEAD]))->where('status', 'active')->orderBy('name')->get(['id', 'name', 'name_ar', 'email']);
        $groups = TrainingGroup::with('program:id,title_ar,title_en,code,coordinator_id,status')->where('status', '!=', TrainingGroup::CANCELLED)
            ->where(fn ($q) => $q->whereIn('supervisor_id', $users->pluck('id'))->orWhereHas('program', fn ($p) => $p->whereIn('coordinator_id', $users->pluck('id'))))
            ->get(['id', 'program_id', 'supervisor_id', 'validated_at', 'status']);

        return response()->json(['data' => $users->map(function ($u) use ($groups) {
            $mine = $groups->filter(fn ($g) => $g->supervisor_id === $u->id || $g->program?->coordinator_id === $u->id)->groupBy('program_id');
            $programs = $mine->map(fn ($gs) => [
                'id' => $gs->first()->program_id, 'title' => $gs->first()->program?->title_ar ?: $gs->first()->program?->title_en, 'code' => $gs->first()->program?->code,
                'groups' => $gs->count(), 'validated' => $gs->whereNotNull('validated_at')->count(), 'pending' => $gs->whereNull('validated_at')->count(),
            ])->sortBy('title')->values();

            return ['id' => $u->id, 'name' => $u->name_ar ?: $u->name, 'email' => $u->email, 'programs' => $programs, 'validated' => $programs->sum('validated'), 'pending' => $programs->sum('pending')];
        })->values()]);
    }

    private function need(string $permission): void
    {
        abort_unless($this->user()->hasPermission($permission), 403);
    }
}
