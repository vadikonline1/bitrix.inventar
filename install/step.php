<?php
if (!check_bitrix_sessid()) return;

global $APPLICATION;

// Creează fișierele stub în /bitrix/admin/
$adminDir = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/admin";
$modulePagesDir = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/bitrix.inventar/admin/pages";

if (is_dir($modulePagesDir)) {
    $pages = [
        'dashboard' => 'bitrix_inventar_dashboard.php',
        'equipment_list' => 'bitrix_inventar_equipment_list.php',
        'equipment_edit' => 'bitrix_inventar_equipment_edit.php',
        'allocations' => 'bitrix_inventar_allocations.php',
        'service' => 'bitrix_inventar_service.php',
        'import_export' => 'bitrix_inventar_import_export.php',
        'types_status' => 'bitrix_inventar_types_status.php',
        'types_info' => 'bitrix_inventar_types_info.php'
    ];
    
    $createdCount = 0;
    
    foreach ($pages as $pageName => $fileName) {
        $stubContent = '<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/bitrix.inventar/admin/pages/' . $pageName . '.php");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
?>';
        
        $stubFile = $adminDir . "/" . $fileName;
        
        if (file_put_contents($stubFile, $stubContent)) {
            chmod($stubFile, 0644);
            $createdCount++;
        }
    }
    
    $stubMessage = "$createdCount stub files created in /bitrix/admin/";
} else {
    $stubMessage = "Warning: Pages directory not found!";
}

// Curăță cache-ul Bitrix
try {
    $cachePath = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/cache";
    if (is_dir($cachePath)) {
        $files = glob($cachePath . "/*");
        foreach ($files as $file) {
            if (is_file($file)) @unlink($file);
        }
    }
    $cacheMessage = "Cache cleared successfully.";
} catch (Exception $e) {
    $cacheMessage = "Cache could not be cleared automatically.";
}

$message = "
Module <b>IT Inventory</b> has been successfully installed!<br><br>
<b>✓ Actions completed:</b><br>
- Database tables have been created<br>
- User group <b>IT Inventory</b> has been created<br>
- Public directory <b>/inventar/</b> has been created<br>
- $stubMessage<br>
- $cacheMessage<br><br>
<b>🔗 Quick access:</b><br>
- <a href='/bitrix/admin/bitrix_inventar_dashboard.php' target='_blank'>Admin Dashboard</a><br>
- <a href='/bitrix/admin/bitrix_inventar_equipment_list.php' target='_blank'>Equipment List (Admin)</a><br>
- <a href='/inventar/' target='_blank'>Public page (Users)</a><br><br>
<b>📝 For users:</b><br>
Add users to the <b>IT Inventory</b> group to grant them access to the public page.<br>
User access: <a href='/inventar/'>/inventar/</a>
";

echo CAdminMessage::ShowNote($message);
?>

<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" name="" value="Back to modules list" class="adm-btn">
</form>

<script>
setTimeout(function() {
    window.location.href = '/bitrix/admin/bitrix_inventar_dashboard.php';
}, 3000);
</script>