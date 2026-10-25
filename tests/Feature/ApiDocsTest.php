<?php

use Illuminate\Support\Facades\Gate;

it('generates OpenAPI docs for every v1 endpoint with bearer auth', function () {
    // Scramble only opens the docs in the local environment by default.
    Gate::define('viewApiDocs', fn ($user = null) => true);

    $response = $this->getJson('/docs/api.json')->assertOk();
    $paymentBody = $response->json('paths./payments.post.requestBody.content.application/json.schema.$ref');
    $paymentSchema = $response->json('components.schemas.'.basename($paymentBody));

    expect($response->json('info.title'))->toBe('Inventory API')
        ->and($response->json('components.securitySchemes.http.scheme'))->toBe('bearer')
        ->and(array_keys($response->json('paths')))->toContain(
            '/auth/login', '/sales', '/purchases', '/payments', '/reports/summary',
        )
        ->and($paymentSchema['required'])
        ->toBe(['document_type', 'document_id', 'account_id', 'amount']);
});

it('keeps the docs closed outside the local environment', function () {
    $this->get('/docs/api')->assertForbidden();
});
