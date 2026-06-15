<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Config\Option;
use Bitrix\Inventar\EquipmentTable;
use Bitrix\Inventar\AllocationTable;
use Bitrix\Inventar\TypesTable;
use Bitrix\Inventar\StatusTable;
use Bitrix\Inventar\CustomFieldsTable;

Loader::includeModule('bitrix.inventar');

$ID = intval($_REQUEST['ID'] ?? 0);
$APPLICATION->SetTitle($ID ? "Edit equipment" : "Add new equipment");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

// Get types and statuses from database
$tipOptions = TypesTable::getAllTypes();
$stareOptions = StatusTable::getAllStatus();

// Safe date conversion function
function convertToBitrixDate($dateString) {
    if (empty($dateString)) return null;
    $dateString = trim($dateString);
    
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
        $parts = explode('-', $dateString);
        if (checkdate($parts[1], $parts[2], $parts[0])) {
            return new Date($dateString, 'Y-m-d');
        }
    }
    
    if (preg_match('/^\d{4}\.\d{2}\.\d{2}$/', $dateString)) {
        $parts = explode('.', $dateString);
        if (checkdate($parts[1], $parts[2], $parts[0])) {
            return new Date($parts[0] . '-' . $parts[1] . '-' . $parts[2], 'Y-m-d');
        }
    }
    
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateString)) {
        $parts = explode('/', $dateString);
        if (checkdate($parts[0], $parts[1], $parts[2])) {
            return new Date($parts[2] . '-' . $parts[0] . '-' . $parts[1], 'Y-m-d');
        }
    }
    
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $dateString)) {
        $parts = explode('.', $dateString);
        if (checkdate($parts[1], $parts[0], $parts[2])) {
            return new Date($parts[2] . '-' . $parts[1] . '-' . $parts[0], 'Y-m-d');
        }
    }
    
    return null;
}

// Process save
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save'])) {
    $dataAchizitieStr = trim($_POST['DATA_ACHIZITIE'] ?? '');
    $dataExpirareStr = trim($_POST['DATA_EXPIRARE_GARANTIE'] ?? '');
    
    $dataAchizitie = convertToBitrixDate($dataAchizitieStr);
    $dataExpirare = convertToBitrixDate($dataExpirareStr);
    
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
    
    // Get custom fields for current type
    $currentTip = $_POST['TIP_ENUM'] ?? 'Workstation';
    $currentCustomFields = CustomFieldsTable::getFieldsByType($currentTip);
    
    $customData = [];
    foreach ($currentCustomFields as $field) {
        $fieldName = 'CUSTOM_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $field['label']);
        if (isset($_POST[$fieldName]) && $_POST[$fieldName] !== '') {
            $customData[$fieldName] = $_POST[$fieldName];
        }
    }
    if (!empty($customData)) {
        $fields['OTHERS_INFO'] = json_encode($customData);
    }
    
    if (empty($fields['COD_INVENTAR'])) {
        CAdminMessage::ShowMessage("Error: Inventory code is required!");
    } else {
        try {
            if ($ID) {
                $result = EquipmentTable::update($ID, $fields);
                if ($result->isSuccess()) {
                    CAdminMessage::ShowMessage("Equipment updated successfully!", "OK");
                } else {
                    CAdminMessage::ShowMessage("Error: " . implode(", ", $result->getErrorMessages()));
                }
            } else {
                $result = EquipmentTable::add($fields);
                if ($result->isSuccess()) {
                    $ID = $result->getId();
                    CAdminMessage::ShowMessage("Equipment added successfully!", "OK");
                } else {
                    CAdminMessage::ShowMessage("Error: " . implode(", ", $result->getErrorMessages()));
                }
            }
            
            // ========== AUTO ALLOCATION WHEN RESPONSIBLE USER IS SELECTED ==========
            $selectedUserId = intval($_POST['RESPONSIBLE_USER'] ?? 0);
            
            if ($ID && $selectedUserId > 0) {
                $currentAlloc = AllocationTable::getList([
                    'filter' => ['=EQUIPMENT_ID' => $ID, '=DATA_RETURNARE' => null],
                    'select' => ['ID', 'USER_ID']
                ])->fetch();
                
                if ($currentAlloc) {
                    if ($currentAlloc['USER_ID'] != $selectedUserId) {
                        AllocationTable::update($currentAlloc['ID'], [
                            'DATA_RETURNARE' => new Date(),
                            'MOTIV_RETURNARE' => 'Responsible person changed'
                        ]);
                        AllocationTable::add([
                            'EQUIPMENT_ID' => $ID,
                            'USER_ID' => $selectedUserId,
                            'DATA_PREDARE' => new Date()
                        ]);
                        EquipmentTable::update($ID, ['STARE_ENUM' => 'in_use']);
                    }
                } else {
                    AllocationTable::add([
                        'EQUIPMENT_ID' => $ID,
                        'USER_ID' => $selectedUserId,
                        'DATA_PREDARE' => new Date()
                    ]);
                    EquipmentTable::update($ID, ['STARE_ENUM' => 'in_use']);
                }
            } elseif ($ID && $selectedUserId == 0) {
                $currentAlloc = AllocationTable::getList([
                    'filter' => ['=EQUIPMENT_ID' => $ID, '=DATA_RETURNARE' => null],
                    'select' => ['ID']
                ])->fetch();
                if ($currentAlloc) {
                    AllocationTable::update($currentAlloc['ID'], [
                        'DATA_RETURNARE' => new Date(),
                        'MOTIV_RETURNARE' => 'Released'
                    ]);
                    EquipmentTable::update($ID, ['STARE_ENUM' => 'in_stock']);
                }
            }
            
            if ($ID && isset($result) && $result->isSuccess()) {
                LocalRedirect("/bitrix/admin/bitrix_inventar_equipment_list.php");
            }
        } catch (Exception $e) {
            CAdminMessage::ShowMessage("Error: " . $e->getMessage());
        }
    }
}

// Get equipment data
$equipment = [];
$currentUserId = null;
$customFieldsData = [];
if ($ID) {
    $equipment = EquipmentTable::getById($ID)->fetch();
    $currentUserId = AllocationTable::getCurrentUserForEquipment($ID);
    if ($equipment['DATA_ACHIZITIE']) {
        $equipment['DATA_ACHIZITIE'] = $equipment['DATA_ACHIZITIE'] instanceof Date ? $equipment['DATA_ACHIZITIE']->format('Y-m-d') : date('Y-m-d', strtotime($equipment['DATA_ACHIZITIE']));
    }
    if ($equipment['DATA_EXPIRARE_GARANTIE']) {
        $equipment['DATA_EXPIRARE_GARANTIE'] = $equipment['DATA_EXPIRARE_GARANTIE'] instanceof Date ? $equipment['DATA_EXPIRARE_GARANTIE']->format('Y-m-d') : date('Y-m-d', strtotime($equipment['DATA_EXPIRARE_GARANTIE']));
    }
    if (!empty($equipment['OTHERS_INFO'])) {
        $customFieldsData = json_decode($equipment['OTHERS_INFO'], true);
        if (!is_array($customFieldsData)) $customFieldsData = [];
    }
}

// Determine current type
$urlTip = $_GET['tip'] ?? '';
$currentTip = $urlTip;
if (empty($currentTip) && !empty($equipment['TIP_ENUM'])) {
    $currentTip = $equipment['TIP_ENUM'];
}
if (empty($currentTip)) {
    $currentTip = 'Workstation';
}

// Get custom fields for current type
$currentCustomFields = CustomFieldsTable::getFieldsByType($currentTip);

// Get users from selected group
$responsibleGroupId = Option::get('bitrix.inventar', 'responsible_group_id', 0);
$arUsers = [];
if ($responsibleGroupId) {
    $dbUsers = CUser::GetList('id', 'asc', ['GROUPS_ID' => [$responsibleGroupId], 'ACTIVE' => 'Y']);
    while ($user = $dbUsers->Fetch()) {
        $arUsers[$user['ID']] = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
        if (empty($arUsers[$user['ID']])) $arUsers[$user['ID']] = $user['LOGIN'];
    }
}
?>

<script>
function validateDate(input) {
    let value = input.value.trim();
    if (!value) return true;
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return true;
    if (/^\d{4}\.\d{2}\.\d{2}$/.test(value)) {
        let parts = value.split('.');
        input.value = parts[0] + '-' + parts[1] + '-' + parts[2];
        return true;
    }
    if (/^\d{2}\/\d{2}\/\d{4}$/.test(value)) {
        let parts = value.split('/');
        input.value = parts[2] + '-' + parts[0] + '-' + parts[1];
        return true;
    }
    if (/^\d{2}\.\d{2}\.\d{4}$/.test(value)) {
        let parts = value.split('.');
        input.value = parts[2] + '-' + parts[1] + '-' + parts[0];
        return true;
    }
    return true;
}

function updateCustomFields() {
    var tip = document.getElementById('tip_selector').value;
    var currentId = <?= $ID ?> || 0;
    window.location.href = '?ID=' + currentId + '&tip=' + tip;
}
</script>

<form method="POST">
    <table class="edit-table" width="100%">
        <tr>
            <td width="200"><span style="color:red;">*</span> Inventory code:</td>
            <td><input type="text" name="COD_INVENTAR" value="<?= htmlspecialchars($equipment['COD_INVENTAR'] ?? '') ?>" size="30" required></td>
        </tr>
        <tr>
            <td>Name:</td>
            <td><input type="text" name="DENUMIRE" value="<?= htmlspecialchars($equipment['DENUMIRE'] ?? '') ?>" size="50"></td>
        </tr>
        <tr>
            <td>Type:</td>
            <td>
                <select name="TIP_ENUM" id="tip_selector" onchange="updateCustomFields()">
                    <?php foreach ($tipOptions as $val => $name): ?>
                    <option value="<?= $val ?>" <?= ($currentTip == $val) ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <br><small>Changing type will reload the page to show custom fields</small>
            </td>
        </tr>
        <tr>
            <td>Manufacturer:</td>
            <td><input type="text" name="PRODUCATOR" value="<?= htmlspecialchars($equipment['PRODUCATOR'] ?? '') ?>" size="40"></td>
        </tr>
        <tr>
            <td>Model:</td>
            <td><input type="text" name="MODEL" value="<?= htmlspecialchars($equipment['MODEL'] ?? '') ?>" size="40"></td>
        </tr>
        <tr>
            <td>Serial number:</td>
            <td><input type="text" name="SERIAL_NR" value="<?= htmlspecialchars($equipment['SERIAL_NR'] ?? '') ?>" size="40"></td>
        </tr>
        <tr>
            <td>Purchase date:<br><small>(YYYY-MM-DD)</small></td>
            <td><input type="text" name="DATA_ACHIZITIE" value="<?= htmlspecialchars($equipment['DATA_ACHIZITIE'] ?? '') ?>" size="20" placeholder="2024-01-15" onblur="validateDate(this)"></td>
        </tr>
        <tr>
            <td>Supplier:</td>
            <td><input type="text" name="FURNIZOR" value="<?= htmlspecialchars($equipment['FURNIZOR'] ?? '') ?>" size="40"></td>
        </tr>
        <tr>
            <td>Purchase cost:</td>
            <td><input type="text" name="COST_ACHIZITIE" value="<?= htmlspecialchars($equipment['COST_ACHIZITIE'] ?? '') ?>" size="20"> lei</td>
        </tr>
        <tr>
            <td>Warranty expiry:<br><small>(YYYY-MM-DD)</small></td>
            <td><input type="text" name="DATA_EXPIRARE_GARANTIE" value="<?= htmlspecialchars($equipment['DATA_EXPIRARE_GARANTIE'] ?? '') ?>" size="20" placeholder="2024-01-15" onblur="validateDate(this)"></td>
        </tr>
        <tr>
            <td>Status:</td>
            <td>
                <select name="STARE_ENUM">
                    <?php foreach ($stareOptions as $val => $info): ?>
                    <option value="<?= $val ?>" <?= ($equipment['STARE_ENUM']??'')==$val?'selected':'' ?>><?= htmlspecialchars($info['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <td>Location:</td>
            <td><input type="text" name="LOCATIE" value="<?= htmlspecialchars($equipment['LOCATIE'] ?? '') ?>" size="40"></td>
        </tr>
        <tr>
            <td>Service contract:</td>
            <td><input type="text" name="CONTRACT_SERVICE" value="<?= htmlspecialchars($equipment['CONTRACT_SERVICE'] ?? '') ?>" size="40"></td>
        </tr>
        
        <?php if (!empty($currentCustomFields)): ?>
        <tr style="background:#f0f7ff;"><td colspan="2" style="font-weight:bold;">🔧 Custom fields for type <?= htmlspecialchars($tipOptions[$currentTip] ?? $currentTip) ?></td></tr>
        <?php foreach ($currentCustomFields as $field): 
            $fieldName = 'CUSTOM_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $field['label']);
            $fieldValue = $customFieldsData[$fieldName] ?? '';
        ?>
        <tr>
            <td><?= htmlspecialchars($field['label']) ?>:</td>
            <td>
                <?php if ($field['type'] == 'textarea'): ?>
                    <textarea name="<?= $fieldName ?>" rows="3" cols="50"><?= htmlspecialchars($fieldValue) ?></textarea>
                <?php elseif ($field['type'] == 'date'): ?>
                    <input type="text" name="<?= $fieldName ?>" value="<?= htmlspecialchars($fieldValue) ?>" placeholder="YYYY-MM-DD" onblur="validateDate(this)">
                <?php elseif ($field['type'] == 'number'): ?>
                    <input type="number" step="any" name="<?= $fieldName ?>" value="<?= htmlspecialchars($fieldValue) ?>" size="30">
                <?php elseif ($field['type'] == 'select' && !empty($field['options'])): 
                    $options = explode(',', $field['options']);
                    $options = array_map('trim', $options);
                ?>
                    <select name="<?= $fieldName ?>">
                        <option value="">- Select -</option>
                        <?php foreach ($options as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= ($fieldValue == $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" name="<?= $fieldName ?>" value="<?= htmlspecialchars($fieldValue) ?>" size="50">
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <tr style="background:#f9f9f9;"><td colspan="2"><em>No custom fields defined for this equipment type.</em><br>
        <small>You can add custom fields in the "Additional Fields" section from the main menu.</small></td></tr>
        <?php endif; ?>
        
        <tr style="background:#f0f7ff;">
            <td><span style="color:red;">*</span> Responsible person:</td>
            <td>
                <select name="RESPONSIBLE_USER" style="min-width:250px;">
                    <option value="0">- None -</option>
                    <?php foreach ($arUsers as $uid => $uname): ?>
                    <option value="<?= $uid ?>" <?= ($currentUserId == $uid) ? 'selected' : '' ?>><?= htmlspecialchars($uname) ?></option>
                    <?php endforeach; ?>
                </select>
                <br><small>Select the responsible user - an allocation will be automatically created</small>
            </td>
        </tr>
    </table>
    
    <input type="submit" name="save" value="Save" class="adm-btn-save">
    <a href="/bitrix/admin/bitrix_inventar_equipment_list.php" class="adm-btn">Cancel</a>
</form>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>