<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Services\Saber\FormLinks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «استمارات التقييم» in سابر+ (docs/saber/07-programme-forms.md): each programme's trainee survey and trainer self-reflection as links of its
 * own, generated — never uploaded. A programme's forms, the list of every programme's forms, and the links as an Excel sheet. Right: saber.view.
 */
class SaberFormsController extends Controller
{
    public function __construct(private readonly FormLinks $links) {}

    public function show(Program $program): JsonResponse
    {
        return response()->json(['data' => ['program' => $this->head($program)] + $this->links->one($program)]);
    }

    /** Programmes with their two forms, twenty a page: search by name or code, and when (running, upcoming, ended). */
    public function index(Request $request): JsonResponse
    {
        $page = $this->query($request)->paginate(20);
        $forms = $this->links->many($page->getCollection());

        return response()->json([
            'data' => $page->getCollection()->map(fn (Program $p) => ['program' => $this->head($p)] + $forms[$p->id])->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Every listed programme with its two links, for printing or sending on. */
    public function export(Request $request): BinaryFileResponse
    {
        $programs = $this->query($request)->limit(2000)->get();
        $forms = $this->links->many($programs);
        $ar = app()->getLocale() !== 'en';
        $status = fn (string $s) => __("messages.saber.forms.status.{$s}");
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle($ar ? 'الاستمارات' : 'Forms')->setRightToLeft($ar);
        $ws->fromArray([[
            __('messages.saber.forms.export.code'), __('messages.saber.forms.export.program'), __('messages.saber.forms.export.start'), __('messages.saber.forms.export.end'),
            __('messages.saber.forms.export.trainee'), __('messages.saber.forms.export.trainee_status'), __('messages.saber.forms.export.trainee_answered'),
            __('messages.saber.forms.export.reflection'), __('messages.saber.forms.export.reflection_status'), __('messages.saber.forms.export.reflection_answered'),
        ]]);
        $row = 2;
        foreach ($programs as $p) {
            $f = $forms[$p->id];
            $ws->fromArray([[
                $p->code, $p->translate('title'), $p->start_date?->toDateString(), $p->end_date?->toDateString(),
                $f['trainee']['url'], $status($f['trainee']['status']), "{$f['trainee']['answered']} / {$f['trainee']['expected']}",
                $f['reflection']['url'], $status($f['reflection']['status']), "{$f['reflection']['answered']} / {$f['reflection']['assigned']}",
            ]], null, "A{$row}");
            $ws->getCell("E{$row}")->getHyperlink()->setUrl($f['trainee']['url']);
            $ws->getCell("H{$row}")->getHyperlink()->setUrl($f['reflection']['url']);
            $row++;
        }
        $ws->getStyle('A1:J1')->getFont()->setBold(true);
        foreach (range('A', 'J') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }
        $path = tempnam(sys_get_temp_dir(), 'forms').'.xlsx';
        (new Xlsx($book))->save($path);

        return response()->download($path, 'saber-forms.xlsx')->deleteFileAfterSend();
    }

    private function query(Request $request): Builder
    {
        $d = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'when' => ['nullable', Rule::in(['all', 'running', 'upcoming', 'ended'])]]);
        $today = now()->toDateString();

        return Program::query()->whereNotIn('status', [Program::STATUS_DRAFT, Program::STATUS_ARCHIVED, Program::STATUS_CANCELLED])
            ->when($d['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->whereLike('title_ar', "%{$t}%")->orWhereLike('title_en', "%{$t}%")->orWhereLike('code', "%{$t}%")))
            ->when(($d['when'] ?? 'all') === 'running', fn ($q) => $q->where('start_date', '<=', $today)->where('end_date', '>=', $today))
            ->when(($d['when'] ?? 'all') === 'upcoming', fn ($q) => $q->where('start_date', '>', $today))
            ->when(($d['when'] ?? 'all') === 'ended', fn ($q) => $q->where('end_date', '<', $today))
            ->orderByDesc('start_date')->orderBy('title_ar');
    }

    /** @return array<string, mixed> */
    private function head(Program $p): array
    {
        return ['id' => $p->id, 'code' => $p->code, 'title' => $p->translate('title'), 'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(), 'status' => $p->status];
    }
}
