<?php

namespace App\Services\Content;

use App\Models\ContentImport;
use App\Models\ContentPackage;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\LtiTool;
use App\Models\Material;
use App\Models\Program;
use App\Models\QuestionBank;
use App\Models\User;
use App\Services\FileStorage;
use Illuminate\Support\Str;
use Throwable;

/**
 * IMS Common Cartridge 1.1–1.3 (and Thin CC) import. The cartridge is uploaded as a package first; the preview shows its
 * organisation tree with what each part would become, and the import takes the chosen parts: web pages become article lessons,
 * other files become program materials, QTI becomes a question bank, web links become link lessons, LTI links become LTI lessons.
 */
class CommonCartridgeService
{
    public function __construct(private readonly FileStorage $storage, private readonly QtiService $qti) {}

    /** @return list<array<string, mixed>> */
    public function preview(ContentPackage $package): array
    {
        abort_unless($package->standard === 'cc', 422);
        $resources = collect($package->manifest['resources'] ?? [])->keyBy('id');

        return collect($package->entry_points)->map(function ($i) use ($resources) {
            $res = $resources->get($i['resource_id'] ?? '');
            $type = (string) ($res['type'] ?? '');
            $href = (string) ($res['href'] ?? $i['href'] ?? '');

            return ['id' => $i['id'], 'title' => $i['title'], 'parent' => $i['parent'] ?? null, 'resource_type' => $type, 'href' => $href, 'becomes' => $this->kind($type, $href)];
        })->values()->all();
    }

    /** @return 'folder'|'lesson'|'material'|'bank'|'link'|'lti'|'discussion'|'unsupported' */
    private function kind(string $type, string $href): string
    {
        $t = strtolower($type);
        if ($t === '') {
            return 'folder';
        }
        if (str_contains($t, 'imsqti')) {
            return 'bank';
        }
        if (str_contains($t, 'imsbasiclti') || str_contains($t, 'imslti')) {
            return 'lti';
        }
        if (str_contains($t, 'imswl')) {
            return 'link';
        }
        if (str_contains($t, 'imsdt')) {
            return 'discussion';
        }
        if (str_contains($t, 'webcontent') || str_contains($t, 'associatedcontent')) {
            return preg_match('/\.html?$/i', $href) ? 'lesson' : 'material';
        }

        return 'unsupported';
    }

    /**
     * @param  list<string>|null  $ids  chosen item ids (null = everything)
     * @return array{lessons: int, materials: int, questions: int, links: int, lti: int, skipped: list<array<string, string>>, import_id: string}
     */
    public function import(ContentPackage $package, Program $program, ?array $ids, User $by): array
    {
        $tree = collect($this->preview($package));
        $chosen = $ids === null ? $tree : $tree->filter(fn ($n) => in_array($n['id'], $ids, true) || $this->ancestorChosen($n, $tree, $ids));
        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => $package->title, 'title_en' => $package->title, 'sort_order' => (int) CourseModule::where('program_id', $program->id)->max('sort_order') + 1]);
        $log = ['lessons' => 0, 'materials' => 0, 'questions' => 0, 'links' => 0, 'lti' => 0, 'skipped' => []];
        $bank = null;
        $order = 0;

        foreach ($chosen as $n) {
            try {
                switch ($n['becomes']) {
                    case 'lesson':
                        $html = $this->read($package, $n['href']);
                        $this->lesson($program, $module, $n['title'], 'article', ['body_ar' => $this->clean($html), 'body_en' => $this->clean($html)], ++$order);
                        $log['lessons']++;
                        break;
                    case 'material':
                        $bytes = $this->read($package, $n['href']);
                        $name = basename($n['href']);
                        $path = $this->storage->put('materials', "{$program->id}/".Str::uuid().'-'.Str::slug(pathinfo($name, PATHINFO_FILENAME)).'.'.pathinfo($name, PATHINFO_EXTENSION), $bytes, Mime::of($name));
                        Material::create(['program_id' => $program->id, 'title_ar' => $n['title'], 'title_en' => $n['title'], 'type' => 'file', 'storage_path' => $path, 'mime' => Mime::of($name), 'size' => strlen($bytes), 'visibility' => 'participants', 'uploaded_by' => $by->id]);
                        $log['materials']++;
                        break;
                    case 'bank':
                        $bank ??= QuestionBank::create(['title_ar' => $package->title, 'title_en' => $package->title, 'visibility' => 'center', 'program_id' => $program->id, 'owner_id' => $by->id]);
                        $r = $this->qti->import($bank, $this->read($package, $n['href']), $by);
                        $log['questions'] += $r['created'];
                        foreach ($r['unsupported'] as $u) {
                            $log['skipped'][] = ['item' => $n['title'], 'reason' => 'qti:'.$u['interaction']];
                        }
                        break;
                    case 'link':
                        $url = $this->weblinkUrl($this->read($package, $n['href']));
                        $this->lesson($program, $module, $n['title'], 'article', ['body_ar' => '<p><a href="'.e($url).'" target="_blank" rel="noopener">'.e($n['title']).'</a></p>', 'body_en' => '<p><a href="'.e($url).'" target="_blank" rel="noopener">'.e($n['title']).'</a></p>'], ++$order);
                        $log['links']++;
                        break;
                    case 'lti':
                        [$launch, $title] = $this->ltiLink($this->read($package, $n['href']), $n['title']);
                        // The tool is registered inactive: an administrator adds its keys before it can launch.
                        $tool = LtiTool::create(['name' => $title, 'version' => '1.1', 'launch_url' => $launch ?: 'https://invalid.example', 'is_active' => false]);
                        $this->lesson($program, $module, $title, CourseLesson::LTI, ['lti_tool_id' => $tool->id], ++$order);
                        $log['lti']++;
                        break;
                    case 'discussion':
                        $log['skipped'][] = ['item' => $n['title'], 'reason' => 'discussion_forums_arrive_with_phase_14'];
                        break;
                    case 'folder':
                        break;
                    default:
                        $log['skipped'][] = ['item' => $n['title'], 'reason' => 'unsupported_resource:'.$n['resource_type']];
                }
            } catch (Throwable $e) {
                $log['skipped'][] = ['item' => $n['title'], 'reason' => 'error:'.Str::limit($e->getMessage(), 120, '')];
            }
        }
        $imp = ContentImport::create(['kind' => 'cc', 'program_id' => $program->id, 'log' => $log, 'created_by' => $by->id]);

        return $log + ['import_id' => $imp->id];
    }

    private function ancestorChosen(array $n, $tree, array $ids): bool
    {
        $p = $n['parent'];
        while ($p) {
            if (in_array($p, $ids, true)) {
                return true;
            }
            $p = $tree->firstWhere('id', $p)['parent'] ?? null;
        }

        return false;
    }

    private function read(ContentPackage $package, string $href): string
    {
        abort_if(preg_match('#(^|/)\.\.(/|$)#', $href), 422);

        return $this->storage->get('packages', "{$package->storage_root}/".ltrim($href, '/'));
    }

    private function lesson(Program $program, CourseModule $module, string $title, string $type, array $extra, int $order): CourseLesson
    {
        $t = Str::limit($title, 200, '');

        return CourseLesson::create($extra + ['program_id' => $program->id, 'module_id' => $module->id, 'type' => $type, 'title_ar' => $t, 'title_en' => $t, 'status' => 'draft', 'sort_order' => $order]);
    }

    /** Page content without scripts, frames, forms and event handlers (the body of the page only). */
    public function clean(string $html): string
    {
        if (preg_match('#<body[^>]*>(.*)</body>#is', $html, $m)) {
            $html = $m[1];
        }
        $html = preg_replace('#<(script|style|iframe|object|embed|form|link|meta)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('#<(script|style|iframe|object|embed|form|link|meta)\b[^>]*/?>#is', '', $html) ?? '';
        $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';
        $html = preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1=$2#$2', $html) ?? '';

        return trim($html);
    }

    private function weblinkUrl(string $xml): string
    {
        return preg_match('/<url[^>]*href="([^"]+)"/i', $xml, $m) ? html_entity_decode($m[1]) : '';
    }

    /** @return array{0: string, 1: string} launch url and title */
    private function ltiLink(string $xml, string $fallback): array
    {
        $launch = preg_match('#<(?:\w+:)?launch_url>([^<]+)</#i', $xml, $m) ? html_entity_decode(trim($m[1])) : '';
        $title = preg_match('#<(?:\w+:)?title>([^<]+)</#i', $xml, $t) ? html_entity_decode(trim($t[1])) : $fallback;

        return [$launch, $title];
    }
}
