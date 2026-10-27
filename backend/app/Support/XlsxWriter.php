<?php

namespace App\Support;

use Phar;
use PharData;
use RuntimeException;

final class XlsxWriter
{
    public static function create(array $rows): string
    {
        return self::createWorkbook(['Creative audit' => $rows]);
    }

    /** @param array<string, array<int, array<int, mixed>>> $sheets */
    public static function createWorkbook(array $sheets): string
    {
        if ($sheets === []) {
            throw new RuntimeException('The spreadsheet export has no worksheets.');
        }

        $path = tempnam(sys_get_temp_dir(), 'audit-xlsx-');
        if ($path === false) {
            throw new RuntimeException('Unable to create the spreadsheet export.');
        }

        unlink($path);
        $path .= '.zip';
        $normalized = self::normalizeSheets($sheets);

        try {
            $archive = new PharData($path, 0, null, Phar::ZIP);
            $archive->addFromString('[Content_Types].xml', self::contentTypes(count($normalized)));
            $archive->addFromString('_rels/.rels', self::rootRelationships());
            $archive->addFromString('xl/workbook.xml', self::workbook($normalized));
            $archive->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelationships(count($normalized)));
            $archive->addFromString('xl/styles.xml', self::styles());
            foreach (array_values($normalized) as $index => $sheet) {
                [$worksheet, $relationships] = self::worksheet($sheet['rows']);
                $sheetNumber = $index + 1;
                $archive->addFromString('xl/worksheets/sheet'.$sheetNumber.'.xml', $worksheet);
                if ($relationships !== null) {
                    $archive->addFromString('xl/worksheets/_rels/sheet'.$sheetNumber.'.xml.rels', $relationships);
                }
            }
            unset($archive);

            return $path;
        } catch (\Throwable $error) {
            if (is_file($path)) {
                unlink($path);
            }

            throw $error;
        }
    }

    /** @return array<int, array{name: string, rows: array}> */
    private static function normalizeSheets(array $sheets): array
    {
        $used = [];
        $normalized = [];
        foreach ($sheets as $name => $rows) {
            $base = trim(preg_replace('/[\\\\\/?*\[\]:]/u', ' ', is_string($name) ? $name : 'Sheet '.(count($normalized) + 1))) ?: 'Sheet '.(count($normalized) + 1);
            $base = mb_substr($base, 0, 31);
            $candidate = $base;
            $suffix = 2;
            while (isset($used[mb_strtolower($candidate)])) {
                $tail = ' '.$suffix++;
                $candidate = mb_substr($base, 0, 31 - mb_strlen($tail)).$tail;
            }
            $used[mb_strtolower($candidate)] = true;
            $normalized[] = ['name' => $candidate, 'rows' => array_values($rows ?: [['No data']])];
        }

        return $normalized;
    }

    /** @return array{string, string|null} */
    private static function worksheet(array $rows): array
    {
        $columnCount = max(1, ...array_map(fn ($row) => count((array) $row), $rows ?: [[]]));
        $lastColumn = self::columnName($columnCount);
        $lastRow = max(1, count($rows));
        $xmlRows = [];
        $widths = array_fill(0, $columnCount, 12);
        $hyperlinks = [];

        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            foreach (array_values($row) as $columnIndex => $value) {
                $reference = self::columnName($columnIndex + 1).($rowIndex + 1);
                $plain = (string) ($value ?? '');
                $isHyperlink = $rowIndex > 0 && self::isWebUrl($plain);
                $style = $rowIndex === 0 ? 1 : ($isHyperlink ? 3 : 2);
                $text = self::xml($plain);
                $widths[$columnIndex] = max($widths[$columnIndex], min(48, mb_strlen(preg_replace('/\s+/u', ' ', $plain)) + 2));
                $cells[] = "<c r=\"{$reference}\" t=\"inlineStr\" s=\"{$style}\"><is><t xml:space=\"preserve\">{$text}</t></is></c>";
                if ($isHyperlink) {
                    $hyperlinks[] = ['reference' => $reference, 'target' => $plain];
                }
            }
            $height = $rowIndex === 0 ? ' ht="24" customHeight="1"' : '';
            $xmlRows[] = '<row r="'.($rowIndex + 1).'"'.$height.'>'.implode('', $cells).'</row>';
        }

        $columns = [];
        foreach ($widths as $index => $width) {
            $column = $index + 1;
            $columns[] = '<col min="'.$column.'" max="'.$column.'" width="'.$width.'" customWidth="1"/>';
        }

        $hyperlinkXml = $hyperlinks === [] ? '' : '<hyperlinks>'.collect($hyperlinks)->map(
            fn (array $link, int $index) => '<hyperlink ref="'.$link['reference'].'" r:id="rId'.($index + 1).'"/>'
        )->implode('').'</hyperlinks>';

        $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<dimension ref="A1:'.$lastColumn.$lastRow.'"/>'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="18"/><cols>'.implode('', $columns).'</cols>'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .$hyperlinkXml
            .($lastRow > 1 ? '<autoFilter ref="A1:'.$lastColumn.$lastRow.'"/>' : '')
            .'</worksheet>';

        return [$worksheet, $hyperlinks === [] ? null : self::worksheetRelationships($hyperlinks)];
    }

    private static function isWebUrl(string $value): bool
    {
        return preg_match('/^https?:\/\//i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private static function worksheetRelationships(array $hyperlinks): string
    {
        $relationships = collect($hyperlinks)->map(function (array $link, int $index) {
            return '<Relationship Id="rId'.($index + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="'.self::xml($link['target']).'" TargetMode="External"/>';
        })->implode('');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships
            .'</Relationships>';
    }

    private static function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function contentTypes(int $sheetCount): string
    {
        $worksheets = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $worksheets .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$worksheets
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbook(array $sheets): string
    {
        $xml = '';
        foreach ($sheets as $index => $sheet) {
            $xml .= '<sheet name="'.self::xml($sheet['name']).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$xml.'</sheets>'
            .'</workbook>';
    }

    private static function workbookRelationships(int $sheetCount): string
    {
        $relationships = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $relationships .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships
            .'<Relationship Id="rId'.($sheetCount + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="4"><font><sz val="11"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Aptos Display"/></font><font><sz val="11"/><name val="Aptos"/></font><font><u/><color rgb="FF2563EB"/><sz val="11"/><name val="Aptos"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FFDCE3EC"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
