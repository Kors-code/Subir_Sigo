<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
      <link rel="stylesheet" href="{{asset('css/uploadSigo.css')}}">
    <title>Subir Excel a Siigo</title>
</head>
<body>
    <h2>Subir archivo Excel</h2>

    @if(session('success'))
        <p style="color:green;">{{ session('success') }}</p>
    @endif

    @if($errors->any())
        <ul style="color:red;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form action="{{ route('siigo.uploadExcel') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <input type="file" name="excel_file" accept=".xlsx,.xls" required>
        <input type="date" name="date" required>
        <button type="submit">Subir y Enviar a Siigo</button>
    </form>
</body>
</html>
