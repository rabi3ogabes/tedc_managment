<?php

namespace Tests\Unit\Contracts;

use App\Integrations\Contracts\ContractException;
use App\Integrations\Contracts\Signature;
use PHPUnit\Framework\TestCase;

/** Request and callback signatures a contract can name: HMAC-SHA256/512 with a shared secret, RSA-SHA256 with a key pair. */
class SignatureTest extends TestCase
{
    private const MESSAGE = 'The quick brown fox jumps over the lazy dog';

    public function test_hmac_known_answers(): void
    {
        // RFC-style published vectors for key "key" over the fox sentence.
        $this->assertSame('f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8', Signature::make(['algorithm' => 'hmac-sha256', 'payload' => '{{ body }}'], 'key', '', self::MESSAGE));
        $this->assertSame('b42af09057bac1e2d41708e48a902e09b5ff7f12ab428a4fe86653c73dd248fb82f948a549f7b791a5b41915ee4d1ec3935357e4e2317250d0372afa2ebeeb3a', Signature::make(['algorithm' => 'hmac-sha512', 'payload' => '{{ body }}'], 'key', '', self::MESSAGE));
        $this->assertSame(base64_encode(hex2bin('f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8')), Signature::make(['algorithm' => 'hmac-sha256', 'encoding' => 'base64', 'payload' => '{{ body }}'], 'key', '', self::MESSAGE));
    }

    public function test_the_payload_template_decides_what_is_signed(): void
    {
        $with = Signature::make(['algorithm' => 'hmac-sha256'], 's3cret', '1700000000', '{"a":1}');   // the default is "timestamp.body"
        $this->assertSame(hash_hmac('sha256', '1700000000.{"a":1}', 's3cret'), $with);
        $this->assertSame('sha256='.hash_hmac('sha256', 'POST|/payments|{"a":1}', 's3cret'), Signature::make(['algorithm' => 'hmac-sha256', 'prefix' => 'sha256=', 'payload' => '{{ method }}|{{ path }}|{{ body }}'], 's3cret', '', '{"a":1}', ['method' => 'POST', 'path' => '/payments']));
    }

    public function test_hmac_verification_checks_signature_time_and_prefix(): void
    {
        $spec = ['algorithm' => 'hmac-sha256', 'prefix' => 'sha256='];
        $now = (string) time();
        $good = Signature::make($spec, 'k', $now, 'body');
        $this->assertTrue(Signature::verify($spec, 'k', $good, $now, 'body'));
        $this->assertTrue(Signature::verify($spec, 'k', substr($good, 7), $now, 'body'), 'the prefix is optional on the way in');
        $this->assertFalse(Signature::verify($spec, 'other', $good, $now, 'body'), 'wrong secret');
        $this->assertFalse(Signature::verify($spec, 'k', $good, $now, 'body tampered'), 'changed body');
        $this->assertFalse(Signature::verify($spec, 'k', null, $now, 'body'));
        $this->assertFalse(Signature::verify($spec, '', $good, $now, 'body'), 'no secret set: nothing verifies');
        $old = (string) (time() - 301);
        $this->assertFalse(Signature::verify($spec, 'k', Signature::make($spec, 'k', $old, 'body'), $old, 'body'), 'a captured message cannot be replayed later');
        $this->assertFalse(Signature::verify($spec, 'k', $good, 'not-a-number', 'body'));
        $this->assertTrue(Signature::verify($spec, 'k', Signature::make($spec, 'k', $old, 'body'), $old, 'body', null), 'tolerance off: the time is not checked');
    }

    public function test_rsa_signs_with_the_private_key_and_verifies_with_the_public_one(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $otherPublic = openssl_pkey_get_details($other)['key'];

        $spec = ['algorithm' => 'rsa-sha256', 'encoding' => 'base64', 'payload' => '{{ body }}'];
        $sig = Signature::make($spec, $private, '', 'pay 12.500 QAR');
        $this->assertTrue(Signature::verify($spec, $public, $sig, '', 'pay 12.500 QAR', null));
        $this->assertFalse(Signature::verify($spec, $public, $sig, '', 'pay 99.999 QAR', null));
        $this->assertFalse(Signature::verify($spec, $otherPublic, $sig, '', 'pay 12.500 QAR', null));
        $this->assertFalse(Signature::verify($spec, 'not a key', $sig, '', 'pay 12.500 QAR', null));
        $this->assertFalse(Signature::verify($spec, $public, 'AAAA', '', 'pay 12.500 QAR', null));
    }

    public function test_an_unknown_algorithm_is_refused_not_guessed(): void
    {
        $this->expectException(ContractException::class);
        Signature::make(['algorithm' => 'md5'], 'k', '', 'x');
    }
}
