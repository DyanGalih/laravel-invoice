<?php

namespace Crater\Http\Controllers\V1\Admin\Estimate;

use Crater\Http\Controllers\Controller;
use Crater\Models\Estimate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    Estimate::STATUS_SENT,
                    Estimate::STATUS_ACCEPTED,
                    Estimate::STATUS_REJECTED,
                ]),
            ],
        ]);

        if (! $estimate->transitionStatus($validated['status'])) {
            return response()->json(['error' => 'invalid_estimate_status_transition'], 422);
        }

        return response()->json([
            'success' => true,
        ]);
    }
}
