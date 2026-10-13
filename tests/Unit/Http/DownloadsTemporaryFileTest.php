<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\Concerns\DownloadsTemporaryFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class DownloadsTemporaryFileTest extends TestCase
{
    private function controller(): object
    {
        return new class
        {
            use DownloadsTemporaryFile;

            public function send(callable $write): BinaryFileResponse
            {
                return $this->downloadTemporary($write, 'file.zip');
            }
        };
    }

    public function test_a_failed_build_leaves_no_file_behind(): void
    {
        $written = null;

        try {
            $this->controller()->send(function (string $path) use (&$written) {
                $written = $path;
                file_put_contents($path, 'half');

                throw new RuntimeException('disk full');
            });
            $this->fail('The build error should reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('disk full', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($written);
    }

    public function test_a_built_file_is_sent_and_deleted_after_sending(): void
    {
        $response = $this->controller()->send(fn (string $path) => file_put_contents($path, 'zip'));

        $this->assertSame('zip', file_get_contents($response->getFile()->getPathname()));
        $this->assertStringContainsString('file.zip', $response->headers->get('Content-Disposition'));
        unlink($response->getFile()->getPathname());
    }
}
