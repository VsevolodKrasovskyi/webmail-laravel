<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    // RFC 6238 appendix B: the SHA-1 secret "12345678901234567890", last six digits of the 8-digit codes.
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_it_matches_the_rfc_6238_vectors(): void
    {
        $this->assertSame('287082', Totp::code(self::SECRET, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code(self::SECRET, intdiv(1111111109, 30)));
        $this->assertSame('050471', Totp::code(self::SECRET, intdiv(1111111111, 30)));
    }

    public function test_it_accepts_one_step_of_clock_drift_but_not_more(): void
    {
        $now = 1111111109;
        $code = Totp::code(self::SECRET, Totp::step($now));

        $this->assertSame(Totp::step($now), Totp::verify(self::SECRET, $code, null, $now));
        $this->assertSame(Totp::step($now), Totp::verify(self::SECRET, $code, null, $now + 30));
        $this->assertNull(Totp::verify(self::SECRET, $code, null, $now + 90));
    }

    public function test_a_used_step_cannot_be_used_again(): void
    {
        $now = 1111111109;
        $code = Totp::code(self::SECRET, Totp::step($now));

        $this->assertNull(Totp::verify(self::SECRET, $code, Totp::step($now), $now));
    }

    public function test_malformed_codes_are_rejected(): void
    {
        $this->assertNull(Totp::verify(self::SECRET, 'abcdef'));
        $this->assertNull(Totp::verify(self::SECRET, '12345'));
        $this->assertNull(Totp::verify(self::SECRET, ''));
    }

    public function test_generated_secrets_are_valid_base32_of_the_right_length(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, Totp::generateSecret());
    }
}
