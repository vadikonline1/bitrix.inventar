<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Inventar\EquipmentTable;
use Bitrix\Inventar\AllocationTable;
use Bitrix\Inventar\TypesTable;
use Bitrix\Inventar\StatusTable;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

Loader::includeModule('bitrix.inventar');

// ========== EXPORT CSV ==========
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="inventory_equipment_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    // Obține toate echipamentele cu toate datele
    $equipmentList = EquipmentTable::getList([
        'order' => ['ID' => 'DESC']
    ])->fetchAll();
    
    // Obține tipurile și stările
    $tipuri = TypesTable::getAllTypes();
    $stari = StatusTable::getAllStatus();
    
    // Obține utilizatorii pentru fiecare echipament
    $allocationMap = [];
    foreach ($equipmentList as $eq) {
        $userId = AllocationTable::getCurrentUserForEquipment($eq['ID']);
        if ($userId) {
            $user = \Bitrix\Main\UserTable::getById($userId)->fetch();
            $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
            if (empty($userName)) $userName = $user['LOGIN'];
            $allocationMap[$eq['ID']] = $userName;
        } else {
            $allocationMap[$eq['ID']] = '';
        }
    }
    
    // ========== CONSTRUIEȘTE HEADER ==========
    $headers = [
        'ID',
        'Inventory code',
        'Name',
        'Type',
        'Type Name',
        'Manufacturer',
        'Model',
        'Serial number',
        'Asset UUID',
        'Purchase date',
        'Supplier',
        'Purchase cost',
        'Warranty expiry',
        'Status',
        'Status Name',
        'Location',
        'Service contract',
        'Assigned user',
        'Created by',
        'Created at',
        'Updated by',
        'Updated at'
    ];
    
    // Adaugă câmpuri personalizate la header
    $customHeaders = [];
    $allCustomFields = [];
    foreach ($equipmentList as $eq) {
        if (!empty($eq['OTHERS_INFO'])) {
            $customData = json_decode($eq['OTHERS_INFO'], true);
            if (is_array($customData)) {
                foreach ($customData as $key => $value) {
                    $fieldName = str_replace('CUSTOM_', '', $key);
                    $fieldName = str_replace('_', ' ', $fieldName);
                    $fieldName = ucwords($fieldName);
                    if (!in_array($fieldName, $customHeaders)) {
                        $customHeaders[] = $fieldName;
                        $allCustomFields[$key] = $fieldName;
                    }
                }
            }
        }
    }
    
    $headers = array_merge($headers, $customHeaders);
    
    // Afișează header-ul
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    
    // ========== EXPORTĂ DATELE ==========
    foreach ($equipmentList as $item) {
        $row = [];
        
        // Date de bază
        $row[] = $item['ID'];
        $row[] = $item['COD_INVENTAR'];
        $row[] = $item['DENUMIRE'];
        $row[] = $item['TIP_ENUM'];
        $row[] = $tipuri[$item['TIP_ENUM']] ?? $item['TIP_ENUM'];
        $row[] = $item['PRODUCATOR'] ?? '';
        $row[] = $item['MODEL'] ?? '';
        $row[] = $item['SERIAL_NR'] ?? '';
        $row[] = $item['ASSET_UUID'] ?? '';
        
        // ========== MODIFICARE: AFIȘARE DIRECT DIN DB ==========
        // Purchase date
        $dataAchizitie = '';
        if (!empty($item['DATA_ACHIZITIE'])) {
            if ($item['DATA_ACHIZITIE'] instanceof Date) {
                $dataAchizitie = $item['DATA_ACHIZITIE']->format('Y-m-d');
            } elseif ($item['DATA_ACHIZITIE'] instanceof DateTime) {
                $dataAchizitie = $item['DATA_ACHIZITIE']->format('Y-m-d');
            } else {
                $dataAchizitie = $item['DATA_ACHIZITIE'];
            }
        }
        $row[] = $dataAchizitie;
        
        $row[] = $item['FURNIZOR'] ?? '';
        $row[] = $item['COST_ACHIZITIE'] ?? '';
        
        // Warranty expiry
        $dataExpirare = '';
        if (!empty($item['DATA_EXPIRARE_GARANTIE'])) {
            if ($item['DATA_EXPIRARE_GARANTIE'] instanceof Date) {
                $dataExpirare = $item['DATA_EXPIRARE_GARANTIE']->format('Y-m-d');
            } elseif ($item['DATA_EXPIRARE_GARANTIE'] instanceof DateTime) {
                $dataExpirare = $item['DATA_EXPIRARE_GARANTIE']->format('Y-m-d');
            } else {
                $dataExpirare = $item['DATA_EXPIRARE_GARANTIE'];
            }
        }
        $row[] = $dataExpirare;
        
        $row[] = $item['STARE_ENUM'];
        $row[] = $stari[$item['STARE_ENUM']]['name'] ?? $item['STARE_ENUM'];
        $row[] = $item['LOCATIE'] ?? '';
        $row[] = $item['CONTRACT_SERVICE'] ?? '';
        $row[] = $allocationMap[$item['ID']] ?? '';
        
        // Created by
        $createdBy = '';
        if (!empty($item['CREATED_BY'])) {
            $user = \Bitrix\Main\UserTable::getById($item['CREATED_BY'])->fetch();
            if ($user) {
                $createdBy = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
                if (empty($createdBy)) $createdBy = $user['LOGIN'];
            }
        }
        $row[] = $createdBy;
        
        $row[] = $item['CREATED_AT'] instanceof DateTime ? $item['CREATED_AT']->format('Y-m-d H:i:s') : '';
        
        // Updated by
        $updatedBy = '';
        if (!empty($item['UPDATED_BY'])) {
            $user = \Bitrix\Main\UserTable::getById($item['UPDATED_BY'])->fetch();
            if ($user) {
                $updatedBy = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
                if (empty($updatedBy)) $updatedBy = $user['LOGIN'];
            }
        }
        $row[] = $updatedBy;
        $row[] = $item['UPDATED_AT'] instanceof DateTime ? $item['UPDATED_AT']->format('Y-m-d H:i:s') : '';
        
        // Câmpuri personalizate
        $customData = [];
        if (!empty($item['OTHERS_INFO'])) {
            $customData = json_decode($item['OTHERS_INFO'], true);
            if (!is_array($customData)) $customData = [];
        }
        
        foreach ($allCustomFields as $key => $fieldName) {
            $row[] = $customData[$key] ?? '';
        }
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// ========== PAGINA ADMIN ==========
$APPLICATION->SetTitle("Import/Export");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

if ($APPLICATION->GetGroupRight("bitrix.inventar") < "R") {
    $APPLICATION->AuthForm("Access denied");
}

$importMessage = '';
$imported = 0;
$updated = 0;
$errors = 0;
$warningDetails = array();
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
// Foloseste \DateTime nativ pentru parsare (Bitrix\Main\Type\DateTime nu are
// createFromFormat in toate versiunile), apoi construieste Bitrix Date din Y-m-d.
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
        $dateTime = \DateTime::createFromFormat($format, $dateString);
        if ($dateTime !== false && $dateTime->format($format) === $dateString) {
            if (checkdate((int)$dateTime->format('m'), (int)$dateTime->format('d'), (int)$dateTime->format('Y'))) {
                return new Date($dateTime->format('Y-m-d'), 'Y-m-d');
            }
        }
    }
    
    $timestamp = strtotime($dateString);
    if ($timestamp !== false && $timestamp > 0) {
        $ymd = date('Y-m-d', $timestamp);
        $parts = explode('-', $ymd);
        if (checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            return new Date($ymd, 'Y-m-d');
        }
    }
    
    return null;
}

// Function to find user by name
function findUserByName($userName) {
    if (empty($userName)) {
        return null;
    }
    
    $userName = trim($userName);
    
    // Încearcă să găsească utilizatorul după nume și prenume
    $parts = explode(' ', $userName);
    $name = $parts[0] ?? '';
    $lastName = $parts[1] ?? '';
    
    $filter = ['ACTIVE' => 'Y'];
    if (!empty($name) && !empty($lastName)) {
        $filter['NAME'] = $name;
        $filter['LAST_NAME'] = $lastName;
    } elseif (!empty($name)) {
        $filter['NAME'] = $name;
    }
    
    $dbUsers = CUser::GetList('id', 'asc', $filter);
    if ($user = $dbUsers->Fetch()) {
        return $user['ID'];
    }
    
    // Dacă nu a fost găsit, încearcă să caute după login
    $dbUsers = CUser::GetList('id', 'asc', ['LOGIN' => $userName, 'ACTIVE' => 'Y']);
    if ($user = $dbUsers->Fetch()) {
        return $user['ID'];
    }
    
    return null;
}

// Process CSV import
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['import_file']) && $_FILES['import_file']['error'] == 0) {
    $file = $_FILES['import_file']['tmp_name'];
    $fileName = $_FILES['import_file']['name'];

    // Self-heal: sirurile goale '' in coloanele unice blocheaza inserturile
    // ('' se duplica, NULL nu). Normalizeaza la NULL inainte de import.
    try {
        $connection = Application::getConnection();
        $connection->queryExecute("UPDATE b_bitrix_inventar_equipment SET ASSET_UUID = NULL WHERE ASSET_UUID = ''");
        $connection->queryExecute("UPDATE b_bitrix_inventar_equipment SET SERIAL_NR = NULL WHERE SERIAL_NR = ''");
    } catch (\Exception $e) {
        // Daca coloanele nu exista inca, ignoram (importul va raporta erorile real)
    }
    
    $separator = detectSeparator($file);
    $separatorName = $separator == ',' ? 'comma (,)' : ($separator == ';' ? 'semicolon (;)' : 'TAB');
    
    $handle = fopen($file, "r");
    $firstRow = true;
    $lineNum = 0;
    
    // Obține header-ul pentru a mapa coloanele
    $headerRow = null;
    $columnMap = [];

    // Dictionare canonice CODE/ID + NAME (lowercase) → CODE, pentru normalizarea
    // valorilor din CSV (ex: 'monitor'/'Monitor' → '2').
    $canonicalTypes = [];
    try {
        foreach (TypesTable::getList()->fetchAll() as $t) {
            $canonicalTypes[(string)$t['CODE']] = (string)$t['CODE'];
            $canonicalTypes[mb_strtolower(trim((string)$t['CODE']))] = (string)$t['CODE'];
            $canonicalTypes[mb_strtolower(trim((string)$t['NAME']))] = (string)$t['CODE'];
        }
    } catch (\Exception $e) {}
    $canonicalStatuses = [];
    try {
        foreach (StatusTable::getList()->fetchAll() as $s) {
            $canonicalStatuses[(string)$s['CODE']] = (string)$s['CODE'];
            $canonicalStatuses[mb_strtolower(trim((string)$s['CODE']))] = (string)$s['CODE'];
            $canonicalStatuses[mb_strtolower(trim((string)$s['NAME']))] = (string)$s['CODE'];
        }
    } catch (\Exception $e) {}
    
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
            $headerRow = $data;
            
            // Mapează coloanele
            $columnMap = [
                'COD_INVENTAR' => array_search('Inventory code', $headerRow),
                'DENUMIRE' => array_search('Name', $headerRow),
                'TIP_ENUM' => array_search('Type', $headerRow),
                'PRODUCATOR' => array_search('Manufacturer', $headerRow),
                'MODEL' => array_search('Model', $headerRow),
                'SERIAL_NR' => array_search('Serial number', $headerRow),
                'ASSET_UUID' => array_search('Asset UUID', $headerRow),
                'DATA_ACHIZITIE' => array_search('Purchase date', $headerRow),
                'FURNIZOR' => array_search('Supplier', $headerRow),
                'COST_ACHIZITIE' => array_search('Purchase cost', $headerRow),
                'DATA_EXPIRARE_GARANTIE' => array_search('Warranty expiry', $headerRow),
                'STARE_ENUM' => array_search('Status', $headerRow),
                'LOCATIE' => array_search('Location', $headerRow),
                'CONTRACT_SERVICE' => array_search('Service contract', $headerRow),
                'ASSIGNED_USER' => array_search('Assigned user', $headerRow),
            ];
            continue;
        }
        
        if (count($data) < 3) {
            $errors++;
            $errorDetails[] = "Line <strong>{$lineNum}</strong>: Too few columns (" . count($data) . ")";
            continue;
        }
        
        // Extrage datele folosind mapa
        $codInventar = trim($data[$columnMap['COD_INVENTAR']] ?? '');
        $denumire = trim($data[$columnMap['DENUMIRE']] ?? '');
        $tipEnum = trim($data[$columnMap['TIP_ENUM']] ?? '');
        $serialNr = isset($columnMap['SERIAL_NR']) ? trim($data[$columnMap['SERIAL_NR']] ?? '') : '';
        $assetUuid = (isset($columnMap['ASSET_UUID']) && $columnMap['ASSET_UUID'] !== false) ? trim($data[$columnMap['ASSET_UUID']] ?? '') : '';
        $assignedUser = isset($columnMap['ASSIGNED_USER']) ? trim($data[$columnMap['ASSIGNED_USER']] ?? '') : '';

        // Normalizeaza tipul la CODE-ul canonic (accepta ID, CODE sau NAME,
        // ex: '2', 'monitor', 'Monitor' → '2'). Altfel lookup-urile si
        // custom fields-urile pica pe coduri necunoscute.
        if ($tipEnum !== '' && isset($canonicalTypes) && !isset($canonicalTypes[$tipEnum])) {
            $tipKey = mb_strtolower($tipEnum);
            if (isset($canonicalTypes[$tipKey])) {
                $tipEnum = $canonicalTypes[$tipKey];
            } else {
                $warningDetails[] = "Line <strong>{$lineNum}</strong>: Type '<strong>{$tipEnum}</strong>' not found in dictionary — stored as-is";
            }
        }
        
        if (empty($codInventar)) {
            $errors++;
            $errorDetails[] = "Line <strong>{$lineNum}</strong>: Empty inventory code";
            continue;
        }
        
        if (empty($denumire)) {
            $errors++;
            $errorDetails[] = "Line <strong>{$lineNum}</strong>: Empty name";
            continue;
        }
        
        if (empty($tipEnum)) {
            $errors++;
            $errorDetails[] = "Line <strong>{$lineNum}</strong>: Empty type";
            continue;
        }
        
        // ========== VERIFICĂ DUPLICATE ==========
        $existingCode = EquipmentTable::getList([
            'filter' => ['=COD_INVENTAR' => $codInventar],
            'select' => ['ID']
        ])->fetch();
        
        // Verifică dacă serialul există deja (dacă este completat)
        $existingSerial = null;
        $serialConflict = false;
        if (!empty($serialNr)) {
            $existingSerial = EquipmentTable::getList([
                'filter' => ['=SERIAL_NR' => $serialNr],
                'select' => ['ID', 'COD_INVENTAR']
            ])->fetch();
            
            if ($existingSerial) {
                $serialConflict = true;
            }
        }
        
        // ========== GESTIONEAZĂ CONFLICTUL DE SERIAL ==========
        $modifiedSerial = $serialNr;
        if ($serialConflict && !$existingCode) {
            // Dacă serialul există dar codul inventar este diferit, adaugă codul inventar la serial
            $modifiedSerial = $serialNr . '_' . $codInventar;
            
            // Verifică dacă noul serial este unic
            $checkNewSerial = EquipmentTable::getList([
                'filter' => ['=SERIAL_NR' => $modifiedSerial],
                'select' => ['ID']
            ])->fetch();
            
            if (!$checkNewSerial) {
                $warningDetails[] = "Line <strong>{$lineNum}</strong>: Serial number '<strong>{$serialNr}</strong>' already exists (used by inventory code '<strong>{$existingSerial['COD_INVENTAR']}</strong>'). Changed to '<strong>{$modifiedSerial}</strong>' to avoid conflict.";
            } else {
                $errors++;
                $errorDetails[] = "Line <strong>{$lineNum}</strong>: Serial number '<strong>{$serialNr}</strong>' already exists and the modified version '<strong>{$modifiedSerial}</strong>' also exists. Please fix manually.";
                continue;
            }
        } elseif ($serialConflict && $existingCode) {
            // Dacă și codul inventar și serialul există
            $warningDetails[] = "Line <strong>{$lineNum}</strong>: Both inventory code '<strong>{$codInventar}</strong>' and serial number '<strong>{$serialNr}</strong>' already exist. Equipment will be updated.";
        }
        
        // ========== CONVERTIRE DATE ==========
        $dataAchizitie = isset($columnMap['DATA_ACHIZITIE']) ? convertToBitrixDate($data[$columnMap['DATA_ACHIZITIE']] ?? '') : null;
        $dataExpirare = isset($columnMap['DATA_EXPIRARE_GARANTIE']) ? convertToBitrixDate($data[$columnMap['DATA_EXPIRARE_GARANTIE']] ?? '') : null;
        
        if (!empty($data[$columnMap['DATA_ACHIZITIE']] ?? '') && $dataAchizitie === null) {
            $warningDetails[] = "Line <strong>{$lineNum}</strong>: Purchase date could not be converted";
        }
        
        if (!empty($data[$columnMap['DATA_EXPIRARE_GARANTIE']] ?? '') && $dataExpirare === null) {
            $warningDetails[] = "Line <strong>{$lineNum}</strong>: Warranty date could not be converted";
        }
        
        // Normalizeaza statusul la CODE-ul canonic (accepta ID, CODE sau NAME).
        $stareEnum = isset($columnMap['STARE_ENUM']) ? trim($data[$columnMap['STARE_ENUM']] ?? StatusTable::IN_STOCK) : StatusTable::IN_STOCK;
        if ($stareEnum === '') $stareEnum = StatusTable::IN_STOCK;
        if (isset($canonicalStatuses) && !isset($canonicalStatuses[$stareEnum])) {
            $stareKey = mb_strtolower($stareEnum);
            if (isset($canonicalStatuses[$stareKey])) {
                $stareEnum = $canonicalStatuses[$stareKey];
            } else {
                $warningDetails[] = "Line <strong>{$lineNum}</strong>: Status '<strong>{$stareEnum}</strong>' not found in dictionary — stored as-is";
            }
        }

        $fields = [
            'COD_INVENTAR' => $codInventar,
            'DENUMIRE' => $denumire,
            'TIP_ENUM' => $tipEnum,
            'PRODUCATOR' => isset($columnMap['PRODUCATOR']) ? trim($data[$columnMap['PRODUCATOR']] ?? '') : '',
            'MODEL' => isset($columnMap['MODEL']) ? trim($data[$columnMap['MODEL']] ?? '') : '',
            'SERIAL_NR' => ($modifiedSerial !== '' ? $modifiedSerial : null),
            'ASSET_UUID' => ($assetUuid !== '' ? $assetUuid : null),
            'DATA_ACHIZITIE' => $dataAchizitie,
            'FURNIZOR' => isset($columnMap['FURNIZOR']) ? trim($data[$columnMap['FURNIZOR']] ?? '') : '',
            'COST_ACHIZITIE' => isset($columnMap['COST_ACHIZITIE']) && !empty($data[$columnMap['COST_ACHIZITIE']]) ? floatval($data[$columnMap['COST_ACHIZITIE']]) : null,
            'DATA_EXPIRARE_GARANTIE' => $dataExpirare,
            'STARE_ENUM' => $stareEnum,
            'LOCATIE' => isset($columnMap['LOCATIE']) ? trim($data[$columnMap['LOCATIE']] ?? '') : '',
            'CONTRACT_SERVICE' => isset($columnMap['CONTRACT_SERVICE']) ? trim($data[$columnMap['CONTRACT_SERVICE']] ?? '') : '',
        ];
        
        try {
            $equipmentId = null;
            
            if ($existingCode) {
                // ========== ACTUALIZEAZĂ ECHIPAMENTUL EXISTENT ==========
                $result = EquipmentTable::update($existingCode['ID'], $fields);
                if ($result->isSuccess()) {
                    $updated++;
                    $equipmentId = $existingCode['ID'];
                } else {
                    $errors++;
                    $errorDetails[] = "Line <strong>{$lineNum}</strong>: Update error - " . implode(", ", $result->getErrorMessages());
                    continue;
                }
            } else {
                // ========== ADĂUGĂ ECHIPAMENT NOU ==========
                $fields['NOTIFICATION_SENT'] = 'N';
                $result = EquipmentTable::add($fields);
                if ($result->isSuccess()) {
                    $imported++;
                    $equipmentId = $result->getId();
                } else {
                    $errors++;
                    $errorDetails[] = "Line <strong>{$lineNum}</strong>: " . implode(", ", $result->getErrorMessages());
                    continue;
                }
            }
            
            // ========== PROCESEAZĂ RESPONSABILUL ==========
            if ($equipmentId && !empty($assignedUser)) {
                $userId = findUserByName($assignedUser);
                
                if ($userId) {
                    // Verifică dacă există deja o alocare activă
                    $currentAlloc = AllocationTable::getList([
                        'filter' => ['=EQUIPMENT_ID' => $equipmentId, '=DATA_RETURNARE' => null],
                        'select' => ['ID']
                    ])->fetch();
                    
                    if ($currentAlloc) {
                        // Închide alocarea veche
                        AllocationTable::update($currentAlloc['ID'], [
                            'DATA_RETURNARE' => new Date(),
                            'MOTIV_RETURNARE' => 'Changed via import'
                        ]);
                    }
                    
                    // Adaugă alocare nouă
                    $allocResult = AllocationTable::add([
                        'EQUIPMENT_ID' => $equipmentId,
                        'USER_ID' => $userId,
                        'DATA_PREDARE' => new Date()
                    ]);
                    
                    if ($allocResult->isSuccess()) {
                        EquipmentTable::update($equipmentId, ['STARE_ENUM' => StatusTable::IN_USE]); // In use
                    } else {
                        $warningDetails[] = "Line <strong>{$lineNum}</strong>: Could not assign user '<strong>{$assignedUser}</strong>'";
                    }
                } else {
                    $warningDetails[] = "Line <strong>{$lineNum}</strong>: User '<strong>{$assignedUser}</strong>' not found";
                }
            } elseif ($equipmentId && empty($assignedUser)) {
                // Dacă nu există responsabil, asigură-te că echipamentul este în stoc
                EquipmentTable::update($equipmentId, ['STARE_ENUM' => StatusTable::IN_STOCK]); // In stock
            }
            
        } catch (Exception $e) {
            $errors++;
            $errorDetails[] = "Line <strong>{$lineNum}</strong>: " . $e->getMessage();
        }
    }
    fclose($handle);
    
    // ========== CONSTRUIEȘTE MESAJUL ==========
    $importMessage = "Import completed:<br>";
    $importMessage .= "✅ <strong>{$imported}</strong> records added<br>";
    $importMessage .= "🔄 <strong>{$updated}</strong> records updated<br>";
    $importMessage .= "❌ <strong>{$errors}</strong> errors<br>";
    $importMessage .= "📊 Detected separator: <strong>{$separatorName}</strong><br>";
    
    if (!empty($warningDetails)) {
        $importMessage .= "<br><strong>⚠️ Warnings:</strong><br>" . implode("<br>", array_slice($warningDetails, 0, 15));
        if (count($warningDetails) > 15) {
            $importMessage .= "<br>... and " . (count($warningDetails) - 15) . " more warnings";
        }
    }
    
    if (!empty($errorDetails)) {
        $importMessage .= "<br><br><strong>❌ Errors:</strong><br>" . implode("<br>", array_slice($errorDetails, 0, 15));
        if (count($errorDetails) > 15) {
            $importMessage .= "<br>... and " . (count($errorDetails) - 15) . " more errors";
        }
    }
    
    if ($imported > 0 || $updated > 0) {
        CAdminMessage::ShowNote($importMessage);
    } else {
        CAdminMessage::ShowMessage([
            'MESSAGE' => $importMessage,
            'TYPE' => 'ERROR',
            'HTML' => true
        ]);
    }
}

// Obține numărul total de echipamente
$totalEquipment = EquipmentTable::getCount();
?>

<style>
.import-export-box {
    background: #fff;
    padding: 25px;
    border-radius: 10px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-top: 4px solid #2c7ed6;
}
.import-export-box h3 {
    margin-top: 0;
    color: #2c7ed6;
    font-size: 18px;
    border-bottom: 2px solid #f0f0f0;
    padding-bottom: 12px;
}
.import-export-box .info-text {
    color: #666;
    font-size: 13px;
    margin: 10px 0;
}
.import-export-box .stats {
    background: #f5f7fa;
    padding: 10px 15px;
    border-radius: 6px;
    margin: 10px 0;
    font-size: 13px;
}
.import-example {
    background: #f5f5f5;
    padding: 12px 15px;
    border-radius: 6px;
    font-family: monospace;
    font-size: 12px;
    margin-top: 15px;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-all;
}
.import-example strong {
    color: #2c7ed6;
}
.btn-export {
    background: #4CAF50;
    color: white;
    padding: 10px 25px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    text-decoration: none;
    display: inline-block;
}
.btn-export:hover {
    background: #388E3C;
}
.btn-import {
    background: #2c7ed6;
    color: white;
    padding: 10px 25px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
}
.btn-import:hover {
    background: #1a4d8c;
}
.file-input-wrapper {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.file-input-wrapper input[type="file"] {
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 6px;
    background: #fafafa;
}
.feature-list {
    list-style: none;
    padding: 0;
    margin: 10px 0;
}
.feature-list li {
    padding: 4px 0;
    color: #555;
    font-size: 13px;
}
.feature-list li:before {
    content: "✅ ";
}
@media (max-width: 768px) {
    .import-export-box {
        padding: 15px;
    }
    .file-input-wrapper {
        flex-direction: column;
        align-items: stretch;
    }
    .file-input-wrapper input[type="file"] {
        width: 100%;
    }
    .btn-export,
    .btn-import {
        width: 100%;
        text-align: center;
    }
}
</style>

<div class="import-export-box">
    <h3>📤 Export Data</h3>
    <p class="info-text">Export all equipment to CSV format with all fields including custom fields, assigned user, type and status names.</p>
    
    <div class="stats">
        <strong>Total equipment:</strong> <?= $totalEquipment ?> records will be exported
    </div>
    
    <ul class="feature-list">
        <li>All equipment fields (ID, code, name, manufacturer, model, serial, dates, cost, etc.)</li>
        <li>Type and Status names (human-readable)</li>
        <li>Assigned user (currently allocated to)</li>
        <li>Custom fields (dynamic fields per equipment type)</li>
        <li>Created by / Updated by user names</li>
        <li>Timestamps (created at, updated at)</li>
    </ul>
    
    <a href="?export=excel" class="btn-export">📎 Export to CSV</a>
</div>

<div class="import-export-box">
    <h3>📥 Import Data</h3>
    <p class="info-text">Import equipment from CSV file. Separator is detected automatically (comma, semicolon or TAB).</p>
    <p><strong>⚠️ Note:</strong> If an equipment with the same inventory code already exists, it will be <strong>updated</strong> instead of created.</p>
    <p><strong>ℹ️ Serial number handling:</strong> If a serial number already exists but the inventory code is different, the inventory code will be <strong>appended</strong> to the serial number (e.g., SN12345 → SN12345_PS00006294).</p>
    
    <div class="stats">
        <strong>📋 Imported fields:</strong> Inventory code, Name, Type, Manufacturer, Model, Serial number, Asset UUID, Purchase date, Supplier, Purchase cost, Warranty expiry, Status, Location, Service contract (E-Factura), <strong>Assigned user</strong><br>
        <small>Fields like ID, Custom fields, Created/Updated by are NOT imported.</small>
    </div>
    
    <form method="POST" enctype="multipart/form-data">
        <div class="file-input-wrapper">
            <input type="file" name="import_file" accept=".csv,.txt">
            <button type="submit" class="btn-import">📂 Import CSV</button>
        </div>
    </form>
    
    <div class="import-example">
        <strong>📋 CSV Format Example:</strong>
ID,Inventory code,Name,Type,Manufacturer,Model,Serial number,Asset UUID,Purchase date,Supplier,Purchase cost,Warranty expiry,Status,Location,Service contract,Assigned user
,PS00006294,Monitor Philips,2,Philips,224E,SN12345,b0c648a6-106e-4374-9404-6aed6e883686,2024-01-15,PC Garage,850.00,2027-01-15,2,Office 101,ServiceContract-001,Ion Popescu
,PS00006295,Dell XPS 15,1,Dell,XPS 15,SN67890,,2024-02-20,Dell Store,2500.00,2027-02-20,1,Birou 201,ServiceContract-002,Maria Ionescu

<strong>📌 Status codes (numeric = row ID):</strong>
1 = In use  |  2 = In stock  |  3 = In repair  |  4 = Scrapped  |  5 = Lost

<strong>📌 Type codes (numeric = row ID):</strong>
1 = Workstation  |  2 = Monitor  |  3 = Printer &amp; Scanner  |  4 = Peripherals  |  5 = Cables &amp; Accessories
(see Types &amp; Statuses page for the full list)

<strong>👤 Assigned user:</strong> Can be full name (Ion Popescu) or login (ion.popescu)
    </div>
    
    <p style="margin-top: 10px; font-size: 12px; color: #999;">
        ✅ Accepted date formats: <strong>YYYY-MM-DD</strong>, <strong>MM/DD/YYYY</strong>, <strong>DD/MM/YYYY</strong>, <strong>DD.MM.YYYY</strong><br>
        ✅ UTF-8 encoding recommended
    </p>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Auto-submit file input on change
    document.querySelector('input[type="file"]').addEventListener('change', function() {
        if (this.value) {
            this.closest('form').submit();
        }
    });
});
</script>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>