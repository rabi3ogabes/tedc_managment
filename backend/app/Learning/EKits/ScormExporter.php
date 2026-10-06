<?php

namespace App\Learning\EKits;

use RuntimeException;
use ZipArchive;

/**
 * Exports an e-kit as a SCORM 2004 (4th edition) package, one language per package: a SCO per chapter (reading plus a
 * knowledge check) and a final-assessment SCO that reports score, completion and success to the LMS. Each page is plain
 * HTML with a language and direction, a skip link, native keyboard-operable controls and a live region for feedback.
 */
class ScormExporter
{
    public const PASS_CHAPTER = 0.5;

    public const PASS_FINAL = 0.7;

    /** @return string the zip file's bytes */
    public function export(array $kit, string $lang): string
    {
        $lang = $lang === 'en' ? 'en' : 'ar';
        $tmp = tempnam(sys_get_temp_dir(), 'scorm');
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the package.');
        }
        $scos = [];
        foreach ($kit['chapters'] as $i => $c) {
            $scos[] = ['id' => 'ch'.($i + 1), 'file' => 'ch'.($i + 1).'.html', 'title' => $c['title_'.$lang], 'pass' => self::PASS_CHAPTER, 'html' => $this->page($kit, $lang, $c['title_'.$lang], $c['body_'.$lang], $c['check'], self::PASS_CHAPTER, false)];
        }
        $finalTitle = $lang === 'ar' ? 'التقييم النهائي' : 'Final assessment';
        $scos[] = ['id' => 'final', 'file' => 'final.html', 'title' => $finalTitle, 'pass' => self::PASS_FINAL, 'html' => $this->page($kit, $lang, $finalTitle, '', $kit['final'], self::PASS_FINAL, true)];

        $zip->addFromString('imsmanifest.xml', $this->manifest($kit, $lang, $scos));
        $zip->addFromString('shared/scorm.js', $this->scormJs());
        $zip->addFromString('shared/style.css', $this->css());
        foreach ($scos as $s) {
            $zip->addFromString($s['file'], $s['html']);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function manifest(array $kit, string $lang, array $scos): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '_', $kit['code']).'_'.$lang;
        $items = '';
        $resources = '';
        $objectives = '';
        foreach ($scos as $s) {
            $items .= sprintf("      <item identifier=\"ITEM_%1\$s\" identifierref=\"RES_%1\$s\" isvisible=\"true\">\n        <title>%2\$s</title>\n        <imsss:sequencing>\n          <imsss:objectives>\n            <imsss:primaryObjective objectiveID=\"OBJ_%1\$s\" satisfiedByMeasure=\"true\">\n              <imsss:minNormalizedMeasure>%3\$s</imsss:minNormalizedMeasure>\n            </imsss:primaryObjective>\n          </imsss:objectives>\n        </imsss:sequencing>\n      </item>\n", $s['id'], $this->x($s['title']), $s['pass']);
            $resources .= sprintf("    <resource identifier=\"RES_%1\$s\" type=\"webcontent\" adlcp:scormType=\"sco\" href=\"%2\$s\">\n      <file href=\"%2\$s\"/>\n      <file href=\"shared/scorm.js\"/>\n      <file href=\"shared/style.css\"/>\n    </resource>\n", $s['id'], $s['file']);
        }
        $title = $this->x($kit['title_'.$lang]);
        $desc = $this->x(implode($lang === 'ar' ? '، ' : '; ', $kit['objectives_'.$lang]));

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="MANIFEST_{$id}" version="1.0"
  xmlns="http://www.imsglobal.org/xsd/imscp_v1p1"
  xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_v1p3"
  xmlns:adlseq="http://www.adlnet.org/xsd/adlseq_v1p3"
  xmlns:adlnav="http://www.adlnet.org/xsd/adlnav_v1p3"
  xmlns:imsss="http://www.imsglobal.org/xsd/imsss"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.imsglobal.org/xsd/imscp_v1p1 imscp_v1p1.xsd http://www.adlnet.org/xsd/adlcp_v1p3 adlcp_v1p3.xsd http://www.adlnet.org/xsd/adlseq_v1p3 adlseq_v1p3.xsd http://www.adlnet.org/xsd/adlnav_v1p3 adlnav_v1p3.xsd http://www.imsglobal.org/xsd/imsss imsss_v1p0.xsd">
  <metadata>
    <schema>ADL SCORM</schema>
    <schemaversion>2004 4th Edition</schemaversion>
  </metadata>
  <organizations default="ORG_{$id}">
    <organization identifier="ORG_{$id}">
      <title>{$title}</title>
{$items}      <imsss:sequencing>
        <imsss:controlMode choice="true" flow="true"/>
      </imsss:sequencing>
    </organization>
  </organizations>
  <resources>
{$resources}  </resources>
</manifest>
XML;
    }

    private function page(array $kit, string $lang, string $title, string $body, array $questions, float $pass, bool $final): string
    {
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        $t = $lang === 'ar'
            ? ['skip' => 'تخطَّ إلى المحتوى', 'check' => 'تحقق', 'next' => 'السؤال التالي', 'finish' => 'إنهاء', 'result' => 'نتيجتك', 'of' => 'من', 'passed' => 'نجحت', 'failed' => 'لم تبلغ درجة النجاح', 'correct' => 'إجابة صحيحة', 'wrong' => 'إجابة غير صحيحة', 'pick' => 'اختر إجابة أولًا', 'q' => 'السؤال', 'course' => 'المقرر']
            : ['skip' => 'Skip to content', 'check' => 'Check', 'next' => 'Next question', 'finish' => 'Finish', 'result' => 'Your result', 'of' => 'of', 'passed' => 'Passed', 'failed' => 'Pass mark not reached', 'correct' => 'Correct', 'wrong' => 'Not correct', 'pick' => 'Choose an answer first', 'q' => 'Question', 'course' => 'Course'];
        $qs = [];
        foreach ($questions as $q) {
            $qs[] = ['stem' => $q['stem_'.$lang], 'options' => array_map(fn ($o) => $o[$lang], $q['options']), 'correct' => (int) $q['correct'], 'why' => $q['explanation_'.$lang] ?? ''];
        }
        $data = json_encode(['questions' => $qs, 'pass' => $pass, 'final' => $final, 't' => $t], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        $bodyHtml = $body !== '' ? '<section aria-label="'.e($title).'">'.$body.'</section>' : '';

        return <<<HTML
<!doctype html>
<html lang="{$lang}" dir="{$dir}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$this->h($title)}</title>
<link rel="stylesheet" href="shared/style.css">
</head>
<body>
<a class="skip" href="#main">{$t['skip']}</a>
<header><p class="course">{$t['course']}: {$this->h($kit['title_'.$lang])}</p><h1>{$this->h($title)}</h1></header>
<main id="main" tabindex="-1">
{$bodyHtml}
<section id="quiz" aria-live="polite"></section>
</main>
<script src="shared/scorm.js"></script>
<script>
var EKIT = {$data};
(function () {
  var s = Scorm.init(), qs = EKIT.questions, T = EKIT.t, i = 0, right = 0, quiz = document.getElementById('quiz');
  if (!EKIT.final) { s.set('cmi.completion_status', 'incomplete'); }
  function esc(x) { var d = document.createElement('div'); d.textContent = x; return d.innerHTML; }
  function show() {
    var q = qs[i];
    var h = '<form><fieldset><legend>' + T.q + ' ' + (i + 1) + ' / ' + qs.length + ': ' + esc(q.stem) + '</legend>';
    q.options.forEach(function (o, k) { h += '<label class="opt"><input type="radio" name="a" value="' + k + '"> <span>' + esc(o) + '</span></label>'; });
    h += '</fieldset><button type="submit">' + T.check + '</button><p class="fb" role="status"></p></form>';
    quiz.innerHTML = h;
    var f = quiz.querySelector('form'), fb = quiz.querySelector('.fb');
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      if (f.dataset.done) { i++; return i < qs.length ? show() : done(); }
      var c = f.querySelector('input:checked');
      if (!c) { fb.textContent = T.pick; return; }
      var ok = Number(c.value) === q.correct;
      if (ok) { right++; }
      f.dataset.done = '1';
      Array.prototype.forEach.call(f.querySelectorAll('input'), function (x) { x.disabled = true; });
      fb.className = 'fb ' + (ok ? 'ok' : 'no');
      fb.textContent = (ok ? T.correct : T.wrong) + (q.why ? ' — ' + q.why : '');
      var b = f.querySelector('button'); b.textContent = (i + 1 < qs.length) ? T.next : T.finish; b.focus();
    });
    f.querySelector('input').focus();
  }
  function done() {
    var scaled = qs.length ? right / qs.length : 1, ok = scaled >= EKIT.pass;
    s.set('cmi.score.min', '0'); s.set('cmi.score.max', String(qs.length)); s.set('cmi.score.raw', String(right)); s.set('cmi.score.scaled', scaled.toFixed(4));
    s.set('cmi.success_status', ok ? 'passed' : 'failed');
    s.set('cmi.completion_status', 'completed');
    s.set('cmi.exit', 'normal');
    s.commit();
    quiz.innerHTML = '<h2>' + T.result + '</h2><p class="score">' + right + ' ' + T.of + ' ' + qs.length + '</p><p class="' + (ok ? 'ok' : 'no') + '" role="status">' + (ok ? T.passed : T.failed) + '</p>';
  }
  window.addEventListener('beforeunload', function () { s.finish(); });
  window.addEventListener('pagehide', function () { s.finish(); });
  if (qs.length) { show(); } else { s.set('cmi.completion_status', 'completed'); s.commit(); }
})();
</script>
</body>
</html>
HTML;
    }

    private function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private function scormJs(): string
    {
        return <<<'JS'
/* Minimal SCORM 2004 runtime wrapper: finds API_1484_11, and keeps working (in memory) when opened outside an LMS. */
var Scorm = (function () {
  var api = null, started = false, ended = false, memory = {};
  function find(w) {
    var tries = 0;
    while (w && !w.API_1484_11 && w.parent && w.parent !== w && tries++ < 10) { w = w.parent; }
    return w && w.API_1484_11 ? w.API_1484_11 : null;
  }
  function discover() {
    var a = find(window);
    if (!a && window.opener) { try { a = find(window.opener); } catch (e) { a = null; } }
    return a;
  }
  return {
    init: function () {
      api = discover();
      if (api) { started = api.Initialize('') === 'true'; }
      return this;
    },
    connected: function () { return !!api && started; },
    set: function (k, v) { if (this.connected() && !ended) { return api.SetValue(k, v) === 'true'; } memory[k] = v; return false; },
    get: function (k) { return this.connected() && !ended ? api.GetValue(k) : (memory[k] || ''); },
    commit: function () { return this.connected() && !ended ? api.Commit('') === 'true' : false; },
    finish: function () { if (this.connected() && !ended) { ended = true; api.Commit(''); return api.Terminate('') === 'true'; } return false; }
  };
})();
JS;
    }

    private function css(): string
    {
        return <<<'CSS'
:root { --ink: #1b2433; --navy: #123b5d; --gold: #b8892b; --line: #c8d0db; --ok: #1f7a4d; --no: #b3261e; }
* { box-sizing: border-box; }
body { margin: 0 auto; max-width: 46rem; padding: 1.25rem; font: 1.05rem/1.8 system-ui, "Segoe UI", Tahoma, sans-serif; color: var(--ink); background: #fff; }
h1 { color: var(--navy); font-size: 1.6rem; margin: .2rem 0 1rem; }
.course { margin: 0; color: #5b6573; font-size: .9rem; }
.skip { position: absolute; inset-inline-start: -999px; }
.skip:focus { inset-inline-start: 1rem; top: 1rem; background: #fff; padding: .5rem 1rem; border: 2px solid var(--navy); z-index: 9; }
main:focus { outline: none; }
fieldset { border: 1px solid var(--line); border-radius: .5rem; padding: 1rem; margin: 1rem 0; }
legend { font-weight: 600; padding: 0 .5rem; }
.opt { display: flex; gap: .6rem; align-items: center; padding: .55rem .4rem; border-radius: .4rem; cursor: pointer; }
.opt:hover { background: #f1f4f8; }
input[type=radio] { width: 1.2rem; height: 1.2rem; accent-color: var(--navy); }
button { background: var(--navy); color: #fff; border: 0; border-radius: .5rem; padding: .7rem 1.4rem; font: inherit; cursor: pointer; }
button:hover { background: #0d2c46; }
:focus-visible { outline: 3px solid var(--gold); outline-offset: 2px; }
.fb { min-height: 1.6rem; margin: .8rem 0 0; font-weight: 600; }
.ok { color: var(--ok); } .no { color: var(--no); }
.score { font-size: 2rem; font-weight: 700; color: var(--navy); margin: .2rem 0; }
blockquote { border-inline-start: 4px solid var(--gold); margin: 1rem 0; padding: .1rem 1rem; color: #3a4658; }
@media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
CSS;
    }
}
