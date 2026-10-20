<?php

namespace App\Support\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

class Csv
{
    public static function number(string|float|null $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, $decimals, ',', '');
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     * @param  list<list<string>>  $preamble
     */
    public static function download(string $filename, array $header, array $rows, array $preamble = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows, $preamble) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($preamble as $line) {
                fputcsv($out, $line, ';');
            }
            if ($preamble !== []) {
                fputcsv($out, [], ';');
            }
            fputcsv($out, $header, ';');
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
