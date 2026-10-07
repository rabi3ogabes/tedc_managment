<?php

namespace Database\Seeders\Samples;

use App\Models\PageBlock;
use App\Services\Cms\CmsService;
use App\Services\Cms\HomeLayouts;
use App\Services\ThemeService;

/** The look: occasion themes for this year and the next (Ramadan, the Eids, National Day, Teachers' Day, Sports Day, graduation), a loading page, and a published home layout. */
class SampleTheme
{
    use Steps;

    public function __construct(private readonly SampleContext $c, private readonly ThemeService $themes, private readonly CmsService $cms) {}

    public function run(): void
    {
        $this->step('occasions', fn () => $this->occasions());
        $this->step('loading', fn () => $this->loading());
        $this->step('home', fn () => $this->home());
    }

    private function occasions(): void
    {
        foreach ([(int) date('Y'), (int) date('Y') + 1] as $year) {
            $this->themes->addStandardOccasions($year, $this->c->admin());
        }
    }

    private function loading(): void
    {
        $base = $this->themes->base();
        if (($base['loading']['message_ar'] ?? '') !== 'جارٍ التحميل…') {
            return;   // the administrator already set their own
        }
        $base['loading'] = ['style' => 'emblem', 'message_ar' => 'نصنع أثرًا مستدامًا في التعليم', 'message_en' => 'Building lasting impact in education', 'background' => null, 'accent' => null, 'show_name' => true];
        $this->themes->update($base, $this->c->admin());
    }

    private function home(): void
    {
        if (PageBlock::where('page', 'home')->exists()) {
            return;   // a layout is already in place
        }
        $layout = HomeLayouts::find('classic');
        $this->cms->saveDraft('home', $layout['blocks'], $this->c->admin());
        $this->cms->publish('home', 'تخطيط البداية (عينة)', $this->c->admin());
    }
}
