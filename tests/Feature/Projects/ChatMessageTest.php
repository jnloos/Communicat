<?php

namespace Tests\Feature\Projects;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatMessageTest extends TestCase
{
    use RefreshDatabase;

    private function message(string $content, array $attributes = []): Message
    {
        $message = (new Message)->forceFill($attributes + [
            'project_id' => Project::factory()->create()->id,
            'expert_id' => Expert::factory()->create()->id,
            'content' => $content,
        ]);
        $message->save();

        return $message;
    }

    private function render(Message $message): string
    {
        return $this->blade('<x-projects.chat-message :id="$msg->id" :msg="$msg" />', ['msg' => $message])->__toString();
    }

    public function test_raw_html_and_script_links_in_a_message_are_not_rendered(): void
    {
        $html = $this->render($this->message(
            "Hallo <script>alert(1)</script>\n\n<img src=x onerror=\"alert(2)\">\n\n[klick](javascript:alert(3))"
        ));

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_markdown_still_renders(): void
    {
        $html = $this->render($this->message("**fett**\n\n- eins\n- zwei\n\n| a | b |\n|---|---|\n| 1 | 2 |"));

        $this->assertStringContainsString('<strong>fett</strong>', $html);
        $this->assertStringContainsString('<li>eins</li>', $html);
        $this->assertStringContainsString('<table>', $html);
    }

    public function test_a_message_addressed_to_its_own_sender_shows_no_addressee(): void
    {
        $expert = Expert::factory()->create();
        $other = Expert::factory()->create();

        $toSelf = $this->message('x', ['expert_id' => $expert->id, 'addressee_expert_id' => $expert->id]);
        $toOther = $this->message('x', ['expert_id' => $expert->id, 'addressee_expert_id' => $other->id]);

        $this->assertNull($toSelf->visibleAddressee());
        $this->assertTrue($toOther->visibleAddressee()->is($other));
    }

    public function test_the_addressee_is_plain_text_without_a_bordered_pill_or_second_avatar(): void
    {
        $other = Expert::factory()->create(['name' => 'Robin Adressat']);

        $html = $this->render($this->message('x', ['addressee_expert_id' => $other->id]));

        $this->assertStringContainsString('Robin Adressat', $html);
        $this->assertStringContainsString('data-addressed-expert-id="'.$other->id.'"', $html);
        $this->assertStringContainsString('<span class="sr-only">'.__('chat.message.addressed').'</span>', $html);
        // The old pill: rounded-full border around an extra avatar next to the name.
        $this->assertStringNotContainsString('rounded-full border', $html);
        $without = $this->render($this->message('x'));
        $this->assertSame(substr_count($without, 'data-flux-avatar'), substr_count($html, 'data-flux-avatar'), 'the addressee adds no avatar');
    }

    public function test_the_time_sits_right_aligned_in_the_header_and_the_addressee_name_has_a_title(): void
    {
        $other = Expert::factory()->create(['name' => 'Robin <Adressat>']);
        $message = $this->message('x', [
            'addressee_expert_id' => $other->id,
            'created_at' => '2026-10-29 14:05:09',
        ]);

        $html = $this->render($message);

        $this->assertMatchesRegularExpression('/<time datetime="2026-10-29T14:05:09\+00:00" title="[^"]+" class="ms-auto [^"]*">14:05<\/time>/', $html);
        $this->assertStringContainsString('title="Robin &lt;Adressat&gt;"', $html);
    }
}
