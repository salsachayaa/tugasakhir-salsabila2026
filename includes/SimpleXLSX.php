<?php
/**
 * SimpleXLSX - Lightweight Excel XLSX parser
 * Based on SimpleXLSX by Sergey Shuchkin
 * GitHub: https://github.com/shuchkin/simplexlsx
 */

class SimpleXLSX {
    private $sheets = [];
    private $sheetNames = [];
    
    public static function parse($filename) {
        $xlsx = new self();
        
        if (!file_exists($filename)) {
            return false;
        }
        
        // Open the XLSX file as ZIP
        $zip = new ZipArchive();
        if ($zip->open($filename) !== true) {
            return false;
        }
        
        // Read shared strings
        $sharedStrings = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xmlObj = simplexml_load_string($xml);
            if ($xmlObj) {
                foreach ($xmlObj->si as $val) {
                    if (isset($val->t)) {
                        $sharedStrings[] = (string)$val->t;
                    } else if (isset($val->r)) {
                        $text = '';
                        foreach ($val->r as $r) {
                            if (isset($r->t)) {
                                $text .= (string)$r->t;
                            }
                        }
                        $sharedStrings[] = $text;
                    }
                }
            }
        }
        
        // Read worksheet
        if (($xml = $zip->getFromName('xl/worksheets/sheet1.xml')) !== false) {
            $xmlObj = simplexml_load_string($xml);
            if ($xmlObj && isset($xmlObj->sheetData->row)) {
                foreach ($xmlObj->sheetData->row as $row) {
                    $rowData = [];
                    $colIndex = 0;
                    
                    if (isset($row->c)) {
                        foreach ($row->c as $cell) {
                            $cellValue = '';
                            
                            // Get cell type
                            $cellType = isset($cell['t']) ? (string)$cell['t'] : '';
                            
                            if ($cellType == 's') {
                                // Shared string
                                $index = (int)$cell->v;
                                $cellValue = isset($sharedStrings[$index]) ? $sharedStrings[$index] : '';
                            } else if (isset($cell->v)) {
                                // Direct value
                                $cellValue = (string)$cell->v;
                            }
                            
                            $rowData[] = $cellValue;
                            $colIndex++;
                        }
                    }
                    
                    $xlsx->sheets[0][] = $rowData;
                }
            }
        }
        
        $zip->close();
        
        return $xlsx->sheets[0] ? $xlsx : false;
    }
    
    public function rows($sheetIndex = 0) {
        return isset($this->sheets[$sheetIndex]) ? $this->sheets[$sheetIndex] : [];
    }
}
?>