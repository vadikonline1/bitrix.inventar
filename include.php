<?php
use Bitrix\Main\Loader;
use Bitrix\Main\EventManager;

$module_id = "bitrix.inventar";

// Variabile globale pentru modul
global $MODULE_ID, $MODULE_VERSION, $MODULE_VERSION_DATE, $MODULE_NAME, $MODULE_DESCRIPTION, $PARTNER_NAME, $PARTNER_URI;

$MODULE_ID = "bitrix.inventar";
$MODULE_VERSION = "1.0.0";
$MODULE_VERSION_DATE = "2026-01-15";
$MODULE_NAME = "IT Inventory";
$MODULE_DESCRIPTION = "Complete IT equipment management";
$PARTNER_NAME = "vadikonline1";
$PARTNER_URI = "https://github.com/vadikonline1/bitrix.inventar/";

Loader::registerAutoLoadClasses($module_id, [
    'Bitrix\Inventar\EquipmentTable' => 'lib/EquipmentTable.php',
    'Bitrix\Inventar\AllocationTable' => 'lib/AllocationTable.php',
    'Bitrix\Inventar\HistoryTable' => 'lib/HistoryTable.php',
    'Bitrix\Inventar\ServiceTable' => 'lib/ServiceTable.php',
    'Bitrix\Inventar\Notification' => 'lib/Notification.php',
    'Bitrix\Inventar\TypesTable' => 'lib/TypesTable.php',
    'Bitrix\Inventar\StatusTable' => 'lib/StatusTable.php',
    'Bitrix\Inventar\CustomFieldsTable' => 'lib/CustomFieldsTable.php',
]);

$eventManager = EventManager::getInstance();
$eventManager->addEventHandler('main', 'OnAfterUserLogin', [
    'Bitrix\Inventar\Notification', 'onUserLogin'
]);
?>