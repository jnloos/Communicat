<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Pipelines\UnknownPipeline;
use Tests\Fixtures\Pipelines\DummyPipeline;
use Tests\TestCase;

class PipelineRegistryTest extends TestCase
{
    private function registry(): PipelineRegistry
    {
        return new PipelineRegistry(base_path('tests/Fixtures/Pipelines'), 'Tests\\Fixtures\\Pipelines');
    }

    public function test_discovers_only_classes_that_implement_the_interface(): void
    {
        $this->assertSame(['DummyPipeline' => DummyPipeline::class], $this->registry()->all());
    }

    public function test_resolves_a_pipeline_by_its_short_class_name(): void
    {
        $this->assertInstanceOf(DummyPipeline::class, $this->registry()->resolve('DummyPipeline'));
        $this->assertTrue($this->registry()->has('DummyPipeline'));
        $this->assertFalse($this->registry()->has('NotAPipeline'));
    }

    public function test_unknown_names_throw(): void
    {
        $this->expectException(UnknownPipeline::class);

        $this->registry()->resolve('NotAPipeline');
    }

    public function test_label_falls_back_to_the_class_name(): void
    {
        $this->assertSame('DummyPipeline', $this->registry()->label('DummyPipeline'));
        $this->assertSame(['DummyPipeline' => 'DummyPipeline'], $this->registry()->options());
    }

    public function test_label_uses_a_translation_when_there_is_one(): void
    {
        app('translator')->addLines(['pipelines.DummyPipeline' => 'Attrappe'], app()->getLocale());

        $this->assertSame('Attrappe', $this->registry()->label('DummyPipeline'));
    }

    public function test_the_container_registry_scans_the_app_directory(): void
    {
        $this->assertSame(app(PipelineRegistry::class), app(PipelineRegistry::class));
        $this->assertSame('RoundRobinPipeline', app(PipelineRegistry::class)->default());
    }
}
