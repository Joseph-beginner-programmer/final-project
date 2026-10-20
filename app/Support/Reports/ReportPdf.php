<?php

namespace App\Support\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class ReportPdf
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function download(string $view, array $data, string $filename, string $orientation = 'landscape'): Response
    {
        return Pdf::loadView($view, [
            ...$data,
            'company' => config('company.name'),
            'logo' => self::logo(),
            'generatedAt' => now(),
            'generatedBy' => auth()->user()?->name,
        ])->setPaper('a4', $orientation)->setOption('isFontSubsettingEnabled', true)->download($filename);
    }

    protected static function logo(): ?string
    {
        $path = config('company.logo') ? public_path(config('company.logo')) : null;

        if (! $path || ! is_file($path)) {
            return null;
        }

        return 'data:'.mime_content_type($path).';base64,'.base64_encode(file_get_contents($path));
    }
}
