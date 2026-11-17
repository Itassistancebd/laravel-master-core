<?php

namespace MasterCore\UserManagement\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class UserCsvExportService
{
    /** @var list<string> */
    public const FIELDS = ['id', 'name', 'email', 'email_verified_at', 'is_active', 'created_at'];

    public function download(Builder $query): StreamedResponse
    {
        return response()->streamDownload(static function () use ($query): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, self::FIELDS, ',', '"', '', "\r\n");
            foreach ($query->cursor() as $user) {
                $values = [];
                foreach (self::FIELDS as $field) {
                    $value = $user->getAttribute($field);
                    if ($value instanceof DateTimeInterface) {
                        $value = $value->format('Y-m-d H:i:s');
                    } elseif (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    }
                    $values[] = self::protectFormula((string) ($value ?? ''));
                }
                fputcsv($output, $values, ',', '"', '', "\r\n");
            }
            fclose($output);
        }, 'users-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function protectFormula(string $value): string
    {
        return preg_match('/^[\t\r\n ]*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }
}
