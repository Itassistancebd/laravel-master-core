<?php

namespace MasterCore\AuditLogs\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_name' => $this->event_name,
            'category' => $this->category,
            'outcome' => $this->outcome,
            'source' => $this->source,
            'actor' => $this->actor_id === null ? null : ['type' => $this->actor_type, 'id' => $this->actor_id],
            'subject' => $this->subject_id === null ? null : ['type' => $this->subject_type, 'id' => $this->subject_id],
            'correlation_id' => $this->correlation_id,
            'request_method' => $this->request_method,
            'route_name' => $this->route_name,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'before_values' => $this->before_values,
            'after_values' => $this->after_values,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurred_at?->utc()->toIso8601String(),
            'created_at' => $this->created_at?->utc()->toIso8601String(),
        ];
    }
}
