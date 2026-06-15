<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\Date;
use Bitrix\Inventar\EquipmentTable;
use Bitrix\Inventar\TypesTable;

Loader::includeModule('bitrix.inventar');
global $USER;

if (!$USER->IsAuthorized()) { LocalRedirect('/auth/'); exit; }

$groupId = Option::get('bitrix.inventar', 'inventar_group_id');
$userGroups = \CUser::GetUserGroup($USER->GetID());
if (!in_array($groupId, $userGroups) && !$USER->IsAdmin()) { 
    echo "Access denied."; 
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); 
    exit; 
}

$APPLICATION->SetTitle("Add new equipment");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

$tipOptions = TypesTable::getAllTypes();

function safeDate($dateString) {
    if (empty($dateString)) return null;
    $dateString = trim($dateString);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
        $parts = explode('-', $dateString);
        if (checkdate($parts[1], $parts[2], $parts[0])) return new Date($dateString, 'Y-m-d');
    }
    if (preg_match('/^\d{4}\.\d{2}\.\d{2}$/', $dateString)) {
        $parts = explode('.', $dateString);
        if (checkdate($parts[1], $parts[2], $parts[0])) return new Date($parts[0] . '-' . $parts[1] . '-' . $parts[2], 'Y-m-d');
    }
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateString)) {
        $parts = explode('/', $dateString);
        if (checkdate($parts[0], $parts[1], $parts[2])) return new Date($parts[2] . '-' . $parts[0] . '-' . $parts[1], 'Y-m-d');
    }
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $dateString)) {
        $parts = explode('.', $dateString);
        if (checkdate($parts[1], $parts[0], $parts[2])) return new Date($parts[2] . '-' . $parts[1] . '-' . $parts[0], 'Y-m-d');
    }
    return null;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save'])) {
    $codInventar = trim($_POST['COD_INVENTAR'] ?? '');
    $denumire = trim($_POST['DENUMIRE'] ?? '');
    $tipEnum = $_POST['TIP_ENUM'] ?? '';
    $dataAchizitieStr = trim($_POST['DATA_ACHIZITIE'] ?? '');
    $furnizor = trim($_POST['FURNIZOR'] ?? '');
    $costAchizitie = !empty($_POST['COST_ACHIZITIE']) ? floatval($_POST['COST_ACHIZITIE']) : null;
    
    if (empty($codInventar)) {
        $error = "Inventory code is required!";
    } elseif (empty($denumire)) {
        $error = "Equipment name is required!";
    } elseif (empty($tipEnum)) {
        $error = "Equipment type is required!";
    } else {
        $existing = EquipmentTable::getList([
            'filter' => ['=COD_INVENTAR' => $codInventar],
            'select' => ['ID', 'COD_INVENTAR']
        ])->fetch();
        
        if ($existing) {
            $error = "Error: Equipment with inventory code '{$codInventar}' already exists!";
        } else {
            $dataAchizitie = safeDate($dataAchizitieStr);
            
            $fields = [
                'COD_INVENTAR' => $codInventar,
                'DENUMIRE' => $denumire,
                'TIP_ENUM' => $tipEnum,
                'DATA_ACHIZITIE' => $dataAchizitie,
                'FURNIZOR' => $furnizor,
                'COST_ACHIZITIE' => $costAchizitie,
                'STARE_ENUM' => 'in_stock',
                'NOTIFICATION_SENT' => 'N'
            ];
            
            $result = EquipmentTable::add($fields);
            if ($result->isSuccess()) {
                $_SESSION['INVENTAR_NOTIFICATION'] = [
                    'type' => 'success',
                    'title' => '✅ Equipment added successfully!',
                    'message' => "Equipment {$codInventar} has been registered. Administrators have been notified."
                ];
                LocalRedirect("/inventar/");
            } else {
                $error = "Error: " . implode(", ", $result->getErrorMessages());
            }
        }
    }
}
?>

<style>
.add-form { max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
.add-form h2 { margin-top: 0; color: #2c7ed6; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
.form-group label .required { color: red; }
.form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
.btn-submit { background: #2c7ed6; color: white; padding: 12px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; }
.btn-submit:hover { background: #1a4d8c; }
.btn-back { background: #999; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; margin-left: 10px; }
.note { background: #fff3cd; padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
.error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 6px; }
</style>

<div class="add-form">
    <h2>➕ Add new equipment</h2>
    <div class="note">⚠️ Complete the required fields to add new equipment.</div>
    
    <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label>📝 Inventory code <span class="required">*</span></label>
            <input type="text" name="COD_INVENTAR" required placeholder="Ex: PS00006294">
        </div>
        <div class="form-group">
            <label>🏷️ Name <span class="required">*</span></label>
            <input type="text" name="DENUMIRE" required placeholder="Ex: Dell XPS 15 Laptop">
        </div>
        <div class="form-group">
            <label>🔧 Equipment type <span class="required">*</span></label>
            <select name="TIP_ENUM" required>
                <option value="">- Select type -</option>
                <?php foreach ($tipOptions as $val => $name): ?>
                <option value="<?= $val ?>"><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>📅 Purchase date</label>
            <input type="text" name="DATA_ACHIZITIE" placeholder="YYYY-MM-DD">
        </div>
        <div class="form-group">
            <label>🏢 Supplier</label>
            <input type="text" name="FURNIZOR" placeholder="Ex: PC Garage">
        </div>
        <div class="form-group">
            <label>💰 Purchase cost</label>
            <input type="number" step="0.01" name="COST_ACHIZITIE" placeholder="0.00">
        </div>
        <button type="submit" name="save" class="btn-submit">💾 Save equipment</button>
        <a href="/inventar/" class="btn-back">← Cancel</a>
    </form>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>