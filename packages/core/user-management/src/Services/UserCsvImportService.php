<?php

namespace MasterCore\UserManagement\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use MasterCore\UserManagement\Actions\UserLifecycleManager;
use MasterCore\UserManagement\Support\UserModelResolver;
use Throwable;

final class UserCsvImportService
{
    public const MAX_ROWS = 1000;

    private const MAX_BYTES = 2 * 1024 * 1024;

    private const TOKEN_TTL_MINUTES = 15;

    public function __construct(private readonly UserModelResolver $models) {}

    /** @return array{preview_token:string,expires_at:string,summary:array<string,int>,rows:list<array<string,mixed>>} */
    public function preview(UploadedFile $file, Authenticatable $actor): array
    {
        $parsed = $this->parse($file);
        $rows = $this->classify($parsed);
        $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);
        $token = Crypt::encryptString(json_encode([
            'actor' => (string) $actor->getAuthIdentifier(),
            'sha256' => $this->fileHash($file),
            'expires_at' => $expiresAt->timestamp,
        ], JSON_THROW_ON_ERROR));

        return [
            'preview_token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'summary' => $this->counts($rows),
            'rows' => $this->publicRows($rows),
        ];
    }

    /** @return array{imported:int,skipped:int,failed:int,rows:list<array<string,mixed>>} */
    public function import(UploadedFile $file, string $token, Authenticatable $actor, UserLifecycleManager $lifecycle): array
    {
        $this->assertPreviewToken($file, $token, $actor);
        $rows = $this->classify($this->parse($file));
        $counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0];

        DB::transaction(function () use (&$rows, &$counts, $actor, $lifecycle): void {
            foreach ($rows as &$row) {
                if ($row['status'] !== 'valid') {
                    $counts[$row['status']]++;
                    continue;
                }

                try {
                    DB::transaction(function () use ($row, $actor, $lifecycle): void {
                        if ($this->emailExists((string) $row['attributes']['email'], lock: true)) {
                            throw new DuplicateImportEmail;
                        }
                        $lifecycle->createImported($row['attributes'], $actor);
                    });
                    $row['status'] = 'imported';
                    $counts['imported']++;
                } catch (DuplicateImportEmail) {
                    $row['status'] = 'skipped';
                    $row['errors'] = ['Email already exists.'];
                    $counts['skipped']++;
                } catch (QueryException $exception) {
                    if ($this->emailExists((string) $row['attributes']['email'], lock: true)) {
                        $row['status'] = 'skipped';
                        $row['errors'] = ['Email already exists.'];
                        $counts['skipped']++;
                    } else {
                        $row['status'] = 'failed';
                        $row['errors'] = ['The row could not be imported because a database constraint was violated.'];
                        $counts['failed']++;
                    }
                }
            }
            unset($row);

            event('master-core.audit.record.v1', [[
                'event_name' => 'user.import.completed',
                'actor' => $actor,
                'subject' => null,
                'metadata' => [
                    'imported_rows' => $counts['imported'],
                    'skipped_rows' => $counts['skipped'],
                    'failed_rows' => $counts['failed'],
                ],
            ]]);
        });

        return $counts + ['rows' => $this->publicRows($rows)];
    }

    /** @return list<array{row:int,name:string,email:string,status:string,errors:list<string>,attributes?:array{name:string,email:string}}} */
    private function parse(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => ['The CSV file is invalid or exceeds the 2 MB limit.']]);
        }

        $path = $file->getRealPath();
        $contents = is_string($path) ? file_get_contents($path) : false;
        if (! is_string($contents) || ! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => ['The CSV file must be valid UTF-8.']]);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['The CSV file could not be read.']]);
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (! is_array($header)) {
                throw ValidationException::withMessages(['file' => ['The CSV file is empty.']]);
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $normalizedHeader = array_map(static fn ($value): string => strtolower(trim((string) $value)), $header);
            if (count($normalizedHeader) !== 2 || count(array_unique($normalizedHeader)) !== 2 || array_diff($normalizedHeader, ['name', 'email']) !== []) {
                throw ValidationException::withMessages(['file' => ['CSV headers must contain exactly name and email.']]);
            }

            $indexes = array_flip($normalizedHeader);
            $rows = [];
            $recordNumber = 1;
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $recordNumber++;
                if ($recordNumber - 1 > self::MAX_ROWS) {
                    throw ValidationException::withMessages(['file' => ['The CSV file cannot contain more than 1,000 data rows.']]);
                }

                $blank = count($cells) === 1 && ($cells[0] === null || trim((string) $cells[0]) === '');
                if ($blank) {
                    $rows[] = ['row' => $recordNumber, 'name' => '', 'email' => '', 'email_valid' => false, 'status' => 'skipped', 'errors' => ['Blank row.']];
                    continue;
                }
                if (count($cells) !== 2) {
                    $rows[] = ['row' => $recordNumber, 'name' => '', 'email' => '', 'email_valid' => false, 'status' => 'failed', 'errors' => ['Each row must contain exactly two columns.']];
                    continue;
                }

                $name = trim((string) ($cells[$indexes['name']] ?? ''));
                $email = mb_strtolower(trim((string) ($cells[$indexes['email']] ?? '')));
                $validation = Validator::make(compact('name', 'email'), [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'string', 'email:rfc', 'max:255'],
                ]);
                $valid = $validation->passes();
                $messages = $validation->errors();
                $errors = $valid ? [] : collect($messages->all())->values()->all();
                $rows[] = [
                    'row' => $recordNumber,
                    'name' => $name,
                    'email' => $email,
                    'email_valid' => ! $messages->has('email'),
                    'status' => $errors === [] ? 'valid' : 'failed',
                    'errors' => $errors,
                    ...($errors === [] ? ['attributes' => compact('name', 'email')] : []),
                ];
            }

            if ($rows === []) {
                throw ValidationException::withMessages(['file' => ['The CSV file must contain at least one data row.']]);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function classify(array $rows): array
    {
        $seen = [];
        $emails = [];
        foreach ($rows as &$row) {
            if (! $row['email_valid'] || $row['email'] === '') {
                continue;
            }
            $email = $row['email'];
            if (isset($seen[$email])) {
                if ($row['status'] === 'valid') {
                    $row['status'] = 'skipped';
                    $row['errors'] = ['Email appears more than once in this file.'];
                } else {
                    $row['errors'][] = 'Email appears more than once in this file.';
                }
                unset($row['attributes']);
                continue;
            }
            $seen[$email] = true;
            if ($row['status'] === 'valid') {
                $emails[] = $email;
            }
        }
        unset($row);

        $existing = $this->existingEmails($emails);
        foreach ($rows as &$row) {
            if ($row['status'] === 'valid' && isset($existing[$row['attributes']['email']])) {
                $row['status'] = 'skipped';
                $row['errors'] = ['Email already exists.'];
                unset($row['attributes']);
            }
        }
        unset($row);

        return $rows;
    }

    /** @param list<string> $emails @return array<string,true> */
    private function existingEmails(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $query = $this->models->newQuery();
        $model = $query->getModel();
        if ($this->models->supportsSoftDeletes($model)) {
            $query->withTrashed();
        }
        $column = $model->getConnection()->getQueryGrammar()->wrap($model->qualifyColumn('email'));

        return $query->whereIn(DB::raw('LOWER('.$column.')'), array_values(array_unique($emails)))
            ->pluck('email')
            ->mapWithKeys(static fn ($email): array => [mb_strtolower((string) $email) => true])
            ->all();
    }

    private function emailExists(string $email, bool $lock = false): bool
    {
        $query = $this->models->newQuery();
        $model = $query->getModel();
        if ($this->models->supportsSoftDeletes($model)) {
            $query->withTrashed();
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $column = $model->getConnection()->getQueryGrammar()->wrap($model->qualifyColumn('email'));

        return $query->whereRaw('LOWER('.$column.') = ?', [$email])->exists();
    }

    private function assertPreviewToken(UploadedFile $file, string $token, Authenticatable $actor): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['preview_token' => ['The preview token is invalid or expired. Run preview again.']]);
        }

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['preview_token' => ['The preview token is invalid or expired. Run preview again.']]);
        }
        $expiresAt = filter_var($payload['expires_at'] ?? null, FILTER_VALIDATE_INT);
        if (! is_string($payload['actor'] ?? null)
            || ! hash_equals((string) $actor->getAuthIdentifier(), $payload['actor'])
            || ! is_string($payload['sha256'] ?? null)
            || ! hash_equals($payload['sha256'], $this->fileHash($file))
            || $expiresAt === false
            || Carbon::createFromTimestamp($expiresAt)->isPast()) {
            throw ValidationException::withMessages(['preview_token' => ['The preview token is invalid, expired, or does not match this file. Run preview again.']]);
        }
    }

    private function fileHash(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $hash = is_string($path) ? hash_file('sha256', $path) : false;
        if (! is_string($hash)) {
            throw ValidationException::withMessages(['file' => ['The CSV file could not be verified.']]);
        }

        return $hash;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private function counts(array $rows): array
    {
        return [
            'total_rows' => count($rows),
            'valid' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'valid')),
            'skipped' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'skipped')),
            'failed' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'failed')),
        ];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function publicRows(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'row' => $row['row'],
            'name' => $row['name'],
            'email' => $row['email'],
            'status' => $row['status'],
            'errors' => $row['errors'],
        ], $rows);
    }
}

final class DuplicateImportEmail extends \RuntimeException {}
