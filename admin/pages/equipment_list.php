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

// ========== FUNCȚIE PENTRU REDIRECȚIONARE SIMPLĂ ==========
function simpleRedirect() {
    global $APPLICATION;
    
    // Preia toți parametrii GET
    $params = $_GET;
    
    // Elimină parametrii care nu trebuie păstrați
    unset($params['mode']);
    unset($params['_']);
    unset($params['apply']);
    unset($params['action']);
    unset($params['mass_edit_value']);
    unset($params['delete_id']);
    
    // Elimină parametrii goi
    $params = array_filter($params, function($value) {
        return $value !== '' && $value !== null;
    });
    
    // Asigură-te că page există
    if (!isset($params['page']) || $params['page'] < 1) {
        $params['page'] = 1;
    }
    
    $url = $APPLICATION->GetCurPage();
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    // Redirecționează cu JavaScript pentru a forța refresh complet
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
    simpleRedirect();
}

// Process individual delete
if (isset($_GET['delete_id']) && intval($_GET['delete_id']) > 0) {
    $id = intval($_GET['delete_id']);
    if ($APPLICATION->GetGroupRight("bitrix.inventar") >= "W") {
        EquipmentTable::delete($id);
        CAdminMessage::ShowMessage("Equipment deleted successfully!", "OK");
    }
    simpleRedirect();
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
    simpleRedirect();
}

// Get types and statuses from database
$tipText = TypesTable::getAllTypes();
$stareInfo = StatusTable::getAllStatus();

// ========== PRELUARE PARAMETRI FILTRU ==========
$search = trim($_GET['search'] ?? '');
$filterType = trim($_GET['filter_type'] ?? '');
$filterStatus = trim($_GET['filter_status'] ?? '');
$filterLocation = trim($_GET['filter_location'] ?? '');
$filterDateFrom = trim($_GET['filter_date_from'] ?? '');
$filterDateTo = trim($_GET['filter_date_to'] ?? '');
$filterUser = trim($_GET['filter_user'] ?? '');
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;

// Reset filter
if (isset($_GET['reset_filter'])) {
    LocalRedirect('/bitrix/admin/bitrix_inventar_equipment_list.php');
}

// ========== CONSTRUIRE FILTRU ==========
$connection = Application::getConnection();
$sqlHelper = $connection->getSqlHelper();

$whereConditions = [];

// Search - caută în toate câmpurile relevante
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

if (!empty($filterDateFrom)) {
    $whereConditions[] = "e.DATA_ACHIZITIE >= '" . $sqlHelper->forSql($filterDateFrom) . "'";
}

if (!empty($filterDateTo)) {
    $whereConditions[] = "e.DATA_ACHIZITIE <= '" . $sqlHelper->forSql($filterDateTo) . "'";
}

if (!empty($filterUser)) {
    $userId = intval($filterUser);
    $whereConditions[] = "EXISTS (SELECT 1 FROM b_bitrix_inventar_allocation a WHERE a.EQUIPMENT_ID = e.ID AND a.USER_ID = {$userId} AND a.DATA_RETURNARE IS NULL)";
}

// ========== PAGINARE ==========
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
        'filter_date_from' => $_GET['filter_date_from'] ?? '',
        'filter_date_to' => $_GET['filter_date_to'] ?? '',
        'filter_user' => $_GET['filter_user'] ?? '',
        'page' => $_GET['page'] ?? 1
    ]);
    
    // Adaugă mode dacă există
    if (isset($_GET['mode']) && $_GET['mode'] == 'frame') {
        $params['mode'] = 'frame';
    }
    
    if (empty($params)) {
        return '';
    }
    
    return '&back=' . urlencode(http_build_query($params));
}

$backParams = buildBackUrl();

// ========== AFIȘARE FILTRU ==========
?>
<style>
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
.filter-collapsed .filter-row {
    display: none;
}
@media (max-width: 768px) {
    .filter-group {
        min-width: 100%;
    }
    .filter-actions {
        width: 100%;
        justify-content: flex-end;
    }
}
</style>

<div class="filter-box" id="filterBox">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <span class="filter-toggle" onclick="toggleFilter()">🔽 <span id="filterToggleText">Show filters</span></span>
            <?php if (!empty($search) || !empty($filterType) || !empty($filterStatus) || !empty($filterLocation) || !empty($filterDateFrom) || !empty($filterDateTo) || !empty($filterUser)): ?>
            <span style="background: #ff9800; color: white; padding: 2px 10px; border-radius: 12px; font-size: 12px;">Active filters</span>
            <?php endif; ?>
        </div>
        <div style="font-size: 12px; color: #666;">
            <strong>Total:</strong> <?= $total ?> equipment
        </div>
    </div>
    
    <form method="GET" id="filterForm">
        <div class="filter-row" id="filterRow">
            <div class="filter-group" style="flex: 3;">
                <label>🔍 Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search in: Code, Name, Manufacturer, Model, Serial, Location, Supplier...">
            </div>
            
            <div class="filter-group">
                <label>📁 Type</label>
                <select name="filter_type">
                    <option value="">All types</option>
                    <?php foreach ($tipText as $val => $name): ?>
                    <option value="<?= $val ?>" <?= ($filterType == $val) ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>⚙️ Status</label>
                <select name="filter_status">
                    <option value="">All statuses</option>
                    <?php foreach ($stareInfo as $val => $info): ?>
                    <option value="<?= $val ?>" <?= ($filterStatus == $val) ? 'selected' : '' ?>><?= htmlspecialchars($info['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="filter-row" id="filterRow2" style="margin-top: 8px;">
            <div class="filter-group">
                <label>📍 Location</label>
                <select name="filter_location">
                    <option value="">All locations</option>
                    <?php foreach ($allLocations as $loc): ?>
                    <option value="<?= htmlspecialchars($loc) ?>" <?= ($filterLocation == $loc) ? 'selected' : '' ?>><?= htmlspecialchars($loc) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>👤 Responsible user</label>
                <select name="filter_user">
                    <option value="">All users</option>
                    <?php foreach ($arUsers as $uid => $uname): ?>
                    <option value="<?= $uid ?>" <?= ($filterUser == $uid) ? 'selected' : '' ?>><?= htmlspecialchars($uname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>📅 Purchase date from</label>
                <input type="date" name="filter_date_from" value="<?= htmlspecialchars($filterDateFrom) ?>">
            </div>
            
            <div class="filter-group">
                <label>📅 Purchase date to</label>
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

<script>
function toggleFilter() {
    var rows = document.querySelectorAll('#filterRow, #filterRow2');
    var toggleText = document.getElementById('filterToggleText');
    var isHidden = rows[0].style.display === 'none';
    
    rows.forEach(function(row) {
        row.style.display = isHidden ? 'flex' : 'none';
    });
    
    toggleText.textContent = isHidden ? 'Hide filters' : 'Show filters';
}

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

<?php
// Display pagination info
echo '<div style="margin-bottom: 15px; padding: 10px; background: #f5f5f5; border-radius: 5px;">';
echo '<strong>Total equipment:</strong> ' . $total . ' | ';
echo '<strong>Page:</strong> ' . $page . ' of ' . $totalPages;
if (!empty($search) || !empty($filterType) || !empty($filterStatus) || !empty($filterLocation) || !empty($filterDateFrom) || !empty($filterDateTo) || !empty($filterUser)) {
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
    
	$row->AddViewField(
		"DATA_ACHIZITIE",
		!empty($arRes['DATA_ACHIZITIE']) ? htmlspecialchars($arRes['DATA_ACHIZITIE']) : '-'
	);

	$row->AddViewField(
		"DATA_EXPIRARE_GARANTIE",
		!empty($arRes['DATA_EXPIRARE_GARANTIE']) ? htmlspecialchars($arRes['DATA_EXPIRARE_GARANTIE']) : '-'
	);
    
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

// Adaugă acțiunile de editare în masă
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
        'filter_date_from' => $_GET['filter_date_from'] ?? '',
        'filter_date_to' => $_GET['filter_date_to'] ?? '',
        'filter_user' => $_GET['filter_user'] ?? ''
    ]);
    
    // Adaugă mode dacă există
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
    // Găsim dropdown-ul de acțiuni - name="action"
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
                // Verificăm dacă containerul există deja
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
                
                // Eliminăm event listener-urile vechi
                actionSelect.removeEventListener('change', handleActionChange);
                // Adăugăm event listener nou
                actionSelect.addEventListener('change', handleActionChange);
                
                // Salvăm referințe pentru handler
                window._massEditValueSelect = valueSelect;
                window._massEditContainer = container;
                
                // Verificăm dacă acțiunea curentă este edit_type sau edit_status
                var currentAction = actionSelect.value;
                if (currentAction === "edit_type" || currentAction === "edit_status") {
                    // Trigger pentru a afișa dropdown-ul
                    handleActionChange();
                }
            }
        }
    }
}

// Handler pentru schimbarea acțiunii
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

// Funcție de inițializare completă
function fullInit() {
    // Inițializare dropdown-uri
    initMassEdit();
    
    // Auto-submit filter on Enter key
    document.querySelectorAll("#filterForm input, #filterForm select").forEach(function(el) {
        el.addEventListener("keypress", function(e) {
            if (e.key === "Enter") {
                e.preventDefault();
                document.getElementById("filterForm").submit();
            }
        });
    });
}

// Inițializare la încărcarea paginii
if (document.readyState === 'loading') {
    document.addEventListener("DOMContentLoaded", function() {
        setTimeout(fullInit, 200);
    });
} else {
    // Pagina este deja încărcată
    setTimeout(fullInit, 200);
}

// Pentru cazul în care pagina este reîncărcată parțial prin AJAX (iframe mode)
// Ascultăm evenimentele de reîncărcare
if (window.BX && window.BX.ajax) {
    // Folosim un MutationObserver pentru a detecta schimbările în DOM
    var observer = new MutationObserver(function(mutations) {
        // Verificăm dacă footer-ul a fost modificat
        var footer = document.getElementById("tbl_equipment_footer");
        if (footer) {
            // Verificăm dacă dropdown-urile noastre există
            var container = footer.querySelector(".mass-edit-fields");
            if (!container) {
                // Dacă nu există, le recreăm
                initMassEdit();
            }
        }
    });
    
    // Observăm întregul document pentru schimbări
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
}

// Reinițializare după orice refresh (pentru cazul în care DOM-ul se schimbă)
setTimeout(function() {
    initMassEdit();
}, 1000);

// Pentru cazul în care se folosește BX.ajax.submitComponentForm
// Suprascriem funcția de callback pentru a reinițializa după submit
if (window.BX && window.BX.ajax) {
    var originalSubmit = BX.ajax.submitComponentForm;
    if (originalSubmit) {
        BX.ajax.submitComponentForm = function(form, container, bReturn) {
            var result = originalSubmit.call(this, form, container, bReturn);
            // După submit, reinițializăm
            setTimeout(function() {
                initMassEdit();
            }, 500);
            return result;
        };
    }
}
</script>

<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
?>
