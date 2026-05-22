<?php

/**
 * Creates a small professional XLSX file without external composer packages.
 * Requires PHP ZipArchive, which is commonly enabled on cPanel hosting.
 */
function xlsxXmlEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsxColumnName(int $number): string {
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
}

function buildPortableZip(array $entries): string {
    $content = '';
    $directory = '';
    $offset = 0;
    foreach ($entries as $name => $data) {
        $name = (string)$name;
        $data = (string)$data;
        $crc = crc32($data);
        $size = strlen($data);
        $nameLength = strlen($name);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0) . $name . $data;
        $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset) . $name;
        $content .= $local;
        $offset += strlen($local);
    }
    $count = count($entries);
    return $content . $directory . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($directory), strlen($content), 0);
}

function streamSimpleXlsx(string $filename, array $headers, array $rows, string $sheetName = 'Appraisals'): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'appraisal_xlsx_');

    $safeSheetName = preg_replace('~[\\/?*\[\]:]~', '', $sheetName) ?: 'Appraisals';
    $safeSheetName = substr($safeSheetName, 0, 31);
    $allRows = array_merge([$headers], $rows);
    $sheetRows = '';

    foreach ($allRows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $cells = '';
        foreach (array_values($row) as $colIndex => $cell) {
            $ref = xlsxColumnName($colIndex + 1) . $excelRow;
            $style = $rowIndex === 0 ? ' s="1"' : '';
            if (is_numeric($cell) && $cell !== '' && !preg_match('/^0\d+$/', (string)$cell)) {
                $cells .= '<c r="' . $ref . '"' . $style . '><v>' . xlsxXmlEscape($cell) . '</v></c>';
            } else {
                $cells .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . xlsxXmlEscape($cell) . '</t></is></c>';
            }
        }
        $sheetRows .= '<row r="' . $excelRow . '">' . $cells . '</row>';
    }

    $columnWidths = [6, 30, 18, 22, 25, 18, 16, 22, 32, 32, 32, 34, 18, 22, 18, 18, 30, 13, 16, 24];
    $colsXml = '';
    foreach ($headers as $index => $_header) {
        $width = $columnWidths[$index] ?? 18;
        $columnNumber = $index + 1;
        $colsXml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="' . $width . '" customWidth="1"/>';
    }

    $lastColumn = xlsxColumnName(max(count($headers), 1));
    $lastRow = max(count($allRows), 1);
    $dimension = 'A1:' . $lastColumn . $lastRow;

    $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="' . $dimension . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $colsXml . '</cols>'
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastColumn . $lastRow . '"/>'
        . '</worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsxXmlEscape($safeSheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF3DA050"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFE5E7EB"/></left><right style="thin"><color rgb="FFE5E7EB"/></right><top style="thin"><color rgb="FFE5E7EB"/></top><bottom style="thin"><color rgb="FFE5E7EB"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf></cellXfs>'
        . '</styleSheet>';

    $entries = [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $worksheet,
    ];

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Unable to create Excel export file.', 500);
        }
        foreach ($entries as $name => $xml) $zip->addFromString($name, $xml);
        $zip->close();
    } else {
        file_put_contents($tempFile, buildPortableZip($entries));
    }

    $safeFile = preg_replace('/[^A-Za-z0-9_\-. ]/', '_', $filename);
    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safeFile . '"');
    header('Content-Length: ' . filesize($tempFile));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($tempFile);
    unlink($tempFile);
    exit;
}
