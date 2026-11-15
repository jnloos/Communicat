<?php

namespace Tests\Feature\Study;

use App\Study\Progress\StatusText;
use App\Study\Progress\StudySnapshot;
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

    private const FORBIDDEN = '/SpeakingShares|ShareReport|ShareTrajectory|Metrics\\\\Questions|Gini|SpeakerShare|Trajectory/';

    public function test_no_module_file_touches_a_share_metric(): void
    {
        $hits = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Study'), FilesystemIterator::SKIP_DOTS)) as $file) {
            if (preg_match('/\.php$/', $file->getFilename()) && preg_match(self::FORBIDDEN, file_get_contents($file->getPathname()), $match)) {
                $hits[] = "{$file->getPathname()}: {$match[0]}";
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
