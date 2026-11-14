<?php

namespace Crater\Http\Controllers\V1\Admin\Update;

use Crater\Http\Controllers\Controller;
use Crater\Space\Updater;
use Illuminate\Http\Request;

class DownloadUpdateController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        if ((! $request->user()) || (! $request->user()->isOwner())) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to update this app.'
            ], 401);
        }

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
}
