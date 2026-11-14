<?php

namespace Crater\Http\Controllers\V1\Admin\Update;

use Crater\Http\Controllers\Controller;
use Crater\Models\Setting;
use Crater\Space\Updater;
use Illuminate\Http\Request;

class UpdateController extends Controller
{
    public function download(Request $request)
    {
        $this->authorize('manage update app');

        $request->validate([
            'version' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$/'],
        ]);

        $path = Updater::download($request->version);

        if (! is_string($path)) {
            return response()->json($path, 422);
        }

        return response()->json([
            'success' => true,
            'path' => $path,
        ]);
    }

    public function unzip(Request $request)
    {
        $this->authorize('manage update app');

        $request->validate([
            'path' => ['required', 'string'],
        ]);

        try {
            $path = Updater::unzip($request->path);

            return response()->json([
                'success' => true,
                'path' => $path,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function copyFiles(Request $request)
    {
        $this->authorize('manage update app');

        $request->validate([
            'path' => ['required', 'string'],
        ]);

        $path = Updater::copyFiles($request->path);

        if ($path !== true) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to copy verified update files.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'path' => $path,
        ]);
    }

    public function migrate(Request $request)
    {
        $this->authorize('manage update app');

        Updater::migrateUpdate();

        return response()->json([
            'success' => true,
        ]);
    }

    public function finishUpdate(Request $request)
    {
        $this->authorize('manage update app');

        $request->validate([
            'installed' => 'required',
            'version' => 'required',
        ]);

        $json = Updater::finishUpdate($request->installed, $request->version);

        return response()->json($json);
    }

    public function checkLatestVersion(Request $request)
    {
        $this->authorize('manage update app');

        set_time_limit(600); // 10 minutes

        $json = Updater::checkForUpdate(Setting::getSetting('version'));

        return response()->json($json);
    }
}
