<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\BidResolution;
use App\Discussion\Values\JevDecides;
use App\Discussion\Values\JevDecisionModel;
use App\Models\Project;
use Tests\TestCase;
use ValueError;

class JevDecisionModelTest extends TestCase
{
    public function test_the_live_setting_is_read_from_the_config_and_only_a_bidder_has_a_resolution(): void
    {
        $selector = JevDecisionModel::fromConfig(JevDecides::Selector);
        $bidder = JevDecisionModel::fromConfig(JevDecides::Bidder);

        $this->assertSame(['provider' => 'typesafe', 'model' => 'jev-1.13.0', 'decides' => 'selector', 'timeout' => 60], $selector->toArray());
        $this->assertSame(['provider' => 'typesafe', 'model' => 'jev-1.13.0', 'decides' => 'bidder', 'timeout' => 60, 'bid_resolution' => 'rounded'], $bidder->toArray());
        $this->assertNull($selector->bidResolution);
        $this->assertSame(BidResolution::Rounded, $bidder->bidResolution);
    }

    public function test_the_provider_is_read_from_the_config_once(): void
    {
        config(['ai.jev.provider' => 'typesafe-eu']);

        $this->assertSame('typesafe-eu', JevDecisionModel::fromConfig(JevDecides::Selector)->provider);
    }

    public function test_a_project_without_a_run_config_uses_the_live_setting(): void
    {
        config(['ai.jev.model' => 'jev-live', 'ai.jev.timeout' => 33]);

        $model = JevDecisionModel::forProject(new Project, JevDecides::Selector);

        $this->assertSame('jev-live', $model->id);
        $this->assertSame(33, $model->timeout);
    }

    public function test_a_started_project_uses_its_frozen_decision_model_whatever_the_config_says_now(): void
    {
        $project = new Project(['run_config' => ['decision_model' => ['provider' => 'typesafe', 'model' => 'jev-frozen', 'decides' => 'bidder', 'timeout' => 17, 'bid_resolution' => 'unrounded']]]);
        config(['ai.jev.model' => 'jev-live', 'ai.jev.timeout' => 99, 'ai.jev.bid_resolution' => 'rounded']);

        $model = JevDecisionModel::forProject($project, JevDecides::Selector);

        $this->assertSame('jev-frozen', $model->id);
        $this->assertSame(17, $model->timeout);
        $this->assertSame(JevDecides::Bidder, $model->decides);
        $this->assertSame(BidResolution::Unrounded, $model->bidResolution);
    }

    public function test_a_snapshot_from_before_the_timeout_was_recorded_keeps_its_model_and_takes_only_the_timeout_from_the_config(): void
    {
        $project = new Project(['run_config' => ['decision_model' => ['provider' => 'typesafe', 'model' => 'jev-frozen', 'decides' => 'selector']]]);
        config(['ai.jev.timeout' => 45]);

        $model = JevDecisionModel::forProject($project, JevDecides::Selector);

        $this->assertSame('jev-frozen', $model->id);
        $this->assertSame(45, $model->timeout);
    }

    public function test_a_damaged_snapshot_falls_back_to_the_live_setting(): void
    {
        $project = new Project(['run_config' => ['decision_model' => ['model' => '', 'provider' => 5]]]);

        $this->assertSame('jev-1.13.0', JevDecisionModel::forProject($project, JevDecides::Selector)->id);
    }

    public function test_an_unknown_bid_resolution_in_the_config_stops_the_run_instead_of_changing_it(): void
    {
        config(['ai.jev.bid_resolution' => 'roundd']);

        $this->expectException(ValueError::class);

        JevDecisionModel::fromConfig(JevDecides::Bidder);
    }
}
