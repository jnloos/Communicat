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
}
