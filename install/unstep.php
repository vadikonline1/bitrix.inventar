<?php
if (!check_bitrix_sessid()) return;

global $APPLICATION;

$adminDir = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/admin";
$stubFiles = [
    'bitrix_inventar_dashboard.php',
    'bitrix_inventar_equipment_list.php',
    'bitrix_inventar_equipment_edit.php',
    'bitrix_inventar_allocations.php',
    'bitrix_inventar_service.php',
    'bitrix_inventar_import_export.php',
    'bitrix_inventar_types_status.php',
    'bitrix_inventar_types_info.php'
];

$deletedCount = 0;
foreach ($stubFiles as $file) {
    $filePath = $adminDir . "/" . $file;
    if (file_exists($filePath) && unlink($filePath)) {
        $deletedCount++;
    }
}

$publicDir = $_SERVER["DOCUMENT_ROOT"] . "/inventar";
$publicDeleted = false;
if (is_dir($publicDir)) {
    $files = glob($publicDir . "/*");
    foreach ($files as $file) {
        if (is_file($file)) @unlink($file);
        if (is_dir($file)) {
            $subFiles = glob($file . "/*");
            foreach ($subFiles as $subFile) {
                @unlink($subFile);
            }
            @rmdir($file);
        }
    }
    if (rmdir($publicDir)) {
        $publicDeleted = true;
    }
}

$message = "
Module <b>IT Inventory</b> has been successfully uninstalled!<br><br>
<b>✓ Removed:</b><br>
- $deletedCount stub files from /bitrix/admin/<br>
- " . ($publicDeleted ? "Directory /inventar/" : "Directory /inventar/ (could not be completely deleted)") . "<br>
- Database tables<br><br>
<b>ℹ️ Note:</b><br>
The <b>IT Inventory</b> user group remains in the system for future use.<br>
You can manually delete the group from: <i>Administration > Users > User Groups</i>
";

echo CAdminMessage::ShowNote($message);
?>

<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" name="" value="Back to modules list" class="adm-btn">
</form>