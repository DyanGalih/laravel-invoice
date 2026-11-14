<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Crater\Space\SecurityReleaseAssurance;

$appDir = dirname(__DIR__);

// 1. Composer audit
$composerCommand = 'composer audit --locked --no-dev --no-interaction --format=json';
$composerOutput = shell_exec("cd {$appDir} && {$composerCommand} 2>&1");
$composerAudit = json_decode($composerOutput ?: '{}', true) ?: [];

// 2. npm audit
$npmCommand = 'npm audit --package-lock-only --omit=dev --json';
$npmOutput = shell_exec("cd {$appDir} && {$npmCommand} 2>&1");
$npmAudit = json_decode($npmOutput ?: '{}', true) ?: [];

// 3. Evaluate audits
$evaluation = SecurityReleaseAssurance::evaluateDependencyAudits($composerAudit, $npmAudit);

// 4. Capture lockfile revisions
$composerLock = $appDir.'/composer.lock';
$npmLock = $appDir.'/package-lock.json';

$evidence = [
    'schema_version' => '1.0',
    'timestamp' => $evaluation['timestamp'],
    'commands' => [
        'composer' => $composerCommand,
        'npm' => $npmCommand,
    ],
    'lockfile_hashes' => [
        'composer.lock' => is_file($composerLock) ? hash_file('sha256', $composerLock) : null,
        'package-lock.json' => is_file($npmLock) ? hash_file('sha256', $npmLock) : null,
    ],
    'audit_summary' => $evaluation,
];

$outputDir = $appDir.'/storage/reports';
if (! is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$evidenceFile = $outputDir.'/release-evidence.json';
file_put_contents($evidenceFile, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Audit evidence written to {$evidenceFile}\n";
echo "Status: {$evaluation['status']}\n";

if ($evaluation['status'] !== 'PASS') {
    echo "Blocking findings detected:\n";
    foreach ($evaluation['blocking_findings'] as $finding) {
        echo " - {$finding}\n";
    }
    exit(1);
}

echo "All production dependency audits passed successfully.\n";
exit(0);
