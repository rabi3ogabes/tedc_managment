<?php

namespace Database\Seeders;

use App\Models\HelpArticle;
use Illuminate\Database\Seeder;

/** Starter help articles for every role; an article that already exists (and may have been edited) is left alone. */
class HelpArticlesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (require __DIR__.'/data/help-articles.php' as $i => $a) {
            [$slug, $module, $roles, $routes, $titleAr, $titleEn, $introAr, $introEn, $stepsAr, $stepsEn] = $a;
            if (HelpArticle::where('slug', $slug)->exists()) {
                continue;
            }
            $article = HelpArticle::create([
                'slug' => $slug, 'module' => $module, 'roles' => $roles, 'related_routes' => $routes, 'title_ar' => $titleAr, 'title_en' => $titleEn,
                'body_ar' => $this->body($introAr, $stepsAr, $a[10] ?? null, 'الخطوات', 'نصيحة'), 'body_en' => $this->body($introEn, $stepsEn, $a[11] ?? null, 'Steps', 'Tip'),
                'sort_order' => $i, 'status' => 'published', 'version' => 1,
            ]);
            $article->versions()->create(['version' => 1, 'snapshot' => $article->only(['title_ar', 'title_en', 'body_ar', 'body_en', 'roles', 'related_routes', 'module'])]);
        }
    }

    private function body(string $intro, array $steps, ?string $tip, string $stepsLabel, string $tipLabel): string
    {
        $html = '<p>'.e($intro).'</p><h3>'.$stepsLabel.'</h3><ol>'.implode('', array_map(fn ($s) => '<li>'.e($s).'</li>', $steps)).'</ol>';

        return $tip ? $html.'<blockquote><strong>'.$tipLabel.':</strong> '.e($tip).'</blockquote>' : $html;
    }
}
