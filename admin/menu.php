<?php
if ($APPLICATION->GetGroupRight("bitrix.inventar") < "R") {
    return false;
}

$aMenu = [
    "parent_menu" => "global_menu_services",
    "sort" => 100,
    "text" => "IT Inventory",
    "title" => "IT Inventory Management",
    "icon" => "form_menu_icon",
    "page_icon" => "form_page_icon",
    "items_id" => "menu_inventar",
    "items" => [
        [
            "text" => "Dashboard",
            "url" => "/bitrix/admin/bitrix_inventar_dashboard.php",
            "more_url" => [],
            "title" => "Reports Dashboard"
        ],
        [
            "text" => "Equipment",
            "url" => "/bitrix/admin/bitrix_inventar_equipment_list.php",
            "more_url" => ["/bitrix/admin/bitrix_inventar_equipment_edit.php"],
            "title" => "Equipment List"
        ],
        [
            "text" => "Allocations",
            "url" => "/bitrix/admin/bitrix_inventar_allocations.php",
            "title" => "User Allocations"
        ],
        [
            "text" => "Service",
            "url" => "/bitrix/admin/bitrix_inventar_service.php",
            "title" => "Service Management"
        ],
        [
            "text" => "Types & Statuses",
            "url" => "/bitrix/admin/bitrix_inventar_types_status.php",
            "title" => "Manage Types and Statuses"
        ],
        [
            "text" => "Additional Fields",
            "url" => "/bitrix/admin/bitrix_inventar_types_info.php",
            "title" => "Manage Additional Fields"
        ],
        [
            "text" => "Import/Export",
            "url" => "/bitrix/admin/bitrix_inventar_import_export.php",
            "title" => "Import and Export Data"
        ]
    ]
];

return $aMenu;
?>