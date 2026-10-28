<?php

namespace App\Support\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

class Excel
{
    public static function number(string|float|null $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, $decimals, ',', '.');
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     * @param  list<list<string>>  $preamble
     */
    public static function download(string $filename, array $header, array $rows, array $preamble = [], string $sheetName = 'Laporan'): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows, $preamble, $sheetName) {
            echo self::workbook($header, $rows, $preamble, $sheetName);
        }, $filename, ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     * @param  list<list<string>>  $preamble
     */
    protected static function workbook(array $header, array $rows, array $preamble, string $sheetName): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<?mso-application progid="Excel.Sheet"?>'."\n"
            .'<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            .self::styles()
            .'<Worksheet ss:Name="'.self::escape(self::sheetTitle($sheetName)).'"><Table>';

        foreach ($preamble as $line) {
            $xml .= self::row($line, 'Preamble');
        }
        if ($preamble !== []) {
            $xml .= '<Row></Row>';
        }

        $xml .= self::row($header, 'Header');

        foreach ($rows as $row) {
            $xml .= self::row($row, 'Default');
        }

        return $xml.'</Table></Worksheet></Workbook>';
    }

    protected static function styles(): string
    {
        return '<Styles>'
            .'<Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#3F3F46"/><Interior ss:Color="#F4F4F5" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#D4D4D8"/></Borders></Style>'
            .'<Style ss:ID="Default"><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E4E4E7"/></Borders></Style>'
            .'<Style ss:ID="Preamble"><Font ss:Bold="1"/></Style>'
            .'</Styles>';
    }

    /**
     * @param  list<string>  $cells
     */
    protected static function row(array $cells, string $styleId): string
    {
        $xml = '<Row>';
        foreach ($cells as $value) {
            $xml .= '<Cell ss:StyleID="'.$styleId.'"><Data ss:Type="String">'.self::escape((string) $value).'</Data></Cell>';
        }

        return $xml.'</Row>';
    }

    protected static function sheetTitle(string $sheetName): string
    {
        return mb_substr(preg_replace('/[\[\]\*\/\\\\\?:]/', ' ', $sheetName), 0, 31);
    }

    protected static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
