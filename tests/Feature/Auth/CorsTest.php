<?php

it('lets the web app call the API from the browser with credentials', function () {
    $this->call('OPTIONS', '/api/v1/auth/me', server: [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('never allows another origin', function () {
    $response = $this->call('OPTIONS', '/api/v1/auth/me', server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    // With one allowed origin the header always names it, so the browser blocks any other site.
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:5173');
});
