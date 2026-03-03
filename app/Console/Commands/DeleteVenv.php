<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Exception;
use Illuminate\Support\Facades\File;

class DeleteVenv extends Command
{
    protected $signature = 'python:delete-venv';

    protected $description = 'Deletes the Python virtual environment (.venv) directory';

    public function handle(): int {
        try {
            $path = base_path('.venv');

            if (!File::exists($path)) {
                $this->warn("No virtual environment found at {$path}");
                return 0;
            }

            $this->info("Deleting Python virtual environment at {$path}...");
            File::deleteDirectory($path);

            $this->info('Virtual environment deleted successfully.');
            return 0;
        }
        catch (Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
