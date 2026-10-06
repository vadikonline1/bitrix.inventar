<?php
// Pagina standard de setari Bitrix: Settings -> System settings -> IT Inventory.
// Bitrix o incarca automat cand exista fisierul options.php in radacina modulului.
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

$module_id = 'bitrix.inventar';
Loader::includeModule($module_id);

$MID = $module_id;

if ($REQUEST_METHOD === 'POST' && check_bitrix_sessid()) {
    Option::set($module_id, 'responsible_group_id', (string)(int)($_POST['responsible_group_id'] ?? 0));
    Option::set($module_id, 'notification_new_equipment', ($_POST['notification_new_equipment'] === 'Y' ? 'Y' : 'N'));
    Option::set($module_id, 'notification_assignment', ($_POST['notification_assignment'] === 'Y' ? 'Y' : 'N'));
    // External API (aceleasi chei ca in Types & Statuses)
    Option::set($module_id, 'external_api_url', trim($_POST['external_api_url'] ?? ''));
    $extField = strtoupper(trim($_POST['external_api_field'] ?? 'ASSET_UUID'));
    if (!in_array($extField, ['ASSET_UUID', 'COD_INVENTAR', 'SERIAL_NR'], true)) $extField = 'ASSET_UUID';
    Option::set($module_id, 'external_api_field', $extField);
    Option::set($module_id, 'external_api_token', trim($_POST['external_api_token'] ?? ''));
    Option::set($module_id, 'external_ssl_skip', ($_POST['external_ssl_skip'] ?? 'N') === 'Y' ? 'Y' : 'N');
    Option::set($module_id, 'external_sync_days', (string)max(1, (int)($_POST['external_sync_days'] ?? 7)));
    Option::set($module_id, 'external_sync_enabled', ($_POST['external_sync_enabled'] ?? 'N') === 'Y' ? 'Y' : 'N');
    if (class_exists('Bitrix\Inventar\ExternalSync')) {
        \Bitrix\Inventar\ExternalSync::refreshAgent();
    }
    LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . LANGUAGE_ID . '&saved=Y');
}

$responsibleGroupId = (int)Option::get($module_id, 'responsible_group_id', 0);
$notifNew = Option::get($module_id, 'notification_new_equipment', 'N');
$notifAssign = Option::get($module_id, 'notification_assignment', 'N');
$extUrl = Option::get($module_id, 'external_api_url', '');
$extField = Option::get($module_id, 'external_api_field', 'ASSET_UUID');
$extToken = Option::get($module_id, 'external_api_token', '');
$extSslSkip = Option::get($module_id, 'external_ssl_skip', 'N');
$extDays = (int)Option::get($module_id, 'external_sync_days', 7);
$extEnabled = Option::get($module_id, 'external_sync_enabled', 'N');

$arGroups = [];
$dbGroups = CGroup::GetList('c_sort', 'asc', ['ACTIVE' => 'Y']);
while ($g = $dbGroups->Fetch()) {
    $arGroups[$g['ID']] = $g['NAME'] . ' (' . $g['STRING_ID'] . ')';
}

if ($_GET['saved'] === 'Y') {
    CAdminMessage::ShowNote('Settings saved.');
}
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <table class="adm-detail-content-table edit-table">
        <tr>
            <td width="40%"><b>Responsible group</b></td>
            <td>
                <select name="responsible_group_id">
                    <option value="0">- Select group -</option>
                    <?php foreach ($arGroups as $gid => $gname): ?>
                        <option value="<?= $gid ?>" <?= $responsibleGroupId == $gid ? 'selected' : '' ?>><?= htmlspecialchars($gname) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <td><b>Notify on new equipment</b></td>
            <td>
                <select name="notification_new_equipment">
                    <option value="Y" <?= $notifNew === 'Y' ? 'selected' : '' ?>>Yes</option>
                    <option value="N" <?= $notifNew !== 'Y' ? 'selected' : '' ?>>No</option>
                </select>
            </td>
        </tr>
        <tr>
            <td><b>Notify on assignment</b></td>
            <td>
                <select name="notification_assignment">
                    <option value="Y" <?= $notifAssign === 'Y' ? 'selected' : '' ?>>Yes</option>
                    <option value="N" <?= $notifAssign !== 'Y' ? 'selected' : '' ?>>No</option>
                </select>
            </td>
        </tr>
        <tr>
            <td><b>External API base URL</b><br><small>ex: <code>http://10.130.10.232:8081/api/v1/assets/</code> — item URL = base + key.</small></td>
            <td><input type="text" name="external_api_url" value="<?= htmlspecialchars($extUrl) ?>" size="60" placeholder="http://10.130.10.232:8081/api/v1/assets/"></td>
        </tr>
        <tr>
            <td><b>External API key field</b></td>
            <td>
                <select name="external_api_field">
                    <option value="ASSET_UUID" <?= $extField === 'ASSET_UUID' ? 'selected' : '' ?>>Asset UUID</option>
                    <option value="COD_INVENTAR" <?= $extField === 'COD_INVENTAR' ? 'selected' : '' ?>>Inventory code</option>
                    <option value="SERIAL_NR" <?= $extField === 'SERIAL_NR' ? 'selected' : '' ?>>Serial number</option>
                </select>
            </td>
        </tr>
        <tr>
            <td><b>External API token (optional)</b><br><small>Sent as <code>Authorization: Bearer</code>.</small></td>
            <td><input type="text" name="external_api_token" value="<?= htmlspecialchars($extToken) ?>" size="60" autocomplete="off"></td>
        </tr>
        <tr>
            <td><b>Skip SSL verification</b><br><small>Only for intranet HTTPS with self-signed certificate.</small></td>
            <td>
                <select name="external_ssl_skip">
                    <option value="Y" <?= $extSslSkip === 'Y' ? 'selected' : '' ?>>Yes</option>
                    <option value="N" <?= $extSslSkip !== 'Y' ? 'selected' : '' ?>>No</option>
                </select>
            </td>
        </tr>
        <tr>
            <td><b>Auto re-sync every (days)</b></td>
            <td><input type="number" name="external_sync_days" value="<?= (int)$extDays ?>" min="1" max="365" style="width:90px;"></td>
        </tr>
        <tr>
            <td><b>Auto sync (cron agent)</b></td>
            <td>
                <select name="external_sync_enabled">
                    <option value="Y" <?= $extEnabled === 'Y' ? 'selected' : '' ?>>Yes</option>
                    <option value="N" <?= $extEnabled !== 'Y' ? 'selected' : '' ?>>No</option>
                </select>
            </td>
        </tr>
    </table>
    <br>
    <input type="submit" name="save" value="Save" class="adm-btn-save">
</form>
