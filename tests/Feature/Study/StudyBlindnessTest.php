<?php

namespace Tests\Feature\Study;

use App\Services\Study\Progress\StatusText;
use App\Services\Study\Progress\StudySnapshot;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The monitoring must stay blind: nothing in the module reads or shows a share, Gini, effect
 * or a curve of them, so watching a wave cannot steer when to stop. SpokenTurns (counting
 * without shares) is allowed.
 */
class StudyBlindnessTest extends TestCase
{
    use BuildsStudyCells;
    use RefreshDatabase;
    use UsesTemporaryPauseFile;

    /** Every place the study's code lives (docs/study.md); the monitoring and the pages read through these. */
    private const STUDY_CODE = [
        'app/Services/Study', 'app/Services/Discord', 'app/Console/Commands/Study', 'app/Livewire/Study',
        'app/Jobs/RunStudyProject.php', 'app/Jobs/ApplyWave.php', 'app/Models/StudyWave.php', 'app/Models/StudyEvent.php',
        'app/Events/BreakerTripped.php', 'app/Http/Controllers/DiscordInteractionController.php', 'app/Providers/StudyServiceProvider.php',
        'resources/views/study', 'lang/de/study.php', 'lang/en/study.php', 'config/study.php', 'routes/study.php',
    ];

    private const FORBIDDEN = '/SpeakingShares|ShareReport|ShareTrajectory|Metrics\\\\Questions|Gini|SpeakerShare|Trajectory/';

    public function test_no_module_file_touches_a_share_metric(): void
    {
        $hits = [];

        foreach (self::STUDY_CODE as $path) {
            $this->assertFileExists(base_path($path), 'A study path moved; update STUDY_CODE.');
            $files = is_dir(base_path($path))
                ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($path), FilesystemIterator::SKIP_DOTS)))
                : [new \SplFileInfo(base_path($path))];

            foreach ($files as $file) {
                if (preg_match('/\.php$/', $file->getFilename()) && preg_match(self::FORBIDDEN, file_get_contents($file->getPathname()), $match)) {
                    $hits[] = "{$file->getPathname()}: {$match[0]}";
                }
            }
        }

        $this->assertSame([], $hits);
    }

    public function test_the_status_text_shows_no_percentage_and_no_share(): void
    {
        $this->cell(spoken: 1);
        $this->cell(spoken: 2, pipeline: 'OrchestratorPipeline');

        $text = app(StatusText::class)->summary(StudySnapshot::read());

        $this->assertStringNotContainsString('%', $text);
        $this->assertStringNotContainsStringIgnoringCase('share', $text);
    }
}
