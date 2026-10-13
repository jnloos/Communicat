<?php

namespace Tests\Unit\Transfer;

use App\Services\Transfer\Bundle;
use App\Services\Transfer\BundleReader;
use App\Services\Transfer\BundleWriter;
use App\Services\Transfer\InvalidBundle;
use Tests\TestCase;
use ZipArchive;

class BundleReaderTest extends TestCase
{
    private function path(): string
    {
        return tempnam(sys_get_temp_dir(), 'bundle').'.zip';
    }

    private function zip(array $entries): string
    {
        $path = $this->path();
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function manifest(): string
    {
        return json_encode(['schema_version' => Bundle::SCHEMA_VERSION]);
    }

    public function test_what_the_writer_puts_the_reader_reads(): void
    {
        $path = $this->path();
        $writer = new BundleWriter($path);
        $writer->put('manifest.json', ['schema_version' => Bundle::SCHEMA_VERSION, 'kind' => Bundle::KIND_GLOBAL]);
        $writer->put('experts.json', [['name' => 'Ä']]);
        $writer->put(Bundle::projectEntry('3f1c0a5e-8d2b-4e7a-9c11-2b6f0d4e8a77'), ['title' => 'x']);
        $writer->close();

        $reader = BundleReader::open($path);

        $this->assertSame(Bundle::KIND_GLOBAL, $reader->manifest()['kind']);
        $this->assertSame([['name' => 'Ä']], $reader->json('experts.json'));
        $this->assertSame([], $reader->json('groups.json'));
        $this->assertSame(['projects/3f1c0a5e-8d2b-4e7a-9c11-2b6f0d4e8a77.json'], $reader->projectEntries());
    }

    public function test_a_file_that_is_no_zip_is_refused(): void
    {
        $path = $this->path();
        file_put_contents($path, '{"project": {}}');

        $this->expectException(InvalidBundle::class);
        $this->expectExceptionMessage(__('transfer.errors.not_a_zip'));
        BundleReader::open($path);
    }

    public function test_a_bundle_without_manifest_says_so(): void
    {
        $this->expectException(InvalidBundle::class);
        $this->expectExceptionMessage(__('transfer.errors.no_manifest'));
        BundleReader::open($this->zip(['experts.json' => '[]']));
    }

    public function test_an_unknown_schema_version_is_refused(): void
    {
        $this->expectException(InvalidBundle::class);
        $this->expectExceptionMessage(__('transfer.errors.unknown_schema'));
        BundleReader::open($this->zip(['manifest.json' => '{"schema_version": 99}']));
    }

    public function test_an_unexpected_or_escaping_path_is_refused(): void
    {
        foreach (['../evil.json', '/etc/passwd', 'projects/../x.json', 'notes.txt', "manifest.json\n"] as $name) {
            try {
                BundleReader::open($this->zip(['manifest.json' => $this->manifest(), $name => '{}']));
                $this->fail("{$name} was accepted");
            } catch (InvalidBundle) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_entry_that_is_not_a_json_object_or_list_is_refused(): void
    {
        foreach (['"x"', '5'] as $content) {
            $reader = BundleReader::open($this->zip(['manifest.json' => $this->manifest(), 'experts.json' => $content]));

            try {
                $reader->json('experts.json');
                $this->fail("{$content} was accepted");
            } catch (InvalidBundle $exception) {
                $this->assertSame(__('transfer.errors.broken_entry', ['name' => 'experts.json']), $exception->getMessage());
            }
        }
    }

    public function test_an_entry_that_exists_but_cannot_be_read_is_refused(): void
    {
        $path = $this->path();
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $this->manifest());
        $zip->addFromString('experts.json', '[]');
        $zip->setEncryptionName('experts.json', ZipArchive::EM_AES_256, 'secret');
        $zip->close();

        $reader = BundleReader::open($path);

        $this->expectException(InvalidBundle::class);
        $this->expectExceptionMessage(__('transfer.errors.broken_entry', ['name' => 'experts.json']));
        $reader->json('experts.json');
    }

    public function test_a_bundle_over_the_total_size_limit_is_refused(): void
    {
        $path = $this->zip(['manifest.json' => $this->manifest()]);

        $this->expectException(InvalidBundle::class);
        $this->expectExceptionMessage(__('transfer.errors.too_large'));
        BundleReader::open($path, maxUnpackedBytes: 10);
    }

    public function test_an_entry_over_the_single_entry_limit_is_refused(): void
    {
        $path = $this->zip(['manifest.json' => $this->manifest()]);

        $this->expectException(InvalidBundle::class);
        $this->expectExceptionMessage(__('transfer.errors.too_large'));
        BundleReader::open($path, maxEntryBytes: 10);
    }

    public function test_an_entry_longer_than_its_declared_size_is_refused_when_read(): void
    {
        $path = $this->zip(['manifest.json' => $this->manifest(), 'experts.json' => json_encode(array_fill(0, 200, 'abcdefgh'))]);
        $this->declareSize($path, 'experts.json', 10);
        $reader = BundleReader::open($path, maxEntryBytes: 50);

        $this->expectException(InvalidBundle::class);
        $this->expectExceptionMessage(__('transfer.errors.too_large'));
        $reader->json('experts.json');
    }

    public function test_what_is_read_in_total_is_held_to_the_unpacked_limit_whatever_the_sizes_declare(): void
    {
        $experts = json_encode(array_fill(0, 20, 'abcdefgh'));
        $path = $this->zip(['manifest.json' => $this->manifest(), 'experts.json' => $experts, 'tags.json' => $experts]);
        $this->declareSize($path, 'experts.json', 1);
        $this->declareSize($path, 'tags.json', 1);
        $reader = BundleReader::open($path, maxUnpackedBytes: 300, maxEntryBytes: 250);
        $reader->json('experts.json');

        $this->expectException(InvalidBundle::class);
        $this->expectExceptionMessage(__('transfer.errors.too_large'));
        $reader->json('tags.json');
    }

    public function test_one_entry_stays_well_below_the_memory_limit(): void
    {
        // json_decode holds the text and the decoded array at once, about twice the entry.
        $this->assertLessThan(ini_parse_quantity('128M') / 3, Bundle::MAX_ENTRY_BYTES);
    }

    /** Overwrites an entry's uncompressed size in its local and central header: a size that lies. */
    private function declareSize(string $path, string $entry, int $size): void
    {
        $bytes = file_get_contents($path);

        for ($at = strpos($bytes, $entry); $at !== false; $at = strpos($bytes, $entry, $at + strlen($entry))) {
            if ($at >= 30 && substr($bytes, $at - 30, 4) === "PK\x03\x04") {
                $bytes = substr_replace($bytes, pack('V', $size), $at - 30 + 22, 4);
            }

            if ($at >= 46 && substr($bytes, $at - 46, 4) === "PK\x01\x02") {
                $bytes = substr_replace($bytes, pack('V', $size), $at - 46 + 24, 4);
            }
        }

        file_put_contents($path, $bytes);
    }
}
