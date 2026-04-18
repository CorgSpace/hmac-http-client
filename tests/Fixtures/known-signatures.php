<?php

declare(strict_types=1);

/*
 * Locked-in HMAC test vectors.
 *
 * These values pin the on-the-wire signing spec. Any change to the canonical
 * string format or signing logic that breaks these fixtures is a breaking
 * change and requires a major version bump.
 *
 * Secret (32 bytes, raw): "testsecretbytes-testsecretbytes!"
 */
return [
    'simple_post' => [
        'secret_base64' => 'dGVzdHNlY3JldGJ5dGVzLXRlc3RzZWNyZXRieXRlcyE=',
        'secret_hex' => '7465737473656372657462797465732d74657374736563726574627974657321',
        'method' => 'POST',
        'request_target' => '/v1/licenses',
        'timestamp' => '1734567890',
        'nonce' => '01HQZZZ0000000000000000001',
        'idempotency_key' => 'evt_test_abc',
        'body' => '{"external_ref":"sub_1","source":"direct"}',
        'expected_canonical' => "POST\n/v1/licenses\n1734567890\n01HQZZZ0000000000000000001\nevt_test_abc\n".hash('sha256', '{"external_ref":"sub_1","source":"direct"}'),
        'expected_signature' => 'SKgo4Ss/++XAsvtLsyf9O5dFJwOiuCU+qgzWHh7blSg=',
    ],
    'empty_body_get' => [
        'secret_base64' => 'dGVzdHNlY3JldGJ5dGVzLXRlc3RzZWNyZXRieXRlcyE=',
        'secret_hex' => '7465737473656372657462797465732d74657374736563726574627974657321',
        'method' => 'GET',
        'request_target' => '/v1/users',
        'timestamp' => '1734567890',
        'nonce' => '01HQZZZ0000000000000000002',
        'idempotency_key' => 'lookup-01HQ',
        'body' => '',
        'expected_canonical' => "GET\n/v1/users\n1734567890\n01HQZZZ0000000000000000002\nlookup-01HQ\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
        'expected_signature' => 'GTJY7Y+spunIcT9sc3lBI6rUeLmYkW1HScIU/i3e5bY=',
    ],
    'query_string_get' => [
        'secret_base64' => 'dGVzdHNlY3JldGJ5dGVzLXRlc3RzZWNyZXRieXRlcyE=',
        'secret_hex' => '7465737473656372657462797465732d74657374736563726574627974657321',
        'method' => 'GET',
        'request_target' => '/v1/search?q=widget&sort=asc',
        'timestamp' => '1734567890',
        'nonce' => '01HQZZZ0000000000000000003',
        'idempotency_key' => 'search-001',
        'body' => '',
        'expected_canonical' => "GET\n/v1/search?q=widget&sort=asc\n1734567890\n01HQZZZ0000000000000000003\nsearch-001\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
        'expected_signature' => 'sbugzCog5JibO+eNx2OzStMUUsYR6fAKBB+MUfQpo8M=',
    ],
];
