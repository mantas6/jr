<?php

test('the root route redirects guests to the filament login', function () {
    $this->get('/')
        ->assertRedirect('/login');
});

test('the filament login displays the application brand', function () {
    $this->get('/login')
        ->assertSee('Tasks');
});

test('the legacy admin prefix is not registered', function () {
    $this->get('/admin')
        ->assertNotFound();
});
