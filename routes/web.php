<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SiigoController;
use App\Http\Controllers\ConfigController;



Route::get('/', function () {
    return view('welcome');
});


Route::get('/configs', [ConfigController::class, 'index']);


Route::get('/siigo/upload', [SiigoController::class, 'showUploadForm'])->name('siigo.uploadForm');
Route::post('/siigo/upload', [SiigoController::class, 'uploadExcel'])->name('siigo.uploadExcel');
Route::post('siigo/invoice/{coll}', [SiigoController::class, 'sendInvoiceSiigo']);
Route::post('siigo/comprobante', [SiigoController::class, 'sendComprobanteSiigo']);
Route::post('siigo/comprobante-test', [SiigoController::class, 'sendComprobanteSiigopRUEBAS'] ?? function(){});
Route::post('siigo/agregar', [SiigoController::class, 'agregarInfoComprobante']);
Route::get('siigo/comprobantes', [SiigoController::class, 'getComprobantes']);
Route::post('siigo/listado/{pg}', [SiigoController::class, 'getListadoSiigo']);
Route::post('siigo/invoices/{pg}', [SiigoController::class, 'getFacturacionSiigo']);
Route::post('siigo/journals/{pg}', [SiigoController::class, 'getComprobantesSiigo']);
Route::post('siigo/pdf/{coll}', [SiigoController::class, 'PdfFactura']);
Route::get('/test-mongo', [App\Http\Controllers\TestController::class, 'index']);
