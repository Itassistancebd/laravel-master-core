<?php

namespace MasterCore\CrudEngine\Tests\Fixtures;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MasterCore\CrudEngine\Http\Responses\CrudResponse;
use MasterCore\CrudEngine\Query\CrudQueryBuilder;
use MasterCore\CrudEngine\Registry\CrudResourceRegistry;
use MasterCore\CrudEngine\Tests\Fixtures\Models\FixtureAccount;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Explicit test routes demonstrate how an owner keeps write and authorization behavior. */
final class FixtureAccountController
{
    public function index(Request $request, CrudResourceRegistry $registry, CrudQueryBuilder $queries): JsonResponse
    {
        Gate::authorize('view-fixture-accounts');
        $resource = $registry->get('fixture-accounts');

        return CrudResponse::collection($queries->paginate($resource, $request->query()), $resource);
    }

    public function show(string $id, CrudResourceRegistry $registry): JsonResponse
    {
        Gate::authorize('view-fixture-account');
        $resource = $registry->get('fixture-accounts');
        $record = $resource->query()->findOrFail($id);

        return CrudResponse::item($resource->serialize($record));
    }

    public function store(Request $request, CrudResourceRegistry $registry): JsonResponse
    {
        Gate::authorize('create-fixture-account');
        $input = $this->validateRecord($request, true);

        $record = DB::transaction(function () use ($input, $request): FixtureAccount {
            $record = FixtureAccount::query()->create($input);
            $this->audit('user.created', $request, $record, ['changed_fields' => ['name', 'email']]);

            return $record;
        });

        return CrudResponse::item($registry->get('fixture-accounts')->serialize($record), 201);
    }

    public function update(Request $request, string $id, CrudResourceRegistry $registry): JsonResponse
    {
        Gate::authorize('edit-fixture-account');
        $record = FixtureAccount::query()->findOrFail($id);
        Gate::authorize('edit-fixture-account-record', $record);
        $input = $this->validateRecord($request, false, $record);

        $record = DB::transaction(function () use ($input, $request, $record): FixtureAccount {
            $record->update($input);
            $this->audit('user.updated', $request, $record, ['changed_fields' => array_keys($input)]);

            return $record->refresh();
        });

        return CrudResponse::item($registry->get('fixture-accounts')->serialize($record));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $record = FixtureAccount::query()->findOrFail($id);
        Gate::authorize('delete-fixture-account-record', $record);

        DB::transaction(function () use ($record, $request): void {
            $record->delete();
            $this->audit('user.trashed', $request, $record);
        });

        return CrudResponse::message('Record moved to trash.');
    }

    public function bulkTrash(Request $request): JsonResponse
    {
        Gate::authorize('delete-fixture-accounts');
        $ids = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1', 'max:'.min(100, max(1, (int) config('master-core-crud-engine.max_bulk_ids', 100)))],
            'ids.*' => ['required', 'distinct', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_int($value) && (! is_string($value) || mb_strlen($value) > 191)) {
                    $fail('Each selected record identifier must be a short string or integer.');
                }
            }],
        ])->validate()['ids'];
        $records = FixtureAccount::query()->whereKey($ids)->get()->sortBy(
            fn (FixtureAccount $record): int => array_search((string) $record->getKey(), array_map('strval', $ids), true),
        );
        if ($records->count() !== count($ids)) {
            throw new BadRequestHttpException('One or more selected records are unavailable.');
        }

        DB::transaction(function () use ($records, $request): void {
            foreach ($records as $record) {
                Gate::authorize('delete-fixture-account-record', $record);
                $record->delete();
                $this->audit('user.trashed', $request, $record);
            }
        });

        return response()->json(['success' => true, 'data' => ['affected' => $records->count(), 'operation' => 'trash']]);
    }

    public function restore(Request $request, string $id, CrudResourceRegistry $registry): JsonResponse
    {
        $record = FixtureAccount::withTrashed()->findOrFail($id);
        if (! $record->trashed()) {
            throw new BadRequestHttpException('The record is not in trash.');
        }
        Gate::authorize('restore-fixture-account', $record);

        DB::transaction(function () use ($record, $request): void {
            $record->restore();
            $this->audit('user.restored', $request, $record);
        });

        return CrudResponse::item($registry->get('fixture-accounts')->serialize($record->refresh()));
    }

    public function forceDelete(Request $request, string $id): JsonResponse
    {
        $record = FixtureAccount::onlyTrashed()->findOrFail($id);
        Gate::authorize('force-delete-fixture-account', $record);
        $input = Validator::make($request->all(), ['confirmation' => ['required', 'string', 'in:'.$record->getKey()]])->validate();

        DB::transaction(function () use ($record, $request, $input): void {
            if ((string) $input['confirmation'] !== (string) $record->getKey()) {
                throw ValidationException::withMessages(['confirmation' => ['Confirmation does not match.']]);
            }
            $this->audit('user.permanently_deleted', $request, $record);
            $record->forceDelete();
        });

        return CrudResponse::message('Record permanently deleted.');
    }

    private function validateRecord(Request $request, bool $creating, ?FixtureAccount $record = null): array
    {
        return Validator::make($request->all(), [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => [$creating ? 'required' : 'sometimes', 'email:rfc', 'max:255', Rule::unique('phase9_crud_fixture_accounts', 'email')->ignore($record?->getKey())],
        ])->validate();
    }

    /** Dispatch a Phase 7 catalog event within the owning mutation transaction. */
    private function audit(string $name, Request $request, Model $record, array $metadata = []): void
    {
        event('master-core.audit.record.v1', [[
            'event_name' => $name,
            'actor' => $request->user(),
            'subject' => $record,
            'metadata' => $metadata,
        ]]);
    }
}
