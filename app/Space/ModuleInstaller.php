<?php

namespace Crater\Space;

use Artisan;
use Crater\Events\ModuleEnabledEvent;
use Crater\Events\ModuleInstalledEvent;
use Crater\Http\Resources\ModuleResource;
use Crater\Models\Module as ModelsModule;
use Crater\Models\Setting;
use File;
use GuzzleHttp\Exception\RequestException;
use Nwidart\Modules\Facades\Module;
use RuntimeException;

// Implementation taken from Akaunting - https://github.com/akaunting/akaunting
class ModuleInstaller
{
    use SiteApi;

    public static function getModules()
    {
        $data = null;
        if (env('APP_ENV') === 'development') {
            $url = 'api/marketplace/modules?is_dev=1';
        } else {
            $url = 'api/marketplace/modules';
        }

        $token = Setting::getSetting('api_token');
        $response = static::getRemote($url, ['timeout' => 100, 'track_redirects' => true], $token);

        if ($response && ($response->getStatusCode() == 401)) {
            return response()->json(['error' => 'invalid_token']);
        }

        if ($response && ($response->getStatusCode() == 200)) {
            $data = $response->getBody()->getContents();
        }

        $data = json_decode($data);

        return ModuleResource::collection(collect($data->modules));
    }

    public static function getModule($module)
    {
        $module = static::validModuleName($module);
        $data = null;
        if (env('APP_ENV') === 'development') {
            $url = 'api/marketplace/modules/'.rawurlencode($module).'?is_dev=1';
        } else {
            $url = 'api/marketplace/modules/'.rawurlencode($module);
        }

        $token = Setting::getSetting('api_token');
        $response = static::getRemote($url, ['timeout' => 100, 'track_redirects' => true], $token);

        if ($response && ($response->getStatusCode() == 401)) {
            return (object)['success' => false, 'error' => 'invalid_token'];
        }

        if ($response && ($response->getStatusCode() == 200)) {
            $data = $response->getBody()->getContents();
        }

        $data = json_decode($data);

        return $data;
    }

    public static function upload($request)
    {
        $module = static::validModuleName($request->module);
        $directory = 'temp-'.bin2hex(random_bytes(12));
        $path = $request->file('avatar')->storeAs($directory, $module.'.zip', 'local');
        $absolutePath = static::resolveStoragePath($path);

        try {
            static::verifyAndMarkPackage($absolutePath, $request->sha256);
        } catch (\Throwable $exception) {
            File::deleteDirectory(dirname($absolutePath));

            throw $exception;
        }

        return static::relativeStoragePath($absolutePath);
    }

    public static function download($module, $version)
    {
        $module = static::validModuleName($module);
        $version = static::validVersion($version);

        if (env('APP_ENV') === 'development') {
            $url = 'api/marketplace/modules/file/'.rawurlencode($module).'?'.http_build_query([
                'version' => $version,
                'is_dev' => 1,
            ]);
        } else {
            $url = 'api/marketplace/modules/file/'.rawurlencode($module).'?'.http_build_query([
                'version' => $version,
            ]);
        }

        $token = Setting::getSetting('api_token');
        $response = static::getRemote($url, ['timeout' => 100, 'track_redirects' => true], $token);

        // Exception
        if ($response instanceof RequestException) {
            return [
                'success' => false,
                'error' => 'Download Exception',
            ];
        }

        if (! $response || $response->getStatusCode() !== 200) {
            return [
                'success' => false,
                'error' => 'Module download failed.',
            ];
        }

        $data = $response->getBody()->getContents();
        if ($data === '') {
            return [
                'success' => false,
                'error' => 'Empty module package.',
            ];
        }

        // Create temp directory
        $temp_dir = storage_path('app/temp-'.bin2hex(random_bytes(12)));

        if (! File::isDirectory($temp_dir) && ! File::makeDirectory($temp_dir, 0755, true)) {
            return [
                'success' => false,
                'error' => 'Unable to create module directory.',
            ];
        }

        $zip_file_path = $temp_dir.'/upload.zip';

        // Add content to the Zip file
        $uploaded = file_put_contents($zip_file_path, $data, LOCK_EX) !== false;

        if (! $uploaded) {
            File::deleteDirectory($temp_dir);

            return [
                'success' => false,
                'error' => 'Unable to store module package.',
            ];
        }

        try {
            static::verifyAndMarkPackage($zip_file_path, static::expectedPackageHash($response, 'CRATER_MODULE_SHA256'));
        } catch (\Throwable $exception) {
            File::deleteDirectory($temp_dir);

            return [
                'success' => false,
                'error' => 'Module package integrity verification failed.',
            ];
        }

        return [
            'success' => true,
            'path' => static::relativeStoragePath($zip_file_path)
        ];
    }

    public static function unzip($module, $zip_file_path)
    {
        static::validModuleName($module);

        return static::extractVerifiedPackage($zip_file_path);
    }

    public static function copyFiles($module, $temp_extract_dir)
    {
        $module = static::validModuleName($module);
        $temp_extract_dir = static::assertVerifiedExtraction($temp_extract_dir);
        $source = realpath($temp_extract_dir.'/'.$module);
        if (! $source || ! is_dir($source) || ! static::isWithin($source, $temp_extract_dir)) {
            throw new RuntimeException('Module archive must contain its named module directory.');
        }

        $basePath = realpath(base_path());
        $modulesCandidate = base_path('Modules');
        if (is_link($modulesCandidate)) {
            throw new RuntimeException('Module root cannot be a symbolic link.');
        }
        if (! File::isDirectory($modulesCandidate) && ! File::makeDirectory($modulesCandidate, 0755, true)) {
            throw new RuntimeException('Unable to create modules directory.');
        }
        $modulesPath = realpath($modulesCandidate);
        if (! $basePath || ! $modulesPath || ! static::isWithin($modulesPath, $basePath)) {
            throw new RuntimeException('Module root is not inside the application root.');
        }
        $destination = $modulesPath.DIRECTORY_SEPARATOR.$module;
        if (is_link($destination)) {
            throw new RuntimeException('Module destination cannot be a symbolic link.');
        }
        if (is_dir($destination) && ! File::deleteDirectory($destination)) {
            throw new RuntimeException('Unable to replace existing module.');
        }

        if (! File::copyDirectory($source, $destination)) {
            return false;
        }

        // Delete temp directory
        File::deleteDirectory($temp_extract_dir);
        if (file_put_contents(static::moduleVerificationMarker($module), 'verified', LOCK_EX) === false) {
            throw new RuntimeException('Unable to record module verification.');
        }

        return true;
    }

    public static function deleteFiles($json)
    {
        $files = json_decode($json, true);
        $modulesRoot = realpath(base_path('Modules'));
        if (! is_array($files) || ! $modulesRoot) {
            return false;
        }

        $targets = [];
        foreach ($files as $file) {
            if (! is_string($file)) {
                return false;
            }

            $relative = static::safeRelativePath($file);
            if ($relative !== 'Modules' && strpos($relative, 'Modules/') !== 0) {
                return false;
            }

            $candidate = base_path($relative);
            if (is_link($candidate)) {
                return false;
            }

            $target = realpath($candidate);
            if (! $target || ! static::isWithin($target, $modulesRoot) || ! is_file($target)) {
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

    public static function complete($module, $version)
    {
        $module = static::validModuleName($module);
        $version = static::validVersion($version);
        $modulesRoot = realpath(base_path('Modules'));
        $modulePath = realpath(base_path('Modules/'.$module));
        if (! $modulesRoot || ! $modulePath || is_link(base_path('Modules/'.$module)) || ! is_dir($modulePath) || ! static::isWithin($modulePath, $modulesRoot) || ! is_file(static::moduleVerificationMarker($module))) {
            throw new RuntimeException('Module installation was not verified.');
        }

        Module::register();
        $installedModule = Module::find($module);
        if (! $installedModule) {
            throw new RuntimeException('Installed module was not found.');
        }

        if (Artisan::call('module:migrate', ['module' => $module, '--force' => true]) !== 0
            || Artisan::call('module:seed', ['module' => $module, '--force' => true]) !== 0
            || Artisan::call('module:enable', ['module' => $module]) !== 0) {
            throw new RuntimeException('Module activation failed.');
        }

        $module = ModelsModule::updateOrCreate(['name' => $module], ['version' => $version, 'installed' => true, 'enabled' => true]);
        File::delete(static::moduleVerificationMarker($module->name));

        ModuleInstalledEvent::dispatch($module);
        ModuleEnabledEvent::dispatch($module);

        return true;
    }

    protected static function validModuleName($module)
    {
        $module = (string) $module;
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,99}$/', $module)) {
            throw new RuntimeException('Invalid module name.');
        }

        return $module;
    }

    protected static function moduleVerificationMarker($module)
    {
        return storage_path('app/module-verified-'.hash('sha256', static::validModuleName($module)));
    }

    public static function checkToken(String $token)
    {
        $url = 'api/marketplace/ping';
        $response = static::getRemote($url, ['timeout' => 100, 'track_redirects' => true], $token);

        if ($response && ($response->getStatusCode() == 200)) {
            $data = $response->getBody()->getContents();

            return response()->json(json_decode($data));
        }

        return response()->json(['error' => 'invalid_token']);
    }
}
