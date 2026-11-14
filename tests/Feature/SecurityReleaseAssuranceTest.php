<?php

use Crater\Space\SecurityReleaseAssurance;
use Illuminate\Support\Facades\Route;

test('cors allows only configured origins', function () {
    config(['cors.allowed_origins' => ['https://app.example', 'https://other.example']]);

    Route::get('/api/v1/cors-test', function () {
        return response()->json(['ok' => true]);
    });

    $allowed = $this->get('/api/v1/cors-test', [
        'Origin' => 'https://app.example',
    ]);
    $denied = $this->get('/api/v1/cors-test', [
        'Origin' => 'https://evil.example',
    ]);

    expect($allowed->getStatusCode())->toBe(200)
        ->and($allowed->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example')
        ->and($denied->getStatusCode())->toBe(200)
        ->and($denied->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

test('cors configuration does not use a wildcard origin', function () {
    expect(config('cors.allowed_origins'))->not->toContain('*');
});

test('browser responses include baseline security headers', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);

    Route::get('/api/v1/security-header-test', function () {
        return response()->json(['ok' => true]);
    });
    Route::get('/security-header-test', function () {
        return response('ok');
    });

    foreach ([
        $this->get('/api/v1/security-header-test'),
        $this->get('/security-header-test'),
        $this->get('/invoices/pdf/security-header-test'),
    ] as $response) {
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
            ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    }
});

test('dependency audit gate fails on unapproved critical or high advisories and passes clean lockfiles', function () {
    $unapprovedComposer = [
        'advisories' => [
            'vulnerable/package' => [
                ['advisoryId' => 'PKSA-test-1234', 'severity' => 'critical'],
            ],
        ],
    ];

    $blockedResult = SecurityReleaseAssurance::evaluateDependencyAudits($unapprovedComposer, []);
    expect($blockedResult['status'])->toBe('BLOCKED')
        ->and($blockedResult['blocking_findings'])->not->toBeEmpty();

    // Expired disposition should still block
    $expiredDisposition = [
        'vulnerable/package' => [
            'advisory' => 'PKSA-test-1234',
            'owner' => 'Security Team',
            'mitigation' => 'Temporary test exception',
            'expiry' => '2020-01-01',
        ],
    ];
    $expiredResult = SecurityReleaseAssurance::evaluateDependencyAudits($unapprovedComposer, [], $expiredDisposition);
    expect($expiredResult['status'])->toBe('BLOCKED');

    // Mismatched advisory ID should still block
    $mismatchedDisposition = [
        'vulnerable/package' => [
            'advisory' => 'PKSA-other-9999',
            'owner' => 'Security Team',
            'mitigation' => 'Temporary test exception',
            'expiry' => '2030-01-01',
        ],
    ];
    $mismatchedResult = SecurityReleaseAssurance::evaluateDependencyAudits($unapprovedComposer, [], $mismatchedDisposition);
    expect($mismatchedResult['status'])->toBe('BLOCKED');

    // Valid, unexpired, matching disposition passes
    $validDisposition = [
        'vulnerable/package' => [
            'advisory' => 'PKSA-test-1234',
            'owner' => 'Security Team',
            'mitigation' => 'Temporary test exception',
            'expiry' => '2030-01-01',
        ],
    ];
    $approvedResult = SecurityReleaseAssurance::evaluateDependencyAudits($unapprovedComposer, [], $validDisposition);
    expect($approvedResult['status'])->toBe('PASS')
        ->and($approvedResult['blocking_findings'])->toBeEmpty();

    $cleanResult = SecurityReleaseAssurance::evaluateDependencyAudits(
        ['advisories' => [], 'abandoned' => []],
        ['vulnerabilities' => []]
    );

    expect($cleanResult['status'])->toBe('PASS')
        ->and($cleanResult['blocking_findings'])->toBeEmpty()
        ->and($cleanResult['timestamp'])->not->toBeEmpty();
});

test('release evidence validation enforces independent reviewer and rejects credentials', function () {
    $missingReviewer = [
        'independent_reviewer' => '',
        'review_timestamp' => date(DATE_ATOM),
        'severity_counts' => ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0],
        'dependency_audit_evidence' => ['status' => 'PASS'],
        'runtime_assurance_results' => ['status' => 'PASS'],
    ];

    $pending = SecurityReleaseAssurance::validateReleaseEvidence($missingReviewer);
    expect($pending['status'])->toBe('PENDING')
        ->and($pending['valid'])->toBeFalse();

    $leakedCredentials = [
        'independent_reviewer' => 'Alice Auditor',
        'review_timestamp' => date(DATE_ATOM),
        'severity_counts' => ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0],
        'secret_token' => 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ123456',
        'dependency_audit_evidence' => ['status' => 'PASS'],
        'runtime_assurance_results' => ['status' => 'PASS'],
    ];

    $rejected = SecurityReleaseAssurance::validateReleaseEvidence($leakedCredentials);
    expect($rejected['status'])->toBe('PENDING')
        ->and($rejected['valid'])->toBeFalse();

    // Expired or missing exception with high findings should be rejected
    $unapprovedFindings = [
        'independent_reviewer' => 'Alice Auditor',
        'review_timestamp' => date(DATE_ATOM),
        'severity_counts' => ['CRITICAL' => 1, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0],
        'approved_exceptions' => [
            [
                'advisory' => 'TEST-ADV-1',
                'owner' => 'SecOps',
                'mitigation' => 'Firewall mitigation',
                'expiry' => '2020-01-01',
            ],
        ],
        'dependency_audit_evidence' => ['status' => 'PASS'],
        'runtime_assurance_results' => ['status' => 'PASS'],
    ];
    $unapprovedResult = SecurityReleaseAssurance::validateReleaseEvidence($unapprovedFindings);
    expect($unapprovedResult['status'])->toBe('PENDING')
        ->and($unapprovedResult['valid'])->toBeFalse();

    $validEvidence = [
        'independent_reviewer' => 'Alice Auditor',
        'review_timestamp' => date(DATE_ATOM),
        'severity_counts' => ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0],
        'dependency_audit_evidence' => ['status' => 'PASS'],
        'runtime_assurance_results' => ['status' => 'PASS'],
    ];

    $approved = SecurityReleaseAssurance::validateReleaseEvidence($validEvidence);
    expect($approved['status'])->toBe('APPROVED')
        ->and($approved['valid'])->toBeTrue();
});

test('deployment runtime verification rejects unsafe debug and exposed database configuration', function () {
    $unsafeConfig = [
        'APP_DEBUG' => 'true',
        'APP_KEY' => '',
        'TRUSTED_PROXIES' => '*',
    ];

    $unsafeDockerCompose = [
        'services' => [
            'db' => [
                'ports' => ['3306:3306'],
            ],
        ],
    ];

    $result = SecurityReleaseAssurance::validateDeploymentConfiguration($unsafeConfig, $unsafeDockerCompose);
    expect($result['safe'])->toBeFalse()
        ->and($result['failures'])->toHaveCount(5);

    $safeConfig = [
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('s', 32)),
        'DB_CONNECTION' => 'mysql',
        'DB_PASSWORD' => 'ephemeral-injected-db-pass',
        'TRUSTED_PROXIES' => '10.0.0.0/8,172.16.0.0/12',
    ];

    $safeDockerCompose = [
        'services' => [
            'db' => [
                'ports' => [],
            ],
        ],
    ];

    $safeResult = SecurityReleaseAssurance::validateDeploymentConfiguration($safeConfig, $safeDockerCompose);
    expect($safeResult['safe'])->toBeTrue()
        ->and($safeResult['failures'])->toBeEmpty();
});

test('package operation runtime checks reject invalid integrity proofs and path traversal archives', function () {
    $tempDir = storage_path('app/temp-'.bin2hex(random_bytes(6)));
    mkdir($tempDir, 0755, true);

    $dummyZip = $tempDir.'/sample.zip';
    $zip = new ZipArchive();
    $zip->open($dummyZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('test.txt', 'hello world');
    $zip->close();

    // 1. Invalid hash check
    $badHashResult = SecurityReleaseAssurance::verifyPackageOperationSafely($dummyZip, str_repeat('a', 64));
    expect($badHashResult['status'])->toBe('REJECTED');

    // 2. Traversal path archive check
    $traversalZip = $tempDir.'/traversal.zip';
    $zip = new ZipArchive();
    $zip->open($traversalZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('../evil.php', '<?php echo "bad";');
    $zip->close();

    $traversalHash = hash_file('sha256', $traversalZip);
    $traversalResult = SecurityReleaseAssurance::verifyPackageOperationSafely($traversalZip, $traversalHash);
    expect($traversalResult['status'])->toBe('REJECTED')
        ->and($traversalResult['error'])->toContain('Archive contains an unsafe path');

    // Cleanup disposable storage
    @unlink($dummyZip);
    @unlink($dummyZip.'.verified');
    @unlink($traversalZip);
    @unlink($traversalZip.'.verified');
    @rmdir($tempDir);
});

test('shared authentication throttling limits repeated failures without disclosing user existence', function () {
    for ($i = 0; $i < 5; $i++) {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'test-user@example.com',
            'password' => 'wrong-password',
        ]);
        expect($response->getStatusCode())->not->toBe(429);
    }

    $throttled = $this->postJson('/api/v1/auth/login', [
        'username' => 'test-user@example.com',
        'password' => 'wrong-password',
    ]);

    expect($throttled->getStatusCode())->toBe(429)
        ->and($throttled->getContent())->not->toContain('password')
        ->and($throttled->getContent())->not->toContain('test-user@example.com');
});
