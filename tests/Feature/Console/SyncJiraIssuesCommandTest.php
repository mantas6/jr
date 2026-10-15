<?php

use App\Jobs\SyncJiraIssuesJob;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Command\Command;

test('it dispatches a sync only for connected users', function () {
    Queue::fake();

    $connected = User::factory()->withJiraConnection()->create();
    User::factory()->create();

    expect(Artisan::call('jira:sync'))->toBe(Command::SUCCESS);

    Queue::assertPushed(SyncJiraIssuesJob::class, 1);
    Queue::assertPushed(
        SyncJiraIssuesJob::class,
        fn (SyncJiraIssuesJob $job) => $job->user->is($connected),
    );
});

test('it dispatches a sync for a single user by id', function () {
    Queue::fake();

    $user = User::factory()->withJiraConnection()->create();

    expect(Artisan::call('jira:sync', ['user' => $user->id]))->toBe(Command::SUCCESS);

    Queue::assertPushed(
        SyncJiraIssuesJob::class,
        fn (SyncJiraIssuesJob $job) => $job->user->is($user) && $job->force === false,
    );
});

test('the force option dispatches a forced full resync', function () {
    Queue::fake();

    $user = User::factory()->withJiraConnection()->create();

    expect(Artisan::call('jira:sync', ['user' => $user->id, '--force' => true]))->toBe(Command::SUCCESS);

    Queue::assertPushed(
        SyncJiraIssuesJob::class,
        fn (SyncJiraIssuesJob $job) => $job->user->is($user) && $job->force === true,
    );
});

test('it fails when the given user does not exist', function () {
    Queue::fake();

    expect(Artisan::call('jira:sync', ['user' => 999]))->toBe(Command::FAILURE);

    Queue::assertNothingPushed();
});
