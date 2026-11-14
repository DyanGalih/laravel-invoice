<?php

namespace Crater\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'contact_name' => $this->contact_name,
            'company_name' => $this->company_name,
            'website' => $this->website,
            'enable_portal' => $this->enable_portal,
            'currency_id' => $this->currency_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'avatar' => $this->avatar,
            'is_owner' => $this->isOwner(),
            'roles' => collect($this->roles)->filter(function ($role) use ($request) {
                return (string) $role->scope === (string) $request->header('company');
            })->values(),
            'formatted_created_at' => $this->formattedCreatedAt,
            'currency' => $this->when($this->currency()->exists(), function () {
                return new CurrencyResource($this->currency);
            }),
            'companies' => $this->when($this->companies()->whereKey($request->header('company'))->exists(), function () use ($request) {
                return CompanyResource::collection($this->companies()->whereKey($request->header('company'))->get());
            })
        ];
    }
}
