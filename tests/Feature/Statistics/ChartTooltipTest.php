<?php

namespace Tests\Feature\Statistics;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Flux hides a chart's tooltip with opacity alone and leaves it where it was
 * last placed. Hovering near the top of a chart places it above the chart,
 * over whatever sits there -- on the statistics page the answer-form switch --
 * and the invisible card then swallowed the next click there. A chart tooltip
 * is never clicked, so every one must let clicks through.
 */
class ChartTooltipTest extends TestCase
{
    public function test_every_chart_tooltip_lets_clicks_through(): void
    {
        $tooltips = collect(File::allFiles(resource_path('views')))
            ->flatMap(fn ($file) => collect(preg_match_all('/<flux:chart\.tooltip(?![.\w])[^>]*>/', $file->getContents(), $m) ? $m[0] : [])
                ->map(fn (string $tag) => [$file->getRelativePathname(), $tag]));

        $this->assertNotEmpty($tooltips);

        foreach ($tooltips as [$file, $tag]) {
            $this->assertStringContainsString('pointer-events-none', $tag, "{$file}: {$tag}");
        }
    }
}
