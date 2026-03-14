<?php

// Registration is disabled in this app (admin-only).
test('registration screen can be rendered', function () {
    $response = $this->get('/register');
    $response->assertStatus(200);
})->skip('Registration is disabled (admin-only app).');

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('admin.dashboard', absolute: false));
})->skip('Registration is disabled (admin-only app).');
