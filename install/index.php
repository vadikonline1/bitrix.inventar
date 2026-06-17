<?php
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\IO\Directory;

class bitrix_inventar extends CModule
{
    var $MODULE_ID = "bitrix.inventar";
    var $MODULE_VERSION;
    var $MODULE_VERSION_DATE;
    var $MODULE_NAME = "IT Inventory";
    var $MODULE_DESCRIPTION = "Complete IT equipment management";
    var $PARTNER_NAME = "vadikonline1";
    var $PARTNER_URI = "https://github.com/vadikonline1/bitrix.inventar/";

    function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . "/version.php";
        $this->MODULE_VERSION = $arModuleVersion["VERSION"];
        $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
    }

	function DoInstall()
	{
		global $APPLICATION;
		
		$this->InstallDB();
		$this->InstallFiles();
		$this->InstallUserGroup();
		$this->CreatePublicFolder();
		
		// Setează opțiunile default pentru notificări
		Option::set($this->MODULE_ID, "notification_new_equipment", "Y");
		Option::set($this->MODULE_ID, "notification_assignment", "Y");
		Option::set($this->MODULE_ID, "responsible_group_id", 0);
		
		// Marchează toate echipamentele existente ca notificate (pentru upgrade-uri)
		if (class_exists('\Bitrix\Inventar\EquipmentTable')) {
			try {
				\Bitrix\Inventar\EquipmentTable::markAllNotificationsAsSent();
			} catch (\Exception $e) {}
		}
		
		ModuleManager::registerModule($this->MODULE_ID);
		
		$APPLICATION->IncludeAdminFile(
			"Installing module " . $this->MODULE_ID,
			__DIR__ . "/step.php"
		);
		
		return true;
	}

    function DoUninstall()
    {
        global $APPLICATION;
        
        $this->UnInstallDB();
        $this->UnInstallFiles();
        
        Option::delete($this->MODULE_ID);
        
        ModuleManager::unRegisterModule($this->MODULE_ID);
        
        $APPLICATION->IncludeAdminFile(
            "Uninstalling module " . $this->MODULE_ID,
            __DIR__ . "/unstep.php"
        );
        
        return true;
    }

    function InstallDB()
    {
        global $DB;
        $sql = file_get_contents(__DIR__ . "/db/install.sql");
        if ($sql) {
            $arSql = explode(";", $sql);
            foreach ($arSql as $query) {
                $query = trim($query);
                if (!empty($query)) $DB->Query($query);
            }
        }
        return true;
    }

    function UnInstallDB()
    {
        global $DB;
        $DB->Query("SET FOREIGN_KEY_CHECKS = 0");
        $tables = [
            'b_bitrix_inventar_allocation', 
            'b_bitrix_inventar_history', 
            'b_bitrix_inventar_service', 
            'b_bitrix_inventar_equipment', 
            'b_bitrix_inventar_types', 
            'b_bitrix_inventar_status', 
            'b_bitrix_inventar_custom_fields'
        ];
        foreach ($tables as $table) {
            $DB->Query("DROP TABLE IF EXISTS {$table}");
        }
        $DB->Query("SET FOREIGN_KEY_CHECKS = 1");
        return true;
    }

    function InstallFiles()
    {
        $pagesDir = __DIR__ . "/../admin/pages";
        if (!is_dir($pagesDir)) {
            mkdir($pagesDir, 0755, true);
        }
        return true;
    }

    function UnInstallFiles()
    {
        $publicDir = $_SERVER["DOCUMENT_ROOT"] . "/inventar";
        if (is_dir($publicDir)) {
            $this->deleteDirectory($publicDir);
        }
        
        // Șterge fișierele stub din /bitrix/admin/
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
        foreach ($stubFiles as $file) {
            $filePath = $adminDir . "/" . $file;
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        
        return true;
    }
    
    function deleteDirectory($dir)
    {
        if (!file_exists($dir)) return true;
        if (!is_dir($dir)) return unlink($dir);
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') continue;
            if (!$this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
        }
        return rmdir($dir);
    }

    function InstallUserGroup()
    {
        global $APPLICATION;
        
        $groupId = null;
        $dbGroup = CGroup::GetList('', '', ['STRING_ID' => 'BITRIX_INVENTAR_GROUP']);
        if ($arGroup = $dbGroup->Fetch()) {
            $groupId = $arGroup['ID'];
        }
        
        if (!$groupId) {
            $cGroup = new CGroup();
            $arFields = [
                'NAME' => 'IT Inventory',
                'STRING_ID' => 'BITRIX_INVENTAR_GROUP',
                'DESCRIPTION' => 'Users with access to IT Inventory module',
                'ACTIVE' => 'Y',
                'C_SORT' => 100
            ];
            $groupId = $cGroup->Add($arFields);
            
            if ($groupId) {
                $APPLICATION->SetGroupRight($this->MODULE_ID, $groupId, 'W');
            }
        }
        
        if ($groupId) {
            Option::set($this->MODULE_ID, "inventar_group_id", $groupId);
        }
        
        return true;
    }
    
    function CreatePublicFolder()
    {
        $publicPath = $_SERVER["DOCUMENT_ROOT"] . "/inventar";
        
        if (!is_dir($publicPath)) {
            mkdir($publicPath, 0755, true);
        }
        
        mkdir($publicPath . "/all", 0755, true);
        mkdir($publicPath . "/add", 0755, true);
        mkdir($publicPath . "/edit", 0755, true);
        
        $this->copyFileIfExists(__DIR__ . "/../public/index.php", $publicPath . "/index.php");
        $this->copyFileIfExists(__DIR__ . "/../public/all/index.php", $publicPath . "/all/index.php");
        $this->copyFileIfExists(__DIR__ . "/../public/add/index.php", $publicPath . "/add/index.php");
        $this->copyFileIfExists(__DIR__ . "/../public/edit/index.php", $publicPath . "/edit/index.php");
        
        return true;
    }
    
    function copyFileIfExists($source, $dest)
    {
        if (file_exists($source)) {
            copy($source, $dest);
            chmod($dest, 0755);
        }
    }
}
?>