<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Inventar\AllocationTable;
use Bitrix\Inventar\EquipmentTable;
use Bitrix\Inventar\StatusTable;

Loader::includeModule('bitrix.inventar');

$APPLICATION->SetTitle("Equipment Allocations");
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

// Process add manual allocation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_allocation'])) {
    $equipmentId = intval($_POST['equipment_id']);
    $userId = intval($_POST['user_id']);
    $dataPredare = safeDate($_POST['data_predare']);
    
    if ($equipmentId && $userId && $dataPredare) {
        // Close old allocation (just in case)
        $currentAlloc = AllocationTable::getList([
            'filter' => ['=EQUIPMENT_ID' => $equipmentId, '=DATA_RETURNARE' => null],
            'select' => ['ID']
        ])->fetch();
        if ($currentAlloc) {
            AllocationTable::update($currentAlloc['ID'], ['DATA_RETURNARE' => new Date()]);
        }
        
        // Add new allocation (notification is sent automatically from onAfterAdd)
        $result = AllocationTable::add([
            'EQUIPMENT_ID' => $equipmentId,
            'USER_ID' => $userId,
            'DATA_PREDARE' => $dataPredare
        ]);
        
        if ($result->isSuccess()) {
            CAdminMessage::ShowMessage("Allocation added successfully!", "OK");
            LocalRedirect($APPLICATION->GetCurPage());
        } else {
            CAdminMessage::ShowMessage("Error: " . implode(", ", $result->getErrorMessages()));
        }
    } else {
        CAdminMessage::ShowMessage("All fields are required!");
    }
}

// Process close allocation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['close_alloc'])) {
    $allocId = intval($_POST['alloc_id']);
    $motiv = $_POST['motiv_returnare'] ?? '';
    $allocation = AllocationTable::getById($allocId)->fetch();
    if ($allocation) {
        AllocationTable::update($allocId, [
            'DATA_RETURNARE' => new Date(),
            'MOTIV_RETURNARE' => $motiv
        ]);
        EquipmentTable::update($allocation['EQUIPMENT_ID'], ['STARE_ENUM' => '2']); // 2 = In stock
        CAdminMessage::ShowMessage("Allocation closed successfully!", "OK");
        LocalRedirect($APPLICATION->GetCurPage());
    }
}

// ========== PRELUARE PARAMETRI FILTRU ==========
$search = trim($_GET['search'] ?? '');
$filterUser = trim($_GET['filter_user'] ?? '');
$filterStatus = trim($_GET['filter_status'] ?? '');
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Reset filter
if (isset($_GET['reset_filter'])) {
    LocalRedirect('/bitrix/admin/bitrix_inventar_allocations.php');
}

// ========== OBȚINE ECHIPAMENTELE NEALOCATE ==========
$unallocatedEquipment = [];
try {
    $connection = Application::getConnection();
    $sql = "
        SELECT e.* 
        FROM b_bitrix_inventar_equipment e
        WHERE NOT EXISTS (
            SELECT 1 
            FROM b_bitrix_inventar_allocation a 
            WHERE a.EQUIPMENT_ID = e.ID 
            AND a.DATA_RETURNARE IS NULL
        )
        ORDER BY e.DENUMIRE ASC
    ";
    $result = $connection->query($sql);
    while ($row = $result->fetch()) {
        $unallocatedEquipment[] = $row;
    }
} catch (SqlQueryException $e) {
    $allEquipment = EquipmentTable::getList(['order' => ['DENUMIRE' => 'ASC']])->fetchAll();
    foreach ($allEquipment as $eq) {
        $currentAlloc = AllocationTable::getList([
            'filter' => ['=EQUIPMENT_ID' => $eq['ID'], '=DATA_RETURNARE' => null],
            'select' => ['ID']
        ])->fetch();
        if (!$currentAlloc) {
            $unallocatedEquipment[] = $eq;
        }
    }
}

// ========== OBȚINE UTILIZATORII ==========
$responsibleGroupId = Option::get('bitrix.inventar', 'responsible_group_id', 0);
$arUsers = [];
if ($responsibleGroupId) {
    $dbUsers = CUser::GetList('id', 'asc', ['GROUPS_ID' => [$responsibleGroupId], 'ACTIVE' => 'Y']);
    while ($user = $dbUsers->Fetch()) {
        $arUsers[$user['ID']] = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
        if (empty($arUsers[$user['ID']])) $arUsers[$user['ID']] = $user['LOGIN'];
    }
}

if (empty($arUsers)) {
    $dbUsers = CUser::GetList('id', 'asc', ['ACTIVE' => 'Y']);
    while ($user = $dbUsers->Fetch()) {
        $arUsers[$user['ID']] = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
        if (empty($arUsers[$user['ID']])) $arUsers[$user['ID']] = $user['LOGIN'];
    }
}

// ========== CONSTRUIRE FILTRU PENTRU LISTA ALOCĂRI ==========
$connection = Application::getConnection();
$sqlHelper = $connection->getSqlHelper();

$whereConditions = [];

// Filtru după cod inventar sau nume echipament
if (!empty($search)) {
    $searchTerm = $sqlHelper->forSql('%' . $search . '%');
    $whereConditions[] = "(e.COD_INVENTAR LIKE '{$searchTerm}' OR e.DENUMIRE LIKE '{$searchTerm}')";
}

// Filtru după utilizator
if (!empty($filterUser)) {
    $whereConditions[] = "a.USER_ID = " . intval($filterUser);
}

// Filtru după status (activ/închis)
if (!empty($filterStatus)) {
    if ($filterStatus == 'active') {
        $whereConditions[] = "a.DATA_RETURNARE IS NULL";
    } elseif ($filterStatus == 'closed') {
        $whereConditions[] = "a.DATA_RETURNARE IS NOT NULL";
    }
}

// ========== CONSTRUIRE QUERY PENTRU LISTA ALOCĂRI ==========
$sql = "
    SELECT a.*, e.COD_INVENTAR, e.DENUMIRE as EQUIPMENT_NAME
    FROM b_bitrix_inventar_allocation a
    LEFT JOIN b_bitrix_inventar_equipment e ON a.EQUIPMENT_ID = e.ID
";

$countSql = "
    SELECT COUNT(*) as CNT
    FROM b_bitrix_inventar_allocation a
    LEFT JOIN b_bitrix_inventar_equipment e ON a.EQUIPMENT_ID = e.ID
";

if (!empty($whereConditions)) {
    $whereClause = " WHERE " . implode(" AND ", $whereConditions);
    $sql .= $whereClause;
    $countSql .= $whereClause;
}

$sql .= " ORDER BY a.ID DESC LIMIT {$offset}, {$perPage}";

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
.alloc-container { max-width: 1400px; margin: 0 auto; }

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
    border-top: 4px solid #2c7ed6;
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
.form-group input { 
    width: 350px; 
    padding: 8px 12px; 
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    background: #fff;
    height: 35px;
}
.form-group select:focus,
.form-group input:focus {
    border-color: #2c7ed6;
    outline: none;
    box-shadow: 0 0 0 3px rgba(44, 126, 214, 0.1);
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
    background: #2c7ed6;
    color: white;
    border: none;
    padding: 10px 30px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
}
.btn-submit:hover {
    background: #1a4d8c;
}

/* ====== TABEL ALOCĂRI ====== */
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
    background: #2c7ed6;
    color: white;
    padding: 4px 14px;
    border-radius: 20px;
    font-size: 13px;
}
.alloc-table { 
    width: 100%; 
    border-collapse: collapse; 
    background: white; 
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.alloc-table th { 
    background: #f5f7fa; 
    color: #333;
    padding: 12px 15px; 
    text-align: left; 
    font-weight: 600;
    border-bottom: 2px solid #e0e0e0;
}
.alloc-table td { 
    padding: 10px 15px; 
    border-bottom: 1px solid #eee; 
    vertical-align: middle;
}
.alloc-table tr:hover td {
    background: #f8faff;
}
.status-active { 
    color: #4CAF50; 
    font-weight: bold; 
}
.status-closed { 
    color: #999; 
}
.btn-close { 
    background: #ff9800; 
    color: white; 
    border: none; 
    padding: 4px 12px; 
    cursor: pointer; 
    border-radius: 4px;
    font-size: 12px;
}
.btn-close:hover {
    background: #f57c00;
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
    background: #2c7ed6;
    color: white;
}
.pagination .disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ====== FORMULAR ÎNCHIDERE ====== */
#closeForm {
    display: none;
    margin-top: 20px;
    padding: 20px;
    background: #fff8e1;
    border-radius: 8px;
    border-left: 4px solid #ff9800;
}
#closeForm .form-group {
    margin-bottom: 12px;
}
#closeForm .form-group label {
    width: 140px;
}
#closeForm textarea {
    width: 400px;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
}
#closeForm .form-actions {
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

/* ====== AVAILABLE EQUIPMENT COUNT ====== */
.available-info {
    background: #e8f5e9;
    padding: 8px 16px;
    border-radius: 6px;
    color: #2e7d32;
    font-size: 13px;
    margin-left: 10px;
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
    .form-group input {
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
    .alloc-table {
        font-size: 12px;
    }
    .alloc-table th,
    .alloc-table td {
        padding: 6px 8px;
    }
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    #closeForm .form-group {
        flex-direction: column;
        align-items: flex-start;
    }
    #closeForm .form-group label {
        width: 100%;
    }
    #closeForm textarea {
        width: 100%;
    }
    #closeForm .form-actions {
        margin-left: 0;
    }
    .info-bar {
        flex-direction: column;
        gap: 5px;
        text-align: center;
    }
}
</style>

<div class="alloc-container">
    <!-- ====== FILTRU ====== -->
    <div class="filter-box">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <span class="filter-toggle" onclick="toggleFilter()">🔽 <span id="filterToggleText">Show filters</span></span>
            <?php if (!empty($search) || !empty($filterUser) || !empty($filterStatus)): ?>
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
                    <label>👤 User</label>
                    <select name="filter_user">
                        <option value="">All users</option>
                        <?php foreach ($arUsers as $uid => $uname): ?>
                        <option value="<?= $uid ?>" <?= ($filterUser == $uid) ? 'selected' : '' ?>><?= htmlspecialchars($uname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>📌 Status</label>
                    <select name="filter_status">
                        <option value="">All</option>
                        <option value="active" <?= ($filterStatus == 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="closed" <?= ($filterStatus == 'closed') ? 'selected' : '' ?>>Closed</option>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">🔍 Filter</button>
                    <a href="?reset_filter=1" class="btn-reset">✖ Reset</a>
                </div>
            </div>
            <input type="hidden" name="page" value="1">
        </form>
    </div>

    <!-- ====== FORMULAR ADĂUGARE ALOCARE ====== -->
    <div class="form-box">
        <h3>➕ Add Manual Allocation</h3>
        <form method="POST">
            <div class="form-group">
                <label>Equipment <span style="color:red;">*</span></label>
                <select name="equipment_id" required>
                    <option value="">- Select equipment -</option>
                    <?php if (count($unallocatedEquipment) > 0): ?>
                        <?php foreach ($unallocatedEquipment as $eq): ?>
                        <option value="<?= $eq['ID'] ?>">
                            [<?= htmlspecialchars($eq['COD_INVENTAR']) ?>] 
                            <?= htmlspecialchars($eq['DENUMIRE']) ?>
                            <?php if (!empty($eq['MODEL'])): ?> - <?= htmlspecialchars($eq['MODEL']) ?><?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>No available equipment</option>
                    <?php endif; ?>
                </select>
                <small>
                    <?php if (count($unallocatedEquipment) > 0): ?>
                        <span class="available-info">✅ <?= count($unallocatedEquipment) ?> equipment available for allocation</span>
                    <?php else: ?>
                        <span style="color:#f44336;">⚠️ No equipment available for allocation</span>
                    <?php endif; ?>
                </small>
            </div>
            
            <div class="form-group">
                <label>Responsible user <span style="color:red;">*</span></label>
                <select name="user_id" required>
                    <option value="">- Select user -</option>
                    <?php foreach ($arUsers as $uid => $uname): ?>
                    <option value="<?= $uid ?>"><?= htmlspecialchars($uname) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Users from the responsible group</small>
            </div>
            
            <div class="form-group">
                <label>Handover date <span style="color:red;">*</span></label>
                <input type="text" name="data_predare" required value="<?= date('Y-m-d') ?>" placeholder="YYYY-MM-DD">
                <small>Format: YYYY-MM-DD (ex: 2024-01-15)</small>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="add_allocation" class="btn-submit">➕ Add Allocation</button>
            </div>
        </form>
    </div>

    <!-- ====== LISTA ALOCĂRI ====== -->
    <div class="section-header">
        <h3>📋 Allocation History</h3>
        <span class="badge">Total: <?= $total ?> allocations</span>
    </div>

    <!-- ====== INFO BAR ====== -->
    <div class="info-bar">
        <span>Showing <?= count($list) ?> of <?= $total ?> allocations</span>
        <span>Page <?= $page ?> of <?= $totalPages > 0 ? $totalPages : 1 ?></span>
    </div>

    <?php if (count($list) > 0): ?>
    <table class="alloc-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Equipment</th>
                <th>Inventory code</th>
                <th>User</th>
                <th>Handover date</th>
                <th>Return date</th>
                <th>Return reason</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($list as $item):
                $equipmentName = $item['EQUIPMENT_NAME'] ?? 'N/A';
                $codInventar = $item['COD_INVENTAR'] ?? 'N/A';
                $user = \Bitrix\Main\UserTable::getById($item['USER_ID'])->fetch();
                $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']) ?: $user['LOGIN'];
                $isActive = empty($item['DATA_RETURNARE']);
            ?>
            <tr>
                <td><?= $item['ID'] ?></td>
                <td><?= htmlspecialchars($equipmentName) ?></td>
                <td><strong><?= htmlspecialchars($codInventar) ?></strong></td>
                <td><?= htmlspecialchars($userName) ?></td>
                <td><?= $item['DATA_PREDARE'] instanceof Date ? $item['DATA_PREDARE']->format('d.m.Y') : date('d.m.Y', strtotime($item['DATA_PREDARE'])) ?></td>
                <td><?= $item['DATA_RETURNARE'] ? ($item['DATA_RETURNARE'] instanceof Date ? $item['DATA_RETURNARE']->format('d.m.Y') : date('d.m.Y', strtotime($item['DATA_RETURNARE']))) : '-' ?></td>
                <td><?= htmlspecialchars($item['MOTIV_RETURNARE'] ?: '-') ?></td>
                <td>
                    <?php if ($isActive): ?>
                        <span class="status-active">● Active</span>
                    <?php else: ?>
                        <span class="status-closed">Closed</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($isActive): ?>
                    <button class="btn-close" onclick="showCloseForm(<?= $item['ID'] ?>)">Close</button>
                    <?php endif; ?>
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
            'filter_user' => $filterUser,
            'filter_status' => $filterStatus
        ]), ['page' => 1])) ?>">« First</a>
        <a href="?<?= http_build_query(array_merge(array_filter([
            'search' => $search,
            'filter_user' => $filterUser,
            'filter_status' => $filterStatus
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
                'filter_user' => $filterUser,
                'filter_status' => $filterStatus
            ]), ['page' => $i])) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($endPage < $totalPages): ?>
        <span>...</span>
        <?php endif; ?>
        
        <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(array_merge(array_filter([
            'search' => $search,
            'filter_user' => $filterUser,
            'filter_status' => $filterStatus
        ]), ['page' => $page + 1])) ?>">Next →</a>
        <a href="?<?= http_build_query(array_merge(array_filter([
            'search' => $search,
            'filter_user' => $filterUser,
            'filter_status' => $filterStatus
        ]), ['page' => $totalPages])) ?>">Last »</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ====== FORMULAR ÎNCHIDERE ====== -->
    <div id="closeForm">
        <form method="POST">
            <input type="hidden" name="alloc_id" id="close_alloc_id">
            <div class="form-group">
                <label>Return reason:</label>
                <textarea name="motiv_returnare" rows="3" cols="50" placeholder="Enter reason for returning the equipment..."></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" name="close_alloc" class="btn-confirm">✅ Confirm Close</button>
                <button type="button" onclick="hideCloseForm()" class="btn-cancel">Cancel</button>
            </div>
        </form>
    </div>

    <script>
    function showCloseForm(allocId) {
        document.getElementById('close_alloc_id').value = allocId;
        document.getElementById('closeForm').style.display = 'block';
        document.getElementById('closeForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    function hideCloseForm() {
        document.getElementById('closeForm').style.display = 'none';
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
        <div class="icon">📭</div>
        <p>No allocations recorded.</p>
        <p style="font-size: 13px; color: #bbb;">Start by adding a new allocation using the form above.</p>
    </div>
    <?php endif; ?>
</div>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>