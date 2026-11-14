<?php

namespace Crater\Http\Controllers\V1\Admin\Modules;

use Crater\Http\Controllers\Controller;
use Crater\Space\ModuleInstaller;
use Illuminate\Http\Request;

class CopyModuleController extends Controller
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
            'path' => ['required', 'string'],
        ]);

        $response = ModuleInstaller::copyFiles($request->module, $request->path);

        return response()->json([
            'success' => $response
        ]);
    }
}
