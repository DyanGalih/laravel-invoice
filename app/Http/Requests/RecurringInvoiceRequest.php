<?php

namespace Crater\Http\Requests;

use Crater\Models\CompanySetting;
use Crater\Models\Customer;
use Crater\Models\RecurringInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecurringInvoiceRequest extends FormRequest
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
        $companyCurrency = CompanySetting::getSetting('currency', $this->header('company'));

        $rules = [
            'starts_at' => [
                'required',
                'date',
            ],
            'send_automatically' => [
                'required',
                'boolean'
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
            'discount' => [
                'required',
                'numeric',
                'min:0',
            ],
            'discount_val' => [
                'sometimes',
                'numeric',
                'min:0',
            ],
            'sub_total' => [
                'sometimes',
                'integer',
                'min:0',
            ],
            'total' => [
                'sometimes',
                'integer',
                'min:0',
            ],
            'tax' => [
                'sometimes',
                'integer',
                'min:0',
            ],
            'status' => [
                'required',
                Rule::in([
                    RecurringInvoice::COMPLETED,
                    RecurringInvoice::ON_HOLD,
                    RecurringInvoice::ACTIVE,
                ]),
            ],
            'frequency' => [
                'required',
                'string',
            ],
            'limit_by' => [
                'required',
                Rule::in([
                    RecurringInvoice::NONE,
                    RecurringInvoice::COUNT,
                    RecurringInvoice::DATE,
                ]),
            ],
            'limit_count' => [
                'required_if:limit_by,COUNT',
                'nullable',
                'integer',
                'min:1',
            ],
            'limit_date' => [
                'required_if:limit_by,DATE',
                'nullable',
                'date',
            ],
            'items' => [
                'required',
                'array',
            ],
            'items.*' => [
                'required',
                'array',
            ],
            'items.*.item_id' => [
                'nullable',
                'integer',
                Rule::exists('items', 'id')->where('company_id', $this->header('company')),
            ],
            'items.*.name' => ['required', 'string'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'items.*.taxes' => ['nullable', 'array'],
            'items.*.taxes.*.tax_type_id' => [
                'required_with:items.*.taxes.*',
                'integer',
                Rule::exists('tax_types', 'id')->where('company_id', $this->header('company')),
            ],
            'taxes' => ['nullable', 'array'],
            'taxes.*.tax_type_id' => [
                'required_with:taxes.*',
                'integer',
                Rule::exists('tax_types', 'id')->where('company_id', $this->header('company')),
            ],
            'currency_id' => [
                'nullable',
                'integer',
                Rule::exists('currencies', 'id'),
            ]
        ];

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

        return  $rules;
    }

    public function getRecurringInvoicePayload()
    {
        $company_currency = CompanySetting::getSetting('currency', $this->header('company'));
        $current_currency = $this->currency_id;
        $exchange_rate = $company_currency != $current_currency ? $this->exchange_rate : 1;
        $currency = Customer::where('company_id', $this->header('company'))
            ->findOrFail($this->customer_id)
            ->currency_id;

        $nextInvoiceAt = RecurringInvoice::getNextInvoiceDate($this->frequency, $this->starts_at);

        return collect($this->validated())
            ->only([
                'starts_at',
                'send_automatically',
                'customer_id',
                'discount',
                'status',
                'frequency',
                'limit_by',
                'limit_count',
                'limit_date',
                'currency_id',
            ])
            ->merge([
                'creator_id' => $this->user()->id,
                'company_id' => $this->header('company'),
                'next_invoice_at' => $nextInvoiceAt,
                'tax_per_item' => CompanySetting::getSetting('tax_per_item', $this->header('company')) ?? 'NO ',
                'discount_per_item' => CompanySetting::getSetting('discount_per_item', $this->header('company')) ?? 'NO',
                'due_amount' => 0,
                'exchange_rate' => $exchange_rate,
                'currency_id' => $currency
            ])
            ->toArray();
    }
}
