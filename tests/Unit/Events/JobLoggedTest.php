<?php

namespace Tests\Unit\Events;

use App\Events\JobLogged;
use App\Models\JobLog;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class JobLoggedTest extends TestCase
{
    /**
     * A job report carries prompts and model answers, so it may reach only the
     * people who can open the project -- never a channel every user can join.
     */
    public function test_it_broadcasts_on_the_projects_channel(): void
    {
        $event = new JobLogged(new JobLog(['project_id' => 7]));

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-projects.7', $channels[0]->name);
        $this->assertTrue($event->broadcastWhen());
    }

    public function test_a_job_without_a_project_is_not_broadcast(): void
    {
        $event = new JobLogged(new JobLog(['project_id' => null]));

        $this->assertFalse($event->broadcastWhen());
        $this->assertSame([], $event->broadcastOn());
    }
}
