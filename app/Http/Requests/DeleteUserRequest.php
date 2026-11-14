<?php

namespace Crater\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteUserRequest extends FormRequest
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
            'users' => [
                'required',
                'array',
                'min:1',
            ],
            'users.*' => [
                'required',
                'integer',
                Rule::exists('user_company', 'user_id')
                    ->where('company_id', $this->header('company')),
            ],
        ];
    }
}
