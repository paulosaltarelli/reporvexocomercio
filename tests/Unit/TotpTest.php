<?php

namespace Tests\Unit;

use App\Platform\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    public function test_sha1_rfc6238_vectors_and_replay_window(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        foreach ([59=>'287082',1111111109=>'081804',1111111111=>'050471',1234567890=>'005924',2000000000=>'279037',20000000000=>'353130'] as $time=>$expected) {
            $step = intdiv($time, 30);
            $this->assertSame($expected, Totp::code($secret, $step));
            $this->assertSame($step, Totp::matchStep($secret, $expected, $time + 30, null));
            $this->assertNull(Totp::matchStep($secret, $expected, $time, $step));
            $this->assertNull(Totp::matchStep($secret, $expected, $time + 60, null));
        }
    }
}
