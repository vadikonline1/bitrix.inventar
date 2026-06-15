<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Loader;
use Bitrix\Inventar\EquipmentTable;
use Bitrix\Main\Type\Date;

Loader::includeModule('bitrix.inventar');

// Export CSV - must be before any HTML output
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="inventory_equipment_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    $list = EquipmentTable::getList()->fetchAll();
    
    echo "ID,Inventory code,Name,Type,Manufacturer,Model,Serial,Purchase date,Supplier,Cost,Warranty,Status,Location\n";
    
    foreach ($list as $item) {
        $dataAchizitie = '';
        if (!empty($item['DATA_ACHIZITIE'])) {
            if ($item['DATA_ACHIZITIE'] instanceof Date) {
                $dataAchizitie = $item['DATA_ACHIZITIE']->format('Y-m-d');
            } else {
                $timestamp = strtotime($item['DATA_ACHIZITIE']);
                $dataAchizitie = date('Y-m-d', $timestamp);
            }
        }
        
        $dataExpirare = '';
        if (!empty($item['DATA_EXPIRARE_GARANTIE'])) {
            if ($item['DATA_EXPIRARE_GARANTIE'] instanceof Date) {
                $dataExpirare = $item['DATA_EXPIRARE_GARANTIE']->format('Y-m-d');
            } else {
                $timestamp = strtotime($item['DATA_EXPIRARE_GARANTIE']);
                $dataExpirare = date('Y-m-d', $timestamp);
            }
        }
        
        echo '"' . $item['ID'] . '",';
        echo '"' . str_replace('"', '""', $item['COD_INVENTAR']) . '",';
        echo '"' . str_replace('"', '""', $item['DENUMIRE']) . '",';
        echo '"' . str_replace('"', '""', $item['TIP_ENUM']) . '",';
        echo '"' . str_replace('"', '""', $item['PRODUCATOR']) . '",';
        echo '"' . str_replace('"', '""', $item['MODEL']) . '",';
        echo '"' . str_replace('"', '""', $item['SERIAL_NR']) . '",';
        echo '"' . $dataAchizitie . '",';
        echo '"' . str_replace('"', '""', $item['FURNIZOR']) . '",';
        echo '"' . $item['COST_ACHIZITIE'] . '",';
        echo '"' . $dataExpirare . '",';
        echo '"' . str_replace('"', '""', $item['STARE_ENUM']) . '",';
        echo '"' . str_replace('"', '""', $item['LOCATIE']) . "\"\n";
    }
    exit;
}

$APPLICATION->SetTitle("Import/Export");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

if ($APPLICATION->GetGroupRight("bitrix.inventar") < "R") {
    $APPLICATION->AuthForm("Access denied");
}

$importMessage = '';
$imported = 0;
$errors = 0;
$errorDetails = array();

// Function to detect separator
function detectSeparator($filePath) {
    $handle = fopen($filePath, "r");
    $firstLine = fgets($handle);
    fclose($handle);
    
    $countComma = substr_count($firstLine, ',');
    $countSemicolon = substr_count($firstLine, ';');
    $countTab = substr_count($firstLine, "\t");
    
    if ($countSemicolon >= $countComma && $countSemicolon >= $countTab) {
        return ';';
    } elseif ($countTab >= $countComma && $countTab >= $countSemicolon) {
        return "\t";
    } else {
        return ',';
    }
}

// Function to convert date to Bitrix Date object
function convertToBitrixDate($dateString) {
    if (empty($dateString)) return null;
    
    $dateString = trim($dateString);
    
    $formats = [
        'Y-m-d',
        'm/d/Y',
        'n/j/Y',
        'd/m/Y',
        'j/n/Y',
        'd.m.Y',
        'j.n.Y',
        'd-m-Y',
        'j-n-Y',
        'Y/m/d',
        'Y.n.j',
    ];
    
    foreach ($formats as $format) {
        $dateTime = DateTime::createFromFormat($format, $dateString);
        if ($dateTime !== false && $dateTime->format($format) === $dateString) {
            if (checkdate($dateTime->format('m'), $dateTime->format('d'), $dateTime->format('Y'))) {
                return Date::createFromPhp($dateTime);
            }
        }
    }
    
    $timestamp = strtotime($dateString);
    if ($timestamp !== false && $timestamp > 0) {
        $dateTime = new DateTime();
        $dateTime->setTimestamp($timestamp);
        if (checkdate($dateTime->format('m'), $dateTime->format('d'), $dateTime->format('Y'))) {
            return Date::createFromPhp($dateTime);
        }
    }
    
    return null;
}

// Process CSV import
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['import_file']) && $_FILES['import_file']['error'] == 0) {
    $file = $_FILES['import_file']['tmp_name'];
    $fileName = $_FILES['import_file']['name'];
    
    $separator = detectSeparator($file);
    $separatorName = $separator == ',' ? 'comma (,)' : ($separator == ';' ? 'semicolon (;)' : 'TAB');
    
    $handle = fopen($file, "r");
    $firstRow = true;
    $lineNum = 0;
    
    while (($data = fgetcsv($handle, 10000, $separator)) !== FALSE) {
        $lineNum++;
        
        // Clean BOM UTF-8
        if ($lineNum == 1 && strpos($data[0], "\xEF\xBB\xBF") === 0) {
            $data[0] = substr($data[0], 3);
        }
        
        // Clean double quotes
        foreach ($data as $key => $value) {
            $data[$key] = trim($value, '"');
        }
        
        if ($firstRow) {
            $firstRow = false;
            continue;
        }
        
        if (count($data) < 3) {
            $errors++;
            $errorDetails[] = "Line {$lineNum}: Too few columns (" . count($data) . ")";
            continue;
        }
        
        $codInventar = trim($data[1] ?? '');
        $denumire = trim($data[2] ?? '');
        $tipEnum = trim($data[3] ?? '');
        
        if (empty($codInventar)) {
            $errors++;
            $errorDetails[] = "Line {$lineNum}: Empty inventory code";
            continue;
        }
        
        if (empty($denumire)) {
            $errors++;
            $errorDetails[] = "Line {$lineNum}: Empty name";
            continue;
        }
        
        if (empty($tipEnum)) {
            $errors++;
            $errorDetails[] = "Line {$lineNum}: Empty type";
            continue;
        }
        
        // Check for duplicates
        $existing = EquipmentTable::getList([
            'filter' => ['=COD_INVENTAR' => $codInventar],
            'select' => ['ID']
        ])->fetch();
        
        if ($existing) {
            $errors++;
            $errorDetails[] = "Line {$lineNum}: Inventory code {$codInventar} already exists";
            continue;
        }
        
        // Date conversion
        $dataAchizitie = convertToBitrixDate($data[7] ?? '');
        $dataExpirare = convertToBitrixDate($data[10] ?? '');
        
        if (!empty($data[7]) && $dataAchizitie === null) {
            $errorDetails[] = "Line {$lineNum}: Warning - purchase date '{$data[7]}' could not be converted";
        }
        
        if (!empty($data[10]) && $dataExpirare === null) {
            $errorDetails[] = "Line {$lineNum}: Warning - warranty date '{$data[10]}' could not be converted";
        }
        
        $fields = [
            'COD_INVENTAR' => $codInventar,
            'DENUMIRE' => $denumire,
            'TIP_ENUM' => $tipEnum,
            'PRODUCATOR' => trim($data[4] ?? ''),
            'MODEL' => trim($data[5] ?? ''),
            'SERIAL_NR' => trim($data[6] ?? ''),
            'DATA_ACHIZITIE' => $dataAchizitie,
            'FURNIZOR' => trim($data[8] ?? ''),
            'COST_ACHIZITIE' => !empty($data[9]) ? floatval($data[9]) : null,
            'DATA_EXPIRARE_GARANTIE' => $dataExpirare,
            'STARE_ENUM' => trim($data[11] ?? 'in_stock'),
            'LOCATIE' => trim($data[12] ?? ''),
            'NOTIFICATION_SENT' => 'N'
        ];
        
        try {
            $result = EquipmentTable::add($fields);
            if ($result->isSuccess()) {
                $imported++;
            } else {
                $errors++;
                $errorDetails[] = "Line {$lineNum}: " . implode(", ", $result->getErrorMessages());
            }
        } catch (Exception $e) {
            $errors++;
            $errorDetails[] = "Line {$lineNum}: " . $e->getMessage();
        }
    }
    fclose($handle);
    
    $importMessage = "Import completed: <strong>{$imported}</strong> records added, <strong>{$errors}</strong> errors.<br>";
    $importMessage .= "Detected separator: <strong>{$separatorName}</strong><br>";
    if (!empty($errorDetails)) {
        $importMessage .= "<br><strong>Details:</strong><br>" . implode("<br>", array_slice($errorDetails, 0, 15));
    }
    
    if ($imported > 0) {
        CAdminMessage::ShowNote($importMessage);
    } else {
        CAdminMessage::ShowMessage($importMessage);
    }
}
?>

<style>
.import-export-box {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.import-export-box h3 {
    margin-top: 0;
    color: #2c7ed6;
}
.import-example {
    background: #f5f5f5;
    padding: 10px;
    border-radius: 6px;
    font-family: monospace;
    font-size: 12px;
    margin-top: 10px;
    overflow-x: auto;
}
</style>

<div class="import-export-box">
    <h3>📤 Export data</h3>
    <p>Export all equipment to CSV format (comma separator).</p>
    <a href="?export=excel" class="adm-btn">📎 Export to CSV</a>
</div>

<div class="import-export-box">
    <h3>📥 Import data</h3>
    <p>Import equipment from CSV file. Separator is detected automatically (comma, semicolon or TAB).</p>
    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="import_file" accept=".csv,.txt">
        <input type="submit" value="📂 Import" class="adm-btn" style="margin-left: 10px;">
    </form>
    
    <div class="import-example">
        <strong>Example format (CSV with comma):</strong><br>
        ID,Inventory code,Name,Type,Manufacturer,Model,Serial,Purchase date,Supplier,Cost,Warranty,Status,Location<br>
        ,PS00006294,Monitor Philips,monitor,Philips,224E,SN12345,2024-01-15,PC Garage,850.00,2027-01-15,in_use,Office 101<br><br>
        <strong>Example format (CSV with semicolon):</strong><br>
        ID;Inventory code;Name;Type;Manufacturer;Model;Serial;Purchase date;Supplier;Cost;Warranty;Status;Location<br>
        ;PS00006294;Monitor Philips;monitor;Philips;224E;SN12345;2024-01-15;PC Garage;850.00;2027-01-15;in_use;Office 101
    </div>
    <p><small>Accepted date formats: <strong>YYYY-MM-DD</strong>, <strong>MM/DD/YYYY</strong>, <strong>DD/MM/YYYY</strong>, <strong>DD.MM.YYYY</strong></small></p>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>