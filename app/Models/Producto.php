<?php

namespace App\Models;

use Jenssegers\Mongodb\Eloquent\Model;
class Producto extends Model
{
    protected $table = 'productos';
    protected $fillable = ['id', 'codigo', 'nombre', 'descripcion', 'cuenta_debito', 'cuenta_credito'];
}
