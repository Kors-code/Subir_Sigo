<!doctype html>
<html>
<head>
  <meta charset="utf-8">
</head>
<body>
  <h4>DUTY FREE PARTNERS COLOMBIA, SAS<br>
  ... <br>
  FOLIO: {{ $folio }} FECHA: {{ $fecha }} HORA: {{ json_encode($hora) }}</h4>
  <table>
    <tr><th>Unds</th><th>Descripcion</th><th>Total</th></tr>
    @foreach($products as $p)
      <tr>
        <td>{{ $p['quantity'] ?? '' }}</td>
        <td>{{ $p['description'] ?? '' }}</td>
        <td style="text-align:right">{{ $p['importe'] ?? '' }}</td>
      </tr>
    @endforeach
  </table>
  <h3>TRM USD:$ {{ $trm }} TOTAL DLL: $ {{ $usd }} <br> TOTAL COP: $ {{ $cop }}</h3>

  @foreach($costumer as $c)
    <div>
      _______________________________________________  <br>
      PAX INFO <br>
      Nombre: {{ $c['NOMBRE_DE_PAX'] ?? '' }} <br>
      Origen: {{ $c['ORIGEN'] ?? '' }} <br>
      Passport: {{ $c['PASAPORTE'] ?? '' }} Pais: {{ $c['NACION'] ?? '' }} <br>
    </div>
  @endforeach

  <p>IMPRESO SOFTWARE MACROPRO ...</p>
</body>
</html>
