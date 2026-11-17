<?php

namespace MasterCore\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CoreNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'event_key' => $this->event_key,
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? '',
            'read_at' => $this->read_at?->utc()->toIso8601String(),
            'created_at' => $this->created_at?->utc()->toIso8601String(),
        ];
    }
}
