<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Inventar\ServiceTable;
use Bitrix\Inventar\EquipmentTable;
use Bitrix\Inventar\StatusTable;

Loader::includeModule('bitrix.inventar');

$APPLICATION->SetTitle("Service and Repairs");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

if ($APPLICATION->GetGroupRight("bitrix.inventar") < "R") {
    $APPLICATION->AuthForm("Access denied");
}

// Safe date conversion function
function safeDate($dateString) {
    if (empty($dateString)) return null;
    $dateString = trim($dateString);
    
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
        $parts = explode('-', $dateString);
        if (checkdate($parts[1], $parts[2], $parts[0])) {
            return new Date($dateString, 'Y-m-d');
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

// Process add service
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_service'])) {
    $equipmentId = intval($_POST['equipment_id']);
    $dataIntrare = safeDate($_POST['data_intrare']);
    $problema = $_POST['problema'];
    
    if ($equipmentId && $dataIntrare && $problema) {
        $result = ServiceTable::add([
            'EQUIPMENT_ID' => $equipmentId,
            'DATA_INTRARE' => $dataIntrare,
            'PROBLEMA' => $problema,
            'STATUS_ENUM' => 'in_service'
        ]);
        if ($result->isSuccess()) {
            EquipmentTable::update($equipmentId, ['STARE_ENUM' => '3']); // 3 = In repair
            CAdminMessage::ShowMessage("Service record added successfully!", "OK");
            LocalRedirect($APPLICATION->GetCurPage());
        } else {
            CAdminMessage::ShowMessage("Error: " . implode(", ", $result->getErrorMessages()));
        }
    } else {
        CAdminMessage::ShowMessage("All fields are required!");
    }
}

// Process complete service
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_service'])) {
    $serviceId = intval($_POST['service_id']);
    $service = ServiceTable::getById($serviceId)->fetch();
    if ($service) {
        $result = ServiceTable::update($serviceId, [
            'DATA_IESIRE' => new Date(),
            'SOLUTIE' => $_POST['solutie'],
            'COST_SERVICE' => floatval($_POST['cost_service']),
            'STATUS_ENUM' => 'repaired'
        ]);
        if ($result->isSuccess()) {
            EquipmentTable::update($service['EQUIPMENT_ID'], ['STARE_ENUM' => '2']); // 2 = In stock
            CAdminMessage::ShowMessage("Service completed successfully!", "OK");
            LocalRedirect($APPLICATION->GetCurPage());
        } else {
            CAdminMessage::ShowMessage("Error: " . implode(", ", $result->getErrorMessages()));
        }
    }
}

// Process delete service
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_service'])) {
    $serviceId = intval($_POST['service_id']);
    $service = ServiceTable::getById($serviceId)->fetch();
    if ($service) {
        // Restore equipment status to 'in_stock' if it was in repair
        EquipmentTable::update($service['EQUIPMENT_ID'], ['STARE_ENUM' => '2']);
        ServiceTable::delete($serviceId);
        CAdminMessage::ShowMessage("Service record deleted successfully!", "OK");
        LocalRedirect($APPLICATION->GetCurPage());
    }
}

// ========== PRELUARE PARAMETRI FILTRU ==========
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['filter_status'] ?? '');
$filterDateFrom = trim($_GET['filter_date_from'] ?? '');
$filterDateTo = trim($_GET['filter_date_to'] ?? '');
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Reset filter
if (isset($_GET['reset_filter'])) {
    LocalRedirect('/bitrix/admin/bitrix_inventar_service.php');
}

// ========== OBȚINE ECHIPAMENTELE PENTRU DROPDOWN ==========
$equipments = EquipmentTable::getList([
    'order' => ['DENUMIRE' => 'ASC']
])->fetchAll();

// ========== CONSTRUIRE FILTRU ==========
$connection = Application::getConnection();
$sqlHelper = $connection->getSqlHelper();

$whereConditions = [];

// Filtru după cod inventar sau nume echipament
if (!empty($search)) {
    $searchTerm = $sqlHelper->forSql('%' . $search . '%');
    $whereConditions[] = "(e.COD_INVENTAR LIKE '{$searchTerm}' OR e.DENUMIRE LIKE '{$searchTerm}')";
}

// Filtru după status
if (!empty($filterStatus)) {
    if ($filterStatus == 'in_service') {
        $whereConditions[] = "s.STATUS_ENUM = 'in_service'";
    } elseif ($filterStatus == 'repaired') {
        $whereConditions[] = "s.STATUS_ENUM = 'repaired'";
    }
}

// Filtru după data intrării
if (!empty($filterDateFrom)) {
    $whereConditions[] = "s.DATA_INTRARE >= '" . $sqlHelper->forSql($filterDateFrom) . "'";
}
if (!empty($filterDateTo)) {
    $whereConditions[] = "s.DATA_INTRARE <= '" . $sqlHelper->forSql($filterDateTo) . "'";
}

// ========== CONSTRUIRE QUERY ==========
$sql = "
    SELECT s.*, e.COD_INVENTAR, e.DENUMIRE as EQUIPMENT_NAME
    FROM b_bitrix_inventar_service s
    LEFT JOIN b_bitrix_inventar_equipment e ON s.EQUIPMENT_ID = e.ID
";

$countSql = "
    SELECT COUNT(*) as CNT
    FROM b_bitrix_inventar_service s
    LEFT JOIN b_bitrix_inventar_equipment e ON s.EQUIPMENT_ID = e.ID
";

if (!empty($whereConditions)) {
    $whereClause = " WHERE " . implode(" AND ", $whereConditions);
    $sql .= $whereClause;
    $countSql .= $whereClause;
}

$sql .= " ORDER BY s.ID DESC LIMIT {$offset}, {$perPage}";

// Execută query-urile
try {
    $total = 0;
    $countResult = $connection->query($countSql);
    if ($row = $countResult->fetch()) {
        $total = intval($row['CNT']);
    }
    
    $result = $connection->query($sql);
    $list = [];
    while ($row = $result->fetch()) {
        $list[] = $row;
    }
} catch (SqlQueryException $e) {
    $list = [];
    $total = 0;
    CAdminMessage::ShowMessage("Database error: " . $e->getMessage());
}

$totalPages = ceil($total / $perPage);

// Obține stările pentru afișare
$stari = StatusTable::getAllStatus();
?>

<style>
/* ====== STILURI GENERALE ====== */
.service-container { max-width: 1400px; margin: 0 auto; }

/* ====== FILTRU ====== */
.filter-box {
    background: #f5f5f5;
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    border: 1px solid #ddd;
}
.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.filter-group {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 150px;
}
.filter-group label {
    font-size: 11px;
    font-weight: bold;
    color: #555;
    margin-bottom: 3px;
}
.filter-group input,
.filter-group select {
    padding: 6px 8px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 13px;
    height: 32px;
    background: white;
    width: 100%;
}
.filter-group input:focus,
.filter-group select:focus {
    border-color: #2c7ed6;
    outline: none;
}
.filter-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    padding-bottom: 0;
}
.filter-actions .btn-filter {
    background: #2c7ed6;
    color: white;
    border: none;
    padding: 6px 16px;
    border-radius: 4px;
    cursor: pointer;
    height: 32px;
    font-size: 13px;
}
.filter-actions .btn-filter:hover {
    background: #1a4d8c;
}
.filter-actions .btn-reset {
    background: #999;
    color: white;
    border: none;
    padding: 6px 16px;
    border-radius: 4px;
    cursor: pointer;
    height: 32px;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
}
.filter-actions .btn-reset:hover {
    background: #777;
}
.filter-toggle {
    cursor: pointer;
    color: #2c7ed6;
    font-weight: bold;
    padding: 5px 10px;
    background: #e8f0fe;
    border-radius: 4px;
    display: inline-block;
    margin-bottom: 10px;
    font-size: 13px;
}
.filter-toggle:hover {
    background: #d0e0fe;
}

/* ====== FORMULAR ====== */
.form-box { 
    background: #fff; 
    padding: 25px; 
    margin-bottom: 25px; 
    border-radius: 10px; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.08); 
    border-top: 4px solid #FF9800;
}
.form-box h3 { 
    margin-top: 0; 
    color: #2c3e50; 
    font-size: 18px;
    border-bottom: 2px solid #f0f0f0;
    padding-bottom: 12px;
}
.form-group { 
    margin-bottom: 18px; 
    display: flex;
    align-items: center;
    flex-wrap: wrap;
}
.form-group label { 
    display: inline-block; 
    width: 160px; 
    font-weight: bold; 
    color: #333;
}
.form-group select,
.form-group input,
.form-group textarea { 
    width: 350px; 
    padding: 8px 12px; 
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    background: #fff;
}
.form-group select {
    height: 35px;
}
.form-group textarea {
    font-family: inherit;
    resize: vertical;
}
.form-group select:focus,
.form-group input:focus,
.form-group textarea:focus {
    border-color: #FF9800;
    outline: none;
    box-shadow: 0 0 0 3px rgba(255, 152, 0, 0.1);
}
.form-group small { 
    display: block;
    margin-left: 160px;
    color: #999; 
    font-size: 12px; 
    margin-top: 4px;
}
.form-actions {
    margin-left: 160px;
}
.btn-submit {
    background: #FF9800;
    color: white;
    border: none;
    padding: 10px 30px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
}
.btn-submit:hover {
    background: #f57c00;
}

/* ====== TABEL SERVICE ====== */
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    margin: 25px 0 15px 0;
}
.section-header h3 {
    margin: 0;
    font-size: 18px;
    color: #2c3e50;
}
.section-header .badge {
    background: #FF9800;
    color: white;
    padding: 4px 14px;
    border-radius: 20px;
    font-size: 13px;
}
.service-table { 
    width: 100%; 
    border-collapse: collapse; 
    background: white; 
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.service-table th { 
    background: #f5f7fa; 
    color: #333;
    padding: 12px 15px; 
    text-align: left; 
    font-weight: 600;
    border-bottom: 2px solid #e0e0e0;
}
.service-table td { 
    padding: 10px 15px; 
    border-bottom: 1px solid #eee; 
    vertical-align: middle;
}
.service-table tr:hover td {
    background: #f8faff;
}
.status-in-service { 
    color: #FF9800; 
    font-weight: bold; 
}
.status-repaired { 
    color: #4CAF50; 
    font-weight: bold; 
}
.btn-complete { 
    background: #4CAF50; 
    color: white; 
    border: none; 
    padding: 4px 12px; 
    cursor: pointer; 
    border-radius: 4px;
    font-size: 12px;
}
.btn-complete:hover {
    background: #388E3C;
}
.btn-delete { 
    background: #f44336; 
    color: white; 
    border: none; 
    padding: 4px 12px; 
    cursor: pointer; 
    border-radius: 4px;
    font-size: 12px;
    margin-left: 4px;
}
.btn-delete:hover {
    background: #d32f2f;
}

/* ====== PAGINARE ====== */
.pagination {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-top: 25px;
    flex-wrap: wrap;
}
.pagination a,
.pagination span {
    padding: 8px 15px;
    background: #f0f0f0;
    text-decoration: none;
    color: #333;
    border-radius: 6px;
    transition: background 0.2s;
}
.pagination a:hover {
    background: #ddd;
}
.pagination .active {
    background: #FF9800;
    color: white;
}

/* ====== FORMULAR COMPLETARE ====== */
#completeForm {
    display: none;
    margin-top: 20px;
    padding: 20px;
    background: #e8f5e9;
    border-radius: 8px;
    border-left: 4px solid #4CAF50;
}
#completeForm .form-group {
    margin-bottom: 12px;
}
#completeForm .form-group label {
    width: 140px;
}
#completeForm textarea,
#completeForm input {
    width: 400px;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
}
#completeForm .form-actions {
    margin-left: 140px;
}
.btn-confirm {
    background: #4CAF50;
    color: white;
    border: none;
    padding: 8px 25px;
    border-radius: 6px;
    cursor: pointer;
}
.btn-confirm:hover {
    background: #388E3C;
}
.btn-cancel {
    background: #999;
    color: white;
    border: none;
    padding: 8px 25px;
    border-radius: 6px;
    cursor: pointer;
    margin-left: 10px;
}
.btn-cancel:hover {
    background: #777;
}

/* ====== INFO BAR ====== */
.info-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    padding: 10px 15px;
    background: #f5f7fa;
    border-radius: 6px;
    margin-bottom: 15px;
    font-size: 13px;
    color: #555;
}

/* ====== EMPTY STATE ====== */
.empty-state {
    text-align: center;
    padding: 40px;
    color: #999;
    background: #fafafa;
    border-radius: 10px;
    border: 2px dashed #ddd;
}
.empty-state .icon {
    font-size: 48px;
    margin-bottom: 10px;
}

/* ====== RESPONSIVE ====== */
@media (max-width: 768px) {
    .filter-row {
        flex-direction: column;
    }
    .filter-group {
        width: 100%;
        min-width: 100%;
    }
    .filter-actions {
        width: 100%;
        justify-content: flex-end;
    }
    .form-group {
        flex-direction: column;
        align-items: flex-start;
    }
    .form-group label {
        width: 100%;
        margin-bottom: 5px;
    }
    .form-group select,
    .form-group input,
    .form-group textarea {
        width: 100%;
    }
    .form-group small {
        margin-left: 0;
    }
    .form-actions {
        margin-left: 0;
        width: 100%;
    }
    .btn-submit {
        width: 100%;
        text-align: center;
    }
    .service-table {
        font-size: 12px;
    }
    .service-table th,
    .service-table td {
        padding: 6px 8px;
    }
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    #completeForm .form-group {
        flex-direction: column;
        align-items: flex-start;
    }
    #completeForm .form-group label {
        width: 100%;
    }
    #completeForm textarea,
    #completeForm input {
        width: 100%;
    }
    #completeForm .form-actions {
        margin-left: 0;
    }
    .info-bar {
        flex-direction: column;
        gap: 5px;
        text-align: center;
    }
}
</style>

<div class="service-container">
    <!-- ====== FILTRU ====== -->
    <div class="filter-box">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <span class="filter-toggle" onclick="toggleFilter()">🔽 <span id="filterToggleText">Show filters</span></span>
            <?php if (!empty($search) || !empty($filterStatus) || !empty($filterDateFrom) || !empty($filterDateTo)): ?>
            <span style="background: #ff9800; color: white; padding: 2px 10px; border-radius: 12px; font-size: 12px;">Active filters</span>
            <?php endif; ?>
        </div>
        
        <form method="GET" id="filterForm">
            <div class="filter-row" id="filterRow">
                <div class="filter-group" style="flex: 2;">
                    <label>🔍 Search (Inventory code or Equipment name)</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by inventory code or equipment name...">
                </div>
                
                <div class="filter-group">
                    <label>📌 Status</label>
                    <select name="filter_status">
                        <option value="">All</option>
                        <option value="in_service" <?= ($filterStatus == 'in_service') ? 'selected' : '' ?>>In Service</option>
                        <option value="repaired" <?= ($filterStatus == 'repaired') ? 'selected' : '' ?>>Repaired</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>📅 Entry date from</label>
                    <input type="date" name="filter_date_from" value="<?= htmlspecialchars($filterDateFrom) ?>">
                </div>
                
                <div class="filter-group">
                    <label>📅 Entry date to</label>
                    <input type="date" name="filter_date_to" value="<?= htmlspecialchars($filterDateTo) ?>">
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">🔍 Filter</button>
                    <a href="?reset_filter=1" class="btn-reset">✖ Reset</a>
                </div>
            </div>
            <input type="hidden" name="page" value="1">
        </form>
    </div>

    <!-- ====== FORMULAR ADĂUGARE SERVICE ====== -->
    <div class="form-box">
        <h3>➕ Add New Service Record</h3>
        <form method="POST">
            <div class="form-group">
                <label>Equipment <span style="color:red;">*</span></label>
                <select name="equipment_id" required>
                    <option value="">- Select equipment -</option>
                    <?php foreach ($equipments as $eq): ?>
                    <option value="<?= $eq['ID'] ?>">[<?= htmlspecialchars($eq['COD_INVENTAR']) ?>] <?= htmlspecialchars($eq['DENUMIRE']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Service entry date <span style="color:red;">*</span></label>
                <input type="text" name="data_intrare" required value="<?= date('Y-m-d') ?>" placeholder="YYYY-MM-DD">
                <small>Format: YYYY-MM-DD (ex: 2024-01-15)</small>
            </div>
            <div class="form-group">
                <label>Problem / Defect <span style="color:red;">*</span></label>
                <textarea name="problema" rows="3" cols="50" required placeholder="Describe the problem..."></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" name="add_service" class="btn-submit">➕ Add Service</button>
            </div>
        </form>
    </div>

    <!-- ====== LISTA SERVICE ====== -->
    <div class="section-header">
        <h3>📋 Service History</h3>
        <span class="badge">Total: <?= $total ?> records</span>
    </div>

    <!-- ====== INFO BAR ====== -->
    <div class="info-bar">
        <span>Showing <?= count($list) ?> of <?= $total ?> records</span>
        <span>Page <?= $page ?> of <?= $totalPages > 0 ? $totalPages : 1 ?></span>
    </div>

    <?php if (count($list) > 0): ?>
    <table class="service-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Equipment</th>
                <th>Inventory code</th>
                <th>Entry date</th>
                <th>Exit date</th>
                <th>Problem</th>
                <th>Solution</th>
                <th>Cost</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($list as $item):
                $isActive = ($item['STATUS_ENUM'] == 'in_service');
                $equipmentName = $item['EQUIPMENT_NAME'] ?? 'N/A';
                $codInventar = $item['COD_INVENTAR'] ?? 'N/A';
            ?>
            <tr>
                <td><?= $item['ID'] ?></td>
                <td><?= htmlspecialchars($equipmentName) ?></td>
                <td><strong><?= htmlspecialchars($codInventar) ?></strong></td>
                <td><?= $item['DATA_INTRARE'] instanceof Date ? $item['DATA_INTRARE']->format('d.m.Y') : date('d.m.Y', strtotime($item['DATA_INTRARE'])) ?></td>
                <td><?= $item['DATA_IESIRE'] ? ($item['DATA_IESIRE'] instanceof Date ? $item['DATA_IESIRE']->format('d.m.Y') : date('d.m.Y', strtotime($item['DATA_IESIRE']))) : '-' ?></td>
                <td><?= htmlspecialchars(substr($item['PROBLEMA'] ?? '', 0, 80)) ?>...</td>
                <td><?= htmlspecialchars(substr($item['SOLUTIE'] ?? '', 0, 80)) ?>...</td>
                <td><?= $item['COST_SERVICE'] ? number_format($item['COST_SERVICE'], 2) . ' lei' : '-' ?></td>
                <td>
                    <?php if ($isActive): ?>
                        <span class="status-in-service">● In Service</span>
                    <?php else: ?>
                        <span class="status-repaired">✅ Repaired</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($isActive): ?>
                    <button class="btn-complete" onclick="showCompleteForm(<?= $item['ID'] ?>)">Complete</button>
                    <?php endif; ?>
                    <button class="btn-delete" onclick="if(confirm('Are you sure you want to delete this service record?')) showDeleteForm(<?= $item['ID'] ?>)">Delete</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ====== PAGINARE ====== -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge(array_filter([
            'search' => $search,
            'filter_status' => $filterStatus,
            'filter_date_from' => $filterDateFrom,
            'filter_date_to' => $filterDateTo
        ]), ['page' => 1])) ?>">« First</a>
        <a href="?<?= http_build_query(array_merge(array_filter([
            'search' => $search,
            'filter_status' => $filterStatus,
            'filter_date_from' => $filterDateFrom,
            'filter_date_to' => $filterDateTo
        ]), ['page' => $page - 1])) ?>">← Previous</a>
        <?php endif; ?>
        
        <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        if ($startPage > 1) {
            echo '<span>...</span>';
        }
        
        for ($i = $startPage; $i <= $endPage; $i++):
        ?>
            <?php if ($i == $page): ?>
            <span class="active"><?= $i ?></span>
            <?php else: ?>
            <a href="?<?= http_build_query(array_merge(array_filter([
                'search' => $search,
                'filter_status' => $filterStatus,
                'filter_date_from' => $filterDateFrom,
                'filter_date_to' => $filterDateTo
            ]), ['page' => $i])) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($endPage < $totalPages): ?>
        <span>...</span>
        <?php endif; ?>
        
        <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(array_merge(array_filter([
            'search' => $search,
            'filter_status' => $filterStatus,
            'filter_date_from' => $filterDateFrom,
            'filter_date_to' => $filterDateTo
        ]), ['page' => $page + 1])) ?>">Next →</a>
        <a href="?<?= http_build_query(array_merge(array_filter([
            'search' => $search,
            'filter_status' => $filterStatus,
            'filter_date_from' => $filterDateFrom,
            'filter_date_to' => $filterDateTo
        ]), ['page' => $totalPages])) ?>">Last »</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ====== FORMULAR COMPLETARE ====== -->
    <div id="completeForm">
        <form method="POST">
            <input type="hidden" name="service_id" id="complete_service_id">
            <div class="form-group">
                <label>Solution applied <span style="color:red;">*</span></label>
                <textarea name="solutie" rows="3" cols="50" required placeholder="Describe the solution applied..."></textarea>
            </div>
            <div class="form-group">
                <label>Service cost (lei):</label>
                <input type="number" step="0.01" name="cost_service" value="0.00" placeholder="0.00">
            </div>
            <div class="form-actions">
                <button type="submit" name="complete_service" class="btn-confirm">✅ Complete Service</button>
                <button type="button" onclick="hideCompleteForm()" class="btn-cancel">Cancel</button>
            </div>
        </form>
    </div>

    <!-- ====== FORMULAR ȘTERGERE ====== -->
    <div id="deleteForm" style="display:none;">
        <form method="POST">
            <input type="hidden" name="service_id" id="delete_service_id">
            <input type="submit" name="delete_service" value="Delete">
        </form>
    </div>

    <script>
    function showCompleteForm(serviceId) {
        document.getElementById('complete_service_id').value = serviceId;
        document.getElementById('completeForm').style.display = 'block';
        document.getElementById('completeForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    function hideCompleteForm() {
        document.getElementById('completeForm').style.display = 'none';
    }
    function showDeleteForm(serviceId) {
        document.getElementById('delete_service_id').value = serviceId;
        document.getElementById('deleteForm').submit();
    }
    
    function toggleFilter() {
        var row = document.getElementById('filterRow');
        var toggleText = document.getElementById('filterToggleText');
        var isHidden = row.style.display === 'none';
        
        row.style.display = isHidden ? 'flex' : 'none';
        toggleText.textContent = isHidden ? 'Hide filters' : 'Show filters';
    }
    
    // Auto-submit filter on Enter key
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll("#filterForm input, #filterForm select").forEach(function(el) {
            el.addEventListener("keypress", function(e) {
                if (e.key === "Enter") {
                    e.preventDefault();
                    document.getElementById("filterForm").submit();
                }
            });
        });
    });
    </script>

    <?php else: ?>
    <div class="empty-state">
        <div class="icon">🔧</div>
        <p>No service records found.</p>
        <p style="font-size: 13px; color: #bbb;">Start by adding a new service record using the form above.</p>
    </div>
    <?php endif; ?>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>