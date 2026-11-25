<?php

namespace App\Integrations\Contracts;

/**
 * Checks a contract or a mapping before it is saved. A contract is data: it names paths (never a host), templates (never a secret or
 * a code), headers (never credentials), pagination, error tables and JSON Schemas. What cannot be said here cannot be done by it.
 */
final class ContractValidator
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public const AUTH_TYPES = ['none', 'bearer', 'api_key', 'basic', 'oauth2', 'hmac', 'mtls'];

    public const MAX_BYTES = 200_000;

    /** Headers a contract may not set: credentials belong to the auth block, the rest belong to the HTTP client. */
    private const FORBIDDEN_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'host', 'content-length', 'transfer-encoding', 'connection', 'upgrade', 'te', 'trailer'];

    /**
     * @param  'contract'|'mapping'|string  $kind
     * @param  list<string>  $secretSettings  the names of this system's settings that hold secrets
     * @param  list<string>|null  $knownSettings  every setting the system has (a contract may only name those); null: not checked
     * @return list<string>
     */
    public static function problems(string $system, string $kind, array $doc, array $secretSettings, ?array $knownSettings = null): array
    {
        if (strlen((string) json_encode($doc, JSON_UNESCAPED_UNICODE)) > self::MAX_BYTES) {
            return ['the document is too large (200 kB at most)'];
        }

        return match ($kind) {
            'contract' => self::contract($system, $doc, $secretSettings, $knownSettings),
            'mapping' => self::mapping($doc),
            default => ['unknown kind "'.mb_substr($kind, 0, 20).'"'],
        };
    }

    /** @return list<string> */
    private static function contract(string $system, array $doc, array $secrets, ?array $known): array
    {
        $p = [];
        if (($doc['system'] ?? null) !== $system) {
            $p[] = '"system" must be "'.$system.'"';
        }
        $p = array_merge($p, self::auth((array) ($doc['auth'] ?? ['type' => 'none']), $known));
        if (isset($doc['base_url'])) {
            $p = array_merge($p, self::baseUrl($doc['base_url'], $known));
        }
        $ops = $doc['operations'] ?? null;
        if (! is_array($ops) || $ops === []) {
            return array_merge($p, ['"operations" must list at least one operation']);
        }
        foreach ($ops as $name => $op) {
            $name = (string) $name;
            if (! preg_match('/^[a-z][a-z0-9_]{1,60}$/', $name)) {
                $p[] = 'operation name "'.mb_substr($name, 0, 30).'" must be lower-case letters, digits and underscores';
            }
            $p = array_merge($p, array_map(fn ($x) => "{$name}: {$x}", self::operation(is_array($op) ? $op : [], $secrets, $known)));
        }
        if (isset($doc['inbound'])) {
            $p = array_merge($p, self::inbound($doc['inbound'], $secrets, $known));
        }
        if (isset($doc['sms'])) {
            $p = array_merge($p, self::sms($doc['sms']));
        }
        foreach ((array) ($doc['tests'] ?? []) as $i => $t) {
            if (! is_array($t) || ! isset($t['name'], $t['operation']) || ! isset($ops[$t['operation']])) {
                $p[] = 'test case #'.($i + 1).' must name an operation of this contract';

                continue;
            }
            if (isset($t['fixture']) && (! is_string($t['fixture']) || ! preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)?$/', $t['fixture']))) {
                $p[] = 'test case #'.($i + 1).': "fixture" is the name of a recorded answer (lower-case letters, digits, underscores, one dot)';
            }
            if (isset($t['response']) && (! is_array($t['response']) || ! is_int($t['response']['status'] ?? null) || $t['response']['status'] < 100 || $t['response']['status'] > 599)) {
                $p[] = 'test case #'.($i + 1).': "response" is an answer with a "status" (100 to 599) and a "body"';
            }
            if (isset($t['settings'])) {
                $p = array_merge($p, array_map(fn ($x) => 'test case #'.($i + 1).': '.$x, self::caseSettings($t['settings'], $secrets)));
            }
        }

        return $p;
    }

    /** How the system signs and numbers what it sends us. @return list<string> */
    private static function inbound(mixed $inbound, array $secrets, ?array $known): array
    {
        if (! is_array($inbound)) {
            return ['inbound must be an object'];
        }
        $p = [];
        if (isset($inbound['signature'])) {
            $s = $inbound['signature'];
            if (! is_array($s)) {
                return ['inbound.signature must be an object'];
            }
            if (empty($s['header'])) {
                $p[] = 'inbound.signature needs "header", the header that carries the signature';
            }
            foreach (['header', 'timestamp_header'] as $k) {
                if (isset($s[$k])) {
                    $p = array_merge($p, self::headerProblems((string) $s[$k], 'inbound.signature.'.$k, true));
                }
            }
            if (! in_array($s['algorithm'] ?? 'hmac-sha256', Signature::ALGORITHMS, true)) {
                $p[] = 'inbound.signature.algorithm must be one of '.implode(', ', Signature::ALGORITHMS);
            }
            if (! in_array($s['encoding'] ?? 'hex', ['hex', 'base64'], true)) {
                $p[] = 'inbound.signature.encoding must be hex or base64';
            }
            if (isset($s['prefix']) && (! is_string($s['prefix']) || ! preg_match('/^[A-Za-z0-9=:_\-]{0,20}$/', $s['prefix']))) {
                $p[] = 'inbound.signature.prefix is a short plain text such as "sha256="';
            }
            $payload = (string) ($s['payload'] ?? '{{ timestamp }}.{{ body }}');
            $p = array_merge($p, array_map(fn ($x) => 'inbound.signature.payload: '.$x, Template::problems($payload, $secrets, $known)));
            if (empty($s['timestamp_header']) && in_array('timestamp', Template::placeholders($payload), true)) {
                $p[] = 'inbound.signature.payload uses the timestamp, but no "timestamp_header" says where it comes from';
            }
            if (isset($s['tolerance']) && (! is_int($s['tolerance']) || $s['tolerance'] < 1 || $s['tolerance'] > 86400)) {
                $p[] = 'inbound.signature.tolerance is the age in seconds a message may have (1 to 86400)';
            }
            if (isset($s['secret_setting'])) {
                if (! is_string($s['secret_setting']) || ! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $s['secret_setting'])) {
                    $p[] = 'inbound.signature.secret_setting must name a setting (lower-case letters, digits, underscores)';
                } elseif ($known !== null && ! in_array($s['secret_setting'], $known, true)) {
                    $p[] = 'inbound.signature.secret_setting: this system has no setting "'.$s['secret_setting'].'"';
                }
            }
        }
        if (isset($inbound['samples'])) {
            $samples = $inbound['samples'];
            if (! is_array($samples)) {
                $p[] = 'inbound.samples must be an object of sample messages';
            } else {
                foreach ($samples as $name => $sample) {
                    if (! is_array($sample) || $sample === []) {
                        $p[] = 'inbound.samples.'.$name.' must be a message (an object)';

                        continue;
                    }
                    $p = array_merge($p, array_map(fn ($x) => 'inbound.samples.'.$name.': '.$x, Template::problemsIn($sample, $secrets, $known)));
                }
            }
        }
        if (isset($inbound['receipt'])) {
            $r = $inbound['receipt'];
            if (! is_array($r)) {
                $p[] = 'inbound.receipt must be an object';
            } elseif (isset($r['records']) && (! is_string($r['records']) || ! JsonPath::valid($r['records']))) {
                $p[] = 'inbound.receipt.records is not a supported path';
            }
        }
        if (isset($inbound['idempotency'])) {
            $i = $inbound['idempotency'];
            if (! is_array($i)) {
                return array_merge($p, ['inbound.idempotency must be an object']);
            }
            if (isset($i['header'])) {
                $p = array_merge($p, self::headerProblems((string) $i['header'], 'inbound.idempotency.header', true));
            }
            if (isset($i['path']) && (! is_string($i['path']) || ! JsonPath::valid($i['path']))) {
                $p[] = 'inbound.idempotency.path is not a supported path';
            }
        }

        return $p;
    }

    /** @return list<string> */
    private static function auth(array $a, ?array $known): array
    {
        $type = (string) ($a['type'] ?? 'none');
        if (! in_array($type, self::AUTH_TYPES, true)) {
            return ['auth type "'.mb_substr($type, 0, 20).'" is not supported'];
        }
        $p = [];
        foreach (['setting', 'name_setting', 'user_setting', 'password_setting', 'token_url_setting', 'client_id_setting', 'client_secret_setting', 'secret_setting', 'cert_setting', 'key_setting'] as $k) {
            if (isset($a[$k]) && (! is_string($a[$k]) || ! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $a[$k]))) {
                $p[] = 'auth.'.$k.' must name a setting (lower-case letters, digits, underscores)';
            } elseif (isset($a[$k]) && $known !== null && ! in_array($a[$k], $known, true)) {
                $p[] = 'auth.'.$k.': this system has no setting "'.$a[$k].'"';
            }
        }
        if (isset($a['optional']) && ! is_bool($a['optional'])) {
            $p[] = 'auth.optional must be true or false';
        }
        $need = ['bearer' => ['setting'], 'api_key' => ['setting'], 'basic' => ['user_setting', 'password_setting'], 'oauth2' => ['client_id_setting', 'client_secret_setting'], 'hmac' => ['secret_setting', 'signature_header'], 'mtls' => ['cert_setting', 'key_setting']][$type] ?? [];
        foreach ($need as $k) {
            if (empty($a[$k])) {
                $p[] = 'auth type "'.$type.'" needs "'.$k.'"';
            }
        }
        if ($type === 'oauth2') {
            $p = array_merge($p, self::oauth2($a));
        }
        if (isset($a['fallback'])) {
            $fb = $a['fallback'];
            if (! is_array($fb) || ! in_array($fb['type'] ?? '', ['bearer', 'api_key', 'basic'], true) || ! in_array($type, ['bearer', 'api_key'], true)) {
                $p[] = 'auth.fallback is a simple credential (bearer, api_key or basic) behind a bearer or api_key one';
            } elseif (isset($fb['fallback'])) {
                $p[] = 'auth.fallback cannot have a fallback of its own';
            } else {
                $p = array_merge($p, array_map(fn ($x) => 'auth.fallback: '.$x, self::auth($fb, $known)));
            }
        }
        if ($type === 'api_key') {
            if (empty($a['name']) && empty($a['name_setting'])) {
                $p[] = 'auth type "api_key" needs "name" (or "name_setting", a setting that holds it)';
            }
            if (! in_array($a['in'] ?? 'header', ['header', 'query'], true)) {
                $p[] = 'auth.in must be header or query';
            }
        }
        if (in_array($type, ['api_key', 'hmac'], true)) {
            foreach (['name', 'signature_header', 'timestamp_header'] as $k) {
                if (isset($a[$k])) {
                    $p = array_merge($p, self::headerProblems((string) $a[$k], 'auth.'.$k, $type === 'api_key' && ($a['in'] ?? 'header') === 'query'));
                }
            }
        }
        if ($type === 'hmac') {
            if (! in_array($a['algorithm'] ?? 'hmac-sha256', Signature::ALGORITHMS, true)) {
                $p[] = 'auth.algorithm must be one of '.implode(', ', Signature::ALGORITHMS);
            }
            if (! in_array($a['encoding'] ?? 'hex', ['hex', 'base64'], true)) {
                $p[] = 'auth.encoding must be hex or base64';
            }
            $p = array_merge($p, array_map(fn ($x) => 'auth.payload: '.$x, Template::problems((string) ($a['payload'] ?? '{{ timestamp }}.{{ body }}'))));
        }

        return $p;
    }

    /** Where an OAuth2 client-credentials token comes from, how the client signs in, and how the token is presented. @return list<string> */
    private static function oauth2(array $a): array
    {
        $p = [];
        if (empty($a['token_url_setting']) && empty($a['token_url']) && empty($a['token_path'])) {
            $p[] = 'auth type "oauth2" needs the token address: "token_url", "token_path" or "token_url_setting"';
        }
        if (isset($a['token_url'])) {
            $parts = is_string($a['token_url']) ? parse_url($a['token_url']) : false;
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query']) || ! preg_match('#^https://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9_\-./~%]*)?$#', (string) $a['token_url'])) {
                $p[] = 'auth.token_url must be a plain https address (no user name, query or fragment)';
            }
        }
        if (isset($a['token_path'])) {
            if (! is_string($a['token_path']) || ! self::plainPath($a['token_path'])) {
                $p[] = 'auth.token_path must be a path on the system\'s own address, such as /oauth2/access_token';
            }
            if (isset($a['token_url'])) {
                $p[] = 'auth: give "token_url" or "token_path", not both';
            }
        }
        if (isset($a['client_auth']) && ! in_array($a['client_auth'], ['body', 'basic'], true)) {
            $p[] = 'auth.client_auth must be body (the client in the form) or basic (the client in the Authorization header)';
        }
        if (isset($a['header_scheme']) && (! is_string($a['header_scheme']) || ! preg_match('/^[A-Za-z][A-Za-z0-9\-]{0,19}$/', $a['header_scheme']))) {
            $p[] = 'auth.header_scheme is the word before the token in the Authorization header, such as Bearer or JWT';
        }
        if (isset($a['token_params'])) {
            if (! is_array($a['token_params']) || array_is_list($a['token_params']) && $a['token_params'] !== []) {
                $p[] = 'auth.token_params must be an object of plain form fields';
            } else {
                foreach ($a['token_params'] as $name => $value) {
                    if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,40}$/', (string) $name) || in_array(strtolower((string) $name), ['grant_type', 'client_id', 'client_secret'], true)) {
                        $p[] = 'auth.token_params: "'.mb_substr((string) $name, 0, 30).'" is not a plain field name TEDC leaves to the contract';
                    } elseif (! is_scalar($value) || mb_strlen((string) $value) > 100) {
                        $p[] = 'auth.token_params: "'.$name.'" must be a short plain value';
                    }
                }
            }
        }

        return $p;
    }

    /**
     * The system's address made from a name the administrator enters (a tenant's subdomain): the scheme and the registrable domain are the
     * contract's, the name is one DNS label that has to match the contract's pattern, and nothing else can be put in.
     *
     * @return list<string>
     */
    private static function baseUrl(mixed $base, ?array $known): array
    {
        if (! is_array($base) || ! is_string($base['pattern'] ?? null)) {
            return ['base_url must be an object with a "pattern", such as https://{subdomain}.example.com'];
        }
        $label = '(?:[a-z0-9-]+|\{[a-z][a-z0-9_]{0,30}\})';
        if (! preg_match('#^https://('.$label.'(?:\.'.$label.')+)(/[A-Za-z0-9_\-./]*)?$#', $base['pattern'], $m) || str_contains($base['pattern'], '..')) {
            return ['base_url.pattern must be an https address of plain names, with the name to fill in as {name} (no port, user name, query or fragment)'];
        }
        $p = [];
        $labels = explode('.', $m[1]);
        if (count($labels) < 3 || str_contains((string) end($labels), '{') || str_contains((string) $labels[count($labels) - 2], '{')) {
            $p[] = 'base_url.pattern must fix the domain: the last two parts of the host are written out (https://{subdomain}.example.com)';
        }
        if (str_contains($m[2] ?? '', '{')) {
            $p[] = 'base_url.pattern: only the host can hold a {name}';
        }
        preg_match_all('/\{([a-z][a-z0-9_]{0,30})\}/', $base['pattern'], $used);
        $variables = $base['variables'] ?? null;
        if (! is_array($variables) || $variables === [] || array_is_list($variables)) {
            return array_merge($p, ['base_url.variables must say, for each {name}, the pattern its value has to match']);
        }
        foreach (array_unique($used[1]) as $name) {
            if (! isset($variables[$name])) {
                $p[] = 'base_url.variables has no pattern for {'.$name.'}';
            }
        }
        foreach ($variables as $name => $regex) {
            if (! in_array($name, $used[1], true)) {
                $p[] = 'base_url.variables: "'.mb_substr((string) $name, 0, 30).'" is not used in the pattern';
            } elseif (! is_string($regex) || strlen($regex) > 100 || @preg_match('/'.str_replace('/', '\\/', $regex).'/', '') === false) {
                $p[] = 'base_url.variables: the pattern of {'.$name.'} is not a valid regular expression (100 characters at most)';
            }
            if ($known !== null && ! in_array($name, $known, true)) {
                $p[] = 'base_url: this system has no setting "'.mb_substr((string) $name, 0, 30).'"';
            }
        }

        return $p;
    }

    /** The plain settings a test case is written for (a tenant, an organisation), never a secret. @return list<string> */
    private static function caseSettings(mixed $settings, array $secrets): array
    {
        if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            return ['"settings" is an object of plain values'];
        }
        $p = [];
        foreach ($settings as $name => $value) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,40}$/', (string) $name)) {
                $p[] = 'settings: "'.mb_substr((string) $name, 0, 30).'" is not a setting name (lower-case letters, digits, underscores)';
            } elseif (in_array($name, $secrets, true)) {
                $p[] = 'settings: "'.$name.'" is a secret and does not belong in a document';
            } elseif (! is_scalar($value) || mb_strlen((string) $value) > 200) {
                $p[] = 'settings: "'.$name.'" must be a short plain value';
            }
        }

        return $p;
    }

    /** @return list<string> */
    private static function operation(array $op, array $secrets, ?array $known): array
    {
        $p = [];
        if (! in_array($op['method'] ?? null, self::METHODS, true)) {
            $p[] = 'method must be one of '.implode(', ', self::METHODS);
        }
        $path = $op['path'] ?? null;
        if (! is_string($path) || ! self::plainPath($path)) {
            $p[] = 'path must be a path on the system\'s own address, such as /employees (no host, no query, no "..")';
        } else {
            $p = array_merge($p, array_map(fn ($x) => 'path: '.$x, Template::problems($path, $secrets, $known)));
        }
        foreach (['query', 'headers'] as $part) {
            if (isset($op[$part])) {
                if (! is_array($op[$part])) {
                    $p[] = $part.' must be an object';

                    continue;
                }
                $p = array_merge($p, array_map(fn ($x) => $part.': '.$x, Template::problemsIn($op[$part], $secrets, $known)));
            }
        }
        foreach (array_keys((array) ($op['headers'] ?? [])) as $h) {
            $p = array_merge($p, self::headerProblems(rtrim((string) $h, '?'), 'header "'.mb_substr((string) $h, 0, 30).'"', false));
        }
        if (isset($op['body'])) {
            $format = $op['body']['format'] ?? 'json';
            if (! in_array($format, ['json', 'form', 'raw', 'none'], true)) {
                $p[] = 'body.format must be json, form, raw or none';
            }
            $p = array_merge($p, array_map(fn ($x) => 'body: '.$x, Template::problemsIn($op['body']['template'] ?? null, $secrets, $known)));
        }
        foreach (['records', 'next_page', 'next_cursor', 'total'] as $k) {
            if (isset($op['response'][$k]) && (! is_string($op['response'][$k]) || ! JsonPath::valid($op['response'][$k]))) {
                $p[] = 'response.'.$k.' is not a supported path (only $, .name, [0], [\'key\'], [*], [?(@.key==\'text\')])';
            }
        }
        if (isset($op['response']['error'])) {
            $list = is_array($op['response']['error']) ? $op['response']['error'] : [$op['response']['error']];   // one path, or a few to try in order
            if ($list === [] || count($list) > 5 || array_filter($list, fn ($path) => ! is_string($path) || ! JsonPath::valid($path))) {
                $p[] = 'response.error is not a supported path (or a list of up to five)';
            }
        }
        foreach ((array) ($op['response']['values'] ?? []) as $name => $paths) {
            $list = is_array($paths) ? $paths : [$paths];   // one path, or a few to try in order
            if ($list === [] || count($list) > 5 || array_filter($list, fn ($path) => ! is_string($path) || ! JsonPath::valid($path))) {
                $p[] = 'response.values.'.$name.' is not a supported path (or a list of up to five)';
            }
        }
        if (isset($op['pagination'])) {
            $pg = (array) $op['pagination'];
            if (! in_array($pg['type'] ?? 'none', ['none', 'page', 'offset', 'cursor'], true)) {
                $p[] = 'pagination.type must be none, page, offset or cursor';
            }
            if (isset($pg['max_pages']) && (! is_int($pg['max_pages']) || $pg['max_pages'] < 1 || $pg['max_pages'] > 1000)) {
                $p[] = 'pagination.max_pages must be between 1 and 1000';
            }
            foreach (['param', 'size_param'] as $k) {
                if (isset($pg[$k]) && ! preg_match('/^[A-Za-z][A-Za-z0-9_.\-]{0,40}$/', (string) $pg[$k])) {
                    $p[] = 'pagination.'.$k.' is not a plain parameter name';
                }
            }
        }
        if (isset($op['windows']) && (! is_array($op['windows']) || ! is_int($op['windows']['max_days'] ?? null) || $op['windows']['max_days'] < 1 || $op['windows']['max_days'] > 366)) {
            $p[] = 'windows needs "max_days": the longest stretch of time (1 to 366 days) one call can ask for';
        }
        if (isset($op['success_when'])) {
            $p = array_merge($p, self::successRules($op['success_when']));
        }
        if (isset($op['idempotency']['header'])) {
            $p = array_merge($p, self::headerProblems((string) $op['idempotency']['header'], 'idempotency.header', false));
        }
        if (isset($op['rate_limit']['per_minute']) && (! is_int($op['rate_limit']['per_minute']) || $op['rate_limit']['per_minute'] < 1 || $op['rate_limit']['per_minute'] > 6000)) {
            $p[] = 'rate_limit.per_minute must be between 1 and 6000';
        }
        if (isset($op['timeout']) && (! is_int($op['timeout']) || $op['timeout'] < 1 || $op['timeout'] > 120)) {
            $p[] = 'timeout must be between 1 and 120 seconds';
        }
        foreach (['request_schema', 'response_schema'] as $k) {
            if (isset($op[$k]) && ! is_array($op[$k])) {
                $p[] = $k.' must be a JSON Schema object';
            }
        }

        return $p;
    }

    /**
     * What is left of a path once its placeholders are out must be a plain path on the system's address. A placeholder that leaves a
     * system setting unescaped (a path the administrator sets) stands for a whole path when it comes first; the finished path is checked
     * again, whatever it holds, when the call is made.
     */
    private static function plainPath(string $path): bool
    {
        if (str_contains($path, '..') || str_contains($path, '://')) {
            return false;
        }
        $fixed = (string) preg_replace('/^\{\{[^{}]*\|\s*raw\s*\}\}/', '/x', $path, 1);   // a leading unescaped setting stands for a whole path
        $fixed = (string) preg_replace('/\{\{[^{}]*\}\}/', 'x', $fixed);

        return (bool) preg_match('#^/[A-Za-z0-9_\-.~/%:,]*$#', $fixed) && ! str_starts_with($fixed, '//');
    }

    /** How a text message is encoded for this gateway. @return list<string> */
    private static function sms(mixed $sms): array
    {
        if (! is_array($sms)) {
            return ['sms must be an object'];
        }
        $p = [];
        if (! in_array($sms['encoding'] ?? 'auto', ['auto', 'unicode'], true)) {
            $p[] = 'sms.encoding must be auto (by the text) or unicode (always UCS-2)';
        }
        if (! in_array($sms['unicode_as'] ?? 'hex', ['hex', 'base64', 'text'], true)) {
            $p[] = 'sms.unicode_as must be hex, base64 or text';
        }
        foreach (['gsm7', 'ucs2'] as $k) {
            $name = ($sms['encoding_names'] ?? [])[$k] ?? null;
            if ($name !== null && (! is_string($name) || ! preg_match('/^[A-Za-z0-9_\-]{1,20}$/', $name))) {
                $p[] = 'sms.encoding_names.'.$k.' is how the gateway names it (letters, digits, - and _)';
            }
        }

        return $p;
    }

    /** What the success rule(s) of an operation may say. @return list<string> */
    private static function successRules(mixed $rules): array
    {
        if (! is_array($rules) || $rules === []) {
            return ['success_when must say something: a rule, or a list of rules'];
        }
        $p = [];
        foreach (array_is_list($rules) ? $rules : [$rules] as $i => $rule) {
            $at = 'success_when'.(array_is_list($rules) ? ' #'.($i + 1) : '');
            if (! is_array($rule)) {
                $p[] = $at.' must be an object';

                continue;
            }
            $conditions = array_intersect(array_keys($rule), ['equals', 'not_equals', 'in', 'not_in', 'exists', 'absent', 'body_contains', 'body_not_contains']);
            if ($conditions === []) {
                $p[] = $at.' needs a condition: equals, not_equals, in, not_in, exists, absent, body_contains or body_not_contains';
            }
            $needsPath = array_diff($conditions, ['body_contains', 'body_not_contains']);
            if ($needsPath !== [] && (! isset($rule['path']) || ! is_string($rule['path']) || ! JsonPath::valid($rule['path']))) {
                $p[] = $at.' needs a "path" in the supported subset for '.implode(', ', $needsPath);
            }
            foreach (['in', 'not_in'] as $k) {
                if (isset($rule[$k]) && (! is_array($rule[$k]) || ! array_is_list($rule[$k]) || array_filter($rule[$k], fn ($v) => ! is_scalar($v)))) {
                    $p[] = $at.': "'.$k.'" is a list of plain values';
                }
            }
            foreach (['body_contains', 'body_not_contains'] as $k) {
                if (isset($rule[$k]) && (! is_string($rule[$k]) || $rule[$k] === '' || mb_strlen($rule[$k]) > 200)) {
                    $p[] = $at.': "'.$k.'" is a short text (up to 200 characters)';
                }
            }
        }

        return $p;
    }

    /** @return list<string> */
    private static function headerProblems(string $name, string $label, bool $isQueryName): array
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_]{0,60}$/', $name)) {
            return [$label.' is not a plain header name'];
        }
        if (! $isQueryName && in_array(strtolower($name), self::FORBIDDEN_HEADERS, true)) {
            return [$label.': "'.$name.'" cannot be set by a contract (credentials belong to the auth block)'];
        }

        return [];
    }

    /** @return list<string> */
    private static function mapping(array $doc): array
    {
        $entities = $doc['entities'] ?? null;
        if (! is_array($entities) || $entities === []) {
            return ['a mapping needs "entities"'];
        }
        $p = [];
        foreach ($entities as $name => $entity) {
            $p = array_merge($p, array_map(fn ($x) => "{$name}: {$x}", Mapper::problems(is_array($entity) ? $entity : [])));
        }

        return $p;
    }
}
