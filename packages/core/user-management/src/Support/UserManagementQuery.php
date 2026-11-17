<?php

namespace MasterCore\UserManagement\Support;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class UserManagementQuery
{
    public function __construct(private readonly UserModelResolver $models) {}

    public function build(array $filters, bool $trashed = false): Builder
    {
        $query = $this->models->newQuery();
        $model = $query->getModel();

        if (! $this->models->supportsSoftDeletes($model)) {
            throw new InvalidArgumentException('The configured host user model must use Laravel SoftDeletes.');
        }

        $query = $trashed ? $query->onlyTrashed() : $query;

        if (! empty($filters['search'])) {
            $term = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(function (Builder $search) use ($term): void {
                $search->where('name', 'like', $term)->orWhere('email', 'like', $term);
            });
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('is_active', $filters['status'] === 'active');
        }

        if (($filters['email_verified'] ?? null) !== null) {
            $filters['email_verified'] === 'verified'
                ? $query->whereNotNull('email_verified_at')
                : $query->whereNull('email_verified_at');
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $direction = $filters['sort_direction'] ?? 'desc';
        $allowed = ['name', 'email', 'email_verified_at', 'is_active', 'created_at', 'deleted_at'];
        if (! in_array($sortBy, $allowed, true) || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Unsupported user list sorting requested.');
        }

        return $query->orderBy($sortBy, $direction)->orderBy($model->getKeyName());
    }
}
