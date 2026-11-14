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
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;
use ZipArchive;

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
        $data = null;
        if (env('APP_ENV') === 'development') {
            $url = 'api/marketplace/modules/'.$module.'?is_dev=1';
        } else {
            $url = 'api/marketplace/modules/'.$module;
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
        // Create a unique working directory
        $temp_dir = storage_path('app/working-'.Str::uuid()->toString());

        if (! File::isDirectory($temp_dir)) {
            File::makeDirectory($temp_dir);
        }

        $path = $request->file('avatar')->storeAs(
            'working-'.Str::uuid()->toString(),
            $request->module.'.zip',
            'local'
        );

        return $path;
    }

    public static function download($module, $version)
    {
        $data = null;
        $path = null;

        if (env('APP_ENV') === 'development') {
            $url = "api/marketplace/modules/file/{$module}?version={$version}&is_dev=1";
        } else {
            $url = "api/marketplace/modules/file/{$module}?version={$version}";
        }

        $token = Setting::getSetting('api_token');
        $response = static::getRemote($url, ['timeout' => 100, 'track_redirects' => true], $token);

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

        if ($response && ($response->getStatusCode() == 401 || $response->getStatusCode() == 404 || $response->getStatusCode() == 500)) {
            return json_decode($response->getBody()->getContents());
        }

        if ($response && ($response->getStatusCode() == 200)) {
            $data = $response->getBody()->getContents();
        }

        // Create a unique working directory
        $temp_dir = storage_path('app/working-'.Str::uuid()->toString());

        if (! File::isDirectory($temp_dir)) {
            File::makeDirectory($temp_dir);
        }

        $zip_file_path = $temp_dir.'/upload.zip';

        // Add content to the Zip file
        $uploaded = is_int(file_put_contents($zip_file_path, $data)) ? true : false;

        if (! $uploaded) {
            return false;
        }

        return [
            'success' => true,
            'path' => $zip_file_path
        ];
    }

    public static function unzip($module, $zip_file_path)
    {
        if (! file_exists($zip_file_path)) {
            throw new \Exception('Zip file not found');
        }

        $temp_extract_dir = storage_path('app/extract-'.Str::uuid()->toString());

        static::safeExtractArchive($zip_file_path, $temp_extract_dir);

        // Delete zip file
        File::delete($zip_file_path);

        return $temp_extract_dir;
    }

    public static function copyFiles($module, $temp_extract_dir)
    {
        $module = static::validVersion($module);
        $moduleRoot = base_path('Modules');
        if (! File::isDirectory($moduleRoot)) {
            File::makeDirectory($moduleRoot, 0755, true);
        }

        $realModuleRoot = realpath($moduleRoot);
        $destination = $realModuleRoot.'/'.$module;

        // Delete Existing Module directory
        if (File::isDirectory($destination)) {
            File::deleteDirectory($destination);
        }

        if (! File::copyDirectory($temp_extract_dir, $destination)) {
            return false;
        }

        // Delete the working directory
        File::deleteDirectory($temp_extract_dir);

        return true;
    }

    public static function deleteFiles($json)
    {
        $files = json_decode($json);
        $moduleRoot = realpath(base_path('Modules'));

        foreach ($files as $file) {
            $relativePath = static::safeRelativePath($file);
            $target = realpath($moduleRoot.'/'.$relativePath);
            if ($target && static::isWithin($target, $moduleRoot)) {
                \File::delete($target);
            }
        }

        return true;
    }

    public static function complete($module, $version)
    {
        Module::register();

        Artisan::call("module:migrate $module --force");
        Artisan::call("module:seed $module --force");
        Artisan::call("module:enable $module");

        $module = ModelsModule::updateOrCreate(['name' => $module], ['version' => $version, 'installed' => true, 'enabled' => true]);

        ModuleInstalledEvent::dispatch($module);
        ModuleEnabledEvent::dispatch($module);

        return true;
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
