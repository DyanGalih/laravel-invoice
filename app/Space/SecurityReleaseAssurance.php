<?php

namespace Crater\Space;

use DateTime;
use RuntimeException;

class SecurityReleaseAssurance
{
    use SiteApi;
    /**
     * Approved vulnerability dispositions with owner, mitigation, and expiry.
     */
    public const APPROVED_DISPOSITIONS = [
        '@tiptap/core' => [
            'advisory' => 'GHSA-cp6q-959q-f8rh',
            'severity' => 'moderate',
            'owner' => 'Frontend Engineering',
            'mitigation' => 'Rich text attributes sanitized; upgrade to tiptap v3 scheduled with frontend modernization.',
            'expiry' => '2027-01-01',
        ],
        'vue-i18n' => [
            'advisory' => 'GHSA-83rm-6c8r-g728',
            'severity' => 'high',
            'owner' => 'Frontend Engineering',
            'mitigation' => 'Static local translation files only, no dynamic user input formatted through i18n tag attributes; v11 upgrade scheduled.',
            'expiry' => '2027-01-01',
        ],
        '@intlify/core-base' => [
            'advisory' => 'GHSA-83rm-6c8r-g728',
            'severity' => 'high',
            'owner' => 'Frontend Engineering',
            'mitigation' => 'Transitive dependency of vue-i18n; static translation files only.',
            'expiry' => '2027-01-01',
        ],
        '@intlify/message-compiler' => [
            'advisory' => 'GHSA-83rm-6c8r-g728',
            'severity' => 'high',
            'owner' => 'Frontend Engineering',
            'mitigation' => 'Transitive dependency of vue-i18n; static translation files only.',
            'expiry' => '2027-01-01',
        ],
        '@intlify/shared' => [
            'advisory' => 'GHSA-83rm-6c8r-g728',
            'severity' => 'high',
            'owner' => 'Frontend Engineering',
            'mitigation' => 'Transitive dependency of vue-i18n; static translation files only.',
            'expiry' => '2027-01-01',
        ],
    ];

    /**
     * Validate audit payloads from Composer and npm.
     *
     * @param  array  $composerAudit
     * @param  array  $npmAudit
     * @param  array  $dispositions
     * @return array
     */
    public static function evaluateDependencyAudits(array $composerAudit, array $npmAudit, array $dispositions = []): array
    {
        $dispositions = array_merge(self::APPROVED_DISPOSITIONS, $dispositions);
        $blockingFindings = [];
        $recordedAdvisories = [];

        $counts = [
            'CRITICAL' => 0,
            'HIGH' => 0,
            'MEDIUM' => 0,
            'LOW' => 0,
        ];

        // Evaluate Composer
        $composerAdvisories = $composerAudit['advisories'] ?? [];
        foreach ($composerAdvisories as $package => $advisories) {
            foreach ($advisories as $advisory) {
                $severity = strtoupper($advisory['severity'] ?? 'HIGH');
                if (isset($counts[$severity])) {
                    $counts[$severity]++;
                } else {
                    $counts['HIGH']++;
                }

                $advisoryId = $advisory['advisoryId'] ?? $advisory['cve'] ?? 'unknown';
                $recordedAdvisories[] = [
                    'ecosystem' => 'composer',
                    'package' => $package,
                    'advisory' => $advisoryId,
                    'severity' => $severity,
                ];

                if (! isset($dispositions[$package]) && in_array($severity, ['CRITICAL', 'HIGH'], true)) {
                    $blockingFindings[] = "Composer package {$package} has unapproved {$severity} advisory {$advisoryId}";
                }
            }
        }

        if (! empty($composerAudit['abandoned'])) {
            foreach ($composerAudit['abandoned'] as $package => $replacement) {
                if (! isset($dispositions[$package])) {
                    $blockingFindings[] = "Composer package {$package} is abandoned without an approved disposition";
                }
            }
        }

        // Evaluate npm
        $npmVulns = $npmAudit['vulnerabilities'] ?? [];
        foreach ($npmVulns as $package => $details) {
            $severity = strtoupper($details['severity'] ?? 'HIGH');
            if ($severity === 'MODERATE') {
                $severity = 'MEDIUM';
            }

            if (isset($counts[$severity])) {
                $counts[$severity]++;
            }

            $recordedAdvisories[] = [
                'ecosystem' => 'npm',
                'package' => $package,
                'severity' => $severity,
            ];

            if (! isset($dispositions[$package]) && in_array($severity, ['CRITICAL', 'HIGH'], true)) {
                $blockingFindings[] = "npm package {$package} has unapproved {$severity} vulnerability";
            }
        }

        return [
            'status' => empty($blockingFindings) ? 'PASS' : 'BLOCKED',
            'timestamp' => (new DateTime('now'))->format(DateTime::ATOM),
            'counts' => $counts,
            'advisories' => $recordedAdvisories,
            'blocking_findings' => $blockingFindings,
            'dispositions' => $dispositions,
        ];
    }

    /**
     * Validate release evidence according to the release evidence contract.
     *
     * @param  array  $evidence
     * @return array
     */
    public static function validateReleaseEvidence(array $evidence): array
    {
        $errors = [];

        // 1. Independent Reviewer check
        if (empty($evidence['independent_reviewer']) || ! is_string($evidence['independent_reviewer']) || trim($evidence['independent_reviewer']) === '') {
            $errors[] = 'Release evidence is missing a named independent reviewer.';
        }

        // 2. Review timestamp check (must be valid ISO-8601)
        if (empty($evidence['review_timestamp']) || ! is_string($evidence['review_timestamp'])) {
            $errors[] = 'Release evidence is missing a review timestamp.';
        } else {
            $d = DateTime::createFromFormat(DateTime::ATOM, $evidence['review_timestamp']);
            if (! $d) {
                $errors[] = 'Review timestamp must be in ISO-8601 format.';
            }
        }

        // 3. Canonical severity counts
        if (! isset($evidence['severity_counts']) || ! is_array($evidence['severity_counts'])) {
            $errors[] = 'Release evidence is missing canonical severity counts.';
        } else {
            foreach (['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'] as $sev) {
                if (! array_key_exists($sev, $evidence['severity_counts']) || ! is_int($evidence['severity_counts'][$sev])) {
                    $errors[] = "Severity count for {$sev} must be an integer.";
                }
            }

            if (($evidence['severity_counts']['CRITICAL'] ?? 0) > 0 || ($evidence['severity_counts']['HIGH'] ?? 0) > 0) {
                if (empty($evidence['approved_exceptions'])) {
                    $errors[] = 'Unresolved CRITICAL or HIGH findings require an approved, time-bounded exception.';
                }
            }
        }

        // 4. Secret / credential scan
        $jsonString = json_encode($evidence);
        $secretPatterns = [
            '/(?:password|passwd|pwd)\s*["\':=]+\s*["\'](?!placeholder)[^"\']{4,}["\']/i',
            '/(?:secret|token|api_key|apikey)\s*["\':=]+\s*["\'](?!placeholder)[a-zA-Z0-9_\-]{16,}["\']/i',
            '/-----BEGIN (?:RSA )?PRIVATE KEY-----/',
        ];

        foreach ($secretPatterns as $pattern) {
            if (preg_match($pattern, $jsonString)) {
                $errors[] = 'Release evidence contains potential credentials or secrets.';

                break;
            }
        }

        // 5. Dependency audit & runtime assurance evidence presence
        if (empty($evidence['dependency_audit_evidence']) || ($evidence['dependency_audit_evidence']['status'] ?? '') !== 'PASS') {
            $errors[] = 'Release evidence requires a passing dependency audit evidence block.';
        }

        if (empty($evidence['runtime_assurance_results']) || ($evidence['runtime_assurance_results']['status'] ?? '') !== 'PASS') {
            $errors[] = 'Release evidence requires passing runtime assurance results.';
        }

        return [
            'valid' => empty($errors),
            'status' => empty($errors) ? 'APPROVED' : 'PENDING',
            'errors' => $errors,
        ];
    }

    /**
     * Validate production deployment configuration without touching persistent data.
     *
     * @param  array  $env
     * @param  array  $dockerCompose
     * @return array
     */
    public static function validateDeploymentConfiguration(array $env, array $dockerCompose = []): array
    {
        $failures = [];

        if (($env['APP_DEBUG'] ?? '') === 'true' || ($env['APP_DEBUG'] ?? '') === true) {
            $failures[] = 'APP_DEBUG must be false in production configuration.';
        }

        if (empty($env['APP_KEY'])) {
            $failures[] = 'APP_KEY is required and must not be empty.';
        }

        if (empty($env['DB_PASSWORD']) && ($env['DB_CONNECTION'] ?? 'mysql') === 'mysql') {
            $failures[] = 'DB_PASSWORD is required for production database connections.';
        }

        $trustedProxies = $env['TRUSTED_PROXIES'] ?? '*';
        if ($trustedProxies === '*' || $trustedProxies === '**') {
            $failures[] = 'TRUSTED_PROXIES must use explicit CIDRs or proxy addresses, not a wildcard.';
        }

        if (! empty($dockerCompose['services']['db']['ports'] ?? [])) {
            $failures[] = 'Database service in docker-compose must not expose ports publicly.';
        }

        return [
            'safe' => empty($failures),
            'failures' => $failures,
        ];
    }

    /**
     * Verify package operation safety in disposable storage.
     *
     * @param  string  $zipPath
     * @param  string|null  $expectedHash
     * @return array
     */
    public static function verifyPackageOperationSafely(string $zipPath, ?string $expectedHash = null): array
    {
        try {
            static::verifyAndMarkPackage($zipPath, $expectedHash);
            $extractedDir = static::extractVerifiedPackage($zipPath);

            return [
                'status' => 'EXTRACTED',
                'extracted_dir' => $extractedDir,
                'error' => null,
            ];
        } catch (RuntimeException $e) {
            return [
                'status' => 'REJECTED',
                'error' => $e->getMessage(),
            ];
        }
    }
}
