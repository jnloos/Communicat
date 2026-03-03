<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class PythonVenv
{
    protected string $venvPath;

    public function __construct() {
        $this->venvPath = base_path('.venv');
    }

    public function venvPath(): string {
        return $this->venvPath;
    }

    protected function pipPath(): string {
        if ($this->os() == 'windows') {
            $pipPath = $this->venvPath . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'pip.exe';
        }
        else {
            $pipPath = $this->venvPath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pip';
        }
        return $pipPath;
    }

    protected function binPath(): string {
        if ($this->os() == 'windows') {
            $binary = $this->venvPath . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        }
        else {
            $binary = $this->venvPath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python3';
            if (!file_exists($binary)) {
                $binary = $this->venvPath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
            }
        }
        return $binary;
    }

    protected function os(): string {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return 'windows';
        }
        return 'unix';
    }

    public function status(): bool {
        return file_exists($this->binPath());
    }

    /**
     * @throws Exception
     */
    public function build(): void {
        if(!$this->status()) {
            if (!$this->createVenv()) {
               throw new Exception("Failed to create virtual environment at $this->venvPath");
            }
        }

        if (!$this->installRequirements()) {
            $pipPath = $this->pipPath();
            throw new Exception("Failed to install requirements with $pipPath from requirements.txt");
        }
    }

    /**
     * @throws Exception
     */
    public function clear(): void {
        if (!$this->status()) {
            return;
        }
        try {
            File::deleteDirectory($this->venvPath);
        }
        catch (Exception $e) {
            throw new Exception("Failed to remove virtual environment: " . $e->getMessage());
        }
    }

    protected function createVenv(): bool {
        $venvCommand = ['python3', '-m', 'venv', $this->venvPath];
        $process = Process::run($venvCommand);
        if($process->failed()) {
            $venvCommand[0] = 'python';
            $process = Process::run($venvCommand);
        }
        return !$process->failed();
    }

    protected function installRequirements(): bool {
        $pipPath = $this->pipPath();
        $reqPath = base_path('requirements.txt');

        if (!file_exists($reqPath)) {
            return true;
        }

        $initCommand = [$pipPath, 'install', '-r', $reqPath];
        $process = Process::run($initCommand);
        return !$process->failed();
    }

    public function exec(string $script, array $arguments = []): string {
        if (!$this->status()) {
            return "Error: Python virtual environment is not set up properly at $this->venvPath.";
        }

        // Build the command using the Python binary and provided script/arguments.
        $python = $this->binPath();
        $command = array_merge([$python, $script], $arguments);

        // Run the process.
        $process = Process::run($command);

        // If the process fails, return the error output.
        if ($process->failed()) {
            return $process->errorOutput();
        }

        // Return the output from the process.
        return $process->output();
    }
}
