<?php

namespace Crater\Http\Requests;

use Crater\Models\Invoice;
use Crater\Rules\RelationNotExist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteInvoiceRequest extends FormRequest
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
        return [
            'ids' => [
                'required',
                'array',
                'min:1',
            ],
            'ids.*' => [
                'required',
                'integer',
                Rule::exists('invoices', 'id')
                    ->where('company_id', $this->header('company')),
                new RelationNotExist(Invoice::class, 'payments'),
            ],
        ];
    }
}
