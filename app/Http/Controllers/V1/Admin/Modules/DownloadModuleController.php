<?php

namespace Crater\Http\Controllers\V1\Admin\Modules;

use Crater\Http\Controllers\Controller;
use Crater\Space\ModuleInstaller;
use Illuminate\Http\Request;

class DownloadModuleController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        $this->authorize('manage modules');

        $request->validate([
            'module' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_-]{0,99}$/'],
            'version' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$/'],
        ]);

        $response = ModuleInstaller::download($request->module, $request->version);

        return response()->json($response);
    }
}
