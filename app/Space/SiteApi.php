<?php

namespace Crater\Space;

use Crater\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use ZipArchive;

// Implementation taken from Akaunting - https://github.com/akaunting/akaunting
trait SiteApi
{
    protected static function getRemote($url, $data = [], $token = null)
    {
        $baseUrl = rtrim((string) config('crater.base_url'), '/');
        if (parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Remote package endpoint must use HTTPS.');
        }

        $client = new Client([
            'verify' => true,
            'base_uri' => $baseUrl.'/',
            'allow_redirects' => false,
        ]);

        $headers['headers'] = [
            'Accept' => 'application/json',
            'Referer' => url('/'),
            'crater' => Setting::getSetting('version'),
            'Authorization' => "Bearer {$token}",
        ];

        $data['http_errors'] = false;
        $data['verify'] = true;

        $data = array_merge($data, $headers);

        try {
            $result = $client->get($url, $data);
        } catch (RequestException $e) {
            $result = $e;
        }

        return $result;
    }

    protected static function verifyAndMarkPackage($path, $expectedHash = null)
    {
        if (! is_file($path)) {
            throw new RuntimeException('Package file not found.');
        }

        $expectedHash = strtolower((string) $expectedHash);
        if (! preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            throw new RuntimeException('Package integrity proof is missing.');
        }

        $actualHash = hash_file('sha256', $path);
        if (! hash_equals($expectedHash, strtolower($actualHash))) {
            throw new RuntimeException('Package integrity verification failed.');
        }

        if (file_put_contents($path.'.verified', $actualHash, LOCK_EX) === false) {
            throw new RuntimeException('Unable to record package verification.');
        }

        return $actualHash;
    }

    protected static function expectedPackageHash($response, $environmentKey)
    {
        $headerHash = $response && method_exists($response, 'getHeaderLine')
            ? $response->getHeaderLine('X-Crater-Sha256')
            : null;

        return $headerHash ?: env($environmentKey);
    }

    protected static function extractVerifiedPackage($zipFilePath)
    {
        $zipFilePath = static::resolveStoragePath($zipFilePath);
        $storagePath = realpath(storage_path('app'));
        if (! $zipFilePath || ! $storagePath || ! static::isWithin($zipFilePath, $storagePath) || strpos(basename(dirname($zipFilePath)), 'temp-') !== 0) {
            throw new RuntimeException('Package path is not an approved temporary file.');
        }

        $marker = $zipFilePath.'.verified';
        $verifiedHash = is_file($marker) ? trim((string) file_get_contents($marker)) : '';
        $actualHash = hash_file('sha256', $zipFilePath);
        if (! preg_match('/^[a-f0-9]{64}$/i', $verifiedHash) || ! hash_equals(strtolower($verifiedHash), strtolower($actualHash))) {
            throw new RuntimeException('Package integrity verification is required.');
        }

        $tempExtractDir = storage_path('app/temp2-'.bin2hex(random_bytes(12)));
        if (! mkdir($tempExtractDir, 0755, true) && ! is_dir($tempExtractDir)) {
            throw new RuntimeException('Unable to create package extraction directory.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFilePath) !== true) {
            throw new RuntimeException('Invalid package archive.');
        }

        try {
            $root = realpath($tempExtractDir);
            if (! $root) {
                throw new RuntimeException('Unable to resolve package extraction directory.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));
                if ($name === '' || strpos($name, "\0") !== false || $name[0] === '/' || preg_match('/^[A-Za-z]:\//', $name) || preg_match('#(^|/)\.\.?(/|$)#', $name)) {
                    throw new RuntimeException('Archive contains an unsafe path.');
                }

                $system = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $system, $attributes) && (($attributes >> 16) & 0xF000) === 0xA000) {
                    throw new RuntimeException('Archive contains a symbolic link.');
                }

                $target = $tempExtractDir.'/'.$name;
                $directory = preg_match('#/$#', $name) === 1;
                $parent = dirname($target);
                if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
                    throw new RuntimeException('Unable to create package directory.');
                }

                $realParent = realpath($parent);
                if (! $realParent || ! static::isWithin($realParent, $root)) {
                    throw new RuntimeException('Archive destination escapes its extraction directory.');
                }

                if ($directory) {
                    continue;
                }

                $stream = $zip->getStream($zip->getNameIndex($index));
                if (! is_resource($stream)) {
                    throw new RuntimeException('Unable to read package entry.');
                }
                $contents = stream_get_contents($stream);
                fclose($stream);
                if (file_put_contents($target, $contents, LOCK_EX) === false) {
                    throw new RuntimeException('Unable to extract package entry.');
                }
            }
        } catch (\Throwable $exception) {
            $zip->close();
            \File::deleteDirectory($tempExtractDir);

            throw $exception;
        }

        $zip->close();
        \File::delete($zipFilePath);
        \File::delete($marker);
        if (file_put_contents($tempExtractDir.'/.verified', $actualHash, LOCK_EX) === false) {
            \File::deleteDirectory($tempExtractDir);

            throw new RuntimeException('Unable to record extracted package verification.');
        }

        return static::relativeStoragePath($tempExtractDir);
    }

    protected static function assertVerifiedExtraction($path)
    {
        $path = static::resolveStoragePath($path);
        $storagePath = realpath(storage_path('app'));
        if (! $path || ! $storagePath || ! static::isWithin($path, $storagePath) || strpos(basename($path), 'temp2-') !== 0 || ! is_file($path.'/.verified')) {
            throw new RuntimeException('Package extraction is not approved.');
        }

        return $path;
    }

    protected static function resolveStoragePath($path)
    {
        $storagePath = realpath(storage_path('app'));
        $path = str_replace('\\', '/', (string) $path);
        if (! $storagePath || $path === '' || strpos($path, "\0") !== false) {
            throw new RuntimeException('Invalid package path.');
        }

        $candidates = [$path];
        if ($path[0] !== '/') {
            $candidates[] = storage_path('app/'.$path);
        }

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved && static::isWithin($resolved, $storagePath)) {
                return $resolved;
            }
        }

        throw new RuntimeException('Package path is outside application storage.');
    }

    protected static function isWithin($path, $root)
    {
        return $path === $root || strpos($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) === 0;
    }

    protected static function relativeStoragePath($path)
    {
        $root = realpath(storage_path('app'));
        $path = realpath($path);
        if (! $root || ! $path || ! static::isWithin($path, $root) || $path === $root) {
            throw new RuntimeException('Package path is not inside application storage.');
        }

        return ltrim((string) preg_replace('/^'.preg_quote($root, '/').'[\\\\\/]/', '', $path, 1), DIRECTORY_SEPARATOR);
    }

    protected static function validVersion($version)
    {
        $version = (string) $version;
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$/', $version)) {
            throw new RuntimeException('Invalid package version.');
        }

        return $version;
    }

    protected static function safeRelativePath($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        if ($path === '' || $path[0] === '/' || preg_match('/^[A-Za-z]:\//', $path) || strpos($path, "\0") !== false || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
            throw new RuntimeException('Unsafe file path.');
        }

        return $path;
    }
}
