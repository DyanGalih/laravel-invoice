<?php

namespace Crater\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                Rule::unique('roles')->where('scope', $this->header('company'))
            ],
            'abilities' => [
                'required',
                'array',
            ],
            'abilities.*.ability' => [
                'required',
                Rule::in(array_column(config('abilities.abilities'), 'ability')),
            ]
        ];

        if ($this->getMethod() == 'PUT') {
            $rules['name'] = [
                'required',
                'string',
                Rule::unique('roles')
                    ->ignore($this->route('role')->id, 'id')
                    ->where('scope', $this->header('company'))
            ];
        }

        return $rules;
    }

    public function getRolePayload()
    {
        return collect($this->validated())
            ->only(['name', 'title', 'level'])
            ->merge([
                'scope' => $this->header('company'),
            ])
            ->toArray();
    }
}
