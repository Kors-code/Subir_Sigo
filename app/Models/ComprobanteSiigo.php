<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprobanteSiigo extends Model
{
    protected $table = 'comprobantes_siigo';

    protected $fillable = [
        'operacion',
        'usuario',
        'chunk_index',
        'params',
        'data',
        'last_response',
        'status',
        'attempts',
        'processed_at'
    ];

    protected $casts = [
        'params' => 'array',
        'data' => 'array',
        'attempts' => 'integer',
        'processed_at' => 'datetime',
    ];
}
