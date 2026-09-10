<?php

use App\Jobs\SyncJiraIssuesJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('it dispatches a sync only for connected users', function () {
    Queue::fake();

    $connected = User::factory()->withJiraConnection()->create();
    User::factory()->create();

    $this->artisan('jira:sync')->assertSuccessful();

    Queue::assertPushed(SyncJiraIssuesJob::class, 1);
    Queue::assertPushed(
        SyncJiraIssuesJob::class,
        fn (SyncJiraIssuesJob $job) => $job->user->is($connected),
    );
});

test('it dispatches a sync for a single user by id', function () {
    Queue::fake();

    $user = User::factory()->withJiraConnection()->create();

    $this->artisan('jira:sync', ['user' => $user->id])->assertSuccessful();

    Queue::assertPushed(
        SyncJiraIssuesJob::class,
        fn (SyncJiraIssuesJob $job) => $job->user->is($user),
    );
});

test('it fails when the given user does not exist', function () {
    Queue::fake();

    $this->artisan('jira:sync', ['user' => 999])->assertFailed();

    Queue::assertNothingPushed();
});
