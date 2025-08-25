<?php

use Illuminate\Support\Facades\Route;
// Importaciones para rutas web (agregar aquí si se requieren controladores web)

Route::get('/', function () {
    return view('welcome');
});

// Nota: Las rutas de API deben residir en routes/api.php para evitar conflictos de middleware (CSRF).
