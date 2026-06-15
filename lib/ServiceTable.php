<?php
namespace Bitrix\Inventar;

use Bitrix\Main\Entity;

class ServiceTable extends Entity\DataManager
{
    public static function getTableName() 
    { 
        return 'b_bitrix_inventar_service'; 
    }
    
    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', ['primary'=>true, 'autocomplete'=>true]),
            new Entity\IntegerField('EQUIPMENT_ID', ['required'=>true]),
            new Entity\DateField('DATA_INTRARE', ['required'=>true]),
            new Entity\DateField('DATA_IESIRE'),
            new Entity\TextField('PROBLEMA'),
            new Entity\TextField('SOLUTIE'),
            new Entity\FloatField('COST_SERVICE'),
            new Entity\StringField('STATUS_ENUM'),
            new Entity\DatetimeField('CREATED_AT')
        ];
    }
}
?>