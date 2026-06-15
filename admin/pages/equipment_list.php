<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Loader;
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

// Process individual delete
if (isset($_GET['delete_id']) && intval($_GET['delete_id']) > 0) {
    $id = intval($_GET['delete_id']);
    if ($APPLICATION->GetGroupRight("bitrix.inventar") >= "W") {
        EquipmentTable::delete($id);
        CAdminMessage::ShowMessage("Equipment deleted successfully!", "OK");
    }
    LocalRedirect($APPLICATION->GetCurPage());
}

// Process group delete
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_button']) && $_POST['action_button'] == 'delete') {
    if ($APPLICATION->GetGroupRight("bitrix.inventar") >= "W") {
        if (isset($_POST['ID']) && is_array($_POST['ID'])) {
            foreach ($_POST['ID'] as $id) {
                EquipmentTable::delete($id);
            }
            CAdminMessage::ShowMessage("Equipment deleted successfully!", "OK");
        }
    }
    LocalRedirect($APPLICATION->GetCurPage());
}

// Get types and statuses from database
$tipText = TypesTable::getAllTypes();
$stareInfo = StatusTable::getAllStatus();

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$total = EquipmentTable::getCount();
$totalPages = ceil($total / $perPage);

$list = EquipmentTable::getList([
    'order' => ['ID' => 'DESC'],
    'limit' => $perPage,
    'offset' => $offset
])->fetchAll();

// Define table columns
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

// Display pagination info
echo '<div style="margin-bottom: 15px; padding: 10px; background: #f5f5f5; border-radius: 5px;">';
echo '<strong>Total equipment:</strong> ' . $total . ' | ';
echo '<strong>Page:</strong> ' . $page . ' of ' . $totalPages;
echo '</div>';

// Add rows to table
foreach ($list as $arRes) {
    $f_ID = $arRes['ID'];
    $row = $lAdmin->AddRow($f_ID, $arRes);
    
    // Get current user
    $userId = AllocationTable::getCurrentUserForEquipment($f_ID);
    $userName = '';
    if ($userId) {
        $user = \Bitrix\Main\UserTable::getById($userId)->fetch();
        $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
        if (empty($userName)) $userName = $user['LOGIN'];
    }
    $row->AddViewField("UTILIZATOR", $userName ?: "Not assigned");
    
    // Format dates
    $dataAchizitie = $arRes['DATA_ACHIZITIE'] ? date('d.m.Y', strtotime($arRes['DATA_ACHIZITIE'])) : '-';
    $row->AddViewField("DATA_ACHIZITIE", $dataAchizitie);
    
    $garantie = $arRes['DATA_EXPIRARE_GARANTIE'] ? date('d.m.Y', strtotime($arRes['DATA_EXPIRARE_GARANTIE'])) : '-';
    $row->AddViewField("DATA_EXPIRARE_GARANTIE", $garantie);
    
    // Status styling
    $stareColor = isset($stareInfo[$arRes['STARE_ENUM']]['color']) ? $stareInfo[$arRes['STARE_ENUM']]['color'] : '#666';
    $stareName = isset($stareInfo[$arRes['STARE_ENUM']]['name']) ? $stareInfo[$arRes['STARE_ENUM']]['name'] : $arRes['STARE_ENUM'];
    $row->AddViewField("STARE_ENUM", '<span style="color:' . $stareColor . '; font-weight:bold;">' . htmlspecialchars($stareName) . '</span>');
    
    // Type display
    $tipName = isset($tipText[$arRes['TIP_ENUM']]) ? $tipText[$arRes['TIP_ENUM']] : $arRes['TIP_ENUM'];
    $row->AddViewField("TIP_ENUM", htmlspecialchars($tipName));
    
    // Manufacturer and model
    $row->AddViewField("PRODUCATOR", htmlspecialchars(!empty($arRes['PRODUCATOR']) ? $arRes['PRODUCATOR'] : '-'));
    $row->AddViewField("MODEL", htmlspecialchars(!empty($arRes['MODEL']) ? $arRes['MODEL'] : '-'));
    $row->AddViewField("SERIAL_NR", htmlspecialchars(!empty($arRes['SERIAL_NR']) ? $arRes['SERIAL_NR'] : '-'));
    $row->AddViewField("LOCATIE", htmlspecialchars(!empty($arRes['LOCATIE']) ? $arRes['LOCATIE'] : '-'));
    
    // Actions
    $row->AddActions([
        ["ICON" => "edit", "TEXT" => "Edit", "ACTION" => $lAdmin->ActionRedirect("/bitrix/admin/bitrix_inventar_equipment_edit.php?ID=" . $f_ID), "DEFAULT" => true],
        ["ICON" => "delete", "TEXT" => "Delete", "ACTION" => "if(confirm('Are you sure you want to delete this equipment?')) window.location.href='?delete_id=" . $f_ID . "';"]
    ]);
}

// Add footer and group actions
$lAdmin->AddFooter([
    ["title" => "Select all", "value" => "check_all"],
    ["title" => "Actions", "value" => "delete"]
]);

$lAdmin->AddGroupActionTable([
    "delete" => "Delete selected"
]);

$lAdmin->CheckListMode();
$lAdmin->DisplayList();

// Manual pagination
if ($totalPages > 1) {
    echo '<div style="margin-top: 20px; text-align: center;">';
    echo '<div class="pagination" style="display: inline-flex; gap: 10px; flex-wrap: wrap;">';
    
    if ($page > 1) {
        echo '<a href="?page=1" class="adm-btn" style="background: #2c7ed6;">« First</a>';
        echo '<a href="?page=' . ($page - 1) . '" class="adm-btn" style="background: #2c7ed6;">← Previous</a>';
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
            echo '<a href="?page=' . $i . '" class="adm-btn" style="background: #f0f0f0; color: #333;">' . $i . '</a>';
        }
    }
    
    if ($endPage < $totalPages) {
        echo '<span style="padding: 5px 10px;">...</span>';
    }
    
    if ($page < $totalPages) {
        echo '<a href="?page=' . ($page + 1) . '" class="adm-btn" style="background: #2c7ed6;">Next →</a>';
        echo '<a href="?page=' . $totalPages . '" class="adm-btn" style="background: #2c7ed6;">Last »</a>';
    }
    
    echo '</div>';
    echo '</div>';
}

// Additional buttons
echo '<br><br>';
echo '<a href="/bitrix/admin/bitrix_inventar_equipment_edit.php" class="adm-btn">+ Add new equipment</a>';
echo '&nbsp;&nbsp;<a href="/bitrix/admin/bitrix_inventar_types_status.php" class="adm-btn">⚙️ Manage Types & Statuses</a>';
echo '&nbsp;&nbsp;<a href="/bitrix/admin/bitrix_inventar_allocations.php" class="adm-btn">📋 Allocations</a>';
echo '&nbsp;&nbsp;<a href="/bitrix/admin/bitrix_inventar_service.php" class="adm-btn">🔧 Service</a>';

echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    var form = document.getElementById("form_tbl_equipment");
    if (form) {
        form.action = window.location.href;
    }
});
</script>';

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
?>