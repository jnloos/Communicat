<?php

namespace Tests\Unit\Transfer;

use App\Services\Transfer\BundleWriter;
use RuntimeException;
use Tests\TestCase;

class BundleWriterTest extends TestCase
{
    public function test_finishing_fails_when_the_bundle_cannot_be_written(): void
    {
        $dir = sys_get_temp_dir().'/bundle-dir-'.uniqid();
        mkdir($dir);
        $writer = new BundleWriter($dir.'/out.zip');
        $writer->put('experts.json', []);
        rmdir($dir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot finish the bundle');
        $writer->close();
    }
}
