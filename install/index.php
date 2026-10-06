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
		$this->InstallAgents();
		
		// Setează opțiunile default pentru notificări (default: No)
		Option::set($this->MODULE_ID, "notification_new_equipment", "N");
		Option::set($this->MODULE_ID, "notification_assignment", "N");
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
        // Creeaza stub-urile din /bitrix/admin/ (la fel ca step.php, pentru instalari CLI / reinstalari)
        $adminDir = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/admin";
        if (!class_exists('Bitrix\Inventar\Schema')) {
            @require_once __DIR__ . "/../lib/Schema.php";
        }
        $pages = class_exists('Bitrix\Inventar\Schema')
            ? \Bitrix\Inventar\Schema::adminPages()
            : [
                'dashboard' => 'bitrix_inventar_dashboard.php',
                'equipment_list' => 'bitrix_inventar_equipment_list.php',
                'equipment_edit' => 'bitrix_inventar_equipment_edit.php',
                'service' => 'bitrix_inventar_service.php',
                'import_export' => 'bitrix_inventar_import_export.php',
                'types_status' => 'bitrix_inventar_types_status.php',
                'types_info' => 'bitrix_inventar_types_info.php'
            ];
        if (is_dir($adminDir)) {
            foreach ($pages as $pageName => $fileName) {
                $stubFile = $adminDir . "/" . $fileName;
                // Suprascriem mereu: template-ul contine bypass-ul de login pentru API
                // (request-urile API folosesc bootstrap public, nu cer sesiune admin).
                $stubContent = class_exists('Bitrix\Inventar\Schema')
                    ? \Bitrix\Inventar\Schema::buildAdminStub($pageName)
                    : self::buildAdminStub($pageName);
                @file_put_contents($stubFile, $stubContent);
                @chmod($stubFile, 0644);
            }
        }
        return true;
    }

    /**
     * Fallback pentru buildAdminStub (folosit doar daca lib/Schema.php lipseste).
     * Template identic cu Schema::buildAdminStub.
     */
    public static function buildAdminStub($pageName)
    {
        return '<?php
// Auto-generated stub - Bitrix Inventar module (do not edit manually).
// Module location: /local/modules (custom) or /bitrix/modules (legacy).
$__inventar_root = is_dir($_SERVER["DOCUMENT_ROOT"]."/local/modules/bitrix.inventar")
    ? $_SERVER["DOCUMENT_ROOT"]."/local/modules/bitrix.inventar"
    : $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/bitrix.inventar";
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");
require_once($__inventar_root."/admin/pages/' . $pageName . '.php");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
?>';
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

    // Agentul de sync extern se creeaza din UI (Types & Statuses -> External API)
    // cand se activeaza optiunea "Auto sync (cron)". La instalare nu programam nimic.
    function InstallAgents()
    {
        return true;
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
		
		// Creează directoarele
		if (!is_dir($publicPath)) {
			mkdir($publicPath, 0755, true);
		}
		@mkdir($publicPath . "/all", 0755, true);
		@mkdir($publicPath . "/add", 0755, true);
		@mkdir($publicPath . "/edit", 0755, true);
		
		// Definim paginile care trebuie create ca stub
		$pages = [
			'index' => 'index.php',
			'all' => 'all/index.php',
			'add' => 'add/index.php',
			'edit' => 'edit/index.php'
		];
		
		$createdCount = 0;
		$failedFiles = [];
		
		foreach ($pages as $pageName => $filePath) {
			// Conținutul stub-ului — detecteaza /local vs /bitrix
			$stubContent = '<?php
	// Stub file for public page - ' . $pageName . '
	// This file includes the actual implementation from the module
	$__inventar_root = is_dir($_SERVER["DOCUMENT_ROOT"]."/local/modules/bitrix.inventar")
		? $_SERVER["DOCUMENT_ROOT"]."/local/modules/bitrix.inventar"
		: $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/bitrix.inventar";
	require_once($__inventar_root."/public/' . $filePath . '");
	?>';
			
			$stubFile = $publicPath . '/' . $filePath;
			
			// Asigură-te că directorul există pentru calea fișierului
			$dirPath = dirname($stubFile);
			if (!is_dir($dirPath)) {
				mkdir($dirPath, 0755, true);
			}
			
			// Scrie fișierul stub
			if (file_put_contents($stubFile, $stubContent)) {
				chmod($stubFile, 0644);
				$createdCount++;
			} else {
				$failedFiles[] = $filePath;
			}
		}
		
		// Log pentru debugging (opțional)
		if (!empty($failedFiles)) {
			// Poți adăuga un mesaj de eroare dacă dorești
			AddMessage2Log("Failed to create public stub files: " . implode(", ", $failedFiles), "bitrix.inventar");
		}
		
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