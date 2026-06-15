<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\Date;
use Bitrix\Inventar\EquipmentTable;
use Bitrix\Inventar\AllocationTable;
use Bitrix\Inventar\TypesTable;
use Bitrix\Inventar\StatusTable;

Loader::includeModule('bitrix.inventar');
global $USER;

if (!$USER->IsAuthorized()) { LocalRedirect('/auth/'); exit; }

$groupId = Option::get('bitrix.inventar', 'inventar_group_id');
$userGroups = \CUser::GetUserGroup($USER->GetID());
$isInventarUser = (in_array($groupId, $userGroups) || $USER->IsAdmin());

if (!$isInventarUser) { 
    echo "Access denied. You are not a member of the Inventory group."; 
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); 
    exit; 
}

$ID = intval($_GET['id'] ?? 0);
$backUrl = $_GET['back'] ?? 'all';

$APPLICATION->SetTitle("Edit equipment");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

$tipOptions = TypesTable::getAllTypes();
$stareOptions = StatusTable::getAllStatus();

function convertToBitrixDate($dateString) {
    if (empty($dateString)) return null;
    $dateString = trim($dateString);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
        $parts = explode('-', $dateString);
        if (checkdate($parts[1], $parts[2], $parts[0])) return new Date($dateString, 'Y-m-d');
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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save'])) {
    $dataAchizitieStr = trim($_POST['DATA_ACHIZITIE'] ?? '');
    $dataExpirareStr = trim($_POST['DATA_EXPIRARE_GARANTIE'] ?? '');
    
    $dataAchizitie = convertToBitrixDate($dataAchizitieStr);
    $dataExpirare = convertToBitrixDate($dataExpirareStr);
    
    if (empty($dataAchizitieStr)) $dataAchizitie = null;
    if (empty($dataExpirareStr)) $dataExpirare = null;
    
    $fields = [
        'COD_INVENTAR' => $_POST['COD_INVENTAR'] ?? '',
        'DENUMIRE' => $_POST['DENUMIRE'] ?? '',
        'TIP_ENUM' => $_POST['TIP_ENUM'] ?? '',
        'PRODUCATOR' => $_POST['PRODUCATOR'] ?? '',
        'MODEL' => $_POST['MODEL'] ?? '',
        'SERIAL_NR' => $_POST['SERIAL_NR'] ?? '',
        'DATA_ACHIZITIE' => $dataAchizitie,
        'FURNIZOR' => $_POST['FURNIZOR'] ?? '',
        'COST_ACHIZITIE' => (!empty($_POST['COST_ACHIZITIE']) && is_numeric($_POST['COST_ACHIZITIE'])) ? floatval($_POST['COST_ACHIZITIE']) : null,
        'DATA_EXPIRARE_GARANTIE' => $dataExpirare,
        'STARE_ENUM' => $_POST['STARE_ENUM'] ?? 'in_stock',
        'LOCATIE' => $_POST['LOCATIE'] ?? '',
        'CONTRACT_SERVICE' => $_POST['CONTRACT_SERVICE'] ?? ''
    ];
    
    if (empty($fields['COD_INVENTAR'])) {
        $error = "Inventory code is required!";
    } elseif (empty($fields['DENUMIRE'])) {
        $error = "Equipment name is required!";
    } else {
        try {
            $result = EquipmentTable::update($ID, $fields);
            if ($result->isSuccess()) {
                $_SESSION['INVENTAR_NOTIFICATION'] = [
                    'type' => 'success',
                    'title' => '✅ Equipment updated!',
                    'message' => 'Changes have been saved successfully.'
                ];
                $redirectUrl = ($backUrl == 'all') ? '/inventar/all/' : '/inventar/';
                LocalRedirect($redirectUrl);
            } else {
                $error = "Error: " . implode(", ", $result->getErrorMessages());
            }
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

$equipment = EquipmentTable::getById($ID)->fetch();
if (!$equipment) {
    echo "<p>Equipment not found.</p>";
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit;
}
?>

<style>
.edit-form { max-width: 800px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
.edit-form h2 { margin-top: 0; color: #2c7ed6; border-bottom: 2px solid #2c7ed6; padding-bottom: 10px; }
.form-group { margin-bottom: 20px; display: flex; flex-wrap: wrap; align-items: center; }
.form-group label { width: 180px; font-weight: bold; color: #333; }
.form-group label .required { color: red; }
.form-group input, .form-group select { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; min-width: 200px; }
.form-row { display: flex; gap: 20px; margin-bottom: 20px; }
.form-row .form-group { flex: 1; margin-bottom: 0; }
.btn-submit { background: #2c7ed6; color: white; padding: 12px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; margin-right: 10px; }
.btn-back { background: #999; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; display: inline-block; }
.error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 20px; }
.small-text { color: #666; font-size: 12px; margin-top: 5px; margin-left: 180px; }
@media (max-width: 768px) { .form-group { flex-direction: column; align-items: flex-start; } .form-group label { width: 100%; margin-bottom: 8px; } .form-group input, .form-group select { width: 100%; } .small-text { margin-left: 0; } .form-row { flex-direction: column; gap: 0; } }
</style>

<div class="edit-form">
    <h2>✏️ Edit equipment</h2>
    
    <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group"><label><span class="required">*</span> Inventory code</label><input type="text" name="COD_INVENTAR" value="<?= htmlspecialchars($equipment['COD_INVENTAR']) ?>" required></div>
        <div class="form-group"><label><span class="required">*</span> Name</label><input type="text" name="DENUMIRE" value="<?= htmlspecialchars($equipment['DENUMIRE']) ?>" required></div>
        <div class="form-group"><label>Type</label><select name="TIP_ENUM"><?php foreach ($tipOptions as $val => $name): ?><option value="<?= $val ?>" <?= ($equipment['TIP_ENUM'] == $val) ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div>
        <div class="form-row"><div class="form-group"><label>Manufacturer</label><input type="text" name="PRODUCATOR" value="<?= htmlspecialchars($equipment['PRODUCATOR']) ?>"></div><div class="form-group"><label>Model</label><input type="text" name="MODEL" value="<?= htmlspecialchars($equipment['MODEL']) ?>"></div></div>
        <div class="form-group"><label>Serial number</label><input type="text" name="SERIAL_NR" value="<?= htmlspecialchars($equipment['SERIAL_NR']) ?>"></div>
        <div class="form-row"><div class="form-group"><label>Purchase date</label><input type="text" name="DATA_ACHIZITIE" value="<?= $equipment['DATA_ACHIZITIE'] ?>" placeholder="YYYY-MM-DD"><div class="small-text">Format: YYYY-MM-DD</div></div><div class="form-group"><label>Warranty expiry</label><input type="text" name="DATA_EXPIRARE_GARANTIE" value="<?= $equipment['DATA_EXPIRARE_GARANTIE'] ?>" placeholder="YYYY-MM-DD"><div class="small-text">Format: YYYY-MM-DD</div></div></div>
        <div class="form-group"><label>Supplier</label><input type="text" name="FURNIZOR" value="<?= htmlspecialchars($equipment['FURNIZOR']) ?>"></div>
        <div class="form-group"><label>Purchase cost (lei)</label><input type="number" step="0.01" name="COST_ACHIZITIE" value="<?= $equipment['COST_ACHIZITIE'] ?>" placeholder="0.00"></div>
        <div class="form-group"><label>Status</label><select name="STARE_ENUM"><?php foreach ($stareOptions as $val => $info): ?><option value="<?= $val ?>" <?= ($equipment['STARE_ENUM'] == $val) ? 'selected' : '' ?>><?= htmlspecialchars($info['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Location</label><input type="text" name="LOCATIE" value="<?= htmlspecialchars($equipment['LOCATIE']) ?>"></div>
        <div class="form-group"><label>Service contract</label><input type="text" name="CONTRACT_SERVICE" value="<?= htmlspecialchars($equipment['CONTRACT_SERVICE']) ?>"></div>
        <div style="margin-top: 30px; text-align: center;"><button type="submit" name="save" class="btn-submit">💾 Save changes</button><a href="/inventar/<?= ($backUrl == 'all') ? 'all/' : '' ?>" class="btn-back">← Cancel</a></div>
    </form>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>