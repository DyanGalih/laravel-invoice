<?php

namespace Crater\Http\Requests;

use Crater\Models\CompanySetting;
use Crater\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            'payment_date' => [
                'required',
            ],
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('company_id', $this->header('company')),
            ],
            'exchange_rate' => [
                'nullable',
                'numeric',
                'gt:0',
            ],
            'amount' => [
                'required',
                'integer',
                'min:0',
            ],
            'payment_number' => [
                'required',
                Rule::unique('payments')->where('company_id', $this->header('company'))
            ],
            'invoice_id' => [
                'nullable',
                'integer',
                Rule::exists('invoices', 'id')->where('company_id', $this->header('company')),
            ],
            'payment_method_id' => [
                'nullable',
                'integer',
                Rule::exists('payment_methods', 'id')->where('company_id', $this->header('company')),
            ],
            'notes' => [
                'nullable',
            ],
        ];

        if ($this->isMethod('PUT')) {
            $rules['payment_number'] = [
                'required',
                Rule::unique('payments')
                    ->ignore($this->route('payment')->id)
                    ->where('company_id', $this->header('company')),
            ];
        }

        $companyCurrency = CompanySetting::getSetting('currency', $this->header('company'));

        $customer = Customer::where('company_id', $this->header('company'))->find($this->customer_id);

        if ($customer && $companyCurrency) {
            if ((string)$customer->currency_id !== $companyCurrency) {
                $rules['exchange_rate'] = [
                    'required',
                    'numeric',
                    'gt:0',
                ];
            };
        }

        return $rules;
    }

    public function getPaymentPayload()
    {
        $company_currency = CompanySetting::getSetting('currency', $this->header('company'));
        $current_currency = $this->currency_id;
        $exchange_rate = $company_currency != $current_currency ? $this->exchange_rate : 1;
        $currency = Customer::where('company_id', $this->header('company'))
            ->findOrFail($this->customer_id)
            ->currency_id;

        return collect($this->validated())
            ->only(['payment_date', 'customer_id', 'amount', 'payment_number', 'invoice_id', 'payment_method_id', 'notes'])
            ->merge([
                'creator_id' => $this->user()->id,
                'company_id' => $this->header('company'),
                'exchange_rate' => $exchange_rate,
                'base_amount' => $this->amount * $exchange_rate,
                'currency_id' => $currency
            ])
            ->toArray();
    }
}
