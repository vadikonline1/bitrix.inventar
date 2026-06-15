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

$APPLICATION->SetTitle("IT Inventory - All Equipment");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

$tipText = TypesTable::getAllTypes();
$stareInfo = StatusTable::getAllStatus();

$responsibleGroupId = Option::get('bitrix.inventar', 'responsible_group_id', 0);
$arUsers = [];
if ($responsibleGroupId) {
    $dbUsers = CUser::GetList('id', 'asc', ['GROUPS_ID' => [$responsibleGroupId], 'ACTIVE' => 'Y']);
    while ($user = $dbUsers->Fetch()) {
        $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
        if (empty($userName)) $userName = $user['LOGIN'];
        $arUsers[$user['ID']] = $userName;
    }
}

if (empty($arUsers)) {
    $dbUsers = CUser::GetList('id', 'asc', ['ACTIVE' => 'Y']);
    while ($user = $dbUsers->Fetch()) {
        $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
        if (empty($userName)) $userName = $user['LOGIN'];
        $arUsers[$user['ID']] = $userName;
    }
}

$allTipuri = $tipText;
$allLocatii = [];
$allStari = [];

$search = trim($_GET['search'] ?? '');
$filterTip = $_GET['filter_tip'] ?? '';
$filterStatus = $_GET['filter_status'] ?? '';
$filterLocatie = $_GET['filter_locatie'] ?? '';
$filterResponsabil = $_GET['filter_responsabil'] ?? '';
$page = intval($_GET['page'] ?? 1);
$perPage = 20;

$connection = \Bitrix\Main\Application::getConnection();
$sql = "SELECT e.* FROM b_bitrix_inventar_equipment e WHERE 1=1";
$countSql = "SELECT COUNT(*) as CNT FROM b_bitrix_inventar_equipment e WHERE 1=1";

if (!empty($search)) {
    $searchTerm = $connection->getSqlHelper()->forSql('%' . $search . '%');
    $sql .= " AND (e.COD_INVENTAR LIKE '{$searchTerm}' OR e.DENUMIRE LIKE '{$searchTerm}' OR e.SERIAL_NR LIKE '{$searchTerm}')";
    $countSql .= " AND (e.COD_INVENTAR LIKE '{$searchTerm}' OR e.DENUMIRE LIKE '{$searchTerm}' OR e.SERIAL_NR LIKE '{$searchTerm}')";
}
if (!empty($filterTip)) {
    $sql .= " AND e.TIP_ENUM = '" . $connection->getSqlHelper()->forSql($filterTip) . "'";
    $countSql .= " AND e.TIP_ENUM = '" . $connection->getSqlHelper()->forSql($filterTip) . "'";
}
if (!empty($filterStatus)) {
    $sql .= " AND e.STARE_ENUM = '" . $connection->getSqlHelper()->forSql($filterStatus) . "'";
    $countSql .= " AND e.STARE_ENUM = '" . $connection->getSqlHelper()->forSql($filterStatus) . "'";
}
if (!empty($filterLocatie)) {
    $sql .= " AND e.LOCATIE = '" . $connection->getSqlHelper()->forSql($filterLocatie) . "'";
    $countSql .= " AND e.LOCATIE = '" . $connection->getSqlHelper()->forSql($filterLocatie) . "'";
}
if (!empty($filterResponsabil)) {
    $sql .= " AND EXISTS (SELECT 1 FROM b_bitrix_inventar_allocation a WHERE a.EQUIPMENT_ID = e.ID AND a.USER_ID = " . intval($filterResponsabil) . " AND a.DATA_RETURNARE IS NULL)";
    $countSql .= " AND EXISTS (SELECT 1 FROM b_bitrix_inventar_allocation a WHERE a.EQUIPMENT_ID = e.ID AND a.USER_ID = " . intval($filterResponsabil) . " AND a.DATA_RETURNARE IS NULL)";
}

$allEquipmentRaw = EquipmentTable::getList()->fetchAll();
foreach ($allEquipmentRaw as $eq) {
    if (!empty($eq['LOCATIE']) && !in_array($eq['LOCATIE'], $allLocatii)) $allLocatii[] = $eq['LOCATIE'];
}
$allStatusRaw = StatusTable::getAllStatus();
$allStari = $allStatusRaw;

$offset = ($page - 1) * $perPage;
$totalQuery = $connection->query($countSql);
$total = $totalQuery->fetch()['CNT'];
$totalPages = ceil($total / $perPage);

$sql .= " ORDER BY e.ID DESC LIMIT {$offset}, {$perPage}";
$list = $connection->query($sql)->fetchAll();
?>

<style>
.inventar-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
.inventar-title { font-size: 28px; color: #2c3e50; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 3px solid #2c7ed6; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
.count-badge { background: #2c7ed6; color: white; padding: 5px 15px; border-radius: 30px; font-size: 14px; }
.nav-links { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
.nav-link { padding: 8px 20px; background: #f0f0f0; color: #333; text-decoration: none; border-radius: 6px; transition: all 0.2s; }
.nav-link.active { background: #2c7ed6; color: white; }
.filter-bar { background: #f5f5f5; padding: 20px; border-radius: 12px; margin-bottom: 25px; }
.filter-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
.filter-group { flex: 1; min-width: 150px; }
.filter-group label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 12px; color: #666; }
.filter-group input, .filter-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
.filter-actions { display: flex; gap: 10px; align-items: center; }
.btn-filter { background: #2c7ed6; color: white; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; }
.btn-reset { background: #999; color: white; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; text-decoration: none; }
.btn-add { background: #4CAF50; color: white; padding: 8px 16px; text-decoration: none; border-radius: 6px; margin-left: 15px; font-size: 14px; }
.table-view { display: block; overflow-x: auto; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
.equipment-table { width: 100%; border-collapse: collapse; background: white; font-size: 13px; }
.equipment-table th, .equipment-table td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #eee; }
.equipment-table th { background: #2c7ed6; color: white; font-weight: 600; }
.equipment-table tr:hover { background: #f5f9ff; }
.card-view { display: none; gap: 20px; flex-direction: column; }
.equipment-card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
.card-header { background: linear-gradient(135deg, #2c7ed6, #1a4d8c); color: white; padding: 15px 20px; }
.card-header h3 { margin: 0; font-size: 18px; }
.card-header .card-cod { font-size: 12px; opacity: 0.85; margin-top: 5px; }
.card-body { padding: 15px 20px; }
.card-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
.card-row .label { width: 120px; font-weight: 600; color: #555; }
.card-row .value { flex: 1; color: #333; }
.status-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; color: white; }
.card-footer { padding: 12px 20px; background: #f9f9f9; border-top: 1px solid #eee; display: flex; gap: 10px; justify-content: flex-end; }
.btn { display: inline-flex; align-items: center; padding: 6px 14px; border-radius: 6px; text-decoration: none; font-size: 12px; }
.btn-edit { background: #ff9800; color: white; }
.btn-details { background: #2c7ed6; color: white; }
.pagination { display: flex; justify-content: center; gap: 10px; margin-top: 30px; flex-wrap: wrap; }
.pagination a, .pagination span { padding: 8px 15px; background: #f0f0f0; text-decoration: none; color: #333; border-radius: 6px; }
.pagination .active { background: #2c7ed6; color: white; }
.empty-state { text-align: center; padding: 60px; color: #999; background: white; border-radius: 12px; }
@media (max-width: 768px) { .table-view { display: none; } .card-view { display: flex; } .inventar-title { flex-direction: column; text-align: center; } .filter-row { flex-direction: column; } .filter-group { width: 100%; } .filter-actions { justify-content: center; } .nav-links { justify-content: center; } .card-row { flex-direction: column; } .card-row .label { width: 100%; margin-bottom: 4px; } .card-footer { flex-wrap: wrap; } .btn { flex: 1; text-align: center; } }
</style>

<div class="inventar-container">
    <div class="nav-links">
        <a href="/inventar/" class="nav-link">📋 My Equipment</a>
        <a href="/inventar/all/" class="nav-link active">📊 All Equipment</a>
    </div>
    
    <div class="inventar-title">
        <span>📊 All IT Equipment</span>
        <div>
            <a href="/inventar/add/" class="btn-add">+ Add</a>
            <span class="count-badge">Total: <?= $total ?> equipment</span>
        </div>
    </div>
    
    <form method="GET" class="filter-bar">
        <div class="filter-row">
            <div class="filter-group" style="flex:2;"><label>🔍 Search (code, name, serial)</label><input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search..." style="width: -webkit-fill-available;"></div>
            <div class="filter-group"><label>📁 Type</label><select name="filter_tip"><option value="">All</option><?php foreach ($allTipuri as $val => $name): ?><option value="<?= $val ?>" <?= ($filterTip == $val) ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label>📍 Location</label><select name="filter_locatie"><option value="">All</option><?php foreach ($allLocatii as $loc): ?><option value="<?= htmlspecialchars($loc) ?>" <?= ($filterLocatie == $loc) ? 'selected' : '' ?>><?= htmlspecialchars($loc) ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label>⚙️ Status</label><select name="filter_status"><option value="">All</option><?php foreach ($allStari as $val => $info): ?><option value="<?= $val ?>" <?= ($filterStatus == $val) ? 'selected' : '' ?>><?= htmlspecialchars($info['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label>👤 Responsible</label><select name="filter_responsabil"><option value="">All</option><?php foreach ($arUsers as $uid => $uname): ?><option value="<?= $uid ?>" <?= ($filterResponsabil == $uid) ? 'selected' : '' ?>><?= htmlspecialchars($uname) ?></option><?php endforeach; ?></select></div>
            <div class="filter-actions"><button type="submit" class="btn-filter">🔍 Filter</button><a href="/inventar/all/" class="btn-reset">Reset</a></div>
        </div>
    </form>
    
    <?php if (count($list) > 0): ?>
    <div class="table-view">
        <table class="equipment-table">
            <thead><tr><th>ID</th><th>Inventory code</th><th>Name</th><th>Type</th><th>Manufacturer</th><th>Model</th><th>Serial</th><th>Purchase date</th><th>Supplier</th><th>Cost</th><th>Warranty</th><th>Status</th><th>Location</th><th>Contract</th><th>Responsible</th><th>Actions</th></tr></thead>
            <tbody><?php foreach ($list as $item): $userId = AllocationTable::getCurrentUserForEquipment($item['ID']); $userName = ''; if ($userId && isset($arUsers[$userId])) $userName = $arUsers[$userId]; elseif ($userId) { $user = \Bitrix\Main\UserTable::getById($userId)->fetch(); $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']) ?: $user['LOGIN']; } ?>
            <tr><td><?= $item['ID'] ?></td><td><strong><?= htmlspecialchars($item['COD_INVENTAR']) ?></strong></td><td><?= htmlspecialchars($item['DENUMIRE'] ?: '-') ?></td><td><?= htmlspecialchars($tipText[$item['TIP_ENUM']] ?? $item['TIP_ENUM']) ?></td><td><?= htmlspecialchars($item['PRODUCATOR'] ?: '-') ?></td><td><?= htmlspecialchars($item['MODEL'] ?: '-') ?></td><td><code><?= htmlspecialchars($item['SERIAL_NR'] ?: '-') ?></code></td><td><?= $item['DATA_ACHIZITIE'] ? date('d.m.Y', strtotime($item['DATA_ACHIZITIE'])) : '-' ?></td><td><?= htmlspecialchars($item['FURNIZOR'] ?: '-') ?></td><td><?= $item['COST_ACHIZITIE'] ? number_format($item['COST_ACHIZITIE'], 2) : '-' ?> lei</td><td><?= $item['DATA_EXPIRARE_GARANTIE'] ? date('d.m.Y', strtotime($item['DATA_EXPIRARE_GARANTIE'])) : '-' ?></td><td><span class="status-badge" style="background:<?= $stareInfo[$item['STARE_ENUM']]['color'] ?? '#666' ?>"><?= htmlspecialchars($stareInfo[$item['STARE_ENUM']]['name'] ?? $item['STARE_ENUM']) ?></span></td><td><?= htmlspecialchars($item['LOCATIE'] ?: '-') ?></td><td><?= htmlspecialchars($item['CONTRACT_SERVICE'] ?: '-') ?></td><td><?= htmlspecialchars($userName ?: '-') ?></td><td><div class="action-buttons" style="display: flex; gap: 6px;"><a href="/inventar/edit/?id=<?= $item['ID'] ?>&back=all" class="btn btn-edit">✏️ Edit</a><a href="/inventar/?id=<?= $item['ID'] ?>&back=all" class="btn btn-details">🔍 Details</a></div></td></tr><?php endforeach; ?></tbody>
        </table>
    </div>
    
    <div class="card-view">
        <?php foreach ($list as $item): $userId = AllocationTable::getCurrentUserForEquipment($item['ID']); $userName = ''; if ($userId && isset($arUsers[$userId])) $userName = $arUsers[$userId]; elseif ($userId) { $user = \Bitrix\Main\UserTable::getById($userId)->fetch(); $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']) ?: $user['LOGIN']; } ?>
        <div class="equipment-card"><div class="card-header"><h3><?= htmlspecialchars($item['DENUMIRE'] ?: 'No name') ?></h3><div class="card-cod">Code: <?= htmlspecialchars($item['COD_INVENTAR']) ?></div></div><div class="card-body"><div class="card-row"><span class="label">ID:</span><span class="value"><?= $item['ID'] ?></span></div><div class="card-row"><span class="label">Type:</span><span class="value"><?= htmlspecialchars($tipText[$item['TIP_ENUM']] ?? $item['TIP_ENUM']) ?></span></div><div class="card-row"><span class="label">Manufacturer:</span><span class="value"><?= htmlspecialchars($item['PRODUCATOR'] ?: '-') ?></span></div><div class="card-row"><span class="label">Model:</span><span class="value"><?= htmlspecialchars($item['MODEL'] ?: '-') ?></span></div><div class="card-row"><span class="label">Serial:</span><span class="value"><code><?= htmlspecialchars($item['SERIAL_NR'] ?: '-') ?></code></span></div><div class="card-row"><span class="label">Purchase date:</span><span class="value"><?= $item['DATA_ACHIZITIE'] ? date('d.m.Y', strtotime($item['DATA_ACHIZITIE'])) : '-' ?></span></div><div class="card-row"><span class="label">Supplier:</span><span class="value"><?= htmlspecialchars($item['FURNIZOR'] ?: '-') ?></span></div><div class="card-row"><span class="label">Cost:</span><span class="value"><?= $item['COST_ACHIZITIE'] ? number_format($item['COST_ACHIZITIE'], 2) . ' lei' : '-' ?></span></div><div class="card-row"><span class="label">Warranty:</span><span class="value"><?= $item['DATA_EXPIRARE_GARANTIE'] ? date('d.m.Y', strtotime($item['DATA_EXPIRARE_GARANTIE'])) : '-' ?></span></div><div class="card-row"><span class="label">Status:</span><span class="value"><span class="status-badge" style="background:<?= $stareInfo[$item['STARE_ENUM']]['color'] ?? '#666' ?>"><?= htmlspecialchars($stareInfo[$item['STARE_ENUM']]['name'] ?? $item['STARE_ENUM']) ?></span></span></div><div class="card-row"><span class="label">Location:</span><span class="value"><?= htmlspecialchars($item['LOCATIE'] ?: '-') ?></span></div><div class="card-row"><span class="label">Service contract:</span><span class="value"><?= htmlspecialchars($item['CONTRACT_SERVICE'] ?: '-') ?></span></div><div class="card-row"><span class="label">Responsible:</span><span class="value"><?= htmlspecialchars($userName ?: '-') ?></span></div></div><div class="card-footer"><a href="/inventar/edit/?id=<?= $item['ID'] ?>&back=all" class="btn btn-edit">✏️ Edit</a><a href="/inventar/?id=<?= $item['ID'] ?>&back=all" class="btn btn-details">🔍 Details</a></div></div>
        <?php endforeach; ?>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">« First</a><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">← Previous</a><?php endif; ?>
        <?php $startPage = max(1, $page - 2); $endPage = min($totalPages, $page + 2); for ($i = $startPage; $i <= $endPage; $i++): ?><?php if ($i == $page): ?><span class="active"><?= $i ?></span><?php else: ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a><?php endif; ?><?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next →</a><a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>">Last »</a><?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php else: ?>
    <div class="empty-state"><p>📭 No equipment in inventory.</p></div>
    <?php endif; ?>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>
