<?php
namespace Bitrix\Inventar;

use Bitrix\Main\Entity;

class HistoryTable extends Entity\DataManager
{
    public static function getTableName() 
    { 
        return 'b_bitrix_inventar_history'; 
    }
    
    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', ['primary'=>true, 'autocomplete'=>true]),
            new Entity\IntegerField('EQUIPMENT_ID', ['required'=>true]),
            new Entity\IntegerField('USER_ID', ['required'=>true]),
            new Entity\StringField('ACTION', ['required'=>true]),
            new Entity\StringField('FIELD_NAME'),
            new Entity\TextField('OLD_VALUE'),
            new Entity\TextField('NEW_VALUE'),
            new Entity\DatetimeField('CREATED_AT')
        ];
    }
}
?>