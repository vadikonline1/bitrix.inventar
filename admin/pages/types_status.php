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

// Salvare tipuri - CODE-ul este generat automat
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_types'])) {
    // Șterge toate tipurile existente
    $existing = TypesTable::getList()->fetchAll();
    foreach ($existing as $item) {
        TypesTable::delete($item['ID']);
    }
    
    // Adaugă noile tipuri cu CODE generat automat
    for ($i = 0; $i < count($_POST['type_name'] ?? []); $i++) {
        $typeName = trim($_POST['type_name'][$i] ?? '');
        if (!empty($typeName)) {
            // Generăm CODE-ul automat ca număr (1, 2, 3, ...)
            $autoCode = ($i + 1);
            
            TypesTable::add([
                'CODE' => $autoCode,  // Cod generat automat
                'NAME' => $typeName,
                'SORT' => ($i + 1) * 10
            ]);
        }
    }
    CAdminMessage::ShowMessage("Types saved successfully!", "OK");
}

// Salvare stări - CODE-ul este generat automat
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_status'])) {
    // Șterge toate stările existente
    $existing = StatusTable::getList()->fetchAll();
    foreach ($existing as $item) {
        StatusTable::delete($item['ID']);
    }
    
    // Adaugă noile stări cu CODE generat automat
    for ($i = 0; $i < count($_POST['status_name'] ?? []); $i++) {
        $statusName = trim($_POST['status_name'][$i] ?? '');
        if (!empty($statusName)) {
            // Generăm CODE-ul automat ca număr (1, 2, 3, ...)
            $autoCode = ($i + 1);
            
            StatusTable::add([
                'CODE' => $autoCode,  // Cod generat automat
                'NAME' => $statusName,
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
.btn-add { background: #4CAF50; color: white; border: none; padding: 5px 10px; cursor: pointer; margin-top: 10px; border-radius: 4px; }
.btn-add:hover { background: #45a049; }
.btn-remove { background: #f44336; color: white; border: none; padding: 5px 10px; cursor: pointer; border-radius: 4px; }
.btn-remove:hover { background: #d32f2f; }
.user-preview { background: #f9f9f9; padding: 15px; border-radius: 6px; margin-top: 15px; }
.user-preview ul { margin: 5px 0 0 20px; max-height: 150px; overflow-y: auto; }
.notification-option { margin: 15px 0; padding: 10px; background: #f9f9f9; border-radius: 6px; }
.notification-option label { font-weight: bold; margin-right: 15px; }
.notification-option select { margin-left: 10px; padding: 5px; }
.notification-desc { color: #666; font-size: 12px; margin-top: 5px; margin-left: 25px; }
.code-auto { color: #2c7ed6; font-weight: bold; background: #e8f0fe; padding: 2px 8px; border-radius: 4px; font-size: 13px; display: inline-block; min-width: 30px; text-align: center; }
.code-help { font-size: 11px; color: #666; font-weight: normal; display: block; margin-top: 2px; }
.status-color-preview { display: inline-block; width: 20px; height: 20px; border-radius: 4px; border: 1px solid #ddd; vertical-align: middle; margin-right: 5px; }
.info-box { background: #e8f0fe; padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #2c7ed6; }
.info-box strong { color: #2c7ed6; }
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
    
    <?php
    // Procesare marcare toate notificările ca trimise
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_all_notifications_sent'])) {
        if (class_exists('\Bitrix\Inventar\EquipmentTable')) {
            $count = \Bitrix\Inventar\EquipmentTable::markAllNotificationsAsSent();
            CAdminMessage::ShowMessage("All existing equipment marked as notified! ({$count} records updated)", "OK");
        } else {
            CAdminMessage::ShowMessage("EquipmentTable class not found!", "ERROR");
        }
        LocalRedirect($APPLICATION->GetCurPage());
    }
    
    // Obține numărul de echipamente care nu sunt încă notificate
    $pendingNotifications = 0;
    if (class_exists('\Bitrix\Inventar\EquipmentTable')) {
        $pendingNotifications = \Bitrix\Inventar\EquipmentTable::getCount(['!=NOTIFICATION_SENT' => 'Y']);
    }
    ?>
    
    <?php if ($pendingNotifications > 0): ?>
    <form method="POST" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
        <div class="notification-option" style="background: #fff3cd;">
            <label>⚠️ Pending notifications:</label>
            <div class="notification-desc" style="margin-left: 0;">
                There are <strong><?= $pendingNotifications ?></strong> equipment records that haven't been notified yet.
                If you have disabled notifications, you can mark them as "sent" to prevent future notifications.
            </div>
            <button type="submit" name="mark_all_notifications_sent" class="adm-btn" style="background: #ff9800; margin-top: 10px;" 
                    onclick="return confirm('Are you sure? This will mark all existing equipment as notified and prevent any pending notifications from being sent.')">
                📝 Mark all as notified
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>

<!-- ========== SECȚIUNEA 3: TIPURI ECHIPAMENTE (cu COD automat) ========== -->
<div class="section-box">
    <div class="section-title">📋 Equipment Types</div>
    <div class="info-box">
        <strong>ℹ️ Info:</strong> The <strong>Code</strong> is automatically generated (1, 2, 3, ...) based on the order.
        You only need to enter the <strong>Display name</strong>.
    </div>
    <form method="POST">
        <table class="data-table" id="types-table">
            <thead>
                <tr>
                    <th style="width: 120px;">Code (auto)</th>
                    <th>Display name *</th>
                    <th style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody id="types-body">
                <?php 
                $typeIndex = 1;
                foreach ($tipuri as $tip): 
                ?>
                <tr>
                    <td>
                        <span class="code-auto">#<?= $typeIndex ?></span>
                        <input type="hidden" name="type_code[]" value="<?= $typeIndex ?>">
                    </td>
                    <td>
                        <input type="text" name="type_name[]" value="<?= htmlspecialchars($tip['NAME']) ?>" style="width:100%" placeholder="Enter type name...">
                    </td>
                    <td>
                        <button type="button" class="btn-remove" onclick="removeTypeRow(this)">Delete</button>
                    </td>
                </tr>
                <?php 
                $typeIndex++;
                endforeach; 
                ?>
            </tbody>
        </table>
        <button type="button" class="btn-add" onclick="addTypeRow()">+ Add new type</button>
        <input type="submit" name="save_types" value="💾 Save types" class="adm-btn-save">
    </form>
</div>

<!-- ========== SECȚIUNEA 4: STĂRI ECHIPAMENTE (cu COD automat) ========== -->
<div class="section-box">
    <div class="section-title">📊 Equipment Statuses</div>
    <div class="info-box">
        <strong>ℹ️ Info:</strong> The <strong>Code</strong> is automatically generated (1, 2, 3, ...) based on the order.
        You only need to enter the <strong>Display name</strong> and choose a <strong>Color</strong>.
    </div>
    <form method="POST">
        <table class="data-table" id="status-table">
            <thead>
                <tr>
                    <th style="width: 120px;">Code (auto)</th>
                    <th>Display name *</th>
                    <th style="width: 100px;">Color</th>
                    <th style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody id="status-body">
                <?php 
                $statusIndex = 1;
                foreach ($stari as $stare): 
                ?>
                <tr>
                    <td>
                        <span class="code-auto">#<?= $statusIndex ?></span>
                        <input type="hidden" name="status_code[]" value="<?= $statusIndex ?>">
                    </td>
                    <td>
                        <input type="text" name="status_name[]" value="<?= htmlspecialchars($stare['NAME']) ?>" style="width:100%" placeholder="Enter status name...">
                    </td>
                    <td>
                        <input type="color" name="status_color[]" value="<?= htmlspecialchars($stare['COLOR']) ?>" style="width:60px; height:32px; cursor:pointer;">
                        <span class="status-color-preview" style="background:<?= htmlspecialchars($stare['COLOR']) ?>;"></span>
                    </td>
                    <td>
                        <button type="button" class="btn-remove" onclick="removeStatusRow(this)">Delete</button>
                    </td>
                </tr>
                <?php 
                $statusIndex++;
                endforeach; 
                ?>
            </tbody>
        </table>
        <button type="button" class="btn-add" onclick="addStatusRow()">+ Add new status</button>
        <input type="submit" name="save_status" value="💾 Save statuses" class="adm-btn-save">
    </form>
</div>

<script>
// ========== FUNCȚII PENTRU TIPURI ==========
function addTypeRow() {
    const tbody = document.getElementById('types-body');
    const rows = tbody.querySelectorAll('tr');
    const nextIndex = rows.length + 1;
    
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <span class="code-auto">#${nextIndex}</span>
            <input type="hidden" name="type_code[]" value="${nextIndex}">
        </td>
        <td>
            <input type="text" name="type_name[]" style="width:100%" placeholder="Enter type name...">
        </td>
        <td>
            <button type="button" class="btn-remove" onclick="removeTypeRow(this)">Delete</button>
        </td>
    `;
    tbody.appendChild(row);
}

function removeTypeRow(button) {
    if (confirm('Are you sure you want to delete this type?')) {
        const row = button.closest('tr');
        row.remove();
        recalculateTypeIndexes();
    }
}

function recalculateTypeIndexes() {
    const rows = document.querySelectorAll('#types-body tr');
    rows.forEach(function(row, index) {
        const newIndex = index + 1;
        const codeSpan = row.querySelector('.code-auto');
        const hiddenInput = row.querySelector('input[name="type_code[]"]');
        if (codeSpan) {
            codeSpan.textContent = '#' + newIndex;
        }
        if (hiddenInput) {
            hiddenInput.value = newIndex;
        }
    });
}

// ========== FUNCȚII PENTRU STĂRI ==========
function addStatusRow() {
    const tbody = document.getElementById('status-body');
    const rows = tbody.querySelectorAll('tr');
    const nextIndex = rows.length + 1;
    
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <span class="code-auto">#${nextIndex}</span>
            <input type="hidden" name="status_code[]" value="${nextIndex}">
        </td>
        <td>
            <input type="text" name="status_name[]" style="width:100%" placeholder="Enter status name...">
        </td>
        <td>
            <input type="color" name="status_color[]" value="#666666" style="width:60px; height:32px; cursor:pointer;">
            <span class="status-color-preview" style="background:#666666; display:inline-block; width:20px; height:20px; border-radius:4px; border:1px solid #ddd; vertical-align:middle;"></span>
        </td>
        <td>
            <button type="button" class="btn-remove" onclick="removeStatusRow(this)">Delete</button>
        </td>
    `;
    tbody.appendChild(row);
    
    // Adaugă event listener pentru previzualizarea culorii
    const colorInput = row.querySelector('input[type="color"]');
    const preview = row.querySelector('.status-color-preview');
    colorInput.addEventListener('input', function() {
        preview.style.background = this.value;
    });
}

function removeStatusRow(button) {
    if (confirm('Are you sure you want to delete this status?')) {
        const row = button.closest('tr');
        row.remove();
        recalculateStatusIndexes();
    }
}

function recalculateStatusIndexes() {
    const rows = document.querySelectorAll('#status-body tr');
    rows.forEach(function(row, index) {
        const newIndex = index + 1;
        const codeSpan = row.querySelector('.code-auto');
        const hiddenInput = row.querySelector('input[name="status_code[]"]');
        if (codeSpan) {
            codeSpan.textContent = '#' + newIndex;
        }
        if (hiddenInput) {
            hiddenInput.value = newIndex;
        }
    });
}

// ========== INITIALIZARE ==========
document.addEventListener('DOMContentLoaded', function() {
    // Inițializează previzualizările culorilor pentru stări existente
    document.querySelectorAll('#status-body tr').forEach(function(row) {
        const colorInput = row.querySelector('input[type="color"]');
        const preview = row.querySelector('.status-color-preview');
        if (colorInput && preview) {
            preview.style.background = colorInput.value;
            colorInput.addEventListener('input', function() {
                preview.style.background = this.value;
            });
        }
    });
    
    // Auto-submit pe Enter pentru câmpurile de input
    document.querySelectorAll('#types-body input, #status-body input').forEach(function(input) {
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                // Găsește formularul părinte și submitează-l
                const form = this.closest('form');
                if (form) {
                    form.submit();
                }
            }
        });
    });
});
</script>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>