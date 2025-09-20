<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Config extends Model
{
    protected $table = 'configs'; // nombre exacto de tu tabla

    protected $fillable = [
        'siigo_user',
        'siigo_key',
        'identification_number',
        'business_name',
        'address',
        'phone',
        'email',
        'trm_euro',
        'trm_usd',
        'city',
        'consecutivo_comp_costo',
        'consecutivo_comp_venta',
        'consecutivo_comp_caja',
        'consecutivo_orden_compra',
        'enviar_consecutivo'
    ];
}
