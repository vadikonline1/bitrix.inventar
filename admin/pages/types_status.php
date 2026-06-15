<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Inventar\TypesTable;
use Bitrix\Inventar\StatusTable;

Loader::includeModule('bitrix.inventar');

$APPLICATION->SetTitle("Types and Statuses Management");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

if ($APPLICATION->GetGroupRight("bitrix.inventar") < "W") {
    $APPLICATION->AuthForm("Access denied");
}

// Salvare tipuri
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_types'])) {
    $existing = TypesTable::getList()->fetchAll();
    foreach ($existing as $item) {
        TypesTable::delete($item['ID']);
    }
    for ($i = 0; $i < count($_POST['type_code'] ?? []); $i++) {
        if (!empty($_POST['type_code'][$i]) && !empty($_POST['type_name'][$i])) {
            TypesTable::add([
                'CODE' => $_POST['type_code'][$i],
                'NAME' => $_POST['type_name'][$i],
                'SORT' => ($i + 1) * 10
            ]);
        }
    }
    CAdminMessage::ShowMessage("Types saved successfully!", "OK");
}

// Salvare stări
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_status'])) {
    $existing = StatusTable::getList()->fetchAll();
    foreach ($existing as $item) {
        StatusTable::delete($item['ID']);
    }
    for ($i = 0; $i < count($_POST['status_code'] ?? []); $i++) {
        if (!empty($_POST['status_code'][$i]) && !empty($_POST['status_name'][$i])) {
            StatusTable::add([
                'CODE' => $_POST['status_code'][$i],
                'NAME' => $_POST['status_name'][$i],
                'COLOR' => $_POST['status_color'][$i] ?? '#666666',
                'SORT' => ($i + 1) * 10
            ]);
        }
    }
    CAdminMessage::ShowMessage("Statuses saved successfully!", "OK");
}

// Salvare grup utilizatori
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_group'])) {
    $selectedGroup = intval($_POST['responsible_group'] ?? 0);
    Option::set('bitrix.inventar', 'responsible_group_id', $selectedGroup);
    CAdminMessage::ShowMessage("Responsible users group saved successfully!", "OK");
}

// ========== SALVARE SETĂRI NOTIFICĂRI ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_notification_settings'])) {
    $notificationNewEquipment = $_POST['notification_new_equipment'] == 'Y' ? 'Y' : 'N';
    $notificationAssignment = $_POST['notification_assignment'] == 'Y' ? 'Y' : 'N';
    
    Option::set('bitrix.inventar', 'notification_new_equipment', $notificationNewEquipment);
    Option::set('bitrix.inventar', 'notification_assignment', $notificationAssignment);
    
    CAdminMessage::ShowMessage("Notification settings saved successfully!", "OK");
}

// Obține setările curente
$notificationNewEquipment = Option::get('bitrix.inventar', 'notification_new_equipment', 'Y');
$notificationAssignment = Option::get('bitrix.inventar', 'notification_assignment', 'Y');

// Obține datele curente
$tipuri = TypesTable::getList(['order' => ['SORT' => 'ASC']])->fetchAll();
$stari = StatusTable::getList(['order' => ['SORT' => 'ASC']])->fetchAll();
$responsibleGroupId = Option::get('bitrix.inventar', 'responsible_group_id', 0);

// Preia toate grupurile
$arGroups = [];
$dbGroups = CGroup::GetList('c_sort', 'asc', ['ACTIVE' => 'Y']);
while ($group = $dbGroups->Fetch()) {
    $arGroups[$group['ID']] = $group['NAME'] . ' (' . $group['STRING_ID'] . ')';
}

// Preia utilizatorii din grupul selectat
$selectedUsers = [];
if ($responsibleGroupId > 0) {
    $dbUsers = CUser::GetList('id', 'asc', ['GROUPS_ID' => [$responsibleGroupId], 'ACTIVE' => 'Y']);
    while ($user = $dbUsers->Fetch()) {
        $selectedUsers[] = $user['NAME'] . ' ' . $user['LAST_NAME'] . ' (' . $user['LOGIN'] . ')';
    }
}
?>

<style>
.section-box { background: #fff; padding: 20px; margin-bottom: 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.section-title { font-size: 18px; font-weight: bold; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #2c7ed6; color: #2c3e50; }
.data-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.data-table th, .data-table td { padding: 10px; border: 1px solid #ddd; text-align: left; }
.data-table th { background: #f5f5f5; }
.btn-add { background: #4CAF50; color: white; border: none; padding: 5px 10px; cursor: pointer; margin-top: 10px; }
.btn-remove { background: #f44336; color: white; border: none; padding: 5px 10px; cursor: pointer; }
.user-preview { background: #f9f9f9; padding: 15px; border-radius: 6px; margin-top: 15px; }
.user-preview ul { margin: 5px 0 0 20px; max-height: 150px; overflow-y: auto; }
.notification-option { margin: 15px 0; padding: 10px; background: #f9f9f9; border-radius: 6px; }
.notification-option label { font-weight: bold; margin-right: 15px; }
.notification-option select { margin-left: 10px; padding: 5px; }
.notification-desc { color: #666; font-size: 12px; margin-top: 5px; margin-left: 25px; }
</style>

<!-- ========== SECȚIUNEA 1: GRUP UTILIZATORI ========== -->
<div class="section-box">
    <div class="section-title">👥 User Group for Allocations</div>
    <form method="POST">
        <table class="data-table">
            <tr>
                <td width="200"><strong>Responsible group:</strong></td>
                <td>
                    <select name="responsible_group" style="min-width:250px;">
                        <option value="0">- Select group -</option>
                        <?php foreach ($arGroups as $gid => $gname): ?>
                        <option value="<?= $gid ?>" <?= ($responsibleGroupId == $gid) ? 'selected' : '' ?>><?= htmlspecialchars($gname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
        <input type="submit" name="save_group" value="💾 Save group" class="adm-btn-save">
        <?php if ($responsibleGroupId > 0 && !empty($selectedUsers)): ?>
        <div class="user-preview">
            <strong>📋 Users in selected group (<?= count($selectedUsers) ?>):</strong>
            <ul><?php foreach ($selectedUsers as $user): ?><li><?= htmlspecialchars($user) ?></li><?php endforeach; ?></ul>
        </div>
        <?php elseif ($responsibleGroupId > 0): ?>
        <div class="user-preview"><strong>⚠️ No active users in this group.</strong></div>
        <?php endif; ?>
    </form>
</div>

<!-- ========== SECȚIUNEA 2: SETĂRI NOTIFICĂRI ========== -->
<div class="section-box">
    <div class="section-title">🔔 Notification Settings</div>
    <form method="POST">
        <div class="notification-option">
            <label>📢 New equipment added to inventory:</label>
            <select name="notification_new_equipment">
                <option value="Y" <?= ($notificationNewEquipment == 'Y') ? 'selected' : '' ?>>Yes - Notify group</option>
                <option value="N" <?= ($notificationNewEquipment == 'N') ? 'selected' : '' ?>>No - Don't notify</option>
            </select>
            <div class="notification-desc">When enabled, all users in the responsible group will receive a notification when a new equipment is added.</div>
        </div>
        
        <div class="notification-option">
            <label>📌 Equipment assigned to user:</label>
            <select name="notification_assignment">
                <option value="Y" <?= ($notificationAssignment == 'Y') ? 'selected' : '' ?>>Yes - Notify user</option>
                <option value="N" <?= ($notificationAssignment == 'N') ? 'selected' : '' ?>>No - Don't notify</option>
            </select>
            <div class="notification-desc">When enabled, the responsible user will receive a notification when equipment is assigned to them.</div>
        </div>
        
        <input type="submit" name="save_notification_settings" value="💾 Save notification settings" class="adm-btn-save">
    </form>
</div>

<!-- ========== SECȚIUNEA 3: TIPURI ECHIPAMENTE ========== -->
<div class="section-box">
    <div class="section-title">📋 Equipment Types</div>
    <form method="POST">
        <table class="data-table" id="types-table">
            <thead><tr><th>Code (unique)</th><th>Display name</th><th>Actions</th></tr></thead>
            <tbody id="types-body">
                <?php foreach ($tipuri as $tip): ?>
                <tr>
                    <td><input type="text" name="type_code[]" value="<?= htmlspecialchars($tip['CODE']) ?>" style="width:150px"></td>
                    <td><input type="text" name="type_name[]" value="<?= htmlspecialchars($tip['NAME']) ?>" style="width:250px"></td>
                    <td><button type="button" class="btn-remove" onclick="this.closest('tr').remove()">Delete</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="btn-add" onclick="addTypeRow()">+ Add new type</button>
        <input type="submit" name="save_types" value="💾 Save types" class="adm-btn-save">
    </form>
</div>

<!-- ========== SECȚIUNEA 4: STĂRI ECHIPAMENTE ========== -->
<div class="section-box">
    <div class="section-title">📊 Equipment Statuses</div>
    <form method="POST">
        <table class="data-table" id="status-table">
            <thead><tr><th>Code (unique)</th><th>Display name</th><th>Color</th><th>Actions</th></tr></thead>
            <tbody id="status-body">
                <?php foreach ($stari as $stare): ?>
                <tr>
                    <td><input type="text" name="status_code[]" value="<?= htmlspecialchars($stare['CODE']) ?>" style="width:150px"></td>
                    <td><input type="text" name="status_name[]" value="<?= htmlspecialchars($stare['NAME']) ?>" style="width:200px"></td>
                    <td><input type="color" name="status_color[]" value="<?= htmlspecialchars($stare['COLOR']) ?>"></td>
                    <td><button type="button" class="btn-remove" onclick="this.closest('tr').remove()">Delete</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="btn-add" onclick="addStatusRow()">+ Add new status</button>
        <input type="submit" name="save_status" value="💾 Save statuses" class="adm-btn-save">
    </form>
</div>

<script>
function addTypeRow() {
    const tbody = document.getElementById('types-body');
    const row = tbody.insertRow();
    row.innerHTML = `
        <td><input type="text" name="type_code[]" style="width:150px" placeholder="unique_code"></td>
        <td><input type="text" name="type_name[]" style="width:250px" placeholder="Display name"></td>
        <td><button type="button" class="btn-remove" onclick="this.closest('tr').remove()">Delete</button></td>
    `;
}

function addStatusRow() {
    const tbody = document.getElementById('status-body');
    const row = tbody.insertRow();
    row.innerHTML = `
        <td><input type="text" name="status_code[]" style="width:150px" placeholder="unique_code"></td>
        <td><input type="text" name="status_name[]" style="width:200px" placeholder="Display name"></td>
        <td><input type="color" name="status_color[]" value="#666666"></td>
        <td><button type="button" class="btn-remove" onclick="this.closest('tr').remove()">Delete</button></td>
    `;
}
</script>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>