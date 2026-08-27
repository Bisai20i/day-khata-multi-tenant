<?php

test('security headers are present on responses outside local dev', function () {
    app()['env'] = 'production';

    $response = $this->get('/login');

    $response->assertHeader('Content-Security-Policy');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->toContain("script-src 'self'")
        ->toContain("style-src 'self' 'unsafe-inline'")
        ->toContain("frame-ancestors 'none'");
});

test('security headers are absent in local dev', function () {
    app()['env'] = 'local';

    $response = $this->get('/login');

    $response->assertHeaderMissing('Content-Security-Policy');
    $response->assertHeaderMissing('X-Content-Type-Options');
});
