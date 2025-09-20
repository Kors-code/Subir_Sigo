<?php

namespace App\Http\Controllers;

use App\Models\Producto;

class TestController extends Controller
{
    public function index()
    {
        // Insertar
        Producto::create([
            'nombre' => 'Laptop Gamer',
            'precio' => 3500,
            'stock'  => 15,
        ]);

        // Leer
        $productos = Producto::all();

        return response()->json($productos);
    }
}
