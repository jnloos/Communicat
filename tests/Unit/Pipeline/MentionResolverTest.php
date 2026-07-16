<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Message;
use App\Services\PromptingPipeline\Support\MentionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MentionResolverTest extends TestCase
{
    use RefreshDatabase;

    private MentionResolver $resolver;

    private Expert $alice;

    private Expert $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new MentionResolver;
        $this->alice = Expert::factory()->create(['name' => 'Alice Anders']);
        $this->bob = Expert::factory()->create(['name' => 'Bob Berger']);
    }

    private function experts(Expert ...$experts): Collection
    {
        return collect($experts);
    }

    private function userMessage(string $content): Message
    {
        $message = new Message;
        $message->user_id = 1;
        $message->content = $content;

        return $message;
    }

    public function test_matches_full_name_case_insensitively(): void
    {
        $matched = $this->resolver->match(
            $this->userMessage('Was denkst du, @alice anders?'),
            $this->experts($this->alice, $this->bob),
        );

        $this->assertSame([$this->alice->id], array_map(fn (Expert $e) => $e->id, $matched));
    }

    public function test_matches_unambiguous_first_name(): void
    {
        $matched = $this->resolver->match(
            $this->userMessage('@Bob wie siehst du das?'),
            $this->experts($this->alice, $this->bob),
        );

        $this->assertSame([$this->bob->id], array_map(fn (Expert $e) => $e->id, $matched));
    }

    public function test_ambiguous_first_name_does_not_match(): void
    {
        $bob2 = Expert::factory()->create(['name' => 'Bob Brandt']);

        $matched = $this->resolver->match(
            $this->userMessage('@Bob wie siehst du das?'),
            $this->experts($this->alice, $this->bob, $bob2),
        );

        $this->assertSame([], $matched);
    }

    public function test_ambiguous_first_name_still_matches_full_names(): void
    {
        $bob2 = Expert::factory()->create(['name' => 'Bob Brandt']);

        $matched = $this->resolver->match(
            $this->userMessage('@Bob Brandt, wie siehst du das?'),
            $this->experts($this->alice, $this->bob, $bob2),
        );

        $this->assertSame([$bob2->id], array_map(fn (Expert $e) => $e->id, $matched));
    }

    public function test_multiple_mentions_return_in_order_of_appearance(): void
    {
        $matched = $this->resolver->match(
            $this->userMessage('@Bob Berger und @Alice Anders, was meint ihr?'),
            $this->experts($this->alice, $this->bob),
        );

        $this->assertSame(
            [$this->bob->id, $this->alice->id],
            array_map(fn (Expert $e) => $e->id, $matched),
        );
    }

    public function test_mention_followed_by_punctuation_matches(): void
    {
        $matched = $this->resolver->match(
            $this->userMessage('Danke @Alice! Und weiter?'),
            $this->experts($this->alice, $this->bob),
        );

        $this->assertSame([$this->alice->id], array_map(fn (Expert $e) => $e->id, $matched));
    }

    public function test_name_prefix_does_not_match_longer_name(): void
    {
        $annabell = Expert::factory()->create(['name' => 'Annabell Arndt']);
        $anna = Expert::factory()->create(['name' => 'Anna Albers']);

        $matched = $this->resolver->match(
            $this->userMessage('@Annabell, was sagst du?'),
            $this->experts($anna, $annabell),
        );

        $this->assertSame([$annabell->id], array_map(fn (Expert $e) => $e->id, $matched));
    }

    public function test_mention_inside_word_does_not_match(): void
    {
        $matched = $this->resolver->match(
            $this->userMessage('mail@Alice.example ist keine Erwähnung'),
            $this->experts($this->alice),
        );

        $this->assertSame([], $matched);
    }

    public function test_non_contributor_mention_returns_empty(): void
    {
        $matched = $this->resolver->match(
            $this->userMessage('@Zoe was denkst du?'),
            $this->experts($this->alice, $this->bob),
        );

        $this->assertSame([], $matched);
    }

    public function test_expert_message_returns_empty(): void
    {
        $message = new Message;
        $message->expert_id = $this->alice->id;
        $message->content = '@Bob wie siehst du das?';

        $this->assertSame([], $this->resolver->match($message, $this->experts($this->alice, $this->bob)));
    }

    public function test_null_message_returns_empty(): void
    {
        $this->assertSame([], $this->resolver->match(null, $this->experts($this->alice, $this->bob)));
    }
}
