<?php

namespace Crater\Http\Requests;

use Crater\Models\CompanySetting;
use Crater\Models\Customer;
use Crater\Models\Estimate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstimatesRequest extends FormRequest
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
            'estimate_date' => [
                'required',
                'date',
            ],
            'expiry_date' => [
                'nullable',
                'date',
            ],
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('company_id', $this->header('company')),
            ],
            'estimate_number' => [
                'required',
                Rule::unique('estimates')->where('company_id', $this->header('company'))
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
            'template_name' => [
                'required',
                'string',
                'max:100',
            ],
            'currency_id' => [
                'required',
                'integer',
                Rule::exists('currencies', 'id'),
            ],
            'reference_number' => [
                'nullable',
                'string',
                'max:255',
            ],
            'notes' => [
                'nullable',
                'string',
            ],
            'discount_type' => [
                'nullable',
                Rule::in(['fixed', 'percentage']),
            ],
            'sales_tax_type' => [
                'nullable',
                'string',
            ],
            'sales_tax_address_type' => [
                'nullable',
                'string',
            ],
            'items' => [
                'required',
                'array',
            ],
            'items.*.description' => [
                'nullable',
            ],
            'items.*' => [
                'required',
                'array',
            ],
            'items.*.name' => [
                'required',
                'string',
            ],
            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
            ],
            'items.*.price' => [
                'required',
                'numeric',
                'min:0',
            ],
            'items.*.discount' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'items.*.discount_type' => [
                'nullable',
                Rule::in(['fixed', 'percentage']),
            ],
            'items.*.item_id' => [
                'nullable',
                'integer',
                Rule::exists('items', 'id')->where('company_id', $this->header('company')),
            ],
            'taxes' => [
                'nullable',
                'array',
            ],
            'taxes.*' => [
                'array',
            ],
            'taxes.*.tax_type_id' => [
                'required_with:taxes.*',
                'integer',
                Rule::exists('tax_types', 'id')->where('company_id', $this->header('company')),
            ],
            'items.*.taxes' => [
                'nullable',
                'array',
            ],
            'items.*.taxes.*.tax_type_id' => [
                'required_with:items.*.taxes.*',
                'integer',
                Rule::exists('tax_types', 'id')->where('company_id', $this->header('company')),
            ],
        ];

        $companyCurrency = CompanySetting::getSetting('currency', $this->header('company'));

        $customer = Customer::where('company_id', $this->header('company'))->find($this->customer_id);

        if ($companyCurrency && $customer) {
            if ((string)$customer->currency_id !== $companyCurrency) {
                $rules['exchange_rate'] = [
                    'required',
                    'numeric',
                    'gt:0',
                ];
            };
        }

        if ($this->isMethod('PUT')) {
            $rules['estimate_number'] = [
                'required',
                Rule::unique('estimates')
                    ->ignore($this->route('estimate')->id)
                    ->where('company_id', $this->header('company')),
            ];
        }

        return $rules;
    }

    public function getEstimatePayload()
    {
        $input = $this->validator ? $this->validated() : $this->all();

        $companyId = $this->header('company') ?: ($input['company_id'] ?? null);
        $customerId = $input['customer_id'] ?? $this->customer_id;
        $company_currency = CompanySetting::getSetting('currency', $companyId);
        $current_currency = $input['currency_id'] ?? $this->currency_id;
        $exchange_rate = $company_currency != $current_currency ? $this->exchange_rate : 1;
        $customer = Customer::where('company_id', $companyId)->findOrFail($customerId);

        return collect($input)
            ->only([
                'estimate_date',
                'expiry_date',
                'estimate_number',
                'customer_id',
                'reference_number',
                'notes',
                'discount_type',
                'discount',
                'template_name',
                'currency_id',
                'sales_tax_type',
                'sales_tax_address_type',
            ])
            ->merge([
                'creator_id' => $this->user()->id ?? null,
                'status' => $this->has('estimateSend') ? Estimate::STATUS_SENT : Estimate::STATUS_DRAFT,
                'company_id' => $companyId,
                'tax_per_item' => CompanySetting::getSetting('tax_per_item', $companyId) ?? 'NO ',
                'discount_per_item' => CompanySetting::getSetting('discount_per_item', $companyId) ?? 'NO',
                'exchange_rate' => $exchange_rate,
                'base_discount_val' => 0,
                'base_sub_total' => 0,
                'base_total' => 0,
                'base_tax' => 0,
                'currency_id' => $customer->currency_id,
            ])
            ->toArray();
    }
}
