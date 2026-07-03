<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Inventar\EquipmentTable;
use Bitrix\Inventar\AllocationTable;
use Bitrix\Inventar\TypesTable;
use Bitrix\Inventar\StatusTable;

Loader::includeModule('bitrix.inventar');

$APPLICATION->SetTitle("Equipment List");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

if ($APPLICATION->GetGroupRight("bitrix.inventar") < "R") {
    $APPLICATION->AuthForm("Access denied");
}

// ========== FUNCȚIE PENTRU REDIRECȚIONARE CURATĂ ==========
function cleanRedirect() {
    global $APPLICATION;
    
    $params = $_GET;
    
    // Elimină parametrii temporari
    unset($params['_']);
    unset($params['apply']);
    unset($params['action']);
    unset($params['mass_edit_value']);
    unset($params['delete_id']);
    
    // Elimină parametrii goi
    $params = array_filter($params, function($value) {
        return $value !== '' && $value !== null;
    });
    
    // Păstrează mode=frame dacă există
    if (isset($_GET['mode']) && $_GET['mode'] == 'frame') {
        $params['mode'] = 'frame';
    }
    
    // Asigură-te că page există
    if (!isset($params['page']) || $params['page'] < 1) {
        $params['page'] = 1;
    }
    
    $url = $APPLICATION->GetCurPage();
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    // Redirecționează
    ?>
    <script>
        var url = '<?= $url ?>';
        if (window.top && window.top.location) {
            window.top.location.href = url;
        } else if (window.parent && window.parent.location) {
            window.parent.location.href = url;
        } else {
            window.location.href = url;
        }
    </script>
    <?php
    exit;
}

// ========== PROCESARE EDITARE ÎN MASĂ ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['edit_type', 'edit_status'])) {
    if ($APPLICATION->GetGroupRight("bitrix.inventar") >= "W") {
        if (isset($_POST['ID']) && is_array($_POST['ID']) && count($_POST['ID']) > 0) {
            $updatedCount = 0;
            $action = $_POST['action'];
            $fieldName = ($action == 'edit_type') ? 'TIP_ENUM' : 'STARE_ENUM';
            $fieldValue = $_POST['mass_edit_value'] ?? '';
            
            if (!empty($fieldValue)) {
                foreach ($_POST['ID'] as $id) {
                    try {
                        $updateData = [$fieldName => $fieldValue];
                        $result = EquipmentTable::update($id, $updateData);
                        if ($result->isSuccess()) {
                            $updatedCount++;
                        }
                    } catch (Exception $e) {
                        // Ignore individual errors
                    }
                }
                $fieldLabel = ($action == 'edit_type') ? 'Type' : 'Status';
                CAdminMessage::ShowMessage("Mass update completed: <strong>{$updatedCount}</strong> records updated successfully! ({$fieldLabel})", "OK");
            } else {
                CAdminMessage::ShowMessage("Please select a value for the update!", "ERROR");
            }
        } else {
            CAdminMessage::ShowMessage("No equipment selected for mass edit!", "ERROR");
        }
    }
    cleanRedirect();
}

// Process individual delete
if (isset($_GET['delete_id']) && intval($_GET['delete_id']) > 0) {
    $id = intval($_GET['delete_id']);
    if ($APPLICATION->GetGroupRight("bitrix.inventar") >= "W") {
        EquipmentTable::delete($id);
        CAdminMessage::ShowMessage("Equipment deleted successfully!", "OK");
    }
    cleanRedirect();
}

// Process group delete
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete') {
    if ($APPLICATION->GetGroupRight("bitrix.inventar") >= "W") {
        if (isset($_POST['ID']) && is_array($_POST['ID'])) {
            foreach ($_POST['ID'] as $id) {
                EquipmentTable::delete($id);
            }
            CAdminMessage::ShowMessage("Equipment deleted successfully!", "OK");
        }
    }
    cleanRedirect();
}

// Get types and statuses from database
$tipText = TypesTable::getAllTypes();
$stareInfo = StatusTable::getAllStatus();

// ========== PRELUARE PARAMETRI FILTRU ==========
$search = trim($_GET['search'] ?? '');
$filterType = trim($_GET['filter_type'] ?? '');
$filterStatus = trim($_GET['filter_status'] ?? '');
$filterLocation = trim($_GET['filter_location'] ?? '');
$filterUser = trim($_GET['filter_user'] ?? '');
$filterAssigned = trim($_GET['filter_assigned'] ?? '');

// Reset filter
if (isset($_GET['reset_filter'])) {
    LocalRedirect('/bitrix/admin/bitrix_inventar_equipment_list.php');
}

// ========== CONSTRUIRE FILTRU ==========
$connection = Application::getConnection();
$sqlHelper = $connection->getSqlHelper();

$whereConditions = [];

// Search - caută în toate câmpurile relevante (inclusiv Manufacturer, Model, Serial)
if (!empty($search)) {
    $searchTerm = $sqlHelper->forSql('%' . $search . '%');
    $whereConditions[] = "(e.COD_INVENTAR LIKE '{$searchTerm}' 
                          OR e.DENUMIRE LIKE '{$searchTerm}' 
                          OR e.PRODUCATOR LIKE '{$searchTerm}' 
                          OR e.MODEL LIKE '{$searchTerm}' 
                          OR e.SERIAL_NR LIKE '{$searchTerm}' 
                          OR e.LOCATIE LIKE '{$searchTerm}' 
                          OR e.FURNIZOR LIKE '{$searchTerm}')";
}

if (!empty($filterType)) {
    $whereConditions[] = "e.TIP_ENUM = '" . $sqlHelper->forSql($filterType) . "'";
}

if (!empty($filterStatus)) {
    $whereConditions[] = "e.STARE_ENUM = '" . $sqlHelper->forSql($filterStatus) . "'";
}

if (!empty($filterLocation)) {
    $whereConditions[] = "e.LOCATIE = '" . $sqlHelper->forSql($filterLocation) . "'";
}

if (!empty($filterUser)) {
    $userId = intval($filterUser);
    $whereConditions[] = "EXISTS (SELECT 1 FROM b_bitrix_inventar_allocation a WHERE a.EQUIPMENT_ID = e.ID AND a.USER_ID = {$userId} AND a.DATA_RETURNARE IS NULL)";
}

if (!empty($filterAssigned)) {
    if ($filterAssigned == 'yes') {
        $whereConditions[] = "EXISTS (SELECT 1 FROM b_bitrix_inventar_allocation a WHERE a.EQUIPMENT_ID = e.ID AND a.DATA_RETURNARE IS NULL)";
    } elseif ($filterAssigned == 'no') {
        $whereConditions[] = "NOT EXISTS (SELECT 1 FROM b_bitrix_inventar_allocation a WHERE a.EQUIPMENT_ID = e.ID AND a.DATA_RETURNARE IS NULL)";
    }
}

// ========== PAGINARE ==========
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Construiește query-ul
$sql = "SELECT e.* FROM b_bitrix_inventar_equipment e";
$countSql = "SELECT COUNT(*) as CNT FROM b_bitrix_inventar_equipment e";

if (!empty($whereConditions)) {
    $whereClause = " WHERE " . implode(" AND ", $whereConditions);
    $sql .= $whereClause;
    $countSql .= $whereClause;
}

// Order și limit
$sql .= " ORDER BY e.ID DESC LIMIT {$offset}, {$perPage}";

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

// ========== OBȚINE UTILIZATORII PENTRU FILTRU ==========
$arUsers = [];
$responsibleGroupId = \Bitrix\Main\Config\Option::get('bitrix.inventar', 'responsible_group_id', 0);
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

// ========== OBȚINE LOCAȚIILE PENTRU FILTRU ==========
$allLocations = [];
try {
    $locResult = $connection->query("SELECT DISTINCT LOCATIE FROM b_bitrix_inventar_equipment WHERE LOCATIE IS NOT NULL AND LOCATIE != '' ORDER BY LOCATIE");
    while ($row = $locResult->fetch()) {
        $allLocations[] = $row['LOCATIE'];
    }
} catch (SqlQueryException $e) {
    $allLocations = [];
}

// ========== DEFINE TABLE COLUMNS ==========
$arHeaders = [
    ["id" => "ID", "content" => "ID", "default" => true, "width" => 50],
    ["id" => "COD_INVENTAR", "content" => "Inventory code", "default" => true, "width" => 120],
    ["id" => "DENUMIRE", "content" => "Name", "default" => true, "width" => 200],
    ["id" => "TIP_ENUM", "content" => "Type", "default" => true, "width" => 120],
    ["id" => "PRODUCATOR", "content" => "Manufacturer", "default" => true, "width" => 120],
    ["id" => "MODEL", "content" => "Model", "default" => true, "width" => 120],
    ["id" => "SERIAL_NR", "content" => "Serial", "default" => true, "width" => 120],
    ["id" => "DATA_ACHIZITIE", "content" => "Purchase date", "default" => true, "width" => 100],
    ["id" => "DATA_EXPIRARE_GARANTIE", "content" => "Warranty", "default" => true, "width" => 100],
    ["id" => "STARE_ENUM", "content" => "Status", "default" => true, "width" => 100],
    ["id" => "LOCATIE", "content" => "Location", "default" => true, "width" => 150],
    ["id" => "UTILIZATOR", "content" => "Current user", "default" => true, "width" => 150]
];

$sTableID = "tbl_equipment";
$lAdmin = new CAdminList($sTableID);
$lAdmin->AddHeaders($arHeaders);

// ========== CONSTRUIEȘTE URL-UL DE BACK PENTRU EDITARE ==========
function buildBackUrl() {
    $params = array_filter([
        'search' => $_GET['search'] ?? '',
        'filter_type' => $_GET['filter_type'] ?? '',
        'filter_status' => $_GET['filter_status'] ?? '',
        'filter_location' => $_GET['filter_location'] ?? '',
        'filter_user' => $_GET['filter_user'] ?? '',
        'filter_assigned' => $_GET['filter_assigned'] ?? '',
        'page' => $_GET['page'] ?? 1
    ]);
    
    if (isset($_GET['mode']) && $_GET['mode'] == 'frame') {
        $params['mode'] = 'frame';
    }
    
    if (empty($params)) {
        return '';
    }
    
    return '&back=' . urlencode(http_build_query($params));
}

$backParams = buildBackUrl();

// ========== AFIȘARE FILTRU PROFESIONAL ==========
?>
<style>
/* ========== FILTRU PROFESIONAL ========== */
.filter-container {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 25px;
    overflow: hidden;
    border: 1px solid #e8ecf0;
}

.filter-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e8ecf0;
    cursor: pointer;
    user-select: none;
}
.filter-header:hover {
    background: #f0f4f8;
}
.filter-header .title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    color: #2c3e50;
    font-size: 14px;
}
.filter-header .title .icon {
    font-size: 18px;
}
.filter-header .badge {
    background: #2c7ed6;
    color: white;
    padding: 2px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.filter-header .toggle-icon {
    transition: transform 0.3s ease;
    font-size: 18px;
    color: #999;
}
.filter-header .toggle-icon.open {
    transform: rotate(180deg);
}

.filter-body {
    padding: 20px;
}
.filter-body.collapsed {
    display: none;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 15px 20px;
}
.filter-group {
    display: flex;
    flex-direction: column;
}
.filter-group label {
    font-size: 11px;
    font-weight: 600;
    color: #666;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.filter-group label .icon {
    margin-right: 4px;
}
.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border: 1px solid #dce1e6;
    border-radius: 6px;
    font-size: 13px;
    color: #333;
    background: #fafbfc;
    transition: all 0.2s;
    height: 38px;
    width: 100%;
}
.filter-group input:focus,
.filter-group select:focus {
    border-color: #2c7ed6;
    outline: none;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(44, 126, 214, 0.1);
}
.filter-group input::placeholder {
    color: #b0b8c0;
}
.filter-group select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 35px;
}

.filter-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    padding-top: 5px;
}
.filter-actions .btn-filter {
    background: #2c7ed6;
    color: white;
    border: none;
    padding: 8px 24px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: background 0.2s;
    height: 38px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.filter-actions .btn-filter:hover {
    background: #1a5fa0;
}
.filter-actions .btn-reset {
    background: #e8ecf0;
    color: #555;
    border: none;
    padding: 8px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    transition: background 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 38px;
}
.filter-actions .btn-reset:hover {
    background: #d5dce3;
}

.filter-divider {
    grid-column: 1 / -1;
    border: none;
    border-top: 1px solid #e8ecf0;
    margin: 5px 0;
}

.filter-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 15px 25px;
    padding: 10px 20px;
    background: #f8fafc;
    border-top: 1px solid #e8ecf0;
    font-size: 13px;
    color: #555;
}
.filter-stats .stat-item {
    display: flex;
    align-items: center;
    gap: 5px;
}
.filter-stats .stat-item strong {
    color: #2c3e50;
}
.filter-stats .stat-item .value {
    font-weight: 600;
    color: #2c7ed6;
}

@media (max-width: 768px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }
    .filter-actions {
        flex-wrap: wrap;
    }
    .filter-actions .btn-filter,
    .filter-actions .btn-reset {
        flex: 1;
        justify-content: center;
    }
    .filter-stats {
        flex-direction: column;
        gap: 5px;
        padding: 10px 15px;
    }
}
</style>

<div class="filter-container" id="filterContainer">
    <div class="filter-header" onclick="toggleFilter()">
        <div class="title">
            <span class="icon">🔍</span>
            Advanced Filters
            <?php if (!empty($search) || !empty($filterType) || !empty($filterStatus) || !empty($filterLocation) || !empty($filterUser) || !empty($filterAssigned)): ?>
            <span class="badge">Active</span>
            <?php endif; ?>
        </div>
        <div>
            <span class="toggle-icon" id="filterToggleIcon">▼</span>
        </div>
    </div>
    
    <div class="filter-body" id="filterBody">
        <form method="GET" id="filterForm">
            <div class="filter-grid">
                <!-- Search - include Manufacturer, Model, Serial -->
                <div class="filter-group" style="grid-column: 1 / -1;">
                    <label><span class="icon">🔎</span>Global Search</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search in: Code, Name, Manufacturer, Model, Serial, Location, Supplier...">
                </div>
                
                <hr class="filter-divider">
                
                <!-- Type -->
                <div class="filter-group">
                    <label><span class="icon">📁</span>Type</label>
                    <select name="filter_type">
                        <option value="">All Types</option>
                        <?php foreach ($tipText as $val => $name): ?>
                        <option value="<?= $val ?>" <?= ($filterType == $val) ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Status -->
                <div class="filter-group">
                    <label><span class="icon">⚙️</span>Status</label>
                    <select name="filter_status">
                        <option value="">All Statuses</option>
                        <?php foreach ($stareInfo as $val => $info): ?>
                        <option value="<?= $val ?>" <?= ($filterStatus == $val) ? 'selected' : '' ?>><?= htmlspecialchars($info['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Location -->
                <div class="filter-group">
                    <label><span class="icon">📍</span>Location</label>
                    <select name="filter_location">
                        <option value="">All Locations</option>
                        <?php foreach ($allLocations as $loc): ?>
                        <option value="<?= htmlspecialchars($loc) ?>" <?= ($filterLocation == $loc) ? 'selected' : '' ?>><?= htmlspecialchars($loc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Assigned Status -->
                <div class="filter-group">
                    <label><span class="icon">📌</span>Assigned Status</label>
                    <select name="filter_assigned">
                        <option value="">All Equipment</option>
                        <option value="yes" <?= ($filterAssigned == 'yes') ? 'selected' : '' ?>>✅ Assigned</option>
                        <option value="no" <?= ($filterAssigned == 'no') ? 'selected' : '' ?>>📦 Not Assigned</option>
                    </select>
                </div>
                
                <!-- Responsible User (aliniat lângă Assigned) -->
                <div class="filter-group">
                    <label><span class="icon">👤</span>Responsible User</label>
                    <select name="filter_user">
                        <option value="">All Users</option>
                        <?php foreach ($arUsers as $uid => $uname): ?>
                        <option value="<?= $uid ?>" <?= ($filterUser == $uid) ? 'selected' : '' ?>><?= htmlspecialchars($uname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <hr class="filter-divider">
                
                <!-- Actions -->
                <div class="filter-actions" style="grid-column: 1 / -1;">
                    <button type="submit" class="btn-filter">🔍 Apply Filters</button>
                    <a href="?reset_filter=1" class="btn-reset">✖ Reset All</a>
                </div>
            </div>
            <input type="hidden" name="page" value="1">
        </form>
    </div>
    
    <!-- Filter Stats -->
    <div class="filter-stats" id="filterStats">
        <span class="stat-item">📊 Total: <strong><?= $total ?></strong> equipment</span>
        <?php if (!empty($search)): ?>
        <span class="stat-item">🔎 Search: <span class="value">"<?= htmlspecialchars($search) ?>"</span></span>
        <?php endif; ?>
        <?php if (!empty($filterType) && isset($tipText[$filterType])): ?>
        <span class="stat-item">📁 Type: <span class="value"><?= htmlspecialchars($tipText[$filterType]) ?></span></span>
        <?php endif; ?>
        <?php if (!empty($filterStatus) && isset($stareInfo[$filterStatus])): ?>
        <span class="stat-item">⚙️ Status: <span class="value"><?= htmlspecialchars($stareInfo[$filterStatus]['name']) ?></span></span>
        <?php endif; ?>
        <?php if (!empty($filterLocation)): ?>
        <span class="stat-item">📍 Location: <span class="value"><?= htmlspecialchars($filterLocation) ?></span></span>
        <?php endif; ?>
        <?php if (!empty($filterUser) && isset($arUsers[$filterUser])): ?>
        <span class="stat-item">👤 User: <span class="value"><?= htmlspecialchars($arUsers[$filterUser]) ?></span></span>
        <?php endif; ?>
        <?php if ($filterAssigned == 'yes'): ?>
        <span class="stat-item" style="color:#4CAF50;">✅ Assigned only</span>
        <?php elseif ($filterAssigned == 'no'): ?>
        <span class="stat-item" style="color:#FF9800;">📦 Not assigned only</span>
        <?php endif; ?>
        <span class="stat-item" style="margin-left: auto;">📄 Page <?= $page ?> of <?= $totalPages > 0 ? $totalPages : 1 ?></span>
    </div>
</div>

<script>
function toggleFilter() {
    var body = document.getElementById('filterBody');
    var icon = document.getElementById('filterToggleIcon');
    var isCollapsed = body.classList.contains('collapsed');
    
    if (isCollapsed) {
        body.classList.remove('collapsed');
        icon.textContent = '▼';
    } else {
        body.classList.add('collapsed');
        icon.textContent = '▶';
    }
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
    
    // Restore filter state from localStorage
    var savedState = localStorage.getItem('filterCollapsed');
    if (savedState === 'true') {
        document.getElementById('filterBody').classList.add('collapsed');
        document.getElementById('filterToggleIcon').textContent = '▶';
    }
    
    // Save state when toggling
    document.querySelector('.filter-header').addEventListener('click', function() {
        var isCollapsed = document.getElementById('filterBody').classList.contains('collapsed');
        localStorage.setItem('filterCollapsed', isCollapsed);
    });
});
</script>

<?php
// Display pagination info
echo '<div style="margin-bottom: 15px; padding: 10px; background: #f5f5f5; border-radius: 5px;">';
echo '<strong>Total equipment:</strong> ' . $total . ' | ';
echo '<strong>Page:</strong> ' . $page . ' of ' . $totalPages;
if (!empty($search) || !empty($filterType) || !empty($filterStatus) || !empty($filterLocation) || !empty($filterUser) || !empty($filterAssigned)) {
    echo ' | <span style="color: #ff9800;">⚡ Filtered results</span>';
}
echo '</div>';

// Add rows to table
foreach ($list as $arRes) {
    $f_ID = $arRes['ID'];
    $row = $lAdmin->AddRow($f_ID, $arRes);
    
    $userId = AllocationTable::getCurrentUserForEquipment($f_ID);
    $userName = '';
    if ($userId) {
        $user = \Bitrix\Main\UserTable::getById($userId)->fetch();
        $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
        if (empty($userName)) $userName = $user['LOGIN'];
    }
    $row->AddViewField("UTILIZATOR", $userName ?: "Not assigned");
    
    $dataAchizitie = $arRes['DATA_ACHIZITIE'] ? date('d.m.Y', strtotime($arRes['DATA_ACHIZITIE'])) : '-';
    $row->AddViewField("DATA_ACHIZITIE", $dataAchizitie);
    
    $garantie = $arRes['DATA_EXPIRARE_GARANTIE'] ? date('d.m.Y', strtotime($arRes['DATA_EXPIRARE_GARANTIE'])) : '-';
    $row->AddViewField("DATA_EXPIRARE_GARANTIE", $garantie);
    
    $stareColor = isset($stareInfo[$arRes['STARE_ENUM']]['color']) ? $stareInfo[$arRes['STARE_ENUM']]['color'] : '#666';
    $stareName = isset($stareInfo[$arRes['STARE_ENUM']]['name']) ? $stareInfo[$arRes['STARE_ENUM']]['name'] : $arRes['STARE_ENUM'];
    $row->AddViewField("STARE_ENUM", '<span style="color:' . $stareColor . '; font-weight:bold;">' . htmlspecialchars($stareName) . '</span>');
    
    $tipName = isset($tipText[$arRes['TIP_ENUM']]) ? $tipText[$arRes['TIP_ENUM']] : $arRes['TIP_ENUM'];
    $row->AddViewField("TIP_ENUM", htmlspecialchars($tipName));
    
    $row->AddViewField("PRODUCATOR", htmlspecialchars(!empty($arRes['PRODUCATOR']) ? $arRes['PRODUCATOR'] : '-'));
    $row->AddViewField("MODEL", htmlspecialchars(!empty($arRes['MODEL']) ? $arRes['MODEL'] : '-'));
    $row->AddViewField("SERIAL_NR", htmlspecialchars(!empty($arRes['SERIAL_NR']) ? $arRes['SERIAL_NR'] : '-'));
    $row->AddViewField("LOCATIE", htmlspecialchars(!empty($arRes['LOCATIE']) ? $arRes['LOCATIE'] : '-'));
    
    $editUrl = "/bitrix/admin/bitrix_inventar_equipment_edit.php?ID=" . $f_ID . $backParams;
    $row->AddActions([
        ["ICON" => "edit", "TEXT" => "Edit", "ACTION" => $lAdmin->ActionRedirect($editUrl), "DEFAULT" => true],
        ["ICON" => "delete", "TEXT" => "Delete", "ACTION" => "if(confirm('Are you sure you want to delete this equipment?')) window.location.href='?delete_id=" . $f_ID . "';"]
    ]);
}

// Add footer and group actions
$lAdmin->AddFooter([
    ["title" => "Select all", "value" => "check_all"],
    ["title" => "Actions", "value" => "delete"]
]);

// ========== ACȚIUNI DE GRUP ==========
$arGroupActions = [
    "delete" => "Delete selected"
];

if ($APPLICATION->GetGroupRight("bitrix.inventar") >= "W") {
    $arGroupActions["edit_type"] = "Edit Type";
    $arGroupActions["edit_status"] = "Edit Status";
}

$lAdmin->AddGroupActionTable($arGroupActions);

$lAdmin->CheckListMode();
$lAdmin->DisplayList();

// Build pagination URL with filters
function buildFilterUrl($params = []) {
    $baseParams = array_filter([
        'search' => $_GET['search'] ?? '',
        'filter_type' => $_GET['filter_type'] ?? '',
        'filter_status' => $_GET['filter_status'] ?? '',
        'filter_location' => $_GET['filter_location'] ?? '',
        'filter_user' => $_GET['filter_user'] ?? '',
        'filter_assigned' => $_GET['filter_assigned'] ?? ''
    ]);
    
    if (isset($_GET['mode']) && $_GET['mode'] == 'frame') {
        $baseParams['mode'] = 'frame';
    }
    
    $finalParams = array_merge($baseParams, $params);
    return '?' . http_build_query(array_filter($finalParams));
}

// Manual pagination with filter support
if ($totalPages > 1) {
    echo '<div style="margin-top: 20px; text-align: center;">';
    echo '<div class="pagination" style="display: inline-flex; gap: 10px; flex-wrap: wrap;">';
    
    if ($page > 1) {
        echo '<a href="' . buildFilterUrl(['page' => 1]) . '" class="adm-btn" style="background: #2c7ed6;">« First</a>';
        echo '<a href="' . buildFilterUrl(['page' => $page - 1]) . '" class="adm-btn" style="background: #2c7ed6;">← Previous</a>';
    }
    
    $startPage = max(1, $page - 2);
    $endPage = min($totalPages, $page + 2);
    
    if ($startPage > 1) {
        echo '<span style="padding: 5px 10px;">...</span>';
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        if ($i == $page) {
            echo '<span style="padding: 5px 12px; background: #2c7ed6; color: white; border-radius: 4px;">' . $i . '</span>';
        } else {
            echo '<a href="' . buildFilterUrl(['page' => $i]) . '" class="adm-btn" style="background: #f0f0f0; color: #333;">' . $i . '</a>';
        }
    }
    
    if ($endPage < $totalPages) {
        echo '<span style="padding: 5px 10px;">...</span>';
    }
    
    if ($page < $totalPages) {
        echo '<a href="' . buildFilterUrl(['page' => $page + 1]) . '" class="adm-btn" style="background: #2c7ed6;">Next →</a>';
        echo '<a href="' . buildFilterUrl(['page' => $totalPages]) . '" class="adm-btn" style="background: #2c7ed6;">Last »</a>';
    }
    
    echo '</div>';
    echo '</div>';
}

// Additional buttons
$addUrl = "/bitrix/admin/bitrix_inventar_equipment_edit.php" . ($backParams ? '?' . ltrim($backParams, '&') : '');
echo '<br><br>';
echo '<a href="' . $addUrl . '" class="adm-btn">+ Add new equipment</a>';
echo '&nbsp;&nbsp;<a href="/bitrix/admin/bitrix_inventar_types_status.php" class="adm-btn">⚙️ Manage Types & Statuses</a>';
echo '&nbsp;&nbsp;<a href="/bitrix/admin/bitrix_inventar_allocations.php" class="adm-btn">📋 Allocations</a>';
echo '&nbsp;&nbsp;<a href="/bitrix/admin/bitrix_inventar_service.php" class="adm-btn">🔧 Service</a>';

// ========== JAVASCRIPT PENTRU EDITARE ÎN MASĂ ==========
$tipOptionsJson = [];
foreach ($tipText as $key => $value) {
    $tipOptionsJson[] = ['value' => $key, 'label' => addslashes($value)];
}

$statusOptionsJson = [];
foreach ($stareInfo as $key => $info) {
    $statusOptionsJson[] = ['value' => $key, 'label' => addslashes($info['name'])];
}
?>

<script>
var tipOptions = <?= json_encode($tipOptionsJson) ?>;
var statusOptions = <?= json_encode($statusOptionsJson) ?>;

// Funcție pentru inițializarea dropdown-urilor
function initMassEdit() {
    var actionSelect = document.querySelector("select[name='action']");
    if (!actionSelect) {
        actionSelect = document.getElementById("tbl_equipment_action");
    }
    
    if (actionSelect) {
        var footer = document.getElementById("tbl_equipment_footer");
        if (!footer) {
            footer = document.querySelector(".adm-list-table-footer");
        }
        
        if (footer) {
            var applySpan = footer.querySelector(".adm-table-action-button");
            if (!applySpan) {
                applySpan = footer.querySelector("input[name='apply']");
                if (applySpan) {
                    applySpan = applySpan.parentNode;
                }
            }
            
            if (applySpan) {
                var existingContainer = footer.querySelector(".mass-edit-fields");
                if (existingContainer) {
                    existingContainer.remove();
                }
                
                var container = document.createElement("span");
                container.className = "mass-edit-fields";
                container.style.cssText = "display: none; margin-left: 10px;";
                
                var valueSelect = document.createElement("select");
                valueSelect.name = "mass_edit_value";
                valueSelect.style.cssText = "padding: 3px 8px; height: 28px; min-width: 150px; border: 1px solid #ccc; border-radius: 4px; background: #fff;";
                valueSelect.innerHTML = '<option value="">Select value...</option>';
                
                container.appendChild(valueSelect);
                applySpan.parentNode.insertBefore(container, applySpan);
                
                actionSelect.removeEventListener('change', handleActionChange);
                actionSelect.addEventListener('change', handleActionChange);
                
                window._massEditValueSelect = valueSelect;
                window._massEditContainer = container;
                
                var currentAction = actionSelect.value;
                if (currentAction === "edit_type" || currentAction === "edit_status") {
                    handleActionChange();
                }
            }
        }
    }
}

function handleActionChange() {
    var actionSelect = document.querySelector("select[name='action']") || document.getElementById("tbl_equipment_action");
    var action = actionSelect ? actionSelect.value : '';
    var valueSelect = window._massEditValueSelect;
    var container = window._massEditContainer;
    
    if (!valueSelect || !container) return;
    
    valueSelect.innerHTML = '<option value="">Select value...</option>';
    
    var options = [];
    if (action === "edit_type") {
        options = tipOptions;
    } else if (action === "edit_status") {
        options = statusOptions;
    }
    
    if (action === "edit_type" || action === "edit_status") {
        container.style.display = "inline";
        options.forEach(function(opt) {
            var option = document.createElement("option");
            option.value = opt.value;
            option.textContent = opt.label;
            valueSelect.appendChild(option);
        });
    } else {
        container.style.display = "none";
    }
}

function fullInit() {
    initMassEdit();
    document.querySelectorAll("#filterForm input, #filterForm select").forEach(function(el) {
        el.addEventListener("keypress", function(e) {
            if (e.key === "Enter") {
                e.preventDefault();
                document.getElementById("filterForm").submit();
            }
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener("DOMContentLoaded", function() {
        setTimeout(fullInit, 200);
    });
} else {
    setTimeout(fullInit, 200);
}

if (window.BX && window.BX.ajax) {
    var observer = new MutationObserver(function(mutations) {
        var footer = document.getElementById("tbl_equipment_footer");
        if (footer) {
            var container = footer.querySelector(".mass-edit-fields");
            if (!container) {
                initMassEdit();
            }
        }
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
}

setTimeout(function() {
    initMassEdit();
}, 1000);
</script>

<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
?>
