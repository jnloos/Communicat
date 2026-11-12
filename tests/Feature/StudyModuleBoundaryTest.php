<?php

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The study module (app/Study, see its README) must stay removable: core code may
 * offer the one sidebar hook, but never name the module's classes, routes, views,
 * translations or config. Lives outside tests/Feature/Study on purpose, so it
 * keeps guarding while the module is there and goes with step 4 of the README.
 */
class StudyModuleBoundaryTest extends TestCase
{
    private const ROOTS = ['app', 'bootstrap', 'config', 'database', 'lang', 'resources/views', 'routes', 'tests'];

    private const OWN = ['app/Study/', 'tests/Feature/Study/', 'tests/Feature/StudyModuleBoundaryTest.php', 'bootstrap/cache/', 'bootstrap/providers.php'];

    /** The hooks core may carry; each renders or registers nothing once the folder is gone. */
    private const HOOKS = ["@includeIf('study::nav-item')"];

    public function test_core_code_never_names_the_study_module(): void
    {
        $hits = [];

        foreach (self::ROOTS as $root) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = substr($file->getPathname(), strlen(base_path()) + 1);

                if (! str_ends_with($path, '.php') || collect(self::OWN)->contains(fn (string $own) => str_starts_with($path, $own))) {
                    continue;
                }

                $code = str_replace(self::HOOKS, '', file_get_contents($file->getPathname()));

                if (preg_match('/App\\\\Study|study::|[\'"]study\.|config\([\'"]study|[\'"]study[\'"]\s*=>|RunStudyProject|study:queue/', $code, $match)) {
                    $hits[] = "{$path}: {$match[0]}";
                }
            }
        }

        $this->assertSame([], $hits, 'Core code depends on the study module; move it into app/Study.');
    }
}
