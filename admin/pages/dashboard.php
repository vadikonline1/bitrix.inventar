<?php
use Bitrix\Main\Loader;
use Bitrix\Inventar\EquipmentTable;
use Bitrix\Inventar\TypesTable;
use Bitrix\Inventar\StatusTable;

Loader::includeModule('bitrix.inventar');

$APPLICATION->SetTitle("IT Inventory Dashboard");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

if ($APPLICATION->GetGroupRight("bitrix.inventar") < "R") {
    $APPLICATION->AuthForm("Access denied");
}

// Basic statistics
$totalEquipment = EquipmentTable::getCount();
$totalInUse = EquipmentTable::getCount(['=STARE_ENUM' => 'in_use']);
$totalInStock = EquipmentTable::getCount(['=STARE_ENUM' => 'in_stock']);
$totalInRepair = EquipmentTable::getCount(['=STARE_ENUM' => 'repair']);
$totalScrapped = EquipmentTable::getCount(['=STARE_ENUM' => 'scrapped']);

// Totals by type
$tipuri = TypesTable::getAllTypes();
$totaluriTip = [];
foreach ($tipuri as $cod => $nume) {
    $totaluriTip[$cod] = EquipmentTable::getCount(['=TIP_ENUM' => $cod]);
}

// Statuses
$stari = StatusTable::getAllStatus();

// Calculate total value
$totalValue = 0;
$allEquipment = EquipmentTable::getList(['select' => ['COST_ACHIZITIE']])->fetchAll();
foreach ($allEquipment as $eq) {
    $totalValue += floatval($eq['COST_ACHIZITIE']);
}

// Warranty expiring soon
$expiringWarranty = [];
try {
    $connection = \Bitrix\Main\Application::getConnection();
    $today = date('Y-m-d');
    $future30 = date('Y-m-d', strtotime('+30 days'));
    
    $sql = "
        SELECT ID, DENUMIRE, DATA_EXPIRARE_GARANTIE, COD_INVENTAR 
        FROM b_bitrix_inventar_equipment 
        WHERE DATA_EXPIRARE_GARANTIE IS NOT NULL 
        AND DATA_EXPIRARE_GARANTIE != ''
        AND DATA_EXPIRARE_GARANTIE <= '{$future30}'
        AND DATA_EXPIRARE_GARANTIE >= '{$today}'
    ";
    $expiringWarranty = $connection->query($sql)->fetchAll();
} catch (Exception $e) {
    $expiringWarranty = [];
}
?>

<style>
.dashboard-stats { display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap; }
.stat-card { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); flex: 1; min-width: 150px; text-align: center; }
.stat-card h3 { margin: 0 0 10px 0; font-size: 14px; color: #666; }
.stat-card .number { font-size: 32px; font-weight: bold; color: #2c7ed6; }
.stats-row { display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap; }
.stats-block { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); flex: 1; min-width: 250px; }
.stats-block h3 { margin: 0 0 15px 0; font-size: 16px; color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
.stats-block table { width: 100%; }
.stats-block td { padding: 8px 5px; border-bottom: 1px solid #f0f0f0; }
.stats-block td:last-child { text-align: right; font-weight: bold; }
</style>

<div class="dashboard-stats">
    <div class="stat-card"><h3>Total Equipment</h3><div class="number"><?= $totalEquipment ?></div></div>
    <div class="stat-card"><h3>In Use</h3><div class="number"><?= $totalInUse ?></div></div>
    <div class="stat-card"><h3>In Stock</h3><div class="number"><?= $totalInStock ?></div></div>
    <div class="stat-card"><h3>In Repair</h3><div class="number"><?= $totalInRepair ?></div></div>
    <div class="stat-card"><h3>Scrapped</h3><div class="number"><?= $totalScrapped ?></div></div>
    <div class="stat-card"><h3>Total Value</h3><div class="number"><?= number_format($totalValue, 2) ?> lei</div></div>
</div>

<div class="stats-row">
    <div class="stats-block">
        <h3>📊 Equipment by Type</h3>
        <table>
            <?php foreach ($tipuri as $cod => $nume): ?>
            <tr><td><?= htmlspecialchars($nume) ?></td><td><?= $totaluriTip[$cod] ?></td></tr>
            <?php endforeach; ?>
            <tr style="border-top:2px solid #ddd;"><td><strong>Total</strong></td><td><strong><?= $totalEquipment ?></strong></td></tr>
        </table>
    </div>
    <div class="stats-block">
        <h3>📈 Equipment Status</h3>
        <table>
            <?php foreach ($stari as $cod => $info): ?>
            <tr><td><?= htmlspecialchars($info['name']) ?></td><td><?= EquipmentTable::getCount(['=STARE_ENUM' => $cod]) ?></td></tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<div style="background:#fff; padding:20px; border-radius:8px;">
    <h3>⚠️ Warranties expiring in the next 30 days</h3>
    <?php if (count($expiringWarranty) > 0): ?>
        <table class="adm-list-table">
            <thead><tr><th>Inventory code</th><th>Name</th><th>Expiry date</th></tr></thead>
            <tbody><?php foreach ($expiringWarranty as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['COD_INVENTAR']) ?></td>
                <td><?= htmlspecialchars($item['DENUMIRE']) ?></td>
                <td style="color:red"><?= htmlspecialchars($item['DATA_EXPIRARE_GARANTIE']) ?></td>
            </tr>
            <?php endforeach; ?></tbody>
        </table>
    <?php else: ?>
        <p>No warranties expiring in the next 30 days.</p>
    <?php endif; ?>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>