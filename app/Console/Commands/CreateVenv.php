<?php

namespace App\Console\Commands;

use App\Facades\Python;
use Illuminate\Console\Command;
use Exception;

class CreateVenv extends Command
{
    protected $signature = 'python:create-venv';

    protected $description = 'Creates a Python3 virtual environment and installs required packages';

    public function handle(): int {
        try {
            $this->info('Initializing Python virtual environment...');
            Python::build();
            $this->info('Virtual environment and dependencies set up successfully.');
            return 0;
        }
        catch (Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
