<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Inventar\TypesTable;
use Bitrix\Inventar\StatusTable;
use Bitrix\Inventar\CustomFieldsTable;

Loader::includeModule('bitrix.inventar');


$APPLICATION->SetTitle("Types and Statuses Management");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

if ($APPLICATION->GetGroupRight("bitrix.inventar") < "W") {
    $APPLICATION->AuthForm("Access denied");
}

// Salvare tipuri — CODE = ID numeric.
// Update in place dupa ID (ID-urile nu se schimba niciodata); randurile noi
// primesc CODE = ID-ul generat. Echipamentele (TIP_ENUM) SI campurile custom
// (TYPE_CODE) care referentiau vechiul CODE sunt migrate automat pe noul CODE,
// deci Custom Fields by Type nu se mai "reseteaza".
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_types'])) {
    $existing = TypesTable::getList()->fetchAll();
    $byId = [];
    foreach ($existing as $e) $byId[(int)$e['ID']] = $e;

    $ids = $_POST['type_id'] ?? [];
    $names = $_POST['type_name'] ?? [];

    // Garda anti-wipe: submit complet gol nu sterge tipurile existente.
    $hasValid = false;
    foreach ($names as $n) {
        if (trim((string)$n) !== '') { $hasValid = true; break; }
    }
    if (!$hasValid && !empty($byId)) {
        CAdminMessage::ShowMessage([
            'MESSAGE' => 'Nothing to save: all type names are empty. Existing types and custom fields kept.',
            'TYPE' => 'ERROR',
        ]);
    } else {
        $seenIds = [];
        $sort = 10;

        $connection = \Bitrix\Main\Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();
        $eqTable = \Bitrix\Inventar\EquipmentTable::getTableName();
        $cfTable = CustomFieldsTable::getTableName();

        for ($i = 0; $i < count($names); $i++) {
            $typeName = trim((string)($names[$i] ?? ''));
            if ($typeName === '') continue;
            $id = (int)($ids[$i] ?? 0);
            if ($id > 0 && isset($byId[$id])) {
                $oldCode = (string)$byId[$id]['CODE'];
                $newCode = (string)$id;
                TypesTable::update($id, ['CODE' => $newCode, 'NAME' => $typeName, 'SORT' => $sort]);
                if ($oldCode !== '' && $oldCode !== $newCode) {
                    try {
                        $connection->queryExecute(
                            "UPDATE `{$eqTable}` SET TIP_ENUM = '" . $sqlHelper->forSql($newCode) .
                            "' WHERE TIP_ENUM = '" . $sqlHelper->forSql($oldCode) . "'"
                        );
                    } catch (\Exception $e) {}
                    try {
                        $connection->queryExecute(
                            "UPDATE `{$cfTable}` SET TYPE_CODE = '" . $sqlHelper->forSql($newCode) .
                            "' WHERE TYPE_CODE = '" . $sqlHelper->forSql($oldCode) . "'"
                        );
                    } catch (\Exception $e) {}
                }
                $seenIds[$id] = true;
            } else {
                $tmpCode = 'tmp_' . uniqid();
                $res = TypesTable::add(['CODE' => $tmpCode, 'NAME' => $typeName, 'SORT' => $sort]);
                if ($res->isSuccess()) {
                    $newId = (int)$res->getId();
                    TypesTable::update($newId, ['CODE' => (string)$newId]);
                    $seenIds[$newId] = true;
                }
            }
            $sort += 10;
        }

        foreach ($byId as $id => $e) {
            if (!isset($seenIds[$id])) {
                try { TypesTable::delete($id); } catch (\Exception $ex) {}
            }
        }

        // Heal orfani pre-existenti: custom fields si echipamente al caror cod
        // nu mai exista, dar coincide cu NAME-ul unui tip → remap pe CODE canonic.
        $healedMsg = healOrphanCodes('type');

        CAdminMessage::ShowMessage("Types saved successfully!" . $healedMsg, "OK");
    }
}

// Salvare stari — CODE = ID numeric (1=In use, 2=In stock, 3=In repair...).
// Acelasi mecanism ca la tipuri, cu migrare STARE_ENUM.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_status'])) {
    $existing = StatusTable::getList()->fetchAll();
    $byId = [];
    foreach ($existing as $e) $byId[(int)$e['ID']] = $e;

    $ids = $_POST['status_id'] ?? [];
    $names = $_POST['status_name'] ?? [];
    $colors = $_POST['status_color'] ?? [];

    // Garda anti-wipe: submit complet gol nu sterge starile existente.
    $hasValid = false;
    foreach ($names as $n) {
        if (trim((string)$n) !== '') { $hasValid = true; break; }
    }
    if (!$hasValid && !empty($byId)) {
        CAdminMessage::ShowMessage([
            'MESSAGE' => 'Nothing to save: all status names are empty. Existing statuses kept.',
            'TYPE' => 'ERROR',
        ]);
    } else {
        $seenIds = [];
        $sort = 10;

        $connection = \Bitrix\Main\Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();
        $eqTable = \Bitrix\Inventar\EquipmentTable::getTableName();

        for ($i = 0; $i < count($names); $i++) {
            $statusName = trim((string)($names[$i] ?? ''));
            if ($statusName === '') continue;
            $id = (int)($ids[$i] ?? 0);
            $color = $colors[$i] ?? '#666666';
            if ($id > 0 && isset($byId[$id])) {
                $oldCode = (string)$byId[$id]['CODE'];
                $newCode = (string)$id;
                StatusTable::update($id, ['CODE' => $newCode, 'NAME' => $statusName, 'COLOR' => $color, 'SORT' => $sort]);
                if ($oldCode !== '' && $oldCode !== $newCode) {
                    try {
                        $connection->queryExecute(
                            "UPDATE `{$eqTable}` SET STARE_ENUM = '" . $sqlHelper->forSql($newCode) .
                            "' WHERE STARE_ENUM = '" . $sqlHelper->forSql($oldCode) . "'"
                        );
                    } catch (\Exception $e) {}
                }
                $seenIds[$id] = true;
            } else {
                $tmpCode = 'tmp_' . uniqid();
                $res = StatusTable::add(['CODE' => $tmpCode, 'NAME' => $statusName, 'COLOR' => $color, 'SORT' => $sort]);
                if ($res->isSuccess()) {
                    $newId = (int)$res->getId();
                    StatusTable::update($newId, ['CODE' => (string)$newId]);
                    $seenIds[$newId] = true;
                }
            }
            $sort += 10;
        }

        foreach ($byId as $id => $e) {
            if (!isset($seenIds[$id])) {
                try { StatusTable::delete($id); } catch (\Exception $ex) {}
            }
        }

        // Heal orfani pre-existenti (stari).
        $healedMsg = healOrphanCodes('status');

        CAdminMessage::ShowMessage("Statuses saved successfully!" . $healedMsg, "OK");
    }
}

/**
 * Heal orfani: inregistrari (echipamente TIP_ENUM/STARE_ENUM, custom fields TYPE_CODE)
 * al caror cod nu mai exista in dictionar, dar coincide cu NAME-ul unei intrari
 * (comparatie insensibila, '_'/'-' tratate ca spatii) → remap pe CODE-ul canonic.
 * Intoarce textul de raport ('' daca nu s-a remapat nimic).
 */
function healOrphanCodes($kind)
{
    $healedCf = 0;
    $healedEq = 0;
    try {
        if ($kind === 'type') {
            $rows = \Bitrix\Inventar\TypesTable::getList()->fetchAll();
            $eqColumn = 'TIP_ENUM';
        } else {
            $rows = \Bitrix\Inventar\StatusTable::getList()->fetchAll();
            $eqColumn = 'STARE_ENUM';
        }

        $validCodes = [];
        $normNameToCode = [];
        foreach ($rows as $r) {
            $validCodes[(string)$r['CODE']] = true;
            $normNameToCode[mb_strtolower(trim(str_replace(['_', '-'], ' ', (string)$r['NAME'])))] = (string)$r['CODE'];
        }
        if (empty($normNameToCode)) return '';

        $norm = function ($v) {
            return mb_strtolower(trim(str_replace(['_', '-'], ' ', (string)$v)));
        };

        // 1. Custom fields (doar pentru tipuri).
        if ($kind === 'type') {
            $allCf = \Bitrix\Inventar\CustomFieldsTable::getList(['select' => ['ID', 'TYPE_CODE']])->fetchAll();
            foreach ($allCf as $cf) {
                $tc = (string)($cf['TYPE_CODE'] ?? '');
                if ($tc === '' || isset($validCodes[$tc])) continue;
                $k = $norm($tc);
                if (isset($normNameToCode[$k])) {
                    try {
                        \Bitrix\Inventar\CustomFieldsTable::update($cf['ID'], ['TYPE_CODE' => $normNameToCode[$k]]);
                        $healedCf++;
                    } catch (\Exception $e) {}
                }
            }
        }

        // 2. Echipamente.
        $allEq = \Bitrix\Inventar\EquipmentTable::getList(['select' => ['ID', $eqColumn]])->fetchAll();
        foreach ($allEq as $eq) {
            $v = (string)($eq[$eqColumn] ?? '');
            if ($v === '' || isset($validCodes[$v])) continue;
            $k = $norm($v);
            if (isset($normNameToCode[$k])) {
                try {
                    \Bitrix\Inventar\EquipmentTable::update($eq['ID'], [$eqColumn => $normNameToCode[$k]]);
                    $healedEq++;
                } catch (\Exception $e) {}
            }
        }
    } catch (\Exception $e) {
        return '';
    }

    $msg = '';
    if ($healedCf > 0) $msg .= " Custom fields remapped: <strong>{$healedCf}</strong>.";
    if ($healedEq > 0) $msg .= " Equipment remapped: <strong>{$healedEq}</strong>.";
    return $msg;
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
$notificationNewEquipment = Option::get('bitrix.inventar', 'notification_new_equipment', 'N');
$notificationAssignment = Option::get('bitrix.inventar', 'notification_assignment', 'N');

// Obține datele curente
$tipuri = TypesTable::getList(['order' => ['SORT' => 'ASC']])->fetchAll();
$stari = StatusTable::getList(['order' => ['SORT' => 'ASC']])->fetchAll();
$responsibleGroupId = Option::get('bitrix.inventar', 'responsible_group_id', 0);

// ========== SALVARE SETĂRI EXTERNAL API ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_external_api'])) {
    Option::set('bitrix.inventar', 'external_api_url', trim($_POST['external_api_url'] ?? ''));
    $extField = strtoupper(trim($_POST['external_api_field'] ?? 'ASSET_UUID'));
    if (!in_array($extField, ['ASSET_UUID', 'COD_INVENTAR', 'SERIAL_NR'], true)) $extField = 'ASSET_UUID';
    Option::set('bitrix.inventar', 'external_api_field', $extField);
    Option::set('bitrix.inventar', 'external_api_token', trim($_POST['external_api_token'] ?? ''));
    Option::set('bitrix.inventar', 'external_ssl_skip', ($_POST['external_ssl_skip'] ?? 'N') === 'Y' ? 'Y' : 'N');
    Option::set('bitrix.inventar', 'external_sync_days', (string)max(1, (int)($_POST['external_sync_days'] ?? 7)));
    Option::set('bitrix.inventar', 'external_sync_enabled', ($_POST['external_sync_enabled'] ?? 'N') === 'Y' ? 'Y' : 'N');

    $agentMsg = \Bitrix\Inventar\ExternalSync::refreshAgent();
    CAdminMessage::ShowMessage("External API settings saved successfully! " . htmlspecialchars($agentMsg), "OK");
    LocalRedirect($APPLICATION->GetCurPage());
}

// ========== TEST EXTERNAL CONNECTION (nu salveaza nimic) ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['test_external_api'])) {
    $tUrl = trim($_POST['external_api_url'] ?? '');
    if ($tUrl === '') $tUrl = \Bitrix\Inventar\ExternalSync::getConfig()['url'];
    $tToken = trim($_POST['external_api_token'] ?? '');
    if ($tToken === '' && !isset($_POST['external_api_token'])) {
        $tToken = \Bitrix\Inventar\ExternalSync::getConfig()['token'];
    }
    $tSkip = ($_POST['external_ssl_skip'] ?? 'N') === 'Y';
    $tKey = trim($_POST['test_asset_uuid'] ?? '');
    if ($tKey === '') {
        // fallback: primul echipament cu cheie completata
        try {
            $cfg0 = \Bitrix\Inventar\ExternalSync::getConfig();
            $kf = in_array($cfg0['field'], ['ASSET_UUID', 'COD_INVENTAR', 'SERIAL_NR'], true) ? $cfg0['field'] : 'ASSET_UUID';
            $first = \Bitrix\Inventar\EquipmentTable::getList([
                'filter' => ['!=' . $kf => ''],
                'select' => [$kf],
                'order' => ['ID' => 'ASC'],
                'limit' => 1,
            ])->fetch();
            if ($first) $tKey = trim((string)$first[$kf]);
        } catch (\Exception $e) {}
    }
    try {
        $tRes = \Bitrix\Inventar\ExternalSync::testConnection($tUrl, $tKey, $tToken, $tSkip);
    } catch (\Exception $e) {
        $tRes = ['ok' => false, 'http' => 0, 'error' => $e->getMessage(), 'url' => ''];
    }
    if ($tRes['ok']) {
        CAdminMessage::ShowNote(
            "Connection OK: HTTP <strong>{$tRes['http']}</strong>, received <strong>{$tRes['bytes']}</strong> bytes.<br>" .
            "<code>" . htmlspecialchars($tRes['url']) . "</code>"
        );
    } else {
        CAdminMessage::ShowMessage([
            'MESSAGE' => "Connection FAILED" . ($tRes['http'] ? " (HTTP {$tRes['http']})" : "") . ": " .
                htmlspecialchars($tRes['error']) . "<br><code>" . htmlspecialchars($tRes['url']) . "</code>",
            'TYPE' => 'ERROR',
            'HTML' => true,
        ]);
    }
}

// ========== SYNC NOW (toate echipamentele cu cheie) ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sync_external_all'])) {
    try {
        $syncRes = \Bitrix\Inventar\ExternalSync::syncAll(true);
    } catch (\Exception $e) {
        $syncRes = ['ok' => false, 'error' => $e->getMessage()];
    }
    if ($syncRes['ok']) {
        $msg = "Sync completed: <strong>{$syncRes['synced']}</strong> synced, "
            . "<strong>{$syncRes['skipped']}</strong> skipped, "
            . "<strong>{$syncRes['errors']}</strong> errors (of {$syncRes['total']} with key).";
        if (!empty($syncRes['error_samples'])) {
            $msg .= "<br>" . implode("<br>", array_map('htmlspecialchars', $syncRes['error_samples']));
        }
        CAdminMessage::ShowNote($msg);
    } else {
        CAdminMessage::ShowMessage(['MESSAGE' => "Sync failed: " . htmlspecialchars($syncRes['error']), 'TYPE' => 'ERROR', 'HTML' => true]);
    }
}

$extCfg = \Bitrix\Inventar\ExternalSync::getConfig();
$extWithKey = 0;
$extSynced = 0;
try {
    $extFieldCheck = in_array($extCfg['field'], ['ASSET_UUID', 'COD_INVENTAR', 'SERIAL_NR'], true) ? $extCfg['field'] : 'ASSET_UUID';
    $extWithKey = \Bitrix\Inventar\EquipmentTable::getCount(['!=' . $extFieldCheck => '']);
    $allExt = \Bitrix\Inventar\EquipmentTable::getList(['filter' => ['!=' . $extFieldCheck => ''], 'select' => ['EXTERNAL_API']])->fetchAll();
    foreach ($allExt as $row) {
        if (!empty($row['EXTERNAL_API'])) $extSynced++;
    }
} catch (\Exception $e) {}

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

<!-- ========== SECȚIUNEA 3b: EXTERNAL API (Active) ========== -->
<div class="section-box">
    <div class="section-title">🌐 External API (Active) — sync asset data</div>
    <div class="info-box">
        <strong>ℹ️ How it works:</strong> for each equipment having the key field filled,
        the module calls <code>{Base URL}/{key}</code>,
        e.g. <code>http://10.130.10.232:8081/api/v1/assets/b0c648a6-106e-4374-9404-6aed6e883686</code>.
        The raw response is stored in <code>EXTERNAL_API</code> and shown on the equipment details page
        (<code>/inventar/?id=...</code>).
        Current status: <strong><?= $extWithKey ?></strong> equipment with key (<?= htmlspecialchars($extCfg['field']) ?>),
        <strong><?= $extSynced ?></strong> already synced.
    </div>
    <form method="POST" id="extApiForm">
        <div class="notification-option">
            <label>Base URL:</label>
            <input type="text" name="external_api_url" value="<?= htmlspecialchars($extCfg['url']) ?>" style="width: 480px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="http://10.130.10.232:8081/api/v1/assets/">
            <div class="notification-desc">Full item URL = Base URL + key value. Keep the trailing slash.</div>
        </div>

        <div class="notification-option">
            <label>Key field (local):</label>
            <select name="external_api_field">
                <option value="ASSET_UUID" <?= $extCfg['field'] === 'ASSET_UUID' ? 'selected' : '' ?>>Asset UUID (ASSET_UUID)</option>
                <option value="COD_INVENTAR" <?= $extCfg['field'] === 'COD_INVENTAR' ? 'selected' : '' ?>>Inventory code (COD_INVENTAR)</option>
                <option value="SERIAL_NR" <?= $extCfg['field'] === 'SERIAL_NR' ? 'selected' : '' ?>>Serial number (SERIAL_NR)</option>
            </select>
            <div class="notification-desc">Which equipment field is appended to the Base URL.</div>
        </div>

        <div class="notification-option">
            <label>Token (optional):</label>
            <input type="text" name="external_api_token" value="<?= htmlspecialchars($extCfg['token']) ?>" style="width: 400px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="Bearer token, if the API requires auth" autocomplete="off">
            <div class="notification-desc">Sent as <code>Authorization: Bearer &lt;token&gt;</code>. Leave empty for open APIs.</div>
        </div>

        <div class="notification-option">
            <label>Skip SSL verification:</label>
            <select name="external_ssl_skip">
                <option value="Y" <?= !empty($extCfg['ssl_skip']) ? 'selected' : '' ?>>Yes — intranet with self-signed certificate</option>
                <option value="N" <?= empty($extCfg['ssl_skip']) ? 'selected' : '' ?>>No — verify certificate (default)</option>
            </select>
            <div class="notification-desc">Enable only if sync fails with SSL/certificate errors on an internal HTTPS host (e.g. <code>https://assets.intranet...</code>).</div>
        </div>

        <div class="notification-option">
            <label>Auto re-sync every (days):</label>
            <input type="number" name="external_sync_days" value="<?= (int)$extCfg['days'] ?>" min="1" max="365" style="width: 90px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            <div class="notification-desc">Items synced more recently than this are skipped by the automatic sync.</div>
        </div>

        <div class="notification-option">
            <label>Auto sync (Bitrix agent / cron):</label>
            <select name="external_sync_enabled">
                <option value="Y" <?= $extCfg['enabled'] ? 'selected' : '' ?>>Yes — run automatically every X days</option>
                <option value="N" <?= !$extCfg['enabled'] ? 'selected' : '' ?>>No — manual sync only</option>
            </select>
            <div class="notification-desc">Uses the Bitrix agents system (needs agents/cron active on the server). Saving re-creates the agent.</div>
        </div>

        <input type="submit" name="save_external_api" value="💾 Save external API settings" class="adm-btn-save">
    </form>

    <form method="POST" id="extApiTestForm" style="margin-top:15px; padding-top:15px; border-top:1px solid #eee;">
        <div class="notification-option" style="background:#e8f0fe;">
            <label>🔌 Test connection (saves nothing):</label>
            <div class="notification-desc" style="margin-left:0;">
                Tries one GET with the URL/token/SSL settings currently typed above and shows HTTP status or error.
                Uses the UUID below, or the first equipment having the key field filled.
            </div>
            <div style="margin-top:8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="text" name="test_asset_uuid" value="" style="width: 340px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="UUID to test (optional)">
                <button type="submit" name="test_external_api" class="adm-btn" style="background:#607D8B;">🔌 Test connection</button>
            </div>
            <input type="hidden" name="external_api_url" value="<?= htmlspecialchars($extCfg['url']) ?>">
            <input type="hidden" name="external_api_token" value="<?= htmlspecialchars($extCfg['token']) ?>">
            <input type="hidden" name="external_ssl_skip" value="<?= !empty($extCfg['ssl_skip']) ? 'Y' : 'N' ?>">
        </div>
    </form>
    <script>
    // Testul foloseste valorile tastate in formularul de mai sus (nu doar cele salvate).
    (function () {
        var testForm = document.getElementById('extApiTestForm');
        if (!testForm) return;
        testForm.addEventListener('submit', function () {
            var src = document.getElementById('extApiForm');
            if (!src) return;
            ['external_api_url', 'external_api_token', 'external_ssl_skip'].forEach(function (n) {
                var s = src.querySelector('[name="' + n + '"]');
                var d = testForm.querySelector('input[name="' + n + '"]');
                if (s && d) d.value = s.value;
            });
        });
    })();
    </script>

    <form method="POST" style="margin-top:15px; padding-top:15px; border-top:1px solid #eee;" onsubmit="return confirm('Sync ALL equipment having the key field filled? This may take a while.');">
        <div class="notification-option" style="background:#e8f5e9;">
            <label>🔄 Manual sync now:</label>
            <div class="notification-desc" style="margin-left:0;">
                Re-syncs all <strong><?= $extWithKey ?></strong> equipment having <strong><?= htmlspecialchars($extCfg['field']) ?></strong> filled.
                Single-item sync is also available on each equipment edit page.
                For fully automatic sync, enable "Auto sync" above (Bitrix agent, every X days).
            </div>
            <button type="submit" name="sync_external_all" class="adm-btn" style="background:#4CAF50; margin-top:10px;">🔄 Sync now (all with key)</button>
        </div>
    </form>
</div>

<!-- ========== SECȚIUNEA 4: TIPURI ECHIPAMENTE (cu COD automat) ========== -->
<div class="section-box">
    <div class="section-title">📋 Equipment Types</div>
    <div class="info-box">
        <strong>ℹ️ Info:</strong> The <strong>Code is the numeric row ID</strong> and never changes.
        Renaming a type keeps all equipment linked. New rows get the next ID on save.
    </div>
    <form method="POST">
        <table class="data-table" id="types-table">
            <thead>
                <tr>
                    <th style="width: 120px;">Code (= ID)</th>
                    <th>Display name *</th>
                    <th style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody id="types-body">
                <?php
                foreach ($tipuri as $tip):
                ?>
                <tr>
                    <td>
                        <span class="code-auto"><?= (int)$tip['ID'] ?></span>
                        <input type="hidden" name="type_id[]" value="<?= (int)$tip['ID'] ?>">
                    </td>
                    <td>
                        <input type="text" name="type_name[]" value="<?= htmlspecialchars($tip['NAME']) ?>" style="width:100%" placeholder="Enter type name...">
                    </td>
                    <td>
                        <button type="button" class="btn-remove" onclick="removeTypeRow(this)">Delete</button>
                    </td>
                </tr>
                <?php
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
        <strong>ℹ️ Info:</strong> The <strong>Code is the numeric row ID</strong> and never changes.
        Renaming a status keeps all equipment linked. New rows get the next ID on save.
    </div>
    <form method="POST">
        <table class="data-table" id="status-table">
            <thead>
                <tr>
                    <th style="width: 120px;">Code (= ID)</th>
                    <th>Display name *</th>
                    <th style="width: 100px;">Color</th>
                    <th style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody id="status-body">
                <?php
                foreach ($stari as $stare):
                ?>
                <tr>
                    <td>
                        <span class="code-auto"><?= (int)$stare['ID'] ?></span>
                        <input type="hidden" name="status_id[]" value="<?= (int)$stare['ID'] ?>">
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

    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <span class="code-auto" style="color:#999;">(new)</span>
            <input type="hidden" name="type_id[]" value="">
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
    }
}

function recalculateTypeIndexes() {
    // Codurile sunt stabile — nu mai renumerotam. Functie pastrata pentru compatibilitate.
}

// ========== FUNCȚII PENTRU STĂRI ==========
function addStatusRow() {
    const tbody = document.getElementById('status-body');

    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <span class="code-auto" style="color:#999;">(new)</span>
            <input type="hidden" name="status_id[]" value="">
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
    }
}

function recalculateStatusIndexes() {
    // Codurile sunt stabile — nu mai renumerotam. Functie pastrata pentru compatibilitate.
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