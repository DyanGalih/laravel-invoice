<?php

namespace Crater\Http\Controllers\V1\Customer\Estimate;

use Crater\Http\Controllers\Controller;
use Crater\Http\Requests\CustomerEstimateStatusRequest;
use Crater\Http\Resources\Customer\EstimateResource;
use Crater\Models\Company;
use Crater\Models\Estimate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AcceptEstimateController extends Controller
{
    /**
    * Handle the incoming request.
    *
    * @param  \Illuminate\Http\Request  $request
    * @param  Estimate $estimate
    * @return \Illuminate\Http\Response
    */
    public function __invoke(CustomerEstimateStatusRequest $request, Company $company, $id)
    {
        $estimate = $company->estimates()
            ->whereCustomer(Auth::guard('customer')->id())
            ->where('id', $id)
            ->first();

        if (! $estimate) {
            return response()->json(['error' => 'estimate_not_found'], 404);
        }

        if (! $estimate->transitionStatus($request->validated()['status'], true)) {
            return response()->json(['error' => 'invalid_estimate_status_transition'], 422);
        }

        return new EstimateResource($estimate);
    }
}
