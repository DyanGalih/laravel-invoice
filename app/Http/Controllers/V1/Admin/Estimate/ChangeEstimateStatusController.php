<?php

namespace Crater\Http\Controllers\V1\Admin\Estimate;

use Crater\Http\Controllers\Controller;
use Crater\Models\Estimate;
use Illuminate\Http\Request;

class ChangeEstimateStatusController extends Controller
{
    /**
    * Handle the incoming request.
    *
    * @param  \Illuminate\Http\Request  $request
    * @param  Estimate $estimate
    * @return \Illuminate\Http\Response
    */
    public function __invoke(Request $request, Estimate $estimate)
    {
        $this->authorize('send estimate', $estimate);

        $newStatus = (string) $request->input('status');
        if (! $estimate->canTransitionTo($newStatus)) {
            return response()->json([
                'error' => 'invalid_status_transition',
                'message' => "Cannot transition estimate from {$estimate->status} to {$newStatus}.",
            ], 422);
        }

        $estimate->update(['status' => $newStatus]);

        return response()->json([
            'success' => true,
        ]);
    }
}
