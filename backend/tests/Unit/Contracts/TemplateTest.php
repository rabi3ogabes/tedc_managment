<?php

namespace Tests\Unit\Contracts;

use App\Integrations\Contracts\ContractException;
use App\Integrations\Contracts\Template;
use PHPUnit\Framework\TestCase;

/** The placeholder language of contracts: data, not code. Nothing in a template can run anything or reach a secret. */
class TemplateTest extends TestCase
{
    public function test_placeholders_read_values_by_dotted_path(): void
    {
        $ctx = ['employee' => ['no' => 'E-7', 'tags' => ['a', 'b']], 'since' => '2026-10-01'];
        $this->assertSame('/employees/E-7?since=2026-10-01', Template::render('/employees/{{ employee.no }}?since={{since}}', $ctx));
        $this->assertSame('b', Template::render('{{ employee.tags.1 }}', $ctx));
        $this->assertSame('x--y', Template::render('x-{{ missing }}-y', $ctx), 'an unknown value is empty');
    }

    public function test_filters_format_values(): void
    {
        $ctx = ['name' => '  Sara Ali ', 'at' => '2026-10-14T09:30:00+03:00', 'amount' => 12.5, 'items' => ['a' => 1], 'n' => '7'];
        $this->assertSame('SARA ALI', Template::render('{{ name|trim|upper }}', $ctx));
        $this->assertSame('sara ali', Template::render('{{ name|trim|lower }}', $ctx));
        $this->assertSame('14/10/2026', Template::render('{{ at|date:d/m/Y }}', $ctx));
        $this->assertSame('12.500', Template::render('{{ amount|amount:3 }}', $ctx));
        $this->assertSame('12500', Template::render('{{ amount|minor:3 }}', $ctx), 'QAR has three decimals: 12.5 is 12500 dirhams-thousandths');
        $this->assertSame('1250', Template::render('{{ amount|minor:2 }}', $ctx));
        $this->assertSame('{"a":1}', Template::render('{{ items|json }}', $ctx));
        $this->assertSame('fallback', Template::render('{{ nothing|default:fallback }}', $ctx));
        $this->assertSame('7', Template::render('{{ n|int }}', $ctx));
        $this->assertSame('%D8%B3%D8%A7%D8%B1%D8%A9', Template::render('{{ ar|urlencode }}', ['ar' => 'سارة']));
        $this->assertSame(strtoupper(bin2hex(mb_convert_encoding('سارة', 'UTF-16BE', 'UTF-8'))), Template::render('{{ ar|utf16hex }}', ['ar' => 'سارة']));
        $this->assertSame(hash('sha256', 'abc'), Template::render('{{ x|sha256 }}', ['x' => 'abc']));
        $this->assertSame(base64_encode('abc'), Template::render('{{ x|base64 }}', ['x' => 'abc']));
    }

    public function test_the_target_decides_how_a_value_is_escaped(): void
    {
        $ctx = ['text' => "He said \"hi\"\nسارة"];
        $this->assertSame('{"m":"He said \"hi\"\nسارة"}', Template::render('{"m":"{{ text }}"}', $ctx, 'json'), 'inside a JSON string: quotes and newlines are escaped, Arabic stays readable');
        $this->assertSame('q=He%20said%20%22hi%22%0A%D8%B3%D8%A7%D8%B1%D8%A9', Template::render('q={{ text }}', $ctx, 'url'));
        $this->assertSame('He said "hi"nسارة', str_replace("\n", 'n', Template::render('{{ text }}', $ctx)));
        $this->assertSame('ab', Template::render('{{ v }}', ['v' => "a\r\nb"], 'header'), 'a header value can never carry a line break');
    }

    public function test_a_single_placeholder_keeps_its_type_in_structured_values(): void
    {
        $out = Template::renderValue(['items' => '{{ rows }}', 'n' => '{{ count }}', 'label' => 'n={{ count }}', 'flag' => '{{ on }}'], ['rows' => [['a' => 1]], 'count' => 3, 'on' => true]);
        $this->assertSame([['a' => 1]], $out['items']);
        $this->assertSame(3, $out['n']);
        $this->assertSame('n=3', $out['label']);
        $this->assertTrue($out['flag']);
    }

    public function test_optional_keys_are_left_out_when_empty_and_lists_are_built_with_each(): void
    {
        $out = Template::renderValue(['year' => '{{ year }}', 'since?' => '{{ since }}', 'items' => ['$each' => 'rows', 'as' => 'r', 'template' => ['code' => '{{ r.code }}', 'hours' => '{{ r.hours|amount:1 }}']]],
            ['year' => 2026, 'since' => null, 'rows' => [['code' => 'S1', 'hours' => 3], ['code' => 'S2', 'hours' => 4.25]]]);
        $this->assertSame(['year' => 2026, 'items' => [['code' => 'S1', 'hours' => '3.0'], ['code' => 'S2', 'hours' => '4.3']]], $out);
    }

    public function test_only_known_filters_and_plain_paths_are_allowed(): void
    {
        $this->assertSame([], Template::problems('{{ a.b|trim|upper }} and {{ c|date:Y-m-d }}'));
        foreach (['{{ system("ls") }}', '{{ a|php }}', '{{ a|eval:1 }}', '{{ a.b() }}', '{{ $x }}', '{{ a b }}', '{{ a|upper|nope }}', '{{ ../secret }}', '{{ a[0] }}', '{{ }}'] as $bad) {
            $this->assertNotSame([], Template::problems($bad), $bad);
        }
        $this->expectException(ContractException::class);
        Template::render('{{ a|php }}', ['a' => 1]);
    }

    public function test_secrets_are_not_reachable_from_a_template(): void
    {
        // The context never holds secrets, and a template that names the secrets namespace is refused on save.
        $this->assertNotSame([], Template::problems('{{ secrets.api_key }}'));
        $this->assertNotSame([], Template::problems('{{ settings.api_key }}', ['api_key', 'client_secret']), 'a setting that holds a secret is not a placeholder');
        $this->assertSame([], Template::problems('{{ settings.base_url }}', ['api_key']));
    }

    public function test_raw_leaves_a_value_unescaped_but_only_for_the_systems_own_settings(): void
    {
        $this->assertSame('/api/v2/staff', Template::render('{{ settings.employees_path|raw }}', ['settings' => ['employees_path' => '/api/v2/staff']], 'url'));
        $this->assertSame('%2Fapi%2Fv2%2Fstaff', Template::render('{{ settings.employees_path }}', ['settings' => ['employees_path' => '/api/v2/staff']], 'url'), 'without raw a value is escaped into one segment');
        $this->assertSame([], Template::problems('{{ settings.employees_path|default:/employees|raw }}'));
        $this->assertNotSame([], Template::problems('/employees/{{ id|raw }}'), 'a request value is never left unescaped');
        $this->assertNotSame([], Template::problems('{{ item.name|raw }}'));
    }

    public function test_a_setting_the_system_does_not_have_is_a_problem_once_the_known_ones_are_given(): void
    {
        $this->assertSame([], Template::problems('{{ settings.base_url }} {{ settings.employees_path|default:/e|raw }}', [], ['base_url', 'employees_path']));
        $this->assertNotSame([], Template::problems('{{ settings.employee_path|default:/e|raw }}', [], ['employees_path']), 'a typo would silently use the default');
        $this->assertSame([], Template::problems('{{ settings.anything }}'), 'nothing is checked without the list');
        $this->assertNotSame([], Template::problemsIn(['q' => ['k' => '{{ settings.nope }}']], [], ['base_url']));
    }

    public function test_placeholders_are_listed(): void
    {
        $this->assertSame(['since', 'employee.no'], Template::placeholders('/x?s={{ since }}&e={{ employee.no|upper }}&again={{since}}'));
    }
}
