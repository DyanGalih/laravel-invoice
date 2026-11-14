<?php

namespace Crater\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadModuleRequest extends FormRequest
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
            'avatar' => [
                'required',
                'file',
                'mimes:zip',
                'max:20000'
            ],
            'module' => [
                'required',
                'string',
                'max:100',
                'regex:/^[A-Za-z][A-Za-z0-9_-]{0,99}$/',
            ],
            'sha256' => [
                'required',
                'regex:/^[A-Fa-f0-9]{64}$/',
            ]
        ];
    }
}
