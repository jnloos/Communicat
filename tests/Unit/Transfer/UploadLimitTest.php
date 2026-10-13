<?php

namespace Tests\Unit\Transfer;

use App\Services\Transfer\UploadLimit;
use PHPUnit\Framework\TestCase;

class UploadLimitTest extends TestCase
{
    public function test_ini_shorthand_is_parsed_to_kilobytes(): void
    {
        $this->assertSame(2048, UploadLimit::iniToKilobytes('2M'));
        $this->assertSame(1048576, UploadLimit::iniToKilobytes('1G'));
        $this->assertSame(512, UploadLimit::iniToKilobytes('512K'));
        $this->assertSame(4, UploadLimit::iniToKilobytes('4096'));
        $this->assertSame(0, UploadLimit::iniToKilobytes('-1'));
        $this->assertSame(0, UploadLimit::iniToKilobytes(''));
    }

    public function test_the_limit_never_exceeds_the_cap_or_the_php_limits(): void
    {
        $this->assertLessThanOrEqual(UploadLimit::CAP_KB, UploadLimit::kilobytes());
        $this->assertGreaterThanOrEqual(1, UploadLimit::megabytes());
    }

    public function test_the_lowest_limit_wins_and_unlimited_ones_fall_back_to_the_cap(): void
    {
        $this->assertSame(2048, UploadLimit::fromIni('2M', '8M'));
        $this->assertSame(2048, UploadLimit::fromIni('0', '2M'));
        $this->assertSame(UploadLimit::CAP_KB, UploadLimit::fromIni('0', '-1'));
        $this->assertSame(UploadLimit::CAP_KB, UploadLimit::fromIni('1G', '1G'));
    }

    public function test_a_byte_value_below_one_kilobyte_counts_as_one_not_as_unlimited(): void
    {
        $this->assertSame(1, UploadLimit::iniToKilobytes('500'));
        $this->assertSame(1, UploadLimit::fromIni('500', '8M'));
    }
}
