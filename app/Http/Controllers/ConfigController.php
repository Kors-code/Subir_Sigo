<?php

namespace App\Http\Controllers;

use App\Models\Config;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    public function index()
    {
        // Obtener el primer registro de la tabla Config
        $config = Config::first();

        if (!$config) {
            return response()->json(['error' => 'No hay configuración registrada'], 404);
        }

        return response()->json($config);
    }
}
