<?php
namespace Bitrix\Inventar;

use Bitrix\Main\Entity;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\Date;

class EquipmentTable extends Entity\DataManager
{
    public static function getTableName() 
    { 
        return 'b_bitrix_inventar_equipment'; 
    }
    
    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', [
                'primary' => true, 
                'autocomplete' => true
            ]),
            new Entity\StringField('COD_INVENTAR', [
                'required' => true, 
                'unique' => true
            ]),
            new Entity\StringField('DENUMIRE', [
                'required' => true
            ]),
            new Entity\StringField('TIP_ENUM', [
                'required' => true
            ]),
            new Entity\StringField('PRODUCATOR'),
            new Entity\StringField('MODEL'),
            new Entity\StringField('SERIAL_NR', [
                'unique' => true
            ]), 
            new Entity\DateField('DATA_ACHIZITIE'),
            new Entity\StringField('FURNIZOR'),
            new Entity\FloatField('COST_ACHIZITIE'),
            new Entity\DateField('DATA_EXPIRARE_GARANTIE'),
            new Entity\StringField('STARE_ENUM', [
                'required' => true
            ]),
            new Entity\StringField('LOCATIE'), 
            new Entity\StringField('CONTRACT_SERVICE'),
            new Entity\TextField('OTHERS_INFO'),
            new Entity\StringField('BARCODE_TEXT'),
            new Entity\TextField('QR_CODE_TEXT'), 
            new Entity\StringField('NOTIFICATION_SENT'),
            new Entity\IntegerField('CREATED_BY'), 
            new Entity\DatetimeField('CREATED_AT'),
            new Entity\IntegerField('UPDATED_BY'), 
            new Entity\DatetimeField('UPDATED_AT')
        ];
    }
    
    public static function onBeforeAdd(Entity\Event $event)
    {
        $result = new Entity\EventResult();
        $data = $event->getParameter('fields');
        
        if (empty($data['COD_INVENTAR'])) {
            $prefix = 'INV-' . date('Y') . '-';
            $lastId = static::getList([
                'select' => ['ID'], 
                'order' => ['ID' => 'DESC'], 
                'limit' => 1
            ])->fetch();
            $nextNum = ($lastId ? $lastId['ID'] + 1 : 1);
            $result->modifyFields([
                'COD_INVENTAR' => $prefix . str_pad($nextNum, 5, '0', STR_PAD_LEFT), 
                'NOTIFICATION_SENT' => 'N'
            ]);
        } else {
            $result->modifyFields([
                'NOTIFICATION_SENT' => 'N'
            ]);
        }
        
        global $USER;
        if ($USER && $USER->IsAuthorized()) {
            $result->modifyFields([
                'CREATED_BY' => $USER->GetID(), 
                'UPDATED_BY' => $USER->GetID()
            ]);
        }
        
        return $result;
    }
    
    public static function onAfterAdd(Entity\Event $event)
    {
        $data = $event->getParameter('fields');
        
        if (!empty($data['ID'])) {
            self::sendNewEquipmentNotification($data);
        } else {
            $id = $event->getParameter('id');
            if ($id) {
                $equipment = static::getById($id)->fetch();
                if ($equipment) {
                    self::sendNewEquipmentNotification($equipment);
                }
            }
        }
    }
    
    public static function onBeforeUpdate(Entity\Event $event)
    {
        $result = new Entity\EventResult();
        
        global $USER;
        if ($USER && $USER->IsAuthorized()) {
            $result->modifyFields([
                'UPDATED_BY' => $USER->GetID()
            ]);
        }
        
        return $result;
    }
    
    /**
     * Trimite notificare grupului când se adaugă un echipament nou
     * Dacă notificările sunt dezactivate, marchează automat ca trimis (Y)
     */
    public static function sendNewEquipmentNotification($data)
    {
        // Verifică dacă notificările pentru echipamente noi sunt activate
        $notificationsEnabled = Option::get('bitrix.inventar', 'notification_new_equipment', 'Y');
        
        // Dacă notificările sunt dezactivate, marchează ca trimis și oprește
        if ($notificationsEnabled != 'Y') {
            // Marchează notificarea ca trimisă fără a trimite nimic
            if (isset($data['ID']) && (!isset($data['NOTIFICATION_SENT']) || $data['NOTIFICATION_SENT'] != 'Y')) {
                try {
                    static::update($data['ID'], ['NOTIFICATION_SENT' => 'Y']);
                } catch (\Exception $e) {}
            }
            return;
        }
        
        // Verifică dacă notificarea a fost deja trimisă
        if (isset($data['NOTIFICATION_SENT']) && $data['NOTIFICATION_SENT'] == 'Y') {
            return;
        }
        
        if (empty($data['ID']) || empty($data['COD_INVENTAR']) || empty($data['DENUMIRE'])) {
            return;
        }
        
        if (!Loader::includeModule('im')) {
            // Dacă modulul IM nu este disponibil, marchează ca trimis pentru a evita încercări repetate
            try {
                static::update($data['ID'], ['NOTIFICATION_SENT' => 'Y']);
            } catch (\Exception $e) {}
            return;
        }
        
        $equipmentId = $data['ID'];
        $codInventar = $data['COD_INVENTAR'];
        $denumire = $data['DENUMIRE'];
        
        $editLink = "https://" . $_SERVER['HTTP_HOST'] . "/inventar/edit/?id={$equipmentId}&back=all";
        $detailsLink = "https://" . $_SERVER['HTTP_HOST'] . "/inventar/?id={$equipmentId}";
        
        $message = "🆕 New equipment added to inventory\n\n";
        $message .= "📦 Inventory code: {$codInventar}\n";
        $message .= "🏷️ Name: {$denumire}\n\n";
        $message .= "🔗 View details: {$detailsLink}\n";
        $message .= "✏️ Edit: {$editLink}";
        
        // Colectează utilizatorii din grupul Inventar
        $userIds = [];
        $groupId = Option::get('bitrix.inventar', 'inventar_group_id');
        
        if ($groupId) {
            $users = \Bitrix\Main\UserGroupTable::getList([
                'filter' => ['=GROUP_ID' => $groupId],
                'select' => ['USER_ID']
            ])->fetchAll();
            foreach ($users as $user) {
                $userIds[$user['USER_ID']] = $user['USER_ID'];
            }
        }
        
        // Adaugă administratorii
        $adminUsers = \Bitrix\Main\UserGroupTable::getList([
            'filter' => ['=GROUP_ID' => 1],
            'select' => ['USER_ID']
        ])->fetchAll();
        foreach ($adminUsers as $admin) {
            $userIds[$admin['USER_ID']] = $admin['USER_ID'];
        }
        
        // Trimite notificare
        foreach ($userIds as $userId) {
            \CIMNotify::Add([
                'TO_USER_ID' => $userId,
                'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
                'NOTIFY_MESSAGE' => $message,
                'NOTIFY_TAG' => 'bitrix_inventar_new_' . $equipmentId,
                'NOTIFY_MODULE' => 'bitrix.inventar'
            ]);
        }
        
        // Marchează notificarea ca trimisă
        try {
            static::update($data['ID'], ['NOTIFICATION_SENT' => 'Y']);
        } catch (\Exception $e) {}
    }
    
    /**
     * Trimite notificare persoanei responsabile că i s-a alocat un echipament
     * Dacă notificările sunt dezactivate, nu trimite nimic (nu avem ce marca pentru alocări)
     */
    public static function sendAllocationNotification($equipmentId, $userId)
    {
        // Verifică dacă notificările pentru alocare sunt activate
        $notificationsEnabled = Option::get('bitrix.inventar', 'notification_assignment', 'Y');
        if ($notificationsEnabled != 'Y') {
            return false;
        }
        
        if (!Loader::includeModule('im')) {
            return false;
        }
        
        $equipment = static::getById($equipmentId)->fetch();
        if (!$equipment) {
            return false;
        }
        
        $user = \Bitrix\Main\UserTable::getById($userId)->fetch();
        if (!$user) {
            return false;
        }
        
        $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
        if (empty($userName)) $userName = $user['LOGIN'];
        
        $equipmentLink = "https://" . $_SERVER['HTTP_HOST'] . "/inventar/?id={$equipmentId}";
        
        $message = "🔔 New equipment assigned to you!\n\n";
        $message .= "👤 User: {$userName}\n";
        $message .= "📦 Equipment: {$equipment['DENUMIRE']}\n";
        $message .= "🏷️ Inventory code: {$equipment['COD_INVENTAR']}\n\n";
        $message .= "🔗 View details: {$equipmentLink}";
        
        \CIMNotify::Add([
            'TO_USER_ID' => $userId,
            'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
            'NOTIFY_MESSAGE' => $message,
            'NOTIFY_TAG' => 'bitrix_inventar_allocation_' . $equipmentId,
            'NOTIFY_MODULE' => 'bitrix.inventar'
        ]);
        
        return true;
    }
    
    /**
     * Metodă pentru a marca toate echipamentele existente ca notificate
     * Utile când se dezactivează notificările pentru a preveni notificări retroactive
     */
    public static function markAllNotificationsAsSent()
    {
        $allEquipment = static::getList([
            'filter' => ['!=NOTIFICATION_SENT' => 'Y'],
            'select' => ['ID']
        ])->fetchAll();
        
        $count = 0;
        foreach ($allEquipment as $eq) {
            try {
                static::update($eq['ID'], ['NOTIFICATION_SENT' => 'Y']);
                $count++;
            } catch (\Exception $e) {}
        }
        
        return $count;
    }
}
?>