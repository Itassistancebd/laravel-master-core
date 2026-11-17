<?php

namespace MasterCore\UserManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserResource extends JsonResource
{
    /** Return only fields approved for authenticated User Management responses. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->getAttribute('name'),
            'email' => $this->resource->getAttribute('email'),
            'email_verified_at' => $this->resource->getAttribute('email_verified_at'),
            'is_active' => (bool) $this->resource->getAttribute('is_active'),
            'created_at' => $this->resource->getAttribute('created_at'),
            'deleted_at' => $this->resource->getAttribute('deleted_at'),
        ];
    }
}
