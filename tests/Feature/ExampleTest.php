<?php

use function Pest\Laravel\get;

test('the root route redirects guests to the filament login', function () {
    get('/')
        ->assertRedirect('/login');
});

test('the filament login displays the application brand', function () {
    get('/login')
        ->assertSee('Tasks');
});

test('the legacy admin prefix is not registered', function () {
    get('/admin')
        ->assertNotFound();
});
