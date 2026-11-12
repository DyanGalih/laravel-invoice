<?php

namespace Crater\Http\Controllers\V1\Customer;

use Crater\Http\Controllers\Controller;
use Crater\Http\Resources\PaymentResource;
use Crater\Models\EmailLog;
use Crater\Models\Payment;
use Illuminate\Http\Request;

class PaymentPdfController extends Controller
{
    public function getPdf(EmailLog $emailLog, Request $request)
    {
        abort_unless($emailLog->hasMailableType(Payment::class), 403, 'Invalid document link.');

        if (! $emailLog->isExpired()) {
            return $emailLog->mailable->getGeneratedPDFOrStream('payment');
        }

        abort(403, 'Link Expired.');
    }

    public function getPayment(EmailLog $emailLog)
    {
        abort_unless($emailLog->hasMailableType(Payment::class), 403, 'Invalid document link.');

        $payment = Payment::find($emailLog->mailable_id);

        return new PaymentResource($payment);
    }
}
