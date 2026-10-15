<?php

use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Filament\Resources\JiraReleases\JiraReleaseResource;
use App\Filament\Resources\JiraReleases\Pages\ListJiraReleases;
use App\Filament\Resources\JiraReleases\Pages\ViewJiraRelease;
use App\Filament\Resources\JiraReleases\RelationManagers\IssuesRelationManager;
use App\Models\JiraIssue;
use App\Models\JiraRelease;
use App\Models\User;
use Filament\Navigation\NavigationItem;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Sign in a fresh user for the release resource.
 */
function releaseResourceUser(): User
{
    $user = User::factory()->create();
    actingAs($user);

    return $user;
}

test('guests are redirected to login', function () {
    get(JiraReleaseResource::getUrl())
        ->assertRedirect('/login');
});

test('the releases navigation item is sorted after tasks', function () {
    releaseResourceUser();

    $tasks = collect(JiraIssueResource::getNavigationItems())
        ->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Tasks');
    $releases = JiraReleaseResource::getNavigationItems()[0];

    expect($releases->getLabel())->toBe('Releases')
        ->and($releases->getSort())->toBeGreaterThan($tasks?->getSort() ?? PHP_INT_MAX);
});

test('the list defaults to the unreleased tab, which hides released and archived releases', function () {
    $user = releaseResourceUser();

    $unreleased = JiraRelease::factory()->for($user)->create();
    $overdue = JiraRelease::factory()->for($user)->overdue()->create();
    $released = JiraRelease::factory()->for($user)->released()->create();
    $archived = JiraRelease::factory()->for($user)->archived()->create();

    livewire(ListJiraReleases::class)
        ->assertSet('activeTab', 'unreleased')
        ->assertCanSeeTableRecords([$unreleased, $overdue])
        ->assertCanNotSeeTableRecords([$released, $archived]);
});

test('the released tab shows released releases that are not archived', function () {
    $user = releaseResourceUser();

    $unreleased = JiraRelease::factory()->for($user)->create();
    $released = JiraRelease::factory()->for($user)->released()->create();
    $archivedReleased = JiraRelease::factory()->for($user)->released()->archived()->create();

    livewire(ListJiraReleases::class)
        ->set('activeTab', 'released')
        ->assertCanSeeTableRecords([$released])
        ->assertCanNotSeeTableRecords([$unreleased, $archivedReleased]);
});

test('the archived tab shows every archived release', function () {
    $user = releaseResourceUser();

    $released = JiraRelease::factory()->for($user)->released()->create();
    $archived = JiraRelease::factory()->for($user)->archived()->create();
    $archivedReleased = JiraRelease::factory()->for($user)->released()->archived()->create();

    livewire(ListJiraReleases::class)
        ->set('activeTab', 'archived')
        ->assertCanSeeTableRecords([$archived, $archivedReleased])
        ->assertCanNotSeeTableRecords([$released]);
});

test('each tab badge counts the current user\'s releases in that tab', function () {
    $user = releaseResourceUser();

    JiraRelease::factory()->for($user)->count(2)->create();
    JiraRelease::factory()->for($user)->released()->create();
    JiraRelease::factory()->for($user)->released()->archived()->create();
    JiraRelease::factory()->for(User::factory())->count(3)->create();

    $tabs = livewire(ListJiraReleases::class)->instance()->getCachedTabs();

    expect($tabs['unreleased']->getBadge())->toBe('2')
        ->and($tabs['released']->getBadge())->toBe('1')
        ->and($tabs['archived']->getBadge())->toBe('1');
});

test('the list shows each release\'s status, task count and done progress', function () {
    $user = releaseResourceUser();

    $release = JiraRelease::factory()->for($user)->overdue()->create();
    $release->issues()->attach([
        JiraIssue::factory()->for($user)->done()->create()->id,
        JiraIssue::factory()->for($user)->create()->id,
        JiraIssue::factory()->for($user)->create()->id,
        JiraIssue::factory()->for($user)->create()->id,
    ]);
    $empty = JiraRelease::factory()->for($user)->create();

    livewire(ListJiraReleases::class)
        ->assertTableColumnFormattedStateSet('status', 'Overdue', $release)
        ->assertTableColumnStateSet('issues_count', 4, $release)
        ->assertTableColumnStateSet('progress', '25%', $release)
        ->assertTableColumnStateSet('issues_count', 0, $empty)
        ->assertTableColumnStateSet('progress', null, $empty);
});

test('the list is scoped to the current user', function () {
    $user = releaseResourceUser();

    $mine = JiraRelease::factory()->for($user)->create();
    $theirs = JiraRelease::factory()->for(User::factory())->create();

    livewire(ListJiraReleases::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

test('the view page shows the release details', function () {
    $user = releaseResourceUser();

    $release = JiraRelease::factory()->for($user)->released()->create([
        'name' => 'v4.2.0',
        'description' => 'Spring release',
    ]);

    get(JiraReleaseResource::getUrl('view', ['record' => $release]))
        ->assertOk()
        ->assertSee('v4.2.0')
        ->assertSee('Spring release')
        ->assertSee('Released')
        ->assertSee($release->jira_url);
});

test('another user\'s release cannot be viewed', function () {
    releaseResourceUser();

    $theirs = JiraRelease::factory()->for(User::factory())->create();

    get(JiraReleaseResource::getUrl('view', ['record' => $theirs]))
        ->assertNotFound();
});

test('the view page lists the release\'s tasks linking to the task view page', function () {
    $user = releaseResourceUser();

    $release = JiraRelease::factory()->for($user)->create();
    $linked = JiraIssue::factory()->for($user)->create();
    $unlinked = JiraIssue::factory()->for($user)->create();
    $release->issues()->attach($linked);

    livewire(IssuesRelationManager::class, ['ownerRecord' => $release, 'pageClass' => ViewJiraRelease::class])
        ->assertCanSeeTableRecords([$linked])
        ->assertCanNotSeeTableRecords([$unlinked])
        ->assertTableColumnExists('jira_key')
        ->assertSee(JiraIssueResource::getUrl('view', ['record' => $linked]), escape: false);
});
