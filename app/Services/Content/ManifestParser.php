<?php

namespace App\Services\Content;

use SimpleXMLElement;

/** Reads the manifests of SCORM 1.2 / 2004, cmi5, TinCan and IMS Common Cartridge packages. */
final class ManifestParser
{
    private static function xml(string $text): ?SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        // Entities are never loaded: an uploaded manifest cannot read local files.
        $x = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $x ?: null;
    }

    private static function text(?SimpleXMLElement $n): string
    {
        return trim((string) $n);
    }

    /** scorm12 | scorm2004 | cc */
    public static function kind(string $xml): string
    {
        $l = strtolower($xml);
        if (str_contains($l, 'imsglobal.org/xsd/imscc') || str_contains($l, 'imsccv1') || str_contains($l, 'imscc_v1') || str_contains($l, 'imscc/')) {
            return 'cc';
        }
        if (str_contains($l, 'adlcp_v1p3') || str_contains($l, '2004') || str_contains($l, 'adlseq') || str_contains($l, 'imsss')) {
            return 'scorm2004';
        }

        return 'scorm12';
    }

    /** @return array<string, mixed> */
    public static function ims(string $text, string $kind): array
    {
        $x = self::xml($text);
        if (! $x) {
            return ['title' => 'Package', 'entry_points' => [], 'manifest' => []];
        }
        $x->registerXPathNamespace('m', array_values($x->getNamespaces())[0] ?? '');
        $resources = [];
        foreach ($x->xpath('//*[local-name()="resources"]/*[local-name()="resource"]') ?: [] as $r) {
            $attrs = $r->attributes();
            $scorm = '';
            foreach ($r->attributes('adlcp', true) ?: [] as $k => $v) {
                if (strtolower($k) === 'scormtype') {
                    $scorm = strtolower((string) $v);
                }
            }
            $files = [];
            foreach ($r->xpath('*[local-name()="file"]') ?: [] as $f) {
                $files[] = (string) $f['href'];
            }
            $resources[(string) $attrs['identifier']] = ['id' => (string) $attrs['identifier'], 'type' => (string) $attrs['type'], 'href' => (string) $attrs['href'], 'scorm_type' => $scorm, 'files' => $files];
        }

        $items = [];
        $walk = function (SimpleXMLElement $node, ?string $parent) use (&$walk, &$items, $resources) {
            foreach ($node->xpath('*[local-name()="item"]') ?: [] as $item) {
                $ref = (string) $item['identifierref'];
                $res = $ref !== '' ? ($resources[$ref] ?? null) : null;
                $id = (string) $item['identifier'];
                $items[] = ['id' => $id, 'title' => self::text(($item->xpath('*[local-name()="title"]') ?: [null])[0]) ?: $id, 'href' => $res ? ltrim($res['href'].((string) $item['parameters']), '/') : null, 'parent' => $parent, 'type' => $res ? ($res['scorm_type'] ?: $res['type']) : 'folder', 'resource_type' => $res['type'] ?? null, 'resource_id' => $ref ?: null, 'visible' => ((string) $item['isvisible']) !== 'false'];
                $walk($item, $id);
            }
        };
        $title = '';
        foreach ($x->xpath('//*[local-name()="organizations"]/*[local-name()="organization"]') ?: [] as $org) {
            $title = $title ?: self::text(($org->xpath('*[local-name()="title"]') ?: [null])[0]);
            $walk($org, null);
        }
        if ($items === [] && $resources) {
            foreach ($resources as $r) {
                if ($r['href'] !== '') {
                    $items[] = ['id' => $r['id'], 'title' => $r['id'], 'href' => $r['href'], 'parent' => null, 'type' => $r['scorm_type'] ?: $r['type'], 'resource_type' => $r['type'], 'resource_id' => $r['id'], 'visible' => true];
                }
            }
        }
        if ($title === '') {
            $title = self::text(($x->xpath('//*[local-name()="metadata"]//*[local-name()="title"]//*[local-name()="string"]') ?: [null])[0]) ?: 'Package';
        }
        $version = self::text(($x->xpath('//*[local-name()="metadata"]/*[local-name()="schemaversion"]') ?: [null])[0]);

        return ['title' => $title, 'version' => $version ?: null, 'entry_points' => array_values($items), 'manifest' => ['resources' => array_values($resources), 'kind' => $kind]];
    }

    /** @return array<string, mixed> */
    public static function cmi5(string $text): array
    {
        $x = self::xml($text);
        $aus = [];
        foreach ($x?->xpath('//*[local-name()="au"]') ?: [] as $au) {
            $title = self::text(($au->xpath('*[local-name()="title"]/*[local-name()="langstring"]') ?: [null])[0]);
            $aus[] = ['id' => (string) $au['id'], 'title' => $title ?: (string) $au['id'], 'href' => self::text(($au->xpath('*[local-name()="url"]') ?: [null])[0]), 'type' => 'au', 'move_on' => (string) ($au['moveOn'] ?: 'NotApplicable'), 'launch_method' => (string) ($au['launchMethod'] ?: 'AnyWindow'), 'mastery_score' => ($au['masteryScore'] ?? null) !== null ? (float) $au['masteryScore'] : null, 'parent' => null];
        }
        $course = ($x?->xpath('//*[local-name()="course"]') ?: [null])[0];
        $title = $course ? self::text(($course->xpath('*[local-name()="title"]/*[local-name()="langstring"]') ?: [null])[0]) : '';

        return ['title' => $title ?: 'cmi5', 'entry_points' => $aus, 'manifest' => ['course_id' => $course ? (string) $course['id'] : null]];
    }

    /** @return array<string, mixed> */
    public static function tincan(string $text): array
    {
        $x = self::xml($text);
        $acts = [];
        foreach ($x?->xpath('//*[local-name()="activity"]') ?: [] as $a) {
            $acts[] = ['id' => (string) $a['id'], 'title' => self::text(($a->xpath('*[local-name()="name"]') ?: [null])[0]) ?: (string) $a['id'], 'href' => self::text(($a->xpath('*[local-name()="launch"]') ?: [null])[0]), 'type' => 'sco', 'parent' => null];
        }

        return ['title' => $acts[0]['title'] ?? 'xAPI', 'entry_points' => array_values(array_filter($acts, fn ($a) => $a['href'] !== '')), 'manifest' => []];
    }
}
