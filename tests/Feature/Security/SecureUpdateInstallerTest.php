<?php

use Crater\Space\ModuleInstaller;
use Crater\Space\SiteApi;
use Crater\Space\Updater;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = storage_path('app/test-security-'.uniqid());
    if (! File::isDirectory($this->tempDir)) {
        File::makeDirectory($this->tempDir, 0755, true);
    }
});

afterEach(function () {
    if (isset($this->tempDir) && File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

test('unzip rejects directory traversal entries', function () {
    $zipPath = $this->tempDir.'/traversal.zip';
    $zip = new ZipArchive();
    expect($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('../../../evil.txt', 'malicious content');
    $zip->close();

    $extracted = false;
    try {
        Updater::unzip($zipPath);
        $extracted = true;
    } catch (\Throwable $e) {
        expect($e)->toBeInstanceOf(RuntimeException::class)
            ->and($e->getMessage())->toContain('unsafe path');
    }

    expect($extracted)->toBeFalse();
});

test('module installer unzip rejects directory traversal entries', function () {
    $zipPath = $this->tempDir.'/module-traversal.zip';
    $zip = new ZipArchive();
    expect($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('../evil-module.php', '<?php echo "pwned";');
    $zip->close();

    $extracted = false;
    try {
        ModuleInstaller::unzip('test-module', $zipPath);
        $extracted = true;
    } catch (\Throwable $e) {
        expect($e)->toBeInstanceOf(RuntimeException::class)
            ->and($e->getMessage())->toContain('unsafe path');
    }

    expect($extracted)->toBeFalse();
});

test('remote package requests enforce HTTPS transport', function () {
    config(['crater.base_url' => 'http://insecure.example.com']);

    $testClass = new class {
        use SiteApi {
            getRemote as public;
        }
    };

    expect(fn () => $testClass::getRemote('downloads/check'))
        ->toThrow(RuntimeException::class, 'Remote package endpoint must use HTTPS.');
});

test('safe extract archive rejects symbolic link entries', function () {
    $zipPath = $this->tempDir.'/symlink.zip';
    $zip = new ZipArchive();
    expect($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('valid.txt', 'hello world');
    // Set Unix symlink external attribute: 0120000 (octal) = 0xA000
    $zip->setExternalAttributesName('valid.txt', ZipArchive::OPSYS_UNIX, 0xA1FF << 16);
    $zip->close();

    $extractDir = $this->tempDir.'/extract';

    $testClass = new class {
        use SiteApi {
            safeExtractArchive as public;
        }
    };

    expect(fn () => $testClass::safeExtractArchive($zipPath, $extractDir))
        ->toThrow(RuntimeException::class, 'Archive contains a symbolic link.');
});
