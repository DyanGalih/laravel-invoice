<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Crater\Space\SecurityReleaseAssurance;

$appDir = dirname(__DIR__);

/**
 * Run a command and return its exit code, stdout, and stderr.
 */
function executeAuditCommand(string $command, string $cwd): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (! is_resource($process)) {
        return [
            'exit_code' => -1,
            'stdout' => '',
            'stderr' => 'Failed to spawn audit process.',
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

// 1. Composer audit
$composerCommand = 'composer audit --locked --no-dev --no-interaction --format=json';
$composerResult = executeAuditCommand($composerCommand, $appDir);
$composerAudit = json_decode($composerResult['stdout'], true);

if (! is_array($composerAudit) || json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "Composer audit failed to produce valid JSON output.\n");
    if (! empty($composerResult['stderr'])) {
        fwrite(STDERR, "Stderr: {$composerResult['stderr']}\n");
    }
    exit(1);
}

if (! isset($composerAudit['advisories']) && ! isset($composerAudit['abandoned'])) {
    fwrite(STDERR, "Composer audit returned unexpected payload structure.\n");
    exit(1);
}

// 2. npm audit
$npmCommand = 'npm audit --package-lock-only --omit=dev --json';
$npmResult = executeAuditCommand($npmCommand, $appDir);
$npmAudit = json_decode($npmResult['stdout'], true);

if (! is_array($npmAudit) || json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "npm audit failed to produce valid JSON output.\n");
    if (! empty($npmResult['stderr'])) {
        fwrite(STDERR, "Stderr: {$npmResult['stderr']}\n");
    }
    exit(1);
}

if (! isset($npmAudit['vulnerabilities']) && ! isset($npmAudit['auditReportVersion'])) {
    fwrite(STDERR, "npm audit returned unexpected payload structure.\n");
    exit(1);
}

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
if (! is_dir($outputDir) && ! @mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, "Failed to create directory {$outputDir}\n");
    exit(1);
}

$evidenceFile = $outputDir.'/release-evidence.json';
$payload = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$bytesWritten = @file_put_contents($evidenceFile, $payload);

if ($bytesWritten === false || $bytesWritten !== strlen($payload)) {
    fwrite(STDERR, "Failed to write audit evidence to {$evidenceFile}\n");
    exit(1);
}

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
