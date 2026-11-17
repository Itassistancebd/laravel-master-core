<?php

namespace MasterCore\AuditLogs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class IndexAuditEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'event_name' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'in:authentication,user_management,roles_permissions,settings'],
            'outcome' => ['nullable', 'in:succeeded,failed,denied'],
            'actor_id' => ['nullable', 'string', 'max:191'],
            'subject_id' => ['nullable', 'string', 'max:191'],
            'correlation_id' => ['nullable', 'regex:/^[A-Za-z0-9._-]{1,64}$/'],
            'sort' => ['nullable', 'in:occurred_at'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function passedValidation(): void
    {
        if ($this->filled('from') || $this->filled('to')) {
            $to = $this->date('to') ?? now();
            $from = $this->date('from') ?? $to->copy()->subDays((int) config('master-core-audit-logs.max_date_range_days', 90));
            if ($from->greaterThan($to)) {
                throw ValidationException::withMessages(['from' => ['The start date must not be after the end date.']]);
            }
            $days = $from->diffInDays($to);
            if ($days > (int) config('master-core-audit-logs.max_date_range_days', 90)) {
                throw ValidationException::withMessages(['to' => ['The requested audit date range is too large.']]);
            }
        }
    }
}
