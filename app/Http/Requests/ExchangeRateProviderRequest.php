<?php

namespace Crater\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExchangeRateProviderRequest extends FormRequest
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
            'driver' => [
                'required',
                Rule::in([
                    'currency_freak',
                    'currency_layer',
                    'open_exchange_rate',
                    'currency_converter',
                ]),
            ],
            'key' => [
                'required',
                'string',
            ],
            'currencies' => [
                'nullable',
                'array',
            ],
            'currencies.*' => [
                'nullable',
                'string',
            ],
            'driver_config' => [
                'nullable',
                'array',
            ],
            'driver_config.type' => [
                'nullable',
                'string',
                Rule::in(['PREMIUM', 'PREPAID', 'FREE', 'DEDICATED']),
            ],
            'driver_config.url' => [
                'nullable',
                'url',
                'starts_with:https://',
                'required_if:driver_config.type,DEDICATED',
            ],
            'active' => [
                'nullable',
                'boolean'
            ],
        ];

        return $rules;
    }

    public function getExchangeRateProviderPayload()
    {
        return collect($this->validated())
            ->merge([
                'company_id' => $this->header('company')
            ])
            ->toArray();
    }
}
