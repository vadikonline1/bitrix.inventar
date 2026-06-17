<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Inventar\EquipmentTable;
use Bitrix\Inventar\TypesTable;
use Bitrix\Inventar\StatusTable;

Loader::includeModule('bitrix.inventar');

$APPLICATION->SetTitle("IT Inventory Dashboard");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

if ($APPLICATION->GetGroupRight("bitrix.inventar") < "R") {
    $APPLICATION->AuthForm("Access denied");
}

// ========== STATISTICI DE BAZĂ ==========
$totalEquipment = EquipmentTable::getCount();

// Obține toate stările din baza de date
$stari = StatusTable::getAllStatus();
$stareCounts = [];
foreach ($stari as $cod => $info) {
    $stareCounts[$cod] = EquipmentTable::getCount(['=STARE_ENUM' => $cod]);
}

// Obține toate tipurile din baza de date
$tipuri = TypesTable::getAllTypes();
$tipCounts = [];
foreach ($tipuri as $cod => $nume) {
    $tipCounts[$cod] = EquipmentTable::getCount(['=TIP_ENUM' => $cod]);
}

// ========== CALCULEAZĂ VALOAREA TOTALĂ ==========
$totalValue = 0;
$allEquipment = EquipmentTable::getList(['select' => ['COST_ACHIZITIE']])->fetchAll();
foreach ($allEquipment as $eq) {
    $totalValue += floatval($eq['COST_ACHIZITIE']);
}

// ========== GARANȚII CARE EXPIRĂ ==========
$expiringWarranty = [];
try {
    $connection = Application::getConnection();
    $today = date('Y-m-d');
    $future30 = date('Y-m-d', strtotime('+30 days'));
    
    $sql = "
        SELECT ID, DENUMIRE, DATA_EXPIRARE_GARANTIE, COD_INVENTAR 
        FROM b_bitrix_inventar_equipment 
        WHERE DATA_EXPIRARE_GARANTIE IS NOT NULL 
        AND DATA_EXPIRARE_GARANTIE != ''
        AND DATA_EXPIRARE_GARANTIE <= '{$future30}'
        AND DATA_EXPIRARE_GARANTIE >= '{$today}'
        ORDER BY DATA_EXPIRARE_GARANTIE ASC
    ";
    $result = $connection->query($sql);
    while ($row = $result->fetch()) {
        $expiringWarranty[] = $row;
    }
} catch (SqlQueryException $e) {
    $expiringWarranty = [];
}

// ========== CALCULEAZĂ PROGRES ==========
$inUseCount = $stareCounts['1'] ?? 0; // Presupunem că '1' = In use
$inStockCount = $stareCounts['2'] ?? 0; // Presupunem că '2' = In stock
$inRepairCount = $stareCounts['3'] ?? 0; // Presupunem că '3' = In repair
$scrappedCount = $stareCounts['4'] ?? 0; // Presupunem că '4' = Scrapped

// Dacă stările au coduri diferite, folosește array-ul direct
$inUseCount = $stareCounts['1'] ?? ($stareCounts['in_use'] ?? 0);
$inStockCount = $stareCounts['2'] ?? ($stareCounts['in_stock'] ?? 0);
$inRepairCount = $stareCounts['3'] ?? ($stareCounts['repair'] ?? 0);
$scrappedCount = $stareCounts['4'] ?? ($stareCounts['scrapped'] ?? 0);
?>

<style>
/* ====== STATISTICI ====== */
.dashboard-stats { 
    display: grid; 
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); 
    gap: 20px; 
    margin-bottom: 30px; 
}
.stat-card { 
    background: #fff; 
    border-radius: 10px; 
    padding: 20px 15px; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.08); 
    text-align: center; 
    transition: transform 0.2s, box-shadow 0.2s;
    border-top: 4px solid #2c7ed6;
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.12);
}
.stat-card h3 { 
    margin: 0 0 10px 0; 
    font-size: 13px; 
    color: #888; 
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.stat-card .number { 
    font-size: 32px; 
    font-weight: bold; 
    color: #2c7ed6; 
    line-height: 1.2;
}
.stat-card .sub-info {
    font-size: 12px;
    color: #999;
    margin-top: 5px;
}
.stat-card.color-green { border-top-color: #4CAF50; }
.stat-card.color-green .number { color: #4CAF50; }
.stat-card.color-orange { border-top-color: #FF9800; }
.stat-card.color-orange .number { color: #FF9800; }
.stat-card.color-red { border-top-color: #f44336; }
.stat-card.color-red .number { color: #f44336; }
.stat-card.color-purple { border-top-color: #9C27B0; }
.stat-card.color-purple .number { color: #9C27B0; }
.stat-card.color-gold { border-top-color: #FFC107; }
.stat-card.color-gold .number { color: #FFC107; }

/* ====== ROW-URI STATISTICI ====== */
.stats-row { 
    display: grid; 
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); 
    gap: 20px; 
    margin-bottom: 30px; 
}
.stats-block { 
    background: #fff; 
    border-radius: 10px; 
    padding: 20px; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.08); 
}
.stats-block h3 { 
    margin: 0 0 15px 0; 
    font-size: 16px; 
    color: #333; 
    border-bottom: 2px solid #f0f0f0; 
    padding-bottom: 10px; 
}
.stats-block table { 
    width: 100%; 
    border-collapse: collapse;
}
.stats-block td { 
    padding: 8px 5px; 
    border-bottom: 1px solid #f5f5f5; 
}
.stats-block td:last-child { 
    text-align: right; 
    font-weight: bold; 
}
.stats-block .total-row td {
    border-top: 2px solid #ddd;
    padding-top: 12px;
    font-weight: bold;
}
.color-dot {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-right: 8px;
    vertical-align: middle;
}

/* ====== GARANȚII ====== */
.warranty-box {
    background: #fff;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.warranty-box h3 {
    margin: 0 0 15px 0;
    font-size: 16px;
    color: #333;
    border-bottom: 2px solid #f0f0f0;
    padding-bottom: 10px;
}
.warranty-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.warranty-table th {
    background: #f5f5f5;
    padding: 10px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #ddd;
}
.warranty-table td {
    padding: 10px;
    border-bottom: 1px solid #eee;
}
.warranty-table tr:hover {
    background: #f9f9f9;
}
.warranty-urgent {
    color: #f44336;
    font-weight: bold;
}
.warranty-soon {
    color: #FF9800;
}
.empty-state {
    text-align: center;
    padding: 30px;
    color: #999;
}

/* ====== RESPONSIVE ====== */
@media (max-width: 768px) {
    .dashboard-stats {
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 10px;
    }
    .stat-card {
        padding: 15px 10px;
    }
    .stat-card .number {
        font-size: 24px;
    }
    .stats-row {
        grid-template-columns: 1fr;
    }
    .warranty-table {
        font-size: 12px;
    }
    .warranty-table th,
    .warranty-table td {
        padding: 6px 8px;
    }
}
</style>

<!-- ====== STATISTICI ====== -->
<div class="dashboard-stats">
    <div class="stat-card">
        <h3>Total Equipment</h3>
        <div class="number"><?= $totalEquipment ?></div>
    </div>
    <div class="stat-card color-green">
        <h3>In Use</h3>
        <div class="number"><?= $inUseCount ?></div>
        <div class="sub-info"><?= $totalEquipment > 0 ? round(($inUseCount / $totalEquipment) * 100, 1) : 0 ?>% of total</div>
    </div>
    <div class="stat-card">
        <h3>In Stock</h3>
        <div class="number"><?= $inStockCount ?></div>
        <div class="sub-info"><?= $totalEquipment > 0 ? round(($inStockCount / $totalEquipment) * 100, 1) : 0 ?>% of total</div>
    </div>
    <div class="stat-card color-orange">
        <h3>In Repair</h3>
        <div class="number"><?= $inRepairCount ?></div>
        <div class="sub-info"><?= $totalEquipment > 0 ? round(($inRepairCount / $totalEquipment) * 100, 1) : 0 ?>% of total</div>
    </div>
    <div class="stat-card color-red">
        <h3>Scrapped</h3>
        <div class="number"><?= $scrappedCount ?></div>
        <div class="sub-info"><?= $totalEquipment > 0 ? round(($scrappedCount / $totalEquipment) * 100, 1) : 0 ?>% of total</div>
    </div>
    <div class="stat-card color-gold">
        <h3>Total Value</h3>
        <div class="number"><?= number_format($totalValue, 0) ?> Lei</div>
        <div class="sub-info"><?= $totalEquipment > 0 ? number_format($totalValue / $totalEquipment, 2) : 0 ?> Lei / eq.</div>
    </div>
</div>

<!-- ====== DISTRIBUȚIE ====== -->
<div class="stats-row">
    <div class="stats-block">
        <h3>📊 Equipment by Type</h3>
        <table>
            <?php if (!empty($tipuri)): ?>
                <?php foreach ($tipuri as $cod => $nume): ?>
                <tr>
                    <td>
                        <span class="color-dot" style="background:<?= '#'.dechex(crc32($cod) & 0xFFFFFF) ?>;"></span>
                        <?= htmlspecialchars($nume) ?>
                    </td>
                    <td><?= $tipCounts[$cod] ?? 0 ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td><strong>Total</strong></td>
                    <td><strong><?= $totalEquipment ?></strong></td>
                </tr>
            <?php else: ?>
                <tr><td colspan="2" style="text-align:center; color:#999;">No types defined</td></tr>
            <?php endif; ?>
        </table>
    </div>
    
    <div class="stats-block">
        <h3>📈 Equipment Status</h3>
        <table>
            <?php if (!empty($stari)): ?>
                <?php foreach ($stari as $cod => $info): ?>
                <tr>
                    <td>
                        <span class="color-dot" style="background:<?= htmlspecialchars($info['color'] ?? '#999') ?>;"></span>
                        <?= htmlspecialchars($info['name']) ?>
                    </td>
                    <td><?= $stareCounts[$cod] ?? 0 ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td><strong>Total</strong></td>
                    <td><strong><?= $totalEquipment ?></strong></td>
                </tr>
            <?php else: ?>
                <tr><td colspan="2" style="text-align:center; color:#999;">No statuses defined</td></tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ====== GARANȚII ====== -->
<div class="warranty-box">
    <h3>⚠️ Warranties expiring in the next 30 days</h3>
    <?php if (count($expiringWarranty) > 0): ?>
        <table class="warranty-table">
            <thead>
                <tr>
                    <th>Inventory code</th>
                    <th>Name</th>
                    <th>Expiry date</th>
                    <th>Days left</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expiringWarranty as $item):
                    $daysLeft = (strtotime($item['DATA_EXPIRARE_GARANTIE']) - strtotime(date('Y-m-d'))) / 86400;
                    $daysLeft = round($daysLeft);
                    $cssClass = $daysLeft <= 7 ? 'warranty-urgent' : ($daysLeft <= 15 ? 'warranty-soon' : '');
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($item['COD_INVENTAR']) ?></strong></td>
                    <td><?= htmlspecialchars($item['DENUMIRE']) ?></td>
                    <td class="<?= $cssClass ?>"><?= htmlspecialchars($item['DATA_EXPIRARE_GARANTIE']) ?></td>
                    <td class="<?= $cssClass ?>">
                        <?= $daysLeft ?> days
                        <?php if ($daysLeft <= 7): ?> ⚠️<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty-state">✅ No warranties expiring in the next 30 days.</div>
    <?php endif; ?>
</div>

<!-- ====== GRAFIC SIMPLU (opțional) ====== -->
<?php if ($totalEquipment > 0 && !empty($stari)): ?>
<div style="margin-top: 30px; background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
    <h3 style="margin: 0 0 15px 0; font-size: 16px; color: #333; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px;">📊 Status Distribution</h3>
    <div style="display: flex; height: 30px; border-radius: 6px; overflow: hidden;">
        <?php 
        $colors = ['#4CAF50', '#9E9E9E', '#FF9800', '#f44336', '#5D4037', '#2196F3', '#9C27B0', '#00BCD4'];
        $colorIndex = 0;
        foreach ($stari as $cod => $info):
            $count = $stareCounts[$cod] ?? 0;
            $percentage = $totalEquipment > 0 ? ($count / $totalEquipment) * 100 : 0;
            if ($percentage > 0):
                $color = $info['color'] ?? $colors[$colorIndex % count($colors)];
        ?>
        <div style="flex: <?= $percentage ?>; background: <?= $color ?>; min-width: 10px; position: relative; display: flex; align-items: center; justify-content: center; transition: all 0.3s;" 
             title="<?= htmlspecialchars($info['name']) ?>: <?= $count ?> (<?= round($percentage, 1) ?>%)">
            <?php if ($percentage > 8): ?>
            <span style="color: white; font-size: 11px; font-weight: bold; text-shadow: 0 1px 2px rgba(0,0,0,0.3);">
                <?= round($percentage, 1) ?>%
            </span>
            <?php endif; ?>
        </div>
        <?php 
            $colorIndex++;
            endif;
        endforeach; 
        ?>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 12px;">
        <?php 
        $colorIndex = 0;
        foreach ($stari as $cod => $info):
            $count = $stareCounts[$cod] ?? 0;
            if ($count > 0):
                $color = $info['color'] ?? $colors[$colorIndex % count($colors)];
        ?>
        <div style="display: flex; align-items: center; gap: 5px; font-size: 13px;">
            <span style="display: inline-block; width: 12px; height: 12px; background: <?= $color ?>; border-radius: 3px;"></span>
            <?= htmlspecialchars($info['name']) ?> (<?= $count ?>)
        </div>
        <?php 
            $colorIndex++;
            endif;
        endforeach; 
        ?>
    </div>
</div>
<?php endif; ?>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>