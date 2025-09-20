<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    protected $table = 'invoice_lines';
    protected $guarded = [];
    protected $casts = [
        'Costumer' => 'array',
    ];
}
