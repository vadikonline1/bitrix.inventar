<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\Date;
use Bitrix\Inventar\EquipmentTable;
use Bitrix\Inventar\AllocationTable;
use Bitrix\Inventar\ServiceTable;
use Bitrix\Inventar\HistoryTable;
use Bitrix\Inventar\TypesTable;
use Bitrix\Inventar\StatusTable;

Loader::includeModule('bitrix.inventar');
global $USER;

if (!$USER->IsAuthorized()) { 
    LocalRedirect('/auth/'); 
    exit; 
}

$groupId = Option::get('bitrix.inventar', 'inventar_group_id');
$userGroups = \CUser::GetUserGroup($USER->GetID());
$isInventarUser = (in_array($groupId, $userGroups) || $USER->IsAdmin());

// Get types and statuses from database
$tipText = TypesTable::getAllTypes();
$stareInfo = StatusTable::getAllStatus();

$id = intval($_GET['id'] ?? 0);
$backUrl = $_GET['back'] ?? '';

if ($id) {
    // ========== EQUIPMENT DETAILS PAGE ==========
    $APPLICATION->SetTitle("IT Inventory - Equipment Details");
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
    
    // Display notification from session
    if (isset($_SESSION['INVENTAR_NOTIFICATION'])) {
        $notif = $_SESSION['INVENTAR_NOTIFICATION'];
        echo '<div style="position: fixed; top: 20px; right: 20px; z-index: 10000; min-width: 300px; max-width: 500px; animation: slideIn 0.3s ease-out;">
            <div style="background: ' . ($notif['type'] == 'success' ? '#d4edda' : '#f8d7da') . '; border-left: 4px solid ' . ($notif['type'] == 'success' ? '#28a745' : '#dc3545') . '; border-radius: 8px; padding: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <strong style="color: ' . ($notif['type'] == 'success' ? '#155724' : '#721c24') . ';">' . htmlspecialchars($notif['title'] ?? ($notif['type'] == 'success' ? 'Success!' : 'Error!')) . '</strong>
                    <button onclick="this.parentElement.parentElement.parentElement.style.display=\'none\'" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #666;">&times;</button>
                </div>
                <div style="color: ' . ($notif['type'] == 'success' ? '#155724' : '#721c24') . '; margin-top: 8px;">' . htmlspecialchars($notif['message']) . '</div>
            </div>
        </div>';
        unset($_SESSION['INVENTAR_NOTIFICATION']);
    }
    ?>
    <style>
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    .details-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
    .equipment-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); overflow: hidden; }
    .equipment-header { background: linear-gradient(135deg, #2c7ed6, #1a4d8c); color: white; padding: 30px; text-align: center; }
    .equipment-header h1 { margin: 0; font-size: 24px; }
    .equipment-header .cod { font-size: 14px; opacity: 0.9; margin-top: 10px; }
    .equipment-details { padding: 30px; }
    .details-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 30px; background: #f9f9f9; border-radius: 10px; padding: 10px; }
    .grid-item { display: flex; padding: 10px; background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .grid-label { font-weight: bold; width: 140px; flex-shrink: 0; color: #555; }
    .grid-value { flex: 1; color: #333; word-break: break-word; }
    .status-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; color: white; }
    .section-title { font-size: 18px; font-weight: bold; margin: 25px 0 15px 0; padding-bottom: 10px; border-bottom: 2px solid #2c7ed6; color: #2c3e50; }
    .table-view { display: block; overflow-x: auto; border-radius: 10px; border: 1px solid #ddd; }
    .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .data-table th, .data-table td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
    .data-table th { background: #f5f5f5; font-weight: 600; }
    .data-table tr:hover { background: #f9f9f9; }
    .card-view { display: none; gap: 15px; flex-direction: column; }
    .history-card, .service-card, .history-log-card { background: #f9f9f9; border-radius: 10px; padding: 15px; border-left: 3px solid #2c7ed6; }
    .history-card p, .service-card p, .history-log-card p { margin: 8px 0; font-size: 13px; }
    .history-card strong, .service-card strong, .history-log-card strong { color: #2c7ed6; }
    .status-active { color: green; font-weight: bold; }
    .status-closed { color: gray; }
    .status-service { color: orange; font-weight: bold; }
    .btn-back { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #2c7ed6; color: white; text-decoration: none; border-radius: 6px; transition: background 0.2s; }
    .btn-back-all { background: #6c757d; }
    .btn-back:hover { background: #1a4d8c; }
    .empty-message { text-align: center; padding: 40px; color: #999; background: #f9f9f9; border-radius: 10px; }
    
    @media (max-width: 768px) {
        .details-container { padding: 12px; }
        .equipment-details { padding: 20px; }
        .details-grid { grid-template-columns: 1fr; gap: 10px; }
        .grid-item { flex-direction: column; }
        .grid-label { width: 100%; margin-bottom: 5px; font-size: 12px; }
        .grid-value { font-size: 14px; }
        .table-view { display: none; }
        .card-view { display: flex; }
        .section-title { font-size: 16px; }
        .equipment-header { padding: 20px; }
        .equipment-header h1 { font-size: 20px; }
    }
    </style>
    
    <div class="details-container">
    <?php
    $eq = EquipmentTable::getById($id)->fetch();
    if ($eq) {
        $allocations = AllocationTable::getList([
            'filter' => ['=EQUIPMENT_ID' => $id],
            'order' => ['DATA_PREDARE' => 'DESC']
        ])->fetchAll();
        
        $services = ServiceTable::getList([
            'filter' => ['=EQUIPMENT_ID' => $id],
            'order' => ['DATA_INTRARE' => 'DESC']
        ])->fetchAll();
        
        $history = HistoryTable::getList([
            'filter' => ['=EQUIPMENT_ID' => $id],
            'order' => ['CREATED_AT' => 'DESC'],
            'limit' => 20
        ])->fetchAll();
        
        $customFieldsData = [];
        if (!empty($eq['OTHERS_INFO'])) {
            $customFieldsData = json_decode($eq['OTHERS_INFO'], true);
            if (!is_array($customFieldsData)) $customFieldsData = [];
        }
        ?>
        
        <div class="equipment-card">
            <div class="equipment-header">
                <h1><?= htmlspecialchars($eq['DENUMIRE'] ?: 'No name') ?></h1>
                <div class="cod">Inventory code: <?= htmlspecialchars($eq['COD_INVENTAR']) ?></div>
                <div class="cod">Equipment ID: <?= $eq['ID'] ?></div>
            </div>
            
            <div class="equipment-details">
                <div class="details-grid">
                    <div class="grid-item"><span class="grid-label">Type:</span><span class="grid-value"><?= htmlspecialchars($tipText[$eq['TIP_ENUM']] ?? $eq['TIP_ENUM']) ?></span></div>
                    <div class="grid-item"><span class="grid-label">Manufacturer:</span><span class="grid-value"><?= htmlspecialchars($eq['PRODUCATOR'] ?: '-') ?></span></div>
                    <div class="grid-item"><span class="grid-label">Model:</span><span class="grid-value"><?= htmlspecialchars($eq['MODEL'] ?: '-') ?></span></div>
                    <div class="grid-item"><span class="grid-label">Serial number:</span><span class="grid-value"><?= htmlspecialchars($eq['SERIAL_NR'] ?: '-') ?></span></div>
                    <div class="grid-item"><span class="grid-label">Status:</span><span class="grid-value"><span class="status-badge" style="background:<?= $stareInfo[$eq['STARE_ENUM']]['color'] ?? '#666' ?>"><?= htmlspecialchars($stareInfo[$eq['STARE_ENUM']]['name'] ?? $eq['STARE_ENUM']) ?></span></span></div>
                    <div class="grid-item"><span class="grid-label">Location:</span><span class="grid-value"><?= htmlspecialchars($eq['LOCATIE'] ?: '-') ?></span></div>
                    <?php if ($eq['DATA_ACHIZITIE']): ?>
                    <div class="grid-item"><span class="grid-label">Purchase date:</span><span class="grid-value"><?= date('d.m.Y', strtotime($eq['DATA_ACHIZITIE'])) ?></span></div>
                    <?php endif; ?>
                    <?php if ($eq['COST_ACHIZITIE']): ?>
                    <div class="grid-item"><span class="grid-label">Purchase cost:</span><span class="grid-value"><?= number_format($eq['COST_ACHIZITIE'], 2) ?> lei</span></div>
                    <?php endif; ?>
                    <?php if ($eq['DATA_EXPIRARE_GARANTIE']): ?>
                    <div class="grid-item"><span class="grid-label">Warranty until:</span><span class="grid-value"><?= date('d.m.Y', strtotime($eq['DATA_EXPIRARE_GARANTIE'])) ?></span></div>
                    <?php endif; ?>
                    <?php if ($eq['CONTRACT_SERVICE']): ?>
                    <div class="grid-item"><span class="grid-label">Service contract:</span><span class="grid-value"><?= htmlspecialchars($eq['CONTRACT_SERVICE']) ?></span></div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($customFieldsData)): ?>
                <div class="section-title">🔧 Additional information</div>
                <div class="details-grid">
                    <?php foreach ($customFieldsData as $fieldName => $fieldValue): 
                        $displayName = str_replace('CUSTOM_', '', $fieldName);
                        $displayName = str_replace('_', ' ', $displayName);
                        $displayName = ucwords($displayName);
                    ?>
                    <div class="grid-item"><span class="grid-label"><?= htmlspecialchars($displayName) ?>:</span><span class="grid-value"><?= htmlspecialchars($fieldValue ?: '-') ?></span></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <div class="section-title">📋 Allocation history</div>
                <?php if (count($allocations) > 0): ?>
                <div class="table-view">
                    <table class="data-table">
                        <thead><tr><th>ID</th><th>User</th><th>Handover date</th><th>Return date</th><th>Reason</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($allocations as $alloc): 
                                $user = \Bitrix\Main\UserTable::getById($alloc['USER_ID'])->fetch();
                                $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']) ?: $user['LOGIN'];
                                $isActive = empty($alloc['DATA_RETURNARE']);
                            ?>
                            <tr><td><?= $alloc['ID'] ?></td><td><?= htmlspecialchars($userName) ?></td><td><?= date('d.m.Y', strtotime($alloc['DATA_PREDARE'])) ?></td><td><?= $alloc['DATA_RETURNARE'] ? date('d.m.Y', strtotime($alloc['DATA_RETURNARE'])) : '-' ?></td><td><?= htmlspecialchars($alloc['MOTIV_RETURNARE'] ?: '-') ?></td><td><?php if ($isActive): ?><span class="status-active">● Active</span><?php else: ?><span class="status-closed">Closed</span><?php endif; ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-view">
                    <?php foreach ($allocations as $alloc): 
                        $user = \Bitrix\Main\UserTable::getById($alloc['USER_ID'])->fetch();
                        $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']) ?: $user['LOGIN'];
                        $isActive = empty($alloc['DATA_RETURNARE']);
                    ?>
                    <div class="history-card"><p><strong>ID:</strong> <?= $alloc['ID'] ?></p><p><strong>User:</strong> <?= htmlspecialchars($userName) ?></p><p><strong>Handover date:</strong> <?= date('d.m.Y', strtotime($alloc['DATA_PREDARE'])) ?></p><p><strong>Return date:</strong> <?= $alloc['DATA_RETURNARE'] ? date('d.m.Y', strtotime($alloc['DATA_RETURNARE'])) : '-' ?></p><p><strong>Reason:</strong> <?= htmlspecialchars($alloc['MOTIV_RETURNARE'] ?: '-') ?></p><p><strong>Status:</strong> <?php if ($isActive): ?><span class="status-active">● Active</span><?php else: ?><span class="status-closed">Closed</span><?php endif; ?></p></div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-message">No allocations recorded for this equipment.</div>
                <?php endif; ?>
                
                <div class="section-title">🔧 Service history</div>
                <?php if (count($services) > 0): ?>
                <div class="table-view">
                    <table class="data-table"><thead><tr><th>ID</th><th>Entry date</th><th>Exit date</th><th>Problem</th><th>Solution</th><th>Cost</th><th>Status</th></tr></thead>
                    <tbody><?php foreach ($services as $serv): ?><tr><td><?= $serv['ID'] ?></td><td><?= date('d.m.Y', strtotime($serv['DATA_INTRARE'])) ?></td><td><?= $serv['DATA_IESIRE'] ? date('d.m.Y', strtotime($serv['DATA_IESIRE'])) : '-' ?></td><td><?= htmlspecialchars(substr($serv['PROBLEMA'] ?? '', 0, 100)) ?>...</td><td><?= htmlspecialchars(substr($serv['SOLUTIE'] ?? '', 0, 100)) ?>...</td><td><?= $serv['COST_SERVICE'] ? number_format($serv['COST_SERVICE'], 2) . ' lei' : '-' ?></td><td><?php if ($serv['STATUS_ENUM'] == 'in_service'): ?><span class="status-service">● In service</span><?php else: ?>Repaired<?php endif; ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                </div>
                <div class="card-view"><?php foreach ($services as $serv): ?><div class="service-card"><p><strong>ID:</strong> <?= $serv['ID'] ?></p><p><strong>Entry date:</strong> <?= date('d.m.Y', strtotime($serv['DATA_INTRARE'])) ?></p><p><strong>Exit date:</strong> <?= $serv['DATA_IESIRE'] ? date('d.m.Y', strtotime($serv['DATA_IESIRE'])) : '-' ?></p><p><strong>Problem:</strong> <?= htmlspecialchars(substr($serv['PROBLEMA'] ?? '', 0, 100)) ?>...</p><p><strong>Solution:</strong> <?= htmlspecialchars(substr($serv['SOLUTIE'] ?? '', 0, 100)) ?>...</p><p><strong>Cost:</strong> <?= $serv['COST_SERVICE'] ? number_format($serv['COST_SERVICE'], 2) . ' lei' : '-' ?></p><p><strong>Status:</strong> <?php if ($serv['STATUS_ENUM'] == 'in_service'): ?><span class="status-service">● In service</span><?php else: ?>Repaired<?php endif; ?></p></div><?php endforeach; ?></div>
                <?php else: ?>
                <div class="empty-message">No service records for this equipment.</div>
                <?php endif; ?>
                
                <div class="section-title">📝 Change history</div>
                <?php if (count($history) > 0): ?>
                <div class="table-view"><table class="data-table"><thead><tr><th>Date</th><th>Action</th><th>Field</th><th>Old value</th><th>New value</th><th>User</th></tr></thead>
                <tbody><?php foreach ($history as $hist): $user = \Bitrix\Main\UserTable::getById($hist['USER_ID'])->fetch(); $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']) ?: $user['LOGIN']; ?><tr><td><?= date('d.m.Y H:i:s', strtotime($hist['CREATED_AT'])) ?></td><td><?= htmlspecialchars($hist['ACTION']) ?></td><td><?= htmlspecialchars($hist['FIELD_NAME'] ?: '-') ?></td><td><?= htmlspecialchars(substr($hist['OLD_VALUE'] ?? '', 0, 50)) ?>...</td><td><?= htmlspecialchars(substr($hist['NEW_VALUE'] ?? '', 0, 50)) ?>...</td><td><?= htmlspecialchars($userName) ?></td></tr><?php endforeach; ?></tbody></table></div>
                <div class="card-view"><?php foreach ($history as $hist): $user = \Bitrix\Main\UserTable::getById($hist['USER_ID'])->fetch(); $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']) ?: $user['LOGIN']; ?><div class="history-log-card"><p><strong>Date:</strong> <?= date('d.m.Y H:i:s', strtotime($hist['CREATED_AT'])) ?></p><p><strong>Action:</strong> <?= htmlspecialchars($hist['ACTION']) ?></p><p><strong>Field:</strong> <?= htmlspecialchars($hist['FIELD_NAME'] ?: '-') ?></p><p><strong>Old value:</strong> <?= htmlspecialchars(substr($hist['OLD_VALUE'] ?? '', 0, 50)) ?>...</p><p><strong>New value:</strong> <?= htmlspecialchars(substr($hist['NEW_VALUE'] ?? '', 0, 50)) ?>...</p><p><strong>User:</strong> <?= htmlspecialchars($userName) ?></p></div><?php endforeach; ?></div>
                <?php else: ?>
                <div class="empty-message">No changes recorded.</div>
                <?php endif; ?>
                
                <div style="margin-top: 30px; text-align: center;">
                    <?php if ($backUrl == 'all'): ?>
                    <a href="/inventar/all/" class="btn-back btn-back-all">← Back to All equipment</a>
                    <?php else: ?>
                    <a href="/inventar/" class="btn-back">← Back to My equipment</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    } else {
        echo '<div class="empty-message">Equipment not found.</div>';
    }
    ?>
    </div>
    <?php
} else {
    // ========== CURRENT USER'S EQUIPMENT LIST ==========
    $APPLICATION->SetTitle("IT Inventory - My Equipment");
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
    
    $myEquipment = [];
    $allEquipment = EquipmentTable::getList(['order' => ['ID' => 'DESC']])->fetchAll();
    $currentUserId = $USER->GetID();
    
    foreach ($allEquipment as $eq) {
        $allocatedUserId = AllocationTable::getCurrentUserForEquipment($eq['ID']);
        if ($allocatedUserId == $currentUserId) {
            $myEquipment[] = $eq;
        }
    }
    ?>
    <style>
    .inventar-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .inventar-title { font-size: 28px; color: #2c3e50; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 3px solid #2c7ed6; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
    .count-badge { background: #2c7ed6; color: white; padding: 5px 15px; border-radius: 30px; font-size: 14px; }
    .nav-links { display: flex; gap: 15px; margin-bottom: 20px; }
    .nav-link { padding: 8px 20px; background: #f0f0f0; color: #333; text-decoration: none; border-radius: 6px; }
    .nav-link.active { background: #2c7ed6; color: white; }
    .equipment-table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .equipment-table th { background: #2c7ed6; color: white; padding: 12px 15px; text-align: left; }
    .equipment-table td { padding: 10px 15px; border-bottom: 1px solid #eee; }
    .equipment-table tr:hover { background: #f5f9ff; }
    .btn-details { background: #2c7ed6; color: white; padding: 4px 12px; text-decoration: none; border-radius: 4px; font-size: 12px; }
    .btn-add { background: #4CAF50; color: white; padding: 8px 16px; text-decoration: none; border-radius: 6px; margin-left: 15px; font-size: small; }
    .empty-state { text-align: center; padding: 60px; color: #999; background: white; border-radius: 12px; }
    @media (max-width: 768px) { .inventar-container { padding: 12px; } .inventar-title { flex-direction: column; text-align: center; } .nav-links { justify-content: center; } .equipment-table { font-size: 12px; } .equipment-table th, .equipment-table td { padding: 6px 8px; } }
    </style>
    
    <div class="inventar-container">
        <div class="nav-links">
            <a href="/inventar/" class="nav-link active">📋 My Equipment</a>
            <?php if ($isInventarUser): ?>
            <a href="/inventar/all/" class="nav-link">📊 All Equipment</a>
            <?php endif; ?>
        </div>
        
        <div class="inventar-title">
            <span>📋 Equipment assigned to me</span>
            <div>
                <?php if ($isInventarUser): ?>
                <a href="/inventar/add/" class="btn-add">+ Add</a>
                <?php endif; ?>
                <span class="count-badge">Total: <?= count($myEquipment) ?> equipment</span>
            </div>
        </div>
        
        <?php if (count($myEquipment) > 0): ?>
        <div style="overflow-x: auto;">
            <table class="equipment-table">
                <thead><tr><th>ID</th><th>Inventory code</th><th>Name</th><th>Type</th><th>Status</th><th>Location</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($myEquipment as $item): ?>
                    <tr><td><?= $item['ID'] ?></td><td><strong><?= htmlspecialchars($item['COD_INVENTAR']) ?></strong></td><td><?= htmlspecialchars($item['DENUMIRE'] ?: '-') ?></td><td><?= htmlspecialchars($tipText[$item['TIP_ENUM']] ?? $item['TIP_ENUM']) ?></td><td><span style="background:<?= $stareInfo[$item['STARE_ENUM']]['color'] ?? '#666' ?>; padding:3px 10px; border-radius:20px; color:white;"><?= htmlspecialchars($stareInfo[$item['STARE_ENUM']]['name'] ?? $item['STARE_ENUM']) ?></span></td><td><?= htmlspecialchars($item['LOCATIE'] ?: '-') ?></td><td><a href="?id=<?= $item['ID'] ?>&back=my" class="btn-details">🔍 Details</a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><p>📭 No equipment assigned to you.</p></div>
        <?php endif; ?>
    </div>
    <?php
}

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
?>