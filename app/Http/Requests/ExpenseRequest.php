<?php

namespace Crater\Http\Requests;

use Crater\Models\CompanySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
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
            'expense_date' => [
                'required',
                'date',
            ],
            'expense_category_id' => [
                'required',
                'integer',
                Rule::exists('expense_categories', 'id')->where('company_id', $this->header('company')),
            ],
            'exchange_rate' => [
                'nullable',
                'numeric',
                'gt:0',
            ],
            'payment_method_id' => [
                'nullable',
                'integer',
                Rule::exists('payment_methods', 'id')->where('company_id', $this->header('company')),
            ],
            'amount' => [
                'required',
                'integer',
                'min:0',
            ],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('company_id', $this->header('company')),
            ],
            'notes' => [
                'nullable',
            ],
            'currency_id' => [
                'required',
                'integer',
                Rule::exists('currencies', 'id'),
            ],
            'attachment_receipt' => [
                'nullable',
                'file',
                'mimes:jpg,png,pdf,doc,docx,xls,xlsx,ppt,pptx',
                'max:20000'
            ]
        ];

        if ($companyCurrency && $this->currency_id) {
            if ($companyCurrency !== $this->currency_id) {
                $rules['exchange_rate'] = [
                    'required',
                    'numeric',
                    'gt:0',
                ];
            };
        }

        return $rules;
    }

    public function getExpensePayload()
    {
        $company_currency = CompanySetting::getSetting('currency', $this->header('company'));
        $current_currency = $this->currency_id;
        $exchange_rate = $company_currency != $current_currency ? $this->exchange_rate : 1;

        return collect($this->validated())
            ->only(['expense_date', 'expense_category_id', 'payment_method_id', 'amount', 'customer_id', 'notes', 'currency_id'])
            ->merge([
                'creator_id' => $this->user()->id,
                'company_id' => $this->header('company'),
                'exchange_rate' => $exchange_rate,
                'base_amount' => $this->amount * $exchange_rate,
                'currency_id' => $current_currency
            ])
            ->toArray();
    }
}
