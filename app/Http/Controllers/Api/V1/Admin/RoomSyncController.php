<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\RoomSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** «مزامنة القاعات من ملف» (docs/saber/06-rooms-sync.md): preview, apply, and the room-list template. Right: rooms.manage. */
class RoomSyncController extends Controller
{
    /** What the file would change. Nothing is saved. */
    public function preview(Request $request, RoomSync $sync): JsonResponse
    {
        $d = $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,csv,txt'], 'sheet' => ['nullable', 'string', 'max:120']]);

        return response()->json(['data' => $sync->plan($sync->read($request->file('file')->getRealPath(), $d['sheet'] ?? null))]);
    }

    public function apply(Request $request, RoomSync $sync): JsonResponse
    {
        $d = $request->validate([
            'add' => ['present', 'array', 'max:500'], 'add.*.name' => ['required', 'string', 'max:255'], 'add.*.name_en' => ['nullable', 'string', 'max:255'],
            'add.*.code' => ['nullable', 'string', 'max:32'], 'add.*.room_type' => ['nullable', Rule::in(['training', 'meeting'])], 'add.*.tag' => ['nullable', 'string', 'max:60'],
            'add.*.capacity' => ['nullable', 'integer', 'min:1', 'max:5000'], 'add.*.building' => ['nullable', 'string', 'max:255'], 'add.*.floor' => ['nullable', 'string', 'max:32'],
            'update' => ['present', 'array', 'max:500'], 'update.*.id' => ['required', 'uuid', 'exists:training_rooms,id'], 'update.*.changes' => ['required', 'array'],
            'update.*.changes.name' => ['sometimes', 'string', 'max:255'], 'update.*.changes.name_en' => ['sometimes', 'string', 'max:255'], 'update.*.changes.code' => ['sometimes', 'string', 'max:32'],
            'update.*.changes.room_type' => ['sometimes', Rule::in(['training', 'meeting'])], 'update.*.changes.tag' => ['sometimes', 'string', 'max:60'],
            'update.*.changes.capacity' => ['sometimes', 'integer', 'min:1', 'max:5000'], 'update.*.changes.building' => ['sometimes', 'string', 'max:255'],
            'update.*.changes.floor' => ['sometimes', 'string', 'max:32'], 'update.*.changes.status' => ['sometimes', Rule::in(['active'])],
            'remove' => ['present', 'array', 'max:500'], 'remove.*' => ['uuid', 'exists:training_rooms,id'],
        ]);

        return response()->json(['data' => $sync->apply($d['add'], $d['update'], $d['remove'])]);
    }

    /** An empty room list with the columns the sync reads, and two example rows. */
    public function template(): BinaryFileResponse
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('القاعات')->setRightToLeft(true);
        $ws->fromArray([
            ['اسم القاعة', 'السعة', 'النوع', 'الوسم', 'الرمز', 'المبنى', 'الطابق', 'الاسم بالإنجليزية'],
            ['القاعة 1', 25, 'تدريب', 'رجال', 'R01', 'المبنى الرئيسي', 'الأرضي', 'Room 1'],
            ['قاعة الابتكار', 12, 'اجتماعات', 'الكل', 'M01', 'المبنى الرئيسي', '1', 'Innovation room'],
        ]);
        $ws->getStyle('A1:H1')->getFont()->setBold(true);
        foreach (range('A', 'H') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }
        $path = tempnam(sys_get_temp_dir(), 'rooms').'.xlsx';
        (new Xlsx($book))->save($path);

        return response()->download($path, 'rooms-template.xlsx')->deleteFileAfterSend();
    }
}
