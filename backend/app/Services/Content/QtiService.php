<?php

namespace App\Services\Content;

use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\User;
use App\Services\Assessment\QuestionBankService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** QTI 2.1 export and import (and QTI 1.2 import) for question banks. Interactions TEDC cannot represent are reported, never dropped silently. */
class QtiService
{
    private const NS = 'http://www.imsglobal.org/xsd/imsqti_v2p1';

    public const EXPORTABLE = ['single_choice', 'multiple_select', 'true_false', 'short_answer', 'numeric', 'essay', 'matching', 'ordering', 'fill_blanks', 'dropdown'];

    public function __construct(private readonly QuestionBankService $banks) {}

    // ───────────────────────────── export

    /** @param  list<Question>  $questions  @return array{zip: string, skipped: list<array<string, string>>} */
    public function export(array $questions): array
    {
        $files = [];
        $skipped = [];
        $resources = '';
        foreach ($questions as $q) {
            $xml = in_array($q->type, self::EXPORTABLE, true) ? $this->item($q) : null;
            if ($xml === null) {
                $skipped[] = ['id' => $q->id, 'type' => $q->type, 'reason' => 'unsupported_in_qti'];

                continue;
            }
            $files["items/{$q->id}.xml"] = $xml;
            $resources .= '<resource identifier="r'.$q->id.'" type="imsqti_item_xmlv2p1" href="items/'.$q->id.'.xml"><file href="items/'.$q->id.'.xml"/></resource>';
        }
        $manifest = '<?xml version="1.0" encoding="UTF-8"?><manifest xmlns="http://www.imsglobal.org/xsd/imscp_v1p1" identifier="tedc-qti"><metadata><schema>QTIv2.1</schema><schemaversion>2.1</schemaversion></metadata><organizations/><resources>'.$resources.'</resources></manifest>';
        $path = tempnam(sys_get_temp_dir(), 'qti');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        $zip->addFromString('imsmanifest.xml', $manifest);
        foreach ($files as $n => $c) {
            $zip->addFromString($n, $c);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return ['zip' => $bytes, 'skipped' => $skipped];
    }

    private function esc(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function item(Question $q): ?string
    {
        $p = $q->payload;
        $stem = $this->esc($q->stem_ar);
        $decl = '';
        $body = '';
        $shuffle = 'true';
        switch ($q->type) {
            case 'single_choice':
            case 'multiple_select':
                $multi = $q->type === 'multiple_select';
                $correct = array_values(array_map(fn ($o) => $o['id'], array_filter($p['options'], fn ($o) => $o['correct'])));
                $decl = '<responseDeclaration identifier="RESPONSE" cardinality="'.($multi ? 'multiple' : 'single').'" baseType="identifier"><correctResponse>'.implode('', array_map(fn ($v) => '<value>'.$this->esc($v).'</value>', $correct)).'</correctResponse></responseDeclaration>';
                $body = '<choiceInteraction responseIdentifier="RESPONSE" shuffle="'.$shuffle.'" maxChoices="'.($multi ? 0 : 1).'"><prompt>'.$stem.'</prompt>'.implode('', array_map(fn ($o) => '<simpleChoice identifier="'.$this->esc($o['id']).'">'.$this->esc($o['text_ar']).'</simpleChoice>', $p['options'])).'</choiceInteraction>';
                break;
            case 'true_false':
                $decl = '<responseDeclaration identifier="RESPONSE" cardinality="single" baseType="identifier"><correctResponse><value>'.($p['correct'] ? 'true' : 'false').'</value></correctResponse></responseDeclaration>';
                $body = '<choiceInteraction responseIdentifier="RESPONSE" shuffle="false" maxChoices="1"><prompt>'.$stem.'</prompt><simpleChoice identifier="true">True</simpleChoice><simpleChoice identifier="false">False</simpleChoice></choiceInteraction>';
                break;
            case 'short_answer':
                $acc = $p['accepted'];
                $decl = '<responseDeclaration identifier="RESPONSE" cardinality="single" baseType="string"><correctResponse><value>'.$this->esc($acc[0]).'</value></correctResponse><mapping defaultValue="0">'.implode('', array_map(fn ($a) => '<mapEntry mapKey="'.$this->esc($a).'" mappedValue="1"/>', $acc)).'</mapping></responseDeclaration>';
                $body = '<p>'.$stem.'</p><p><textEntryInteraction responseIdentifier="RESPONSE" expectedLength="30"/></p>';
                break;
            case 'numeric':
                $decl = '<responseDeclaration identifier="RESPONSE" cardinality="single" baseType="float"><correctResponse><value>'.$this->esc((string) $p['value']).'</value></correctResponse></responseDeclaration>';
                $body = '<p>'.$stem.'</p><p><textEntryInteraction responseIdentifier="RESPONSE" expectedLength="12"/></p>';
                break;
            case 'essay':
                $decl = '<responseDeclaration identifier="RESPONSE" cardinality="single" baseType="string"/>';
                $body = '<extendedTextInteraction responseIdentifier="RESPONSE"><prompt>'.$stem.'</prompt></extendedTextInteraction>';
                break;
            case 'ordering':
                $decl = '<responseDeclaration identifier="RESPONSE" cardinality="ordered" baseType="identifier"><correctResponse>'.implode('', array_map(fn ($i) => '<value>'.$this->esc($i['id']).'</value>', $p['items'])).'</correctResponse></responseDeclaration>';
                $body = '<orderInteraction responseIdentifier="RESPONSE" shuffle="true"><prompt>'.$stem.'</prompt>'.implode('', array_map(fn ($i) => '<simpleChoice identifier="'.$this->esc($i['id']).'">'.$this->esc($i['text']).'</simpleChoice>', $p['items'])).'</orderInteraction>';
                break;
            case 'matching':
                $decl = '<responseDeclaration identifier="RESPONSE" cardinality="multiple" baseType="directedPair"><correctResponse>'.implode('', array_map(fn ($x) => '<value>L'.$this->esc($x['id']).' R'.$this->esc($x['id']).'</value>', $p['pairs'])).'</correctResponse></responseDeclaration>';
                $n = count($p['pairs']);
                $body = '<matchInteraction responseIdentifier="RESPONSE" shuffle="true" maxAssociations="'.$n.'"><prompt>'.$stem.'</prompt><simpleMatchSet>'.implode('', array_map(fn ($x) => '<simpleAssociableChoice identifier="L'.$this->esc($x['id']).'" matchMax="1">'.$this->esc($x['left']).'</simpleAssociableChoice>', $p['pairs'])).'</simpleMatchSet><simpleMatchSet>'.implode('', array_map(fn ($x) => '<simpleAssociableChoice identifier="R'.$this->esc($x['id']).'" matchMax="1">'.$this->esc($x['right']).'</simpleAssociableChoice>', $p['pairs'])).'</simpleMatchSet></matchInteraction>';
                break;
            case 'fill_blanks':
                $text = $this->esc($p['text_ar']);
                foreach ($p['blanks'] as $i => $b) {
                    $decl .= '<responseDeclaration identifier="RESPONSE_'.$b['id'].'" cardinality="single" baseType="string"><correctResponse><value>'.$this->esc($b['accepted'][0]).'</value></correctResponse><mapping defaultValue="0">'.implode('', array_map(fn ($a) => '<mapEntry mapKey="'.$this->esc($a).'" mappedValue="1"/>', $b['accepted'])).'</mapping></responseDeclaration>';
                    $text = str_replace('{{'.($i + 1).'}}', '<textEntryInteraction responseIdentifier="RESPONSE_'.$b['id'].'" expectedLength="15"/>', $text);
                }
                $body = '<p>'.$text.'</p>';
                break;
            case 'dropdown':
                $text = $this->esc($p['template_ar']);
                foreach ($p['blanks'] as $i => $b) {
                    $decl .= '<responseDeclaration identifier="RESPONSE_'.$b['id'].'" cardinality="single" baseType="identifier"><correctResponse><value>'.$this->esc($b['correct']).'</value></correctResponse></responseDeclaration>';
                    $inline = '<inlineChoiceInteraction responseIdentifier="RESPONSE_'.$b['id'].'" shuffle="true">'.implode('', array_map(fn ($o) => '<inlineChoice identifier="'.$this->esc($o['id']).'">'.$this->esc($o['text']).'</inlineChoice>', $b['options'])).'</inlineChoiceInteraction>';
                    $text = str_replace('{{'.($i + 1).'}}', $inline, $text);
                }
                $body = '<p>'.$text.'</p>';
                break;
            default:
                return null;
        }

        return '<?xml version="1.0" encoding="UTF-8"?><assessmentItem xmlns="'.self::NS.'" identifier="'.$q->id.'" title="'.$this->esc(mb_substr($q->stem_ar, 0, 60)).'" adaptive="false" timeDependent="false"><outcomeDeclaration identifier="SCORE" cardinality="single" baseType="float"/>'.$decl.'<itemBody>'.$body.'</itemBody><responseProcessing template="http://www.imsglobal.org/question/qti_v2p1/rptemplates/match_correct"/></assessmentItem>';
    }

    // ───────────────────────────── import

    /**
     * @param  string  $bytes  a QTI zip, or a single item / test XML
     * @return array{created: int, errors: list<array<string, string>>, unsupported: list<array<string, string>>, questions: list<Question>}
     */
    public function import(QuestionBank $bank, string $bytes, User $by): array
    {
        $created = [];
        $errors = [];
        $unsupported = [];
        foreach ($this->xmlFiles($bytes) as $name => $xml) {
            $dom = new DOMDocument;
            $prev = libxml_use_internal_errors(true);
            $ok = $dom->loadXML($xml, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            if (! $ok || ! $dom->documentElement) {
                $errors[] = ['file' => $name, 'reason' => 'bad_xml'];

                continue;
            }
            $root = $dom->documentElement->localName;
            if ($root === 'assessmentItem') {
                $items = [$this->parseItem21($dom->documentElement)];
            } elseif ($root === 'questestinterop') {
                $items = $this->parseItems12($dom);
            } else {
                continue;   // manifests and tests are not questions
            }
            foreach ($items as $i) {
                if (isset($i['unsupported'])) {
                    $unsupported[] = ['file' => $name, 'title' => $i['title'] ?? '', 'interaction' => $i['unsupported']];

                    continue;
                }
                try {
                    $created[] = $this->banks->create($bank, $i, $by)['question'];
                } catch (ValidationException $e) {
                    $errors[] = ['file' => $name, 'reason' => collect($e->errors())->flatten()->first() ?? 'invalid'];
                }
            }
        }

        return ['created' => count($created), 'errors' => $errors, 'unsupported' => $unsupported, 'questions' => $created];
    }

    /** @return array<string, string> */
    private function xmlFiles(string $bytes): array
    {
        if (str_starts_with($bytes, 'PK')) {
            $path = tempnam(sys_get_temp_dir(), 'qti');
            file_put_contents($path, $bytes);
            $zip = new ZipArchive;
            $out = [];
            if ($zip->open($path) === true) {
                for ($i = 0; $i < min($zip->numFiles, 2000); $i++) {
                    $n = (string) $zip->getNameIndex($i);
                    if (str_ends_with(strtolower($n), '.xml') && ! preg_match('#(^|/)\.\.(/|$)#', $n)) {
                        $out[$n] = (string) $zip->getFromIndex($i);
                    }
                }
                $zip->close();
            }
            @unlink($path);

            return $out;
        }

        return ['upload.xml' => $bytes];
    }

    private function text(DOMElement $e): string
    {
        return trim(preg_replace('/\s+/u', ' ', $e->textContent) ?? '');
    }

    /** @return array<string, mixed> */
    private function parseItem21(DOMElement $item): array
    {
        $x = new DOMXPath($item->ownerDocument);
        $q = fn (string $path, ?DOMElement $ctx = null) => $x->query($path, $ctx ?? $item);
        $title = $item->getAttribute('title');
        $body = $q('.//*[local-name()="itemBody"]')->item(0);
        if (! $body) {
            return ['unsupported' => 'no_item_body', 'title' => $title];
        }
        $decl = [];
        foreach ($q('.//*[local-name()="responseDeclaration"]') as $d) {
            $vals = [];
            foreach ($q('.//*[local-name()="correctResponse"]/*[local-name()="value"]', $d) as $v) {
                $vals[] = trim($v->textContent);
            }
            $map = [];
            foreach ($q('.//*[local-name()="mapEntry"]', $d) as $m) {
                $map[] = $m->getAttribute('mapKey');
            }
            $decl[$d->getAttribute('identifier')] = ['cardinality' => $d->getAttribute('cardinality'), 'baseType' => $d->getAttribute('baseType'), 'correct' => $vals, 'map' => $map];
        }
        $interactions = [];
        foreach ($q('.//*[contains(local-name(), "Interaction")]', $body) as $i) {
            $interactions[] = $i;
        }
        if ($interactions === []) {
            return ['unsupported' => 'no_interaction', 'title' => $title];
        }
        $first = $interactions[0];
        $kind = $first->localName;
        $prompt = $q('.//*[local-name()="prompt"]', $first)->item(0);
        $stem = $prompt ? $this->text($prompt) : ($this->stemOutside($body, $interactions) ?: $title);
        $base = ['stem_ar' => $stem, 'stem_en' => null, 'points' => 1, 'difficulty' => 'medium'];
        $r = $decl[$first->getAttribute('responseIdentifier')] ?? ['correct' => [], 'map' => [], 'cardinality' => 'single', 'baseType' => ''];

        switch ($kind) {
            case 'choiceInteraction':
                $opts = [];
                foreach ($q('.//*[local-name()="simpleChoice"]', $first) as $c) {
                    $opts[] = ['id' => $c->getAttribute('identifier'), 'text_ar' => $this->text($c), 'correct' => in_array($c->getAttribute('identifier'), $r['correct'], true)];
                }
                $ids = array_map('strtolower', array_column($opts, 'id'));
                if (count($opts) === 2 && in_array('true', $ids, true) && in_array('false', $ids, true)) {
                    return $base + ['type' => 'true_false', 'payload' => ['correct' => in_array('true', array_map('strtolower', $r['correct']), true)]];
                }

                return $base + ['type' => $r['cardinality'] === 'multiple' ? 'multiple_select' : 'single_choice', 'payload' => ['options' => $opts] + ($r['cardinality'] === 'multiple' ? ['partial' => true] : [])];
            case 'textEntryInteraction':
                if (count($interactions) > 1) {
                    $blanks = [];
                    $text = $this->bodyWithBlanks($body, $interactions, $decl, $blanks);

                    return ['stem_ar' => $title ?: $stem] + $base + ['type' => 'fill_blanks', 'payload' => ['text_ar' => $text, 'blanks' => $blanks]];
                }
                if (in_array($r['baseType'], ['float', 'integer'], true)) {
                    return $base + ['type' => 'numeric', 'payload' => ['value' => (float) ($r['correct'][0] ?? 0), 'tolerance' => 0]];
                }

                return $base + ['type' => 'short_answer', 'payload' => ['accepted' => array_values(array_unique(array_filter(array_merge($r['correct'], $r['map']), fn ($v) => $v !== ''))) ?: [''], 'case_sensitive' => false]];
            case 'extendedTextInteraction':
                return $base + ['type' => 'essay', 'payload' => ['rubric' => []]];
            case 'orderInteraction':
                $items = [];
                foreach ($q('.//*[local-name()="simpleChoice"]', $first) as $c) {
                    $items[$c->getAttribute('identifier')] = $this->text($c);
                }
                $ordered = [];
                foreach ($r['correct'] as $id) {
                    if (isset($items[$id])) {
                        $ordered[] = ['id' => $id, 'text' => $items[$id]];
                    }
                }

                return $base + ['type' => 'ordering', 'payload' => ['items' => $ordered ?: array_map(fn ($id, $t) => ['id' => $id, 'text' => $t], array_keys($items), $items)]];
            case 'matchInteraction':
                $sets = $q('.//*[local-name()="simpleMatchSet"]', $first);
                $label = [];
                foreach ($sets as $si => $set) {
                    foreach ($q('.//*[local-name()="simpleAssociableChoice"]', $set) as $c) {
                        $label[$c->getAttribute('identifier')] = [$si, $this->text($c)];
                    }
                }
                $pairs = [];
                foreach ($r['correct'] as $pair) {
                    [$a, $b] = array_pad(preg_split('/\s+/', trim($pair)), 2, '');
                    if (isset($label[$a], $label[$b])) {
                        [$l, $rr] = $label[$a][0] === 0 ? [$label[$a], $label[$b]] : [$label[$b], $label[$a]];
                        $pairs[] = ['id' => 'p'.(count($pairs) + 1), 'left' => $l[1], 'right' => $rr[1]];
                    }
                }

                return $pairs ? $base + ['type' => 'matching', 'payload' => ['pairs' => $pairs]] : ['unsupported' => 'matchInteraction_without_pairs', 'title' => $title];
            case 'inlineChoiceInteraction':
                $blanks = [];
                $text = $this->bodyWithBlanks($body, $interactions, $decl, $blanks, true);

                return ['stem_ar' => $title ?: $stem] + $base + ['type' => 'dropdown', 'payload' => ['template_ar' => $text, 'blanks' => $blanks]];
            default:
                return ['unsupported' => $kind, 'title' => $title];
        }
    }

    /** The item body's text with interactions removed (the stem when there is no <prompt>). */
    private function stemOutside(DOMElement $body, array $interactions): string
    {
        $clone = $body->cloneNode(true);
        $x = new DOMXPath($clone->ownerDocument);
        foreach (iterator_to_array($x->query('.//*[contains(local-name(), "Interaction")]', $clone)) as $n) {
            $n->parentNode->removeChild($n);
        }

        return $this->text($clone);
    }

    /** Replaces each blank with {{n}} and collects the answers. @param  array<int, array<string, mixed>>  $blanks */
    private function bodyWithBlanks(DOMElement $body, array $interactions, array $decl, array &$blanks, bool $inline = false): string
    {
        $doc = $body->ownerDocument;
        $clone = $body->cloneNode(true);
        $x = new DOMXPath($doc);
        $n = 0;
        foreach (iterator_to_array($x->query('.//*[contains(local-name(), "Interaction")]', $clone)) as $i) {
            $n++;
            $rid = $i->getAttribute('responseIdentifier');
            $d = $decl[$rid] ?? ['correct' => [], 'map' => []];
            if ($inline) {
                $opts = [];
                foreach ($x->query('.//*[local-name()="inlineChoice"]', $i) as $c) {
                    $opts[] = ['id' => $c->getAttribute('identifier'), 'text' => $this->text($c)];
                }
                $blanks[] = ['id' => 'b'.$n, 'options' => $opts, 'correct' => $d['correct'][0] ?? ($opts[0]['id'] ?? '')];
            } else {
                $blanks[] = ['id' => 'b'.$n, 'accepted' => array_values(array_unique(array_filter(array_merge($d['correct'], $d['map'])))) ?: ['']];
            }
            $i->parentNode->replaceChild($doc->createTextNode('{{'.$n.'}}'), $i);
        }

        return $this->text($clone);
    }

    /** @return list<array<string, mixed>> */
    private function parseItems12(DOMDocument $dom): array
    {
        $x = new DOMXPath($dom);
        $out = [];
        foreach ($x->query('//*[local-name()="item"]') as $item) {
            $title = $item->getAttribute('title');
            $stem = trim(implode(' ', array_map(fn ($n) => $this->text($n), iterator_to_array($x->query('.//*[local-name()="presentation"]/*[local-name()="material"][1]//*[local-name()="mattext"]', $item)))));
            $lid = $x->query('.//*[local-name()="response_lid"]', $item)->item(0);
            $str = $x->query('.//*[local-name()="response_str"]', $item)->item(0);
            $correct = [];
            foreach ($x->query('.//*[local-name()="respcondition"]//*[local-name()="varequal"]', $item) as $v) {
                $correct[] = trim($v->textContent);
            }
            $base = ['stem_ar' => $stem ?: $title, 'stem_en' => null, 'points' => 1, 'difficulty' => 'medium'];
            if ($lid) {
                $opts = [];
                foreach ($x->query('.//*[local-name()="response_label"]', $lid) as $l) {
                    $opts[] = ['id' => $l->getAttribute('ident'), 'text_ar' => $this->text($x->query('.//*[local-name()="mattext"]', $l)->item(0) ?: $l), 'correct' => in_array($l->getAttribute('ident'), $correct, true)];
                }
                $multi = $lid->getAttribute('rcardinality') === 'Multiple';
                $out[] = $base + ['type' => $multi ? 'multiple_select' : 'single_choice', 'payload' => ['options' => $opts] + ($multi ? ['partial' => true] : [])];
            } elseif ($str) {
                $out[] = $base + ['type' => 'short_answer', 'payload' => ['accepted' => $correct ?: [''], 'case_sensitive' => false]];
            } else {
                $out[] = ['unsupported' => 'qti12_unknown_response', 'title' => $title];
            }
        }

        return $out;
    }
}
