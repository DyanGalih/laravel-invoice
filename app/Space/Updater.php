<?php

namespace Crater\Space;

use Artisan;
use Crater\Events\UpdateFinished;
use File;
use GuzzleHttp\Exception\RequestException;

// Implementation taken from Akaunting - https://github.com/akaunting/akaunting
class Updater
{
    use SiteApi;

    public static function checkForUpdate($installed_version)
    {
        $installed_version = static::validVersion($installed_version);
        $data = null;
        if (env('APP_ENV') === 'development' || env('APP_ENV') === 'local') {
            $url = 'downloads/check/latest/'.rawurlencode($installed_version).'?'.http_build_query([
                'type' => 'update',
                'is_dev' => 1,
            ]);
        } else {
            $url = 'downloads/check/latest/'.rawurlencode($installed_version).'?type=update';
        }

        $response = static::getRemote($url, ['timeout' => 100, 'track_redirects' => true]);

        if ($response && ($response->getStatusCode() == 200)) {
            $data = $response->getBody()->getContents();
        }

        $data = json_decode($data);

        if ($data && ! empty($data->success) && ! empty($data->version) && property_exists($data->version, 'extensions')) {
            $extensions = $data->version->extensions;
            $extensionData = [];
            foreach (json_decode($extensions) as $extension) {
                $extensionData[$extension] = phpversion($extension) ? true : false;
            }
            $extensionData['php'.'('.$data->version->minimum_php_version.')'] = version_compare(phpversion(), $data->version->minimum_php_version, ">=");
            $data->version->extensions = $extensionData;
        }

        return $data;
    }

    public static function download($new_version, $is_cmd = 0)
    {
        $new_version = static::validVersion($new_version);
        $data = null;
        $path = null;

        if (env('APP_ENV') === 'development') {
            $url = 'downloads/file/'.rawurlencode($new_version).'?'.http_build_query([
                'type' => 'update',
                'is_dev' => 1,
                'is_cmd' => (int) $is_cmd,
            ]);
        } else {
            $url = 'downloads/file/'.rawurlencode($new_version).'?'.http_build_query([
                'type' => 'update',
                'is_cmd' => (int) $is_cmd,
            ]);
        }

        $response = static::getRemote($url, ['timeout' => 100, 'track_redirects' => true]);

        // Exception
        if ($response instanceof RequestException) {
            return [
                'success' => false,
                'error' => 'Download Exception',
                'data' => [
                    'path' => $path,
                ],
            ];
        }

        if ($response && ($response->getStatusCode() == 200)) {
            $data = $response->getBody()->getContents();
        }

        if (! is_string($data) || $data === '') {
            return [
                'success' => false,
                'error' => 'Invalid update package.',
            ];
        }

        // Create temp directory
        $temp_dir = storage_path('app/temp-'.bin2hex(random_bytes(12)));

        if (! File::isDirectory($temp_dir) && ! File::makeDirectory($temp_dir, 0755, true)) {
            return [
                'success' => false,
                'error' => 'Unable to create update directory.',
            ];
        }

        $zip_file_path = $temp_dir.'/upload.zip';

        // Add content to the Zip file
        $uploaded = file_put_contents($zip_file_path, $data, LOCK_EX) !== false;

        if (! $uploaded) {
            File::deleteDirectory($temp_dir);

            return false;
        }

        try {
            static::verifyAndMarkPackage($zip_file_path, static::expectedPackageHash($response, 'CRATER_UPDATE_SHA256'));
        } catch (\Throwable $exception) {
            File::deleteDirectory($temp_dir);

            return [
                'success' => false,
                'error' => 'Update package integrity verification failed.',
            ];
        }

        return static::relativeStoragePath($zip_file_path);
    }

    public static function unzip($zip_file_path)
    {
        return static::extractVerifiedPackage($zip_file_path);
    }

    public static function copyFiles($temp_extract_dir)
    {
        $temp_extract_dir = static::assertVerifiedExtraction($temp_extract_dir);
        $source = realpath($temp_extract_dir.'/Crater');
        $destination = realpath(base_path());
        if (! $source || ! $destination || ! static::isWithin($source, $temp_extract_dir) || ! File::copyDirectory($source, $destination)) {
            return false;
        }

        // Delete temp directory
        File::deleteDirectory($temp_extract_dir);

        return true;
    }

    public static function deleteFiles($json)
    {
        $files = json_decode($json, true);
        $root = realpath(base_path());
        if (! is_array($files) || ! $root) {
            return false;
        }

        $targets = [];
        foreach ($files as $file) {
            if (! is_string($file)) {
                return false;
            }

            $relative = static::safeRelativePath($file);
            if (in_array($relative, ['.env', '.env.example'], true)) {
                return false;
            }

            $candidate = base_path($relative);
            if (is_link($candidate)) {
                return false;
            }
            $target = realpath($candidate);
            if (! $target || ! static::isWithin($target, $root) || ! is_file($target)) {
                return false;
            }

            $targets[] = $target;
        }

        foreach (array_unique($targets) as $target) {
            if (! \File::delete($target)) {
                return false;
            }
        }

        return true;
    }

    public static function migrateUpdate()
    {
        Artisan::call('migrate --force');

        return true;
    }

    public static function finishUpdate($installed, $version)
    {
        event(new UpdateFinished($installed, $version));

        return [
            'success' => true,
            'error' => false,
            'data' => [],
        ];
    }
}
