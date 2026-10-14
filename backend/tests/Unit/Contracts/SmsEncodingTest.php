<?php

namespace Tests\Unit\Contracts;

use App\Integrations\Contracts\SmsEncoding;
use PHPUnit\Framework\TestCase;

/** Which alphabet a text message needs (GSM 7-bit or UCS-2), how long it is in the unit of that alphabet, and how many segments it takes. */
class SmsEncodingTest extends TestCase
{
    public function test_plain_latin_text_is_gsm7_and_arabic_needs_ucs2(): void
    {
        $this->assertTrue(SmsEncoding::isGsm7('Hello, your session starts at 10:30. See you!'));
        $this->assertTrue(SmsEncoding::isGsm7("Line one\nLine two"));
        $this->assertFalse(SmsEncoding::isGsm7('مرحبا'));
        $this->assertFalse(SmsEncoding::isGsm7('Hello مرحبا'), 'one Arabic letter makes the whole message Unicode');
        $this->assertFalse(SmsEncoding::isGsm7('Price: 5 € ✓'), 'a check mark is not in the alphabet');
        $this->assertFalse(SmsEncoding::isGsm7("tab\there"), 'a tab is not in the alphabet');
    }

    public function test_the_extension_characters_take_two_places(): void
    {
        $this->assertTrue(SmsEncoding::isGsm7('{x} [y] ~ | ^ \\ €'));
        $this->assertSame(5, SmsEncoding::describe('€100')['length'], 'the euro sign takes 2 places and each digit 1');
        $this->assertSame(2, SmsEncoding::describe('{')['length']);
        $this->assertSame(1, SmsEncoding::describe('@')['length']);
    }

    public function test_gsm7_segments(): void
    {
        $this->assertSame(['encoding' => 'gsm7', 'length' => 160, 'segments' => 1], SmsEncoding::describe(str_repeat('a', 160)));
        $this->assertSame(2, SmsEncoding::describe(str_repeat('a', 161))['segments'], 'a longer message is cut into parts of 153');
        $this->assertSame(2, SmsEncoding::describe(str_repeat('a', 306))['segments']);
        $this->assertSame(3, SmsEncoding::describe(str_repeat('a', 307))['segments']);
        $this->assertSame(1, SmsEncoding::describe('')['segments'] ?: 1);
    }

    public function test_ucs2_segments(): void
    {
        $this->assertSame(['encoding' => 'ucs2', 'length' => 70, 'segments' => 1], SmsEncoding::describe(str_repeat('م', 70)));
        $this->assertSame(2, SmsEncoding::describe(str_repeat('م', 71))['segments'], 'a longer message is cut into parts of 67');
        $this->assertSame(2, SmsEncoding::describe(str_repeat('م', 134))['segments']);
        $this->assertSame(3, SmsEncoding::describe(str_repeat('م', 135))['segments']);
    }

    public function test_an_emoji_takes_two_units_in_ucs2(): void
    {
        $this->assertSame(['encoding' => 'ucs2', 'length' => 3, 'segments' => 1], SmsEncoding::describe('م😀'));
    }

    public function test_the_unicode_forms_a_gateway_may_ask_for(): void
    {
        $this->assertSame('06450631062D06280627', SmsEncoding::utf16Hex('مرحبا'));
        $this->assertSame('D83DDE00', SmsEncoding::utf16Hex('😀'), 'outside the basic plane: a surrogate pair');
        $this->assertSame(base64_encode(hex2bin('06450631062D06280627')), SmsEncoding::utf16Base64('مرحبا'));
    }

    public function test_it_decides_the_encoding_by_the_mode(): void
    {
        $this->assertSame('gsm7', SmsEncoding::choose('Hello', 'auto'));
        $this->assertSame('ucs2', SmsEncoding::choose('مرحبا', 'auto'));
        $this->assertSame('ucs2', SmsEncoding::choose('Hello', 'unicode'), 'a gateway that wants everything as Unicode');
    }
}
