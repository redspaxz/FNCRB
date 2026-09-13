<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Report export engine: CSV and XLSX (Office Open XML, dependency-free via
 * ZipArchive) with SHA-256 integrity checksum for "signed" delivery.
 */
final class Export
{
    /** Stream a CSV download. $rows: array of assoc arrays; keys of row 0 = header. */
    public static function csv(string $filename, array $rows, array $headers = []): never
    {
        $out = fopen('php://output', 'w');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "﻿"; // UTF-8 BOM so Excel decodes accents correctly
        if ($headers) fputcsv($out, $headers);
        foreach ($rows as $r) {
            if (!$headers && !isset($done)) { fputcsv($out, array_keys($r)); $done = true; }
            fputcsv($out, array_values($r));
        }
        fclose($out);
        self::auditExport($filename, count($rows));
        exit;
    }

    /** Build a minimal, valid XLSX (one sheet, inline strings) and return the bytes. */
    public static function buildXlsx(string $sheetTitle, array $headers, array $rows): string
    {
        $cells = '';
        $col = 0;
        foreach ($headers as $h) { $cells .= self::cell($col++, 0, (string)$h, true); }
        foreach ($rows as $r => $row) {
            $col = 0;
            foreach ($row as $v) {
                $cells .= is_int($v) || is_float($v)
                    ? self::numCell($col++, $r + 1, $v)
                    : self::cell($col++, $r + 1, (string)$v, false);
            }
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $cells . '</sheetData></worksheet>';
        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars($sheetTitle, ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();
        $bytes = file_get_contents($tmp);
        unlink($tmp);
        return $bytes;
    }

    /** Stream an XLSX download with SHA-256 integrity header. */
    public static function xlsx(string $filename, string $sheetTitle, array $headers, array $rows): never
    {
        $bytes = self::buildXlsx($sheetTitle, $headers, $rows);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Report-SHA256: ' . hash('sha256', $bytes));
        echo $bytes;
        self::auditExport($filename, count($rows));
        exit;
    }

    private static function cell(int $col, int $row, string $value, bool $bold): string
    {
        $ref = self::ref($col, $row);
        $style = $bold ? ' s="1"' : '';
        return '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
            . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
    }

    private static function numCell(int $col, int $row, int|float $value): string
    {
        return '<c r="' . self::ref($col, $row) . '"><v>' . $value . '</v></c>';
    }

    private static function ref(int $col, int $row): string
    {
        $s = '';
        for ($i = $col; $i >= 0; $i = intdiv($i, 26) - 1) {
            $s = chr(65 + ($i % 26)) . $s;
        }
        return $s . ($row + 1);
    }

    private static function auditExport(string $filename, int $rows): void
    {
        Audit::log('REPORT_EXPORT', null, ['file' => $filename, 'rows' => $rows]);
    }
}
