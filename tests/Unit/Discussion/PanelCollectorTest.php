<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\Questions\DimensionValues;
use App\Discussion\Metrics\Questions\PanelCollector;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\RunPlacement;
use App\Discussion\Values\RunValue;
use App\Discussion\Values\TurnRange;
use App\Discussion\Values\TurnWindow;
use Tests\TestCase;

/** The layout PanelCollector gives an answer, independent of the order the runs arrive in. */
class PanelCollectorTest extends TestCase
{
    public function test_two_splits_give_panels_with_the_first_dimension_varying_slowest(): void
    {
        [$pipelineA, $pipelineB] = array_keys(app(PipelineRegistry::class)->options());
        [$modelA, $modelB] = array_keys(ModelConfig::options());
        $panels = new PanelCollector;

        foreach ([[$pipelineB, $modelB], [$pipelineB, $modelA], [$pipelineA, $modelB], [$pipelineA, $modelA]] as $parts) {
            $panels->add(new RunPlacement($parts, ['' => [1]]), ['' => [new RunValue(1, 'run', 0.5, null), []]]);
        }

        $result = $this->answer($panels, ['split_by' => ['pipeline', 'model']]);

        $this->assertSame(
            ["{$pipelineA}|{$modelA}", "{$pipelineA}|{$modelB}", "{$pipelineB}|{$modelA}", "{$pipelineB}|{$modelB}"],
            array_map(fn ($panel) => $panel->key, $result->panels),
        );
    }

    public function test_without_a_split_there_is_one_panel_with_an_empty_key(): void
    {
        $panels = new PanelCollector;
        $panels->add(new RunPlacement([], ['' => [1]]), ['' => [new RunValue(1, 'run', 0.5, null), []]]);

        $result = $this->answer($panels, []);

        $this->assertSame([''], array_map(fn ($panel) => $panel->key, $result->panels));
        $this->assertSame([], $result->columns);
    }

    /** @param  array<string, mixed>  $question */
    private function answer(PanelCollector $panels, array $question): QuestionResult
    {
        return $panels->result(QuestionDefinition::fromArray($question), app(DimensionValues::class), 4, new TurnWindow(new TurnRange, null));
    }
}
