<?php
namespace Bitrix\Inventar;

use Bitrix\Main\Entity;

class AllocationTable extends Entity\DataManager
{
    public static function getTableName() 
    { 
        return 'b_bitrix_inventar_allocation'; 
    }
    
    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Entity\IntegerField('EQUIPMENT_ID', ['required' => true]),
            new Entity\IntegerField('USER_ID', ['required' => true]),
            new Entity\DateField('DATA_PREDARE', ['required' => true]),
            new Entity\DateField('DATA_RETURNARE'),
            new Entity\TextField('MOTIV_RETURNARE'),
            new Entity\DatetimeField('CREATED_AT')
        ];
    }
    
    public static function getCurrentUserForEquipment($equipmentId)
    {
        $alloc = static::getList([
            'filter' => ['=EQUIPMENT_ID' => $equipmentId, '=DATA_RETURNARE' => null],
            'select' => ['USER_ID']
        ])->fetch();
        return $alloc ? $alloc['USER_ID'] : null;
    }
    
    public static function onAfterAdd(Entity\Event $event)
    {
        $data = $event->getParameter('fields');
        
        // Send notification to responsible user
        EquipmentTable::sendAllocationNotification($data['EQUIPMENT_ID'], $data['USER_ID']);
        
        // Update equipment status
        EquipmentTable::update($data['EQUIPMENT_ID'], ['STARE_ENUM' => 'in_use']);
    }
}
?>