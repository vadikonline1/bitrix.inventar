<?php
namespace Bitrix\Inventar;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

class Notification
{
    public static function checkWarrantyExpiration()
    {
        if (!Loader::includeModule('im')) {
            return;
        }
        
        $expiring = EquipmentTable::getList([
            'filter' => ['<=DATA_EXPIRARE_GARANTIE' => date('Y-m-d', strtotime('+30 days'))]
        ])->fetchAll();
        
        $groupId = Option::get('bitrix.inventar', 'inventar_group_id');
        if (!$groupId) return;
        
        $users = \Bitrix\Main\UserGroupTable::getList([
            'filter' => ['=GROUP_ID' => $groupId],
            'select' => ['USER_ID']
        ])->fetchAll();
        
        foreach ($expiring as $item) {
            foreach ($users as $u) {
                \CIMNotify::Add([
                    'TO_USER_ID' => $u['USER_ID'],
                    'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
                    'NOTIFY_MESSAGE' => "Warranty for {$item['DENUMIRE']} expires on {$item['DATA_EXPIRARE_GARANTIE']}",
                    'NOTIFY_MODULE' => 'bitrix.inventar'
                ]);
            }
        }
    }
    
    public static function onUserLogin($params) 
    { 
        return true; 
    }
}
?>