<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Report export engine: CSV and XLSX (Office Open XML, dependency-free via
 * ZipArchive). Both formats carry an X-Report-SHA256 integrity header and the
 * checksum is written to the audit trail for verifiable delivery.
 */
final class Export
{
    /** Neutralize spreadsheet formula injection (CWE-1236) in text cells. */
    public static function safeCell(mixed $v): mixed
    {
        if (!is_string($v) || $v === '') return $v;
        return in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
    }

    public static function buildCsv(array $rows, array $headers = []): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel decodes accents correctly
        if (!$headers && $rows) $headers = array_keys(reset($rows));
        if ($headers) fputcsv($out, $headers);
        foreach ($rows as $r) {
            fputcsv($out, array_map([self::class, 'safeCell'], array_values($r)));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /** Stream a CSV download. $rows: array of arrays; $headers optional (else keys of row 0). */
    public static function csv(string $filename, array $rows, array $headers = []): never
    {
        $bytes = self::buildCsv($rows, $headers);
        self::send($filename, 'text/csv; charset=utf-8', $bytes, count($rows));
    }

    /** Build a minimal, valid XLSX (one sheet, inline strings, bold header style) and return the bytes. */
    public static function buildXlsx(string $sheetTitle, array $headers, array $rows): string
    {
        $cells = '';
        $col = 0;
        $cells .= '<row r="1">';
        foreach ($headers as $h) { $cells .= self::cell($col++, 0, (string)$h, true); }
        $cells .= '</row>';
        foreach (array_values($rows) as $r => $row) {
            $col = 0;
            $cells .= '<row r="' . ($r + 2) . '">';
            foreach ($row as $v) {
                $cells .= is_int($v) || is_float($v)
                    ? self::numCell($col++, $r + 1, $v)
                    : self::cell($col++, $r + 1, (string)$v, false);
            }
            $cells .= '</row>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $cells . '</sheetData></worksheet>';
        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars(mb_substr($sheetTitle, 0, 31), ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/styles.xml', $stylesXml);
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
        self::send($filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $bytes, count($rows));
    }

    private static function send(string $filename, string $type, string $bytes, int $rows): never
    {
        $sha = hash('sha256', $bytes);
        Audit::log('REPORT_EXPORT', null, ['file' => $filename, 'rows' => $rows, 'sha256' => $sha]);
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('X-Report-SHA256: ' . $sha);
        header('Cache-Control: no-store');
        echo $bytes;
        exit;
    }

    private static function cell(int $col, int $row, string $value, bool $bold): string
    {
        $ref = self::ref($col, $row);
        $style = $bold ? ' s="1"' : '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value); // XML-illegal control chars
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
}
