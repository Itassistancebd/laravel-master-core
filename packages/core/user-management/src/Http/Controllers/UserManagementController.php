<?php

namespace MasterCore\UserManagement\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use MasterCore\UserManagement\Actions\UserLifecycleManager;
use MasterCore\UserManagement\Http\Requests\BulkUsersRequest;
use MasterCore\UserManagement\Http\Requests\ForceDeleteUserRequest;
use MasterCore\UserManagement\Http\Requests\ConfirmUserCsvImportRequest;
use MasterCore\UserManagement\Http\Requests\ExportUsersRequest;
use MasterCore\UserManagement\Http\Requests\ImportUserCsvRequest;
use MasterCore\UserManagement\Http\Requests\IndexUsersRequest;
use MasterCore\UserManagement\Http\Requests\StoreUserRequest;
use MasterCore\UserManagement\Http\Requests\UpdateUserRequest;
use MasterCore\UserManagement\Http\Requests\UpdateUserStatusRequest;
use MasterCore\UserManagement\Http\Resources\UserResource;
use MasterCore\UserManagement\Support\UserManagementQuery;
use MasterCore\UserManagement\Support\UserModelResolver;
use MasterCore\UserManagement\Services\UserCsvExportService;
use MasterCore\UserManagement\Services\UserCsvImportService;
use MasterCore\UserManagement\Support\UserCsvAuthorization;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class UserManagementController
{
    public function index(IndexUsersRequest $request, UserManagementQuery $users): JsonResponse
    {
        $this->authorizeAction($request, 'view');
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? config('master-core-user-management.per_page', 25));
        $page = (int) ($filters['page'] ?? 1);
        $paginator = $users->build($filters)->paginate($perPage, ['*'], 'page', $page);

        return $this->collectionResponse($request, $paginator);
    }

    public function trash(IndexUsersRequest $request, UserManagementQuery $users): JsonResponse
    {
        $this->authorizeAction($request, 'view');
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? config('master-core-user-management.per_page', 25));
        $page = (int) ($filters['page'] ?? 1);

        return $this->collectionResponse($request, $users->build($filters, true)->paginate($perPage, ['*'], 'page', $page));
    }

    public function export(ExportUsersRequest $request, UserManagementQuery $users, UserCsvExportService $csv): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $actor = $this->authenticatedActor($request);
        $this->authorizeAbility($actor, 'export-users');
        $filters = $request->validated();
        $query = $users->build($filters);
        $count = (clone $query)->count();

        event('master-core.audit.record.v1', [[
            'event_name' => 'user.exported',
            'actor' => $actor,
            'subject' => null,
            'metadata' => [
                'record_count' => $count,
                'fields' => UserCsvExportService::FIELDS,
                'filter_keys' => array_values(array_intersect(array_keys($filters), ['search', 'status', 'email_verified', 'sort_by', 'sort_direction'])),
            ],
        ]]);

        return $csv->download($query);
    }

    public function importTemplate(Request $request): Response
    {
        $this->authorizeAbility($this->authenticatedActor($request), 'import-users');

        return response("\xEF\xBB\xBF\"name\",\"email\"\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=users-import-template.csv',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function previewImport(ImportUserCsvRequest $request, UserCsvImportService $imports): JsonResponse
    {
        $actor = $this->authenticatedActor($request);
        $this->authorizeAbility($actor, 'import-users');

        return response()->json(['success' => true, 'data' => $imports->preview($request->file('file'), $actor)]);
    }

    public function import(ConfirmUserCsvImportRequest $request, UserCsvImportService $imports, UserLifecycleManager $manager): JsonResponse
    {
        $actor = $this->authenticatedActor($request);
        $this->authorizeAbility($actor, 'import-users');
        // create-users remains under the host's normal Gate (including its established
        // Super Admin decision); only the new import-users ability requires an explicit grant.
        Gate::forUser($actor)->authorize('create-users');
        $result = $imports->import($request->file('file'), $request->validated('preview_token'), $actor, $manager);

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function capabilities(Request $request, UserCsvAuthorization $csvAuthorization): JsonResponse
    {
        $abilities = config('master-core-user-management.abilities');
        $capabilities = [];
        $manage = config('master-core-user-management.abilities.manage');
        foreach ($abilities as $key => $ability) {
            $checks = array_values(array_filter([$ability, $manage], 'is_string'));
            if (in_array($key, ['export', 'import'], true)) {
                $capabilities[$key] = is_string($ability) && $request->user() instanceof Authenticatable
                    && $csvAuthorization->allows($request->user(), $ability);
                continue;
            }
            $capabilities[$key] = $checks !== [] && Gate::forUser($request->user())->any($checks);
        }

        return response()->json(['success' => true, 'data' => $capabilities]);
    }

    public function show(Request $request, string $user, UserModelResolver $models): JsonResponse
    {
        $record = $models->newQuery()->findOrFail($user);
        $this->authorizeAction($request, 'view', $record);

        return response()->json(['success' => true, 'data' => UserResource::make($record)->resolve($request)]);
    }

    public function store(StoreUserRequest $request, UserLifecycleManager $manager): JsonResponse
    {
        $this->authorizeAction($request, 'create');
        $user = $manager->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'User created. A password setup link was requested.',
            'data' => UserResource::make($user)->resolve($request),
        ], 201);
    }

    public function update(UpdateUserRequest $request, string $user, UserModelResolver $models, UserLifecycleManager $manager): JsonResponse
    {
        $record = $models->newQuery()->findOrFail($user);
        $this->authorizeAction($request, 'update', $record);
        $updated = $manager->update($record, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => UserResource::make($updated)->resolve($request)]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, string $user, UserModelResolver $models, UserLifecycleManager $manager): JsonResponse
    {
        $record = $models->newQuery()->findOrFail($user);
        $this->authorizeAction($request, 'status', $record);
        $updated = $manager->changeStatus($record, (bool) $request->validated('is_active'), $request->user());

        return response()->json(['success' => true, 'data' => UserResource::make($updated)->resolve($request)]);
    }

    public function destroy(Request $request, string $user, UserModelResolver $models, UserLifecycleManager $manager): JsonResponse
    {
        $record = $models->newQuery()->findOrFail($user);
        $this->authorizeAction($request, 'delete', $record);
        $manager->trash($record, $request->user());

        return response()->json(['success' => true, 'message' => 'User moved to trash.']);
    }

    public function restore(Request $request, string $user, UserModelResolver $models, UserLifecycleManager $manager): JsonResponse
    {
        $record = $models->newQuery()->withTrashed()->findOrFail($user);
        if (! $record->trashed()) {
            throw new BadRequestHttpException('The user is not in trash.');
        }
        $this->authorizeAction($request, 'restore', $record);
        $restored = $manager->restore($record, $request->user());

        return response()->json(['success' => true, 'data' => UserResource::make($restored)->resolve($request)]);
    }

    public function forceDelete(ForceDeleteUserRequest $request, string $user, UserModelResolver $models, UserLifecycleManager $manager): JsonResponse
    {
        $record = $models->newQuery()->onlyTrashed()->findOrFail($user);
        $this->authorizeAction($request, 'force_delete', $record);
        $request->ensureMatches($models, $user);
        $manager->forceDelete($record, $request->user());

        return response()->json(['success' => true, 'message' => 'User permanently deleted.']);
    }

    public function sendPasswordReset(Request $request, string $user, UserModelResolver $models, UserLifecycleManager $manager): JsonResponse
    {
        $record = $models->newQuery()->findOrFail($user);
        $this->authorizeAction($request, 'password_reset', $record);
        Password::broker()->sendResetLink(['email' => $record->getAttribute('email')]);
        $manager->recordAdministrativeAction('password-reset-requested', $request->user(), $record);

        return response()->json(['success' => true, 'message' => 'If the account can receive a reset link, one has been sent.'], 202);
    }

    public function resendVerification(Request $request, string $user, UserModelResolver $models, UserLifecycleManager $manager): JsonResponse
    {
        $record = $models->newQuery()->findOrFail($user);
        $this->authorizeAction($request, 'verification', $record);
        if (! $record instanceof MustVerifyEmail) {
            throw new BadRequestHttpException('The configured host user model does not support email verification.');
        }
        if (! $record->hasVerifiedEmail()) {
            $record->sendEmailVerificationNotification();
            $manager->recordAdministrativeAction('verification-resend-requested', $request->user(), $record);
        }

        return response()->json(['success' => true, 'message' => 'Verification notification requested.'], 202);
    }

    public function bulk(BulkUsersRequest $request, string $operation, UserModelResolver $models, UserLifecycleManager $manager): JsonResponse
    {
        $keys = [
            'activate' => 'status',
            'deactivate' => 'status',
            'trash' => 'delete',
            'restore' => 'restore',
            'force-delete' => 'force_delete',
        ];
        if (! isset($keys[$operation])) {
            throw new BadRequestHttpException('Unsupported bulk operation.');
        }

        $ids = $request->validated('ids');
        $query = $models->newQuery();
        if (in_array($operation, ['restore', 'force-delete'], true)) {
            $query->withTrashed();
        }
        if ($operation === 'force-delete') {
            $query->onlyTrashed();
        }
        $records = $query->whereKey($ids)->get()->keyBy(fn (Model $record) => (string) $record->getKey());
        if ($records->count() !== count($ids)) {
            throw new BadRequestHttpException('One or more selected users are unavailable for this operation.');
        }

        foreach ($records as $record) {
            if ($operation === 'restore' && ! $record->trashed()) {
                throw new BadRequestHttpException('One or more selected users are not in trash.');
            }
            $this->authorizeAction($request, $keys[$operation], $record);
            if (in_array($operation, ['deactivate', 'trash', 'force-delete'], true)) {
                $manager->assertSafe($request->user(), $record, $operation === 'force-delete' ? 'force-delete' : ($operation === 'trash' ? 'delete' : 'deactivate'));
            }
        }

        DB::transaction(function () use ($operation, $records, $request, $manager): void {
            foreach ($records as $record) {
                match ($operation) {
                    'activate' => $manager->changeStatus($record, true, $request->user()),
                    'deactivate' => $manager->changeStatus($record, false, $request->user()),
                    'trash' => $manager->trash($record, $request->user()),
                    'restore' => $manager->restore($record, $request->user()),
                    'force-delete' => $manager->forceDelete($record, $request->user()),
                };
            }
        });

        return response()->json(['success' => true, 'data' => ['affected' => $records->count(), 'operation' => $operation]]);
    }

    private function authorizeAction(Request $request, string $key, ?Model $target = null): void
    {
        $ability = config('master-core-user-management.abilities.'.$key);
        $manage = config('master-core-user-management.abilities.manage');
        $arguments = $target === null ? [] : [$target];
        $checks = array_values(array_filter([$ability, $manage], 'is_string'));
        $allowed = $checks !== [] && Gate::forUser($request->user())->any($checks, $arguments);

        if (! $allowed) {
            throw new AuthorizationException('You are not authorized to perform this user operation.');
        }
    }

    private function authenticatedActor(Request $request): Authenticatable
    {
        $actor = $request->user();
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException('Authentication is required for this user operation.');
        }

        return $actor;
    }

    private function authorizeAbility(Authenticatable $actor, string $ability): void
    {
        if (! app(UserCsvAuthorization::class)->allows($actor, $ability)) {
            throw new AuthorizationException('You are not authorized to perform this user operation.');
        }
    }

    private function collectionResponse(Request $request, LengthAwarePaginator $paginator): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())->map(fn (Model $record) => UserResource::make($record)->resolve($request))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }
}
