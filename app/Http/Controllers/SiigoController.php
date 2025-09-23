<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Config;
use App\Models\InvoiceLine;
use App\Models\ComprobanteSiigo;
use GuzzleHttp\Client as GuzzleClient;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use App\Events\SiigoProgress;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Exception\RequestException;
use Exception;

class SiigoController extends Controller
{
    /**
     * Form para subir Excel (vista simple)
     */
    public function showUploadForm()
    {
        return view('siigo.upload');
    }

    /**
     * Procesa Excel y envía facturas a Siigo.
     * - customer fijo = '222222222' (cambiar en $defaultCustomerId)
     * - fecha manual con input 'date' (YYYY-MM-DD)
     * - document_id opcional (por defecto 31800)
     */
public function uploadExcel(Request $request)
{
    $request->validate([
        'excel_file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        'date' => 'nullable|date_format:Y-m-d',
        'document_id' => 'nullable|integer',
        'payment_id' => 'nullable|integer',
        'customer_id' => 'nullable|string',
        'chunk_size' => 'nullable|integer'
    ]);

    $config = Config::first();
    if (!$config) {
        return back()->withErrors(['Config' => 'No hay configuración de Siigo en la base de datos.']);
    }

    // Autenticación Siigo (solo para validar credenciales tempranamente)
    try {
        $token = $this->siigoAuth($config->siigo_user, $config->siigo_key);
    } catch (Exception $e) {
        Log::error('Auth Siigo failed: '.$e->getMessage());
        return back()->withErrors(['SiigoAuth' => 'Error autenticando con Siigo: '.$e->getMessage()]);
    }

    // leer excel
    $file = $request->file('excel_file');
    $sheets = Excel::toArray(null, $file);
    if (empty($sheets) || !isset($sheets[0])) {
        return back()->withErrors(['Excel' => 'No se pudo leer la hoja del Excel.']);
    }
    $rows = $sheets[0];
    if (count($rows) <= 1) {
        return back()->withErrors(['Excel' => 'El archivo está vacío o solo tiene encabezado.']);
    }

    // inputs
    $dateManual = $request->input('date') ?: now()->format('Y-m-d');
    $defaultCustomerId = $request->input('customer_id', '222222222');
    $chunkSize = (int)$request->input('chunk_size', 40); // <- configurable por request, default 40

    // --- convertir filas a registros asociativos (header -> columnas)
    $headers = array_map(function($h){ return trim((string)$h); }, $rows[0]);
    $rawRows = array_slice($rows, 1);

    $registros = [];
    foreach ($rawRows as $r) {
        if (!is_array($r) || count(array_filter($r)) == 0) continue;
        $rec = [];
        foreach ($headers as $i => $h) {
            $rec[$h] = $r[$i] ?? null;
        }
        $rec['FOLIO'] = $rec['FOLIO'] ?? $rec['Folio'] ?? null;
        $rec['CLASIFICACION'] = $rec['CLASIFICACION'] ?? $rec['Clasi'] ?? $rec['CLASIF'] ?? null;
        $rec['CODIGO'] = $rec['CODIGO'] ?? $rec['Codigo'] ?? $rec['SKU'] ?? null;
        $rec['CANTIDAD'] = is_numeric($rec['CANTIDAD'] ?? null) ? (float)$rec['CANTIDAD'] : (float)($rec['Cantidad'] ?? 1);
        $rec['COP'] = is_numeric($rec['COP'] ?? null) ? (float)$rec['COP'] : (float)($rec['VALOR EN PESOS'] ?? 0);
        $rec['Costo_de_v'] = is_numeric($rec['Costo_de_v'] ?? null) ? (float)$rec['Costo_de_v'] : (float)($rec['COSTO DE VENTA'] ?? 0);
        $rec['PDV'] = $rec['PDV'] ?? $rec['PuntoVenta'] ?? null;
        $rec['Detalle'] = substr(($rec['Detalle'] ?? ($rec['DESCRIPCION'] ?? '')), 0, 150);
        $registros[] = $rec;
    }

    // --- cuenta / mapping
    $cuentas = [
      ['cod'=>10,'Debit'=>'61359510','Credit'=>'14350110','Venta'=>'41359510'],
      ['cod'=>11,'Debit'=>'61359511','Credit'=>'14350111','Venta'=>'41359511'],
      ['cod'=>12,'Debit'=>'61359512','Credit'=>'14350112','Venta'=>'41359512'],
      ['cod'=>13,'Debit'=>'61359513','Credit'=>'14350113','Venta'=>'41359513'],
      ['cod'=>14,'Debit'=>'61359514','Credit'=>'14350114','Venta'=>'41359514'],
      ['cod'=>15,'Debit'=>'61359515','Credit'=>'14350115','Venta'=>'41359515'],
      ['cod'=>16,'Debit'=>'61359516','Credit'=>'14350116','Venta'=>'41359516'],
      ['cod'=>17,'Debit'=>'61359517','Credit'=>'14350117','Venta'=>'41359517'],
      ['cod'=>18,'Debit'=>'61359518','Credit'=>'14350118','Venta'=>'41359518'],
      ['cod'=>19,'Debit'=>'61359519','Credit'=>'14350119','Venta'=>'41359519'],
      ['cod'=>21,'Debit'=>'61359521','Credit'=>'14350121','Venta'=>'41359521'],
      ['cod'=>22,'Debit'=>'61359522','Credit'=>'14350122','Venta'=>'41359522'],
      ['cod'=>98,'Debit'=>'61359598','Credit'=>'14350198','Venta'=>'41359598'],
      ['cod'=>20,'Debit'=>'61359520','Credit'=>'14350120','Venta'=>'41359520'],
    ];

    $itemsContable = [];
    $itemsFacturaVentas = [];
    $erroresValidacion = [];

    foreach ($registros as $idx => $r) {
        $clas = (int)($r['CLASIFICACION'] ?? 0);
        $colCod = array_column($cuentas, 'cod');
        $pos2 = array_search($clas, $colCod);
        if ($pos2 === false) {
            $erroresValidacion[] = "Fila índice {$idx}: CLASIFICACION {$clas} no mapeada a cuentas.";
            continue;
        }

        // centro de costos (mínima lógica)
        $cost_center = "";
        if (($r['PDV'] ?? null) == "COLS1") {
            $cost_center = "675";
        } elseif (($r['PDV'] ?? null) == "COLS2") {
            $cost_center = "673";
        } else {
            $cost_center = "23139";
        }

        if (empty($cost_center)) {
            if (!empty($config->centro_costos_default)) {
                $cost_center = $config->centro_costos_default;
            } else {
                $erroresValidacion[] = "Fila índice {$idx}: No se encontró cost_center para PDV '{$r['PDV']}' (Folio {$r['FOLIO']}).";
                continue;
            }
        }

        $debitAcc = $cuentas[$pos2]['Debit'];
        $creditAcc = $cuentas[$pos2]['Credit'];
        $ventaAcc = $cuentas[$pos2]['Venta'];

        $desc = substr("CMV FAC {$r['FOLIO']} SKU {$r['CODIGO']} CANT: {$r['CANTIDAD']} {$r['Detalle']}", 0, 99);
        $dta1 = [
            'account' => ['code' => $debitAcc, 'movement' => 'Debit'],
            'customer' => ['identification' => $defaultCustomerId],
            'product' => [
                'code' => (string)$clas,
                'name' => $desc,
                'quantity' => (int)$r['CANTIDAD'],
                'description' => $desc,
                'value' => (float)($r['Costo_de_v'] ?? 0)
            ],
            'value' => (float)($r['Costo_de_v'] ?? 0),
            'quantity' => (int)$r['CANTIDAD'],
            'cost_center' => $cost_center,
            'description' => $desc,
        ];
        $itemsContable[] = $dta1;

        $dta2 = $dta1;
        $dta2['account'] = ['code' => $creditAcc, 'movement' => 'Credit'];
        $itemsContable[] = $dta2;

        $itemsFacturaVentas[] = [
            'account' => ['code' => $ventaAcc, 'movement' => 'Credit'],
            'customer' => ['identification' => $defaultCustomerId],
            'cost_center' => (int)$cost_center,
            'product' => [
                'code' => (string)$clas,
                'name' => $r['Detalle'],
                'quantity' => 0,
                'description' => $r['Detalle'],
                'value' => (float)($r['COP'] ?? 0),

            ],
            'description' => $r['Detalle'],
            'value' => (float)($r['COP'] ?? 0),
        ];

        $itemsFacturaVentas[] = [
            'account' => ['code' => '13050501', 'movement' => 'Debit'],
            'customer' => ['identification' => $defaultCustomerId],
            'due' => [
                'prefix' => 'C',
                'consecutive' => 1,
                'quote' => 1,
                'date' => $dateManual,
            ],
            'description' => $r['Detalle'],
            'cost_center' => (int)$cost_center,
            'value' => (float)($r['COP'] ?? 0),
        ];


        // --- versión alternativa más clara (igual que arriba, pero sin duplicar arrays)
$creditItem = [
    'account' => ['code' => $ventaAcc, 'movement' => 'Credit'],
    'customer' => ['identification' => $defaultCustomerId],
    'cost_center' => (int)$cost_center,
    'product' => [
        'code' => (string)$clas,
        'name' => $r['Detalle'],
        'quantity' => 0,
        'description' => $r['Detalle'],
        'value' => (float)($r['COP'] ?? 0),
    ],
    'description' => $r['Detalle'],
    'value' => (float)($r['COP'] ?? 0),
];

$debitItem = [
    'account' => ['code' => '13050501', 'movement' => 'Debit'],
    'customer' => ['identification' => $defaultCustomerId],
    'due' => [
        'prefix' => 'C',
        'consecutive' => 1,
        'quote' => 1,
        'date' => $dateManual,
    ],
    'description' => $r['Detalle'],
    'cost_center' => (int)$cost_center,
    'value' => (float)($r['COP'] ?? 0),
];

$itemsFacturaVentas[] = $creditItem;
$itemsFacturaVentas[] = $debitItem;

    }

    // Si hay errores de validación, devuelve para que corrijas el Excel / configuración
    if (!empty($erroresValidacion)) {
        $msg = "Errores de validación: " . implode(" | ", array_slice($erroresValidacion,0,30));
        Log::error($msg);
        return back()->withErrors(['Validacion' => $msg]);
    }

    // --- Ahora enviamos en lotes (chunking) para evitar URLs demasiado largas en validaciones internas de Siigo
    $responsesContable = [];
    $responsesVentas = [];

    // usamos el folio del primer registro si existe, para _id interno
    $operationId = $registros[0]['FOLIO'] ?? null;

    // chunk y enviar contable (iddoc 5086)
    // max reintentos por chunk (adicional a los reintentos dentro de sendComprobanteSiigo)
$maxChunkRetries = 3;

if (!empty($itemsContable)) {
    $chunks = array_chunk($itemsContable, max(1, $chunkSize));
    foreach ($chunks as $i => $chunk) {
        $paramsContable = [
            'user' => $config->siigo_user,
            'key'  => $config->siigo_key,
            'data' => $chunk,
            'date' => $dateManual,
            'obs'  => $request->input('observations') ?? null,
            'iddoc'=> 5086,
            '_id'  => $operationId,
            'reserve_number' => false,
        ];

        $attempt = 0;
        $success = false;
        $lastRespBody = null;
        while ($attempt <= $maxChunkRetries && !$success) {
            $attempt++;
            try {
                $internalReq = \Illuminate\Http\Request::create('/', 'POST', $paramsContable);
                $resp = $this->sendComprobanteSiigo($internalReq);
                $status = $resp->getStatusCode();
                $body = $resp->getContent();
                Log::info("sendContable chunk {$i} attempt {$attempt} status {$status}: {$body}");
                $responsesContable[] = json_decode($body, true) ?: $body;
                $lastRespBody = $body;

                if ($status >= 200 && $status < 300) {
                    $success = true;
                    break;
                }

                // si es un 5xx, esperar y reintentar
                if ($status >= 500) {
                    if ($attempt <= $maxChunkRetries) {
                        $sleep = pow(2, $attempt);
                        Log::warning("sendContable chunk {$i} got {$status}, sleeping {$sleep}s then retrying (attempt {$attempt})");
                        sleep($sleep);
                        continue;
                    }
                }

                // si es 4xx u otro no retryable, marcamos intento terminado
                break;

            } catch (Exception $e) {
                Log::error("sendContable chunk {$i} attempt {$attempt} exception: ".$e->getMessage());
                $responsesContable[] = ['error' => $e->getMessage()];
                if ($attempt <= $maxChunkRetries) {
                    sleep(pow(2, $attempt));
                    continue;
                }
                break;
            }
        }

        if (!$success) {
            // persistir info del chunk fallido para reintento manual o proceso asíncrono
            try {
                ComprobanteSiigo::create([
                    'operacion' => $operationId,
                    'data' => ['chunk_index' => $i, 'params' => $paramsContable, 'last_response' => $lastRespBody],
                    'usuario' => $config->siigo_user,
                ]);
            } catch (\Exception $e) {
                Log::error("No se pudo guardar ComprobanteSiigo por chunk fallido {$i}: ".$e->getMessage());
            }
        }
    }
}

// ------------------ loop para VENTAS (iddoc 31800) ------------------
if (!empty($itemsFacturaVentas)) {
             $chunksV = array_chunk($itemsFacturaVentas, max(1, $chunkSize));
            Log::info("sendVentas -> total itemsFacturaVentas: ".count($itemsFacturaVentas)." chunks: ".count($chunksV));

            // definir método de pago a usar en payments (evita Undefined variable $paymentId)
$paymentId = (int)($request->input('payment_id') ?? ($config->medio_pago_default ?? 0));

            foreach ($chunksV as $i => $chunk) {
                // Normalize items for the chunk (ensure types)
                $chunkOperationId = $operationId ? ($operationId . "_v{$i}") : ("ventas_{$i}_" . uniqid());

$itemsForSiigo = [];
$totalCredit = 0.0;
$totalDebit = 0.0;

foreach ($chunk as $rawItem) {
    $customerObj = $rawItem['customer'] ?? ['identification' => $defaultCustomerId];

    // Si viene product, determinamos quantity, unitValue y lineTotal
    $quantity = 1;
    $unitValue = isset($rawItem['product']['value']) ? (float)$rawItem['product']['value'] : (float)($rawItem['value'] ?? 0.0);
    if (isset($rawItem['product']['quantity'])) {
        $quantity = max(1, (int)$rawItem['product']['quantity']);
    } elseif (isset($rawItem['quantity'])) {
        $quantity = max(1, (int)$rawItem['quantity']);
    }

    // Si en tu fuente 'product.value' es total (no unitario), detectarlo:
    // heurística: si product.value == rawItem.value and quantity>1 -> asumir que product.value es total
    if ($quantity > 1 && isset($rawItem['product']['value']) && isset($rawItem['value'])) {
        // preferimos tratar product.value como unitario si parece unitario; si no, calculamos unitario
        if (abs((float)$rawItem['product']['value'] - (float)$rawItem['value']) > 0.01) {
            // ninguno coincide exactamente -> usar product.value as unit
            $unitValue = (float)$rawItem['product']['value'];
        } else {
            // coincide -> derive unit from total
            $unitValue = ((float)$rawItem['product']['value']) / max(1, $quantity);
        }
    }

    $lineTotal = round($unitValue * $quantity, 2);

    // ITEM: producto (Credit) => incluir product con unitValue y quantity, pero value = lineTotal
    if (!empty($rawItem['product']) && is_array($rawItem['product'])) {
        $prod = $rawItem['product'];
        $itemsForSiigo[] = [
            'product' => [
                'code' => (string)($prod['code'] ?? ''),
                'name' => $prod['name'] ?? ($rawItem['description'] ?? 'Item'),
                'quantity' => $quantity,
                // enviamos unitario en product.value (si tu integración necesita unitario)
                'value' => round($unitValue, 2),
            ],
            // este es el total de la línea
            'value' => $lineTotal,
            'account' => [
                'code' => (string)($rawItem['account']['code'] ?? '41359518'),
                'movement' => $rawItem['account']['movement'] ?? 'Credit'
            ],
            'cost_center' => isset($rawItem['cost_center']) ? (int)$rawItem['cost_center'] : (int)($config->centro_costos_default ?? 0),
            'customer' => $customerObj,
            'description' => $rawItem['description'] ?? ($prod['name'] ?? '')
        ];
        $totalCredit += $lineTotal;
    }

    // ITEM: contrapartida (Debit) => usar el MISMO lineTotal (no el unitario)
    if (!empty($rawItem['due'])) {
        $itemsForSiigo[] = [
            'value' => $lineTotal,
            'account' => [
                'code' => (string)($rawItem['account']['code'] ?? '13050501'),
                'movement' => $rawItem['account']['movement'] ?? 'Debit'
            ],
            'cost_center' => isset($rawItem['cost_center']) ? (int)$rawItem['cost_center'] : (int)($config->centro_costos_default ?? 0),
            'customer' => $customerObj,
            'due' => [
                'prefix' => $rawItem['due']['prefix'] ?? 'C',
                'consecutive' => $rawItem['due']['consecutive'] ?? 1,
                'quote' => $rawItem['due']['quote'] ?? 1,
                'date' => $rawItem['due']['date'] ?? $dateManual
            ],
            'description' => $rawItem['description'] ?? ''
        ];
        $totalDebit += $lineTotal;
    }
}

// DEBUG: log totals before send para confirmar balance
Log::info("sendVentas chunk {$i} totals -> credit: {$totalCredit}, debit: {$totalDebit}, itemsCount: ".count($itemsForSiigo));

// ahora paramsVentas (ENVIAR items, NO data)
$paramsVentas = [
    'iddoc' => 31800,
    '_id' => $chunkOperationId,
    'reserve_number' => false,
    'document' => ['id' => 31800],
    'date' => $dateManual,
    'customer' => ['identification' => $defaultCustomerId],
    'seller' => ['id' => (int)($request->input('seller_id') ?? 1)],
    'cost_center' => isset($chunk[0]['cost_center']) ? (int)$chunk[0]['cost_center'] : (int)($config->centro_costos_default ?? 0),
    'items' => $itemsForSiigo,
    'obs' => $request->input('observations') ?? null,
];

// payments top-level (si existe paymentId)
if (!empty($paymentId) && $paymentId > 0) {
    $totalPayment = 0.0;
    foreach ($itemsForSiigo as $it) {
        if (isset($it['account']['movement']) && strtolower($it['account']['movement']) === 'debit') {
            $totalPayment += (float)($it['value'] ?? 0);
        }
    }
    if ($totalPayment > 0) {
        $paramsVentas['payments'] = [
            [
                'value' => round($totalPayment, 2),
                'due_date' => $dateManual,
                'payment_method' => ['id' => (int)$paymentId]
            ]
        ];
    }
}




        $attempt = 0;
        $success = false;
        $lastRespBody = null;
        while ($attempt <= $maxChunkRetries && !$success) {
            $attempt++;
            try {
                $internalReq = \Illuminate\Http\Request::create('/', 'POST', $paramsVentas);
                $resp = $this->sendComprobanteSiigo($internalReq);
                $status = $resp->getStatusCode();
                $body = $resp->getContent();
                Log::info("sendVentas chunk {$i} attempt {$attempt} status {$status}: {$body}");
                $responsesVentas[] = json_decode($body, true) ?: $body;
                $lastRespBody = $body;

                if ($status >= 200 && $status < 300) {
                    $success = true;
                    break;
                }

                if ($status >= 500) {
                    if ($attempt <= $maxChunkRetries) {
                        $sleep = pow(2, $attempt);
                        Log::warning("sendVentas chunk {$i} got {$status}, sleeping {$sleep}s then retrying (attempt {$attempt})");
                        sleep($sleep);
                        continue;
                    }
                }
                break;

            } catch (Exception $e) {
                Log::error("sendVentas chunk {$i} attempt {$attempt} exception: ".$e->getMessage());
                $responsesVentas[] = ['error' => $e->getMessage()];
                if ($attempt <= $maxChunkRetries) {
                    sleep(pow(2, $attempt));
                    continue;
                }
                break;
            }
        }

        if (!$success) {
            try {
                ComprobanteSiigo::create([
                    'operacion' => $operationId,
                    'data' => ['chunk_index' => $i, 'params' => $paramsVentas, 'last_response' => $lastRespBody],
                    'usuario' => $config->siigo_user,
                ]);
            } catch (\Exception $e) {
                Log::error("No se pudo guardar ComprobanteSiigo por chunk ventas fallido {$i}: ".$e->getMessage());
            }
        }
    }
}

    // Resumen en logs y mensaje al usuario
    $summaryCont = json_encode(array_slice($responsesContable, 0, 20));
    $summaryVent = json_encode(array_slice($responsesVentas, 0, 20));
    Log::info("Resumen respuestas Siigo - Contable: {$summaryCont}");
    Log::info("Resumen respuestas Siigo - Ventas: {$summaryVent}");

    return back()->with('success', "Envíos a Siigo ejecutados. Chunks contable: ".count($responsesContable).", chunks ventas: ".count($responsesVentas).". Revisa logs para detalles.");
}


    /**
     * Internal helper: get document types from Siigo
     * Intento robusto: si endpoint 'v1/document-types' no existe intento 'v1/documents'
     * Devuelve array (tal cual lo retorne Siigo) o lanza excepción.
     */
    protected function fetchSiigoDocumentTypesInternal(GuzzleClient $client, string $token): array
    {
        $tries = ['v1/document-types', 'v1/documents', 'v1/document-types?type=FV'];
        foreach ($tries as $endpoint) {
            try {
                $resp = $client->get($endpoint, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json'
                    ],
                ]);
                $body = json_decode((string)$resp->getBody(), true);
                // si viene paginado o envuelto, intentamos normalizar
                if (is_array($body)) return $body;
                if (isset($body['data']) && is_array($body['data'])) return $body['data'];
            } catch (\Exception $e) {
                // intentar siguiente endpoint
                continue;
            }
        }
        throw new Exception('No fue posible listar document types en Siigo con los endpoints probados.');
    }

    /**
     * Internal helper: get payment methods from Siigo
     */
    protected function fetchSiigoPaymentMethodsInternal(GuzzleClient $client, string $token): array
    {
        $tries = ['v1/payment-types', 'v1/payment-methods', 'v1/payments'];
        foreach ($tries as $endpoint) {
            try {
                $resp = $client->get($endpoint, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json'
                    ],
                ]);
                $body = json_decode((string)$resp->getBody(), true);
                if (is_array($body)) return $body;
                if (isset($body['data']) && is_array($body['data'])) return $body['data'];
            } catch (\Exception $e) {
                continue;
            }
        }
        throw new Exception('No fue posible listar payment methods en Siigo con los endpoints probados.');
    }


    /* -------------------------
       Resto del controlador (sin cambios funcionales importantes)
       -- mantuve tus otros métodos tal cual --
       (si necesitas que te pase el archivo completo con todos
        los métodos idénticos a como los tenías, dímelo)
       ------------------------- */

    // ... (el resto de métodos: sendInvoiceSiigo, sendComprobanteSiigo, etc.)
    // Si quieres que te pegue el archivo completo otra vez con TODO,
    // lo hago; aquí omití repetirlos para centrarme en la corrección.
    // Pero si prefieres tener el controller completo listo, te lo devuelvo.


    /**
     * sendInvoiceSiigo: genera PDFs desde InvoiceLine (MySQL) y marca como PDF.
     */
    public function sendInvoiceSiigo(Request $request, $coll = null)
    {
        try {
            $folios = InvoiceLine::where('Estado', 'Siigo')
                ->select('Folio')
                ->distinct()
                ->pluck('Folio');

            if ($folios->isEmpty()) {
                return response()->json(['message' => 'Sin Documentos para Procesar Siigo'], 200);
            }

            Storage::disk('local')->makeDirectory('PDF/invoices');

            $total = $folios->count();
            $index = 0;

            foreach ($folios as $folio) {
                $lines = InvoiceLine::where('Folio', $folio)->get();

                $usd = $lines->sum('Importe');
                $unidades = $lines->sum('Cantidad');
                $cop = $lines->sum('COP');
                $hora = $lines->pluck('Hora')->unique()->values()->all();
                $trm = $lines->pluck('TRM')->filter()->values()->first();
                $vendedor = $lines->pluck('Nombre_del_vend')->unique()->values()->all();
                $costumer = $lines->pluck('Costumer')->filter()->values()->all();

                $first = $lines->first();
                $fechaObj = $first ? ['D' => $first->Day, 'M' => $first->Month, 'Y' => $first->Year] : null;
                $fechaStr = $this->formatFechaFromMonthAbbrev($fechaObj);

                $detalle = [];
                foreach ($lines as $ln) {
                    $price = ($ln->Cantidad && $ln->Cantidad != 0) ? ($ln->COP / $ln->Cantidad) : 0;
                    $detalle[] = [
                        'description' => $ln->Detalle,
                        'code' => (string) ($ln->Clasi ?: 'Sku-1'),
                        'price' => $price,
                        'quantity' => $ln->Cantidad,
                        'importe' => $ln->Importe,
                        'taxes' => []
                    ];
                }

                $context = [
                    'folio' => $folio,
                    'fecha' => $fechaStr,
                    'hora' => $hora,
                    'products' => $detalle,
                    'vendedor' => $vendedor,
                    'costumer' => $costumer,
                    'resolucion' => $first->Resolucion ?? null,
                    'cop' => number_format($cop, 0, ',', '.'),
                    'trm' => number_format($trm ?? 0, 0, ',', '.'),
                    'usd' => number_format($usd, 0, ',', '.'),
                ];

                $html = $this->buildInvoiceHtml($context);

                $pdf = Pdf::loadHTML($html);
                $filename = $folio . '.pdf';
                $path = "PDF/invoices/{$filename}";
                Storage::disk('local')->put($path, $pdf->output());

                InvoiceLine::where('Folio', $folio)
                    ->update(['Pdf' => $path, 'Estado' => 'PDF']);

                $index++;
                event(new SiigoProgress($total, $index));
            }

            return response()->json(['message' => 'PDFs generados', 'count' => $total], 200);

        } catch (Exception $e) {
            Log::error('sendInvoiceSiigo error: '.$e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * sendComprobanteSiigo: manejos para iddoc 5086, 31800, 34002.
     */
   public function sendComprobanteSiigo(Request $request)
{
    $params = $request->all();
    $iddoc = $params['iddoc'] ?? null;
    if (!$iddoc) return response()->json(['error' => 'iddoc required'], 400);


    $operacion = $params['_id'] ?? ($params['folio'] ?? null);
    if (!$operacion) {
        return response()->json(['error' => 'Folio o _id requerido para control interno'], 400);
    }

    DB::beginTransaction();
    try {
        $config = Config::lockForUpdate()->first();
        if (!$config) {
            DB::rollBack();
            return response()->json(['error' => 'Config no encontrada'], 404);
        }

        // consecutivo logic (igual que antes, mantengo tu lógica)
        $consecutivo = null;
        $reserveNumberFlag = array_key_exists('reserve_number', $params) ? (bool)$params['reserve_number'] : true;
        $explicitNumber = isset($params['number']) ? $params['number'] : null;

        if ($explicitNumber !== null) {
            $consecutivo = $explicitNumber;
        } elseif ($reserveNumberFlag) {
            if ($iddoc == 5086) {
                $config->consecutivo_comp_costo = ($config->consecutivo_comp_costo ?? 0) + 1;
                $config->save();
                $consecutivo = $config->enviar_consecutivo ? $config->consecutivo_comp_costo : null;
            } elseif ($iddoc == 31800) {
                $config->consecutivo_comp_venta = ($config->consecutivo_comp_venta ?? 0) + 1;
                $config->save();
                $consecutivo = $config->consecutivo_comp_venta;
            } elseif ($iddoc == 34002) {
                $config->consecutivo_comp_caja = ($config->consecutivo_comp_caja ?? 0) + 1;
                $config->save();
                $consecutivo = $config->consecutivo_comp_caja;
            } else {
                DB::rollBack();
                return response()->json(['error'=>'Tipo de iddoc no manejado'], 400);
            }
        } else {
            $consecutivo = null;
        }

        DB::commit();

        $token = $this->siigoAuth($params['user'] ?? $config->siigo_user, $params['key'] ?? $config->siigo_key);

                // --- antes: $payload = [...]
        // Construir items desde 'data' ó 'items' (compatibilidad con ambos formatos)
        $items = [];
        if (isset($params['data']) && is_array($params['data'])) {
            $items = $params['data'];
        } elseif (isset($params['items']) && is_array($params['items'])) {
            $items = $params['items'];
        }

        // Base del payload (aseguramos document.id)
        $payload = [
            'document' => ['id' => (int)$iddoc],
            'date' => $params['date'] ?? now()->format('Y-m-d'),
            'items' => $items,
        ];

        // Passthrough de algunos campos top-level opcionales que usan las ventas
        // (mantener otros formatos funcionando: customer, seller, payments, cost_center)
        if (isset($params['obs']) && !isset($payload['observations'])) {
            $payload['observations'] = $params['obs'];
        }
        if (isset($params['observations'])) {
            $payload['observations'] = $params['observations'];
        }
        if (isset($params['customer'])) {
            $payload['customer'] = $params['customer'];
        }
        if (isset($params['seller'])) {
            $payload['seller'] = $params['seller'];
        }
        if (isset($params['payments'])) {
            $payload['payments'] = $params['payments'];
        }
        if (isset($params['cost_center'])) {
            $payload['cost_center'] = $params['cost_center'];
        }
        // si el caller proveyó un 'number' explícito, lo respetamos
        if (isset($params['number'])) {
            $payload['number'] = $params['number'];
        }

        Log::info('sendComprobanteSiigo -> payload prepared', [
            'operacion' => $operacion,
            'iddoc' => $iddoc,
            'reserve_number' => $reserveNumberFlag,
            'explicit_number' => $explicitNumber,
            'payload' => $payload
        ]);

        Log::info('Siigo - payload before send', ['payload' => $payload]);

        // POST con reintentos para 5xx / timeouts
        $client = new GuzzleClient();
        // Aumentado a 10 intentos para que 'documents_service' pueda reintentarse hasta 10 veces.
        $maxAttempts = 10;
        $lastException = null;


        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                Log::info("sendComprobanteSiigo -> attempt {$attempt} for operacion {$operacion}");
                $resp = $client->post('https://api.siigo.com/v1/journals', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Partner-ID' => 'DutyFreeCol',
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json'
                    ],
                    'json' => $payload,
                    'http_errors' => true,
                    'timeout' => 30,
                    'connect_timeout' => 10,
                ]);

                $status = $resp->getStatusCode();
                $body = json_decode((string)$resp->getBody(), true) ?: (string)$resp->getBody();

                Log::info("sendComprobanteSiigo -> success status {$status} for operacion {$operacion}", ['body'=>$body]);

                return response()->json($body, $status);

            } catch (RequestException $re) {
    $lastException = $re;
    $status = $re->hasResponse() ? $re->getResponse()->getStatusCode() : 0;
    $bodyStr = $re->hasResponse() ? (string)$re->getResponse()->getBody() : $re->getMessage();

    // Intentamos decodificar JSON (si aplica). Si no es JSON, guardamos el string.
    $bodyDecoded = json_decode($bodyStr, true);
    if ($bodyDecoded === null) {
        // json_decode devuelve null tanto para JSON null como para fallo, pero Siigo nunca retornará "null" puro;
        // así que en caso de null usamos el string original.
        $bodyDecoded = $bodyStr;
    }

    Log::warning("sendComprobanteSiigo -> attempt {$attempt} RequestException (status {$status})", [
        'operacion' => $operacion,
        'body' => $bodyDecoded
    ]);

    // --- Caso especial: Siigo devuelve 400 con Errors[] y Code == 'documents_service'
    if ($status === 400 && is_array($bodyDecoded) && isset($bodyDecoded['Errors']) && is_array($bodyDecoded['Errors'])) {
        $hasDocsService = false;
        foreach ($bodyDecoded['Errors'] as $err) {
            if (isset($err['Code']) && $err['Code'] === 'documents_service') {
                $hasDocsService = true;
                break;
            }
        }

        if ($hasDocsService) {
            Log::warning("sendComprobanteSiigo -> documents_service detected (attempt {$attempt}) for operacion {$operacion}");
            if ($attempt < $maxAttempts) {
                // backoff con tope razonable
                $sleep = min(60, pow(2, $attempt));
                sleep($sleep);
                continue; // reintentar
            } else {
                // agotados intentos: persistir/retornar el body decodificado tal cual
                return response()->json($bodyDecoded, $status);
            }
        }

        // si no es documents_service, devolvemos el 400 al caller (no reintentar)
        return response()->json($bodyDecoded, $status);
    }

    // --- reintentar para 5xx, timeouts o rate-limit
    if ($status >= 500 || $status === 0 || in_array($status, [408, 429])) {
        if ($attempt < $maxAttempts) {
            $sleep = min(60, pow(2, $attempt));
            Log::info("sendComprobanteSiigo -> sleeping {$sleep}s before retry (attempt {$attempt})");
            sleep($sleep);
            continue;
        } else {
            $outStatus = $status ?: 503;
            return response()->json(is_array($bodyDecoded) ? $bodyDecoded : $bodyStr, $outStatus);
        }
    }

    // --- otros 4xx no reintentables: devolver inmediatamente con cuerpo decodificado si es posible
    $outStatus = $status ?: 400;
    return response()->json(is_array($bodyDecoded) ? $bodyDecoded : $bodyStr, $outStatus);
}
 catch (Exception $e) {
                $lastException = $e;
                Log::error("sendComprobanteSiigo unexpected error on attempt {$attempt}: ".$e->getMessage());
                if ($attempt < $maxAttempts) {
                    sleep(pow(2, $attempt));
                    continue;
                } else {
                    return response()->json(['error' => $e->getMessage()], 500);
                }
            }
        }

        // si cae fuera del loop
        $msg = $lastException ? $lastException->getMessage() : 'Unknown error';
        Log::error('sendComprobanteSiigo final failure: '.$msg);
        return response()->json(['error' => $msg], 500);

    } catch (Exception $e) {
        DB::rollBack();
        Log::error('sendComprobanteSiigo error: '.$e->getMessage());
        return response()->json(['error' => $e->getMessage()], 400);
    } catch (RequestException $re) {
    $status = $re->hasResponse() ? $re->getResponse()->getStatusCode() : 0;
    $body = $re->hasResponse() ? (string)$re->getResponse()->getBody() : $re->getMessage();
    $decoded = json_decode($body, true);

    // Siigo: documento duplicado -> tratar como éxito idempotente (no reintentar)
    if ($status === 409 && is_array($decoded)) {
        Log::info("sendComprobanteSiigo -> duplicated document for operacion {$operacion}", ['body'=>$decoded]);
        // devolver 200 o 409 con un flag; aquí devolvemos 200 con información
        return response()->json([
            'status' => 'duplicated',
            'detail' => $decoded
        ], 200);
    }

    // comportamiento previo para 5xx y retries (o devolver error 4xx)
    Log::warning("sendComprobanteSiigo -> request exception (status {$status}) for operacion {$operacion}", ['body' => $body]);
    // si es 5xx la lógica de retry en el loop ya la maneja — aquí devolvemos el error
    $decoded = json_decode($body, true);
    $outStatus = $status ?: 503;
    return response()->json($decoded ?: $body, $outStatus);
}

}




    /**
     * Versión pruebas que recibe token en params.key (no hace auth)
     */
    public function sendComprobanteSiigopRUEBAS(Request $request)
    {
        $params = $request->all();
        $iddoc = $params['iddoc'] ?? null;
        if (!$iddoc) return response()->json(['error'=>'iddoc required'], 400);
        $token = $params['key'] ?? null;
        if (!$token) return response()->json(['error'=>'token required (key)'], 400);

        try {
            $payload = [
                'document' => ['id' => (int)$iddoc],
                'date' => $params['date'] ?? null,
                'items' => $params['data'] ?? [],
                'observations' => $params['obs'] ?? null,
            ];

            $client = new GuzzleClient();
            $resp = $client->post('https://api.siigo.com/v1/journals', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Partner-ID' => 'DutyFreeCol',
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json'
                ],
                'json' => $payload
            ]);

            return response()->json(json_decode((string)$resp->getBody(), true), $resp->getStatusCode());
        } catch (RequestException $re) {
            $msg = $re->hasResponse() ? (string)$re->getResponse()->getBody() : $re->getMessage();
            Log::error('sendComprobanteSiigopRUEBAS RequestException: '.$msg);
            return response()->json(['error' => $msg], 400);
        } catch (Exception $e) {
            Log::error('sendComprobanteSiigopRUEBAS error: '.$e->getMessage());
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * agregarInfoComprobante: guarda en tabla MySQL comprobantes_siigo
     */
    public function agregarInfoComprobante(Request $request)
    {
        try {
            $data = $request->all();
            $model = ComprobanteSiigo::create([
                'operacion' => $data['operacion'] ?? null,
                'data' => $data,
                'usuario' => $data['user'] ?? null,
            ]);
            return response()->json(['insertedId' => $model->id], 200);
        } catch (Exception $e) {
            Log::error('agregarInfoComprobante error: '.$e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * getComprobantes: lee desde MySQL
     */
    public function getComprobantes()
    {
        try {
            $records = ComprobanteSiigo::all();
            return response()->json($records, 200);
        } catch (Exception $e) {
            Log::error('getComprobantes error: '.$e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * getListadoSiigo: GET v1/{pg}
     */
    public function getListadoSiigo(Request $request, $pg)
    {
        $params = $request->all();
        try {
            $token = $this->siigoAuth($params['user'] ?? null, $params['key'] ?? null);
            $client = new GuzzleClient(['base_uri' => 'https://api.siigo.com/']);
            $resp = $client->get("v1/{$pg}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Partner-Id' => 'DutyFree',
                    'Accept' => 'application/json'
                ]
            ]);
            return response()->json(json_decode((string)$resp->getBody(), true), $resp->getStatusCode());
        } catch (Exception $e) {
            Log::error('getListadoSiigo error: '.$e->getMessage());
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * getFacturacionSiigo
     */
    public function getFacturacionSiigo(Request $request, $pg)
    {
        $params = $request->all();
        try {
            $token = $this->siigoAuth($params['user'] ?? null, $params['key'] ?? null);
            $client = new GuzzleClient(['base_uri' => 'https://api.siigo.com/']);
            $resp = $client->get("v1/invoices?page={$pg}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json'
                ]
            ]);
            return response()->json(json_decode((string)$resp->getBody(), true), $resp->getStatusCode());
        } catch (Exception $e) {
            Log::error('getFacturacionSiigo error: '.$e->getMessage());
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * getComprobantesSiigo (journals)
     */
    public function getComprobantesSiigo(Request $request, $pg)
    {
        $params = $request->all();
        try {
            $token = $this->siigoAuth($params['user'] ?? null, $params['key'] ?? null);
            $client = new GuzzleClient(['base_uri' => 'https://api.siigo.com/']);
            $resp = $client->get("v1/journals?page={$pg}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json'
                ]
            ]);
            return response()->json(json_decode((string)$resp->getBody(), true), $resp->getStatusCode());
        } catch (Exception $e) {
            Log::error('getComprobantesSiigo error: '.$e->getMessage());
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * authSiigo (siigonube) - ejemplo connect/token
     */
    public function authSiigo(Request $request = null)
    {
        try {
            $client = new GuzzleClient();
            $resp = $client->post('https://siigonube.siigo.com:50050/connect/token', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic U2lpZ29XZWI6QUJBMDhCNkEtQjU2Qy00MEE1LTkwQ0YtN0MxRTU0ODkxQjYx',
                    'Accept' => 'application/json'
                ],
                'form_params' => [
                    'grant_type' => 'password',
                    'username' => 'siigoapi@pruebas.com',
                    'password' => '9999',
                    'scope' => 'WebApi offline_access'
                ]
            ]);
            return response()->json(json_decode((string)$resp->getBody(), true), $resp->getStatusCode());
        } catch (Exception $e) {
            Log::error('authSiigo error: '.$e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * PdfFactura: genera PDF para folio específico
     */
    public function PdfFactura(Request $request, $coll = null)
    {
        try {
            $folio = $request->input('Folio');
            if (!$folio) return response()->json(['error' => 'Folio requerido'], 400);

            $lines = InvoiceLine::where('Folio', $folio)->get();
            if ($lines->isEmpty()) return response()->json(['message' => 'Sin Documentos para Procesar Siigo'], 200);

            $first = $lines->first();
            $usd = $lines->sum('Importe');
            $cop = $lines->sum('COP');
            $trm = $lines->pluck('TRM')->filter()->first();

            $detalle = [];
            foreach ($lines as $ln) {
                $price = ($ln->Cantidad && $ln->Cantidad != 0) ? ($ln->COP / $ln->Cantidad) : 0;
                $detalle[] = [
                    'description' => $ln->Detalle,
                    'code' => (string) ($ln->Clasi ?: 'Sku-1'),
                    'price' => $price,
                    'quantity' => $ln->Cantidad,
                    'importe' => $ln->Importe,
                    'taxes' => []
                ];
            }

            $context = [
                'folio' => $folio,
                'fecha' => $this->formatFechaFromMonthAbbrev(['D'=>$first->Day,'M'=>$first->Month,'Y'=>$first->Year]),
                'hora' => $lines->pluck('Hora')->unique()->values()->all(),
                'products' => $detalle,
                'vendedor' => $lines->pluck('Nombre_del_vend')->unique()->values()->all(),
                'costumer' => $lines->pluck('Costumer')->filter()->values()->all(),
                'resolucion' => $first->Resolucion ?? null,
                'cop' => number_format($cop, 0, ',', '.'),
                'trm' => number_format($trm ?? 0,0,',','.'),
                'usd' => number_format($usd, 0, ',', '.'),
            ];

            $html = $this->buildInvoiceHtml($context);
            Storage::disk('local')->makeDirectory('PDF/invoices');
            $path = "PDF/invoices/{$folio}.pdf";
            Storage::disk('local')->put($path, Pdf::loadHTML($html)->output());

            InvoiceLine::where('Folio', $folio)->update(['Pdf' => $path, 'Estado' => 'PDF']);

            return response()->json(['path' => $path], 200);
        } catch (Exception $e) {
            Log::error('PdfFactura error: '.$e->getMessage());
            return response()->json(['error'=>$e->getMessage()], 500);
        }
    }

    /**
     * Helper de autenticación Siigo (UNICA definición)
     */
    protected function siigoAuth(?string $user, ?string $key): string
    {
        if (empty($user) || empty($key)) {
            throw new Exception('Siigo credentials missing (user/key).');
        }

        try {
            $client = new GuzzleClient();
            $resp = $client->post('https://api.siigo.com/auth', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => ['username' => $user, 'access_key' => $key],
                'http_errors' => true
            ]);

            $body = json_decode((string)$resp->getBody(), true);
            if (empty($body['access_token'])) {
                throw new Exception('No access_token returned from Siigo.');
            }
            return $body['access_token'];
        } catch (RequestException $re) {
            $msg = $re->hasResponse() ? (string)$re->getResponse()->getBody() : $re->getMessage();
            Log::error('siigoAuth RequestException: '.$msg);
            throw new Exception($msg);
        }
    }

    /**
     * formatea la fecha a YYYY-MM-DD a partir de abreviatura de mes
     */
    protected function formatFechaFromMonthAbbrev($fechaObj): ?string
    {
        if (!$fechaObj || !isset($fechaObj['D'])) return null;
        $map = ['ENE'=>'01','FEB'=>'02','MAR'=>'03','ABR'=>'04','MAY'=>'05','JUN'=>'06','JUL'=>'07','AGO'=>'08','SEP'=>'09','OCT'=>'10','NOV'=>'11','DIC'=>'12'];
        $M = strtoupper($fechaObj['M'] ?? '');
        $mm = $map[$M] ?? '01';
        return sprintf("%s-%s-%s", $fechaObj['Y'] ?? '0000', $mm, $fechaObj['D']);
    }

    /**
     * Construye HTML del invoice para PDF
     */
    protected function buildInvoiceHtml(array $ctx): string
    {
        $productsHtml = '';
        foreach ($ctx['products'] as $p) {
            $importe = $p['importe'] ?? '';
            $description = htmlspecialchars($p['description'] ?? '', ENT_QUOTES);
            $quantity = $p['quantity'] ?? '';
            $productsHtml .= "<tr>
                <td style=\"text-align: center; border-bottom: 1px solid grey;\">{$quantity}</td>
                <td style=\"text-align: center; border-bottom: 1px solid grey;\">{$description}</td>
                <td style=\"text-align: right; border-bottom: 1px solid grey;\">{$importe}</td>
            </tr>";
        }

        $costumerHtml = '';
        if (is_array($ctx['costumer'])) {
            foreach ($ctx['costumer'] as $c) {
                $nombre = $c['NOMBRE_DE_PAX'] ?? ($c['name'] ?? '');
                $origen = $c['ORIGEN'] ?? '';
                $destin = $c['DESTIN'] ?? '';
                $aerolinea = $c['AEROLINEA'] ?? '';
                $vuelo = $c['VUELO'] ?? '';
                $asiento = $c['ASIENT'] ?? '';
                $pasaporte = $c['PASAPORTE'] ?? '';
                $nacion = $c['NACION'] ?? '';
                $steb = $c['STEB_BAG'] ?? '';

                $costumerHtml .= "<div style=\"text-align:left;max-width: 500px; margin:0 auto; \">
                    _______________________________________________  <br>
                    PAX INFO <br>
                    Nombre: {$nombre} <br>
                    Origen: {$origen} <br>
                    Destino: {$destin} <br>
                    Aerolinea: {$aerolinea} <br>
                    Vuelo: {$vuelo} <br>
                    Asiento: {$asiento} <br>
                    Passport: {$pasaporte} Pais: {$nacion} <br>
                    STEB Bag: {$steb} <br>
                    _______________________________________________
                </div>";
            }
        }

        $html = "
        <div style=\"
            font-family: 'Lucida Sans', 'Lucida Sans Regular', 'Lucida Grande', 'Lucida Sans Unicode', 'Geneva', 'Verdana', 'sans-serif';
            max-width: 800px;
            color: #585858;
            font-size:16px;
            background-color: #fff;
            border-radius: 6px;
            margin: 0 auto;
        \">
            <h4>
                DUTY FREE PARTNERS COLOMBIA, SAS <br>
                AEROPUERTO INTERNACIONAL JOSE MARIA CORDOVA <br>
                LOCALES: 23,23A,23B,23C <br>
                VEREDA SAJONIA, RIONEGRO ANTIOQUIA <br>
                NIT: 901.195.686-7 <br>
                FOLIO: {$ctx['folio']} FECHA: {$ctx['fecha']} HORA: " . (is_array($ctx['hora']) ? implode(', ', $ctx['hora']) : $ctx['hora']) . " <br>
                _______________________________________________
            </h4>

            <table style=\"max-width: 500px; font-size:0.9em;\">
                <tr>
                    <th>Unds</th>
                    <th>Descripcion</th>
                    <th>Total</th>
                </tr>
                {$productsHtml}
            </table>

            <h3>
                TRM USD:\$ {$ctx['trm']} TOTAL DLL: \$ {$ctx['usd']} <br>
                TOTAL COP: \$ {$ctx['cop']} <br>
            </h3>

            {$costumerHtml}

            <p>\"VENTA DE EXPORTACION\" <br> _______________________________________________</p>

            <p>
                Todos los datos personales recopilados <br>
                no seran distribuidos o utilizados <br>
                con prpositos diferentes a dar <br>
                cumplimiento a regulaciones nacionales <br>
                _______________________________________________
            </p>

            <h5>{$ctx['resolucion']}</h5>

            <p>_______________________________________________</p>

            <h5>
                IMPRESO SOFTWARE MACROPRO <br>
                FACTURA DE VENTA POS:{$ctx['folio']} <br>
                MACROPRO SOFTWARE S.A. DE C.V. ID:MSO0105111G1 <br>
            </h5>

            <p>
                _______________________________________________ <br>
                Atendido por: " . (is_array($ctx['vendedor']) ? implode(', ', $ctx['vendedor']) : $ctx['vendedor']) . " <br>
                Servicio al cliente: <br>
                +57(317)432-6895 <br>
                contactomde@skyfreeshop.org <br>
                www.dutyfreepartners.com <br>
            </p>
        </div>
        ";

        return $html;
    }
}
