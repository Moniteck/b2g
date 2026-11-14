<?php

/*
|--------------------------------------------------------------------------
| Herramientas del sistema y límites de subida
|--------------------------------------------------------------------------
|
| Binarios externos que usa el CRM (OCR y PDF) y tamaños máximos de archivo.
| Sin variable de entorno se buscan en el PATH del servidor, así que en un
| servidor bien instalado no hay que configurar nada.
|
| Para resolver la ruta de un binario usa `App\Support\Binaries`:
|   Binaries::tesseract()            Binaries::poppler('pdftotext')
|
*/

return [

    'tesseract' => [
        // Ruta completa al ejecutable, o solo el nombre para buscarlo en el PATH.
        'path' => env('TESSERACT_PATH', 'tesseract'),
        // Idioma exigido para leer capturas y pliegos.
        'language' => 'spa',
    ],

    'poppler' => [
        // Carpeta con pdftotext, pdftoppm, pdftocairo, pdfunite y pdfinfo. Vacío = PATH.
        'path' => env('POPPLER_PATH', ''),
        'binaries' => ['pdftotext', 'pdftoppm', 'pdftocairo', 'pdfunite', 'pdfinfo'],
    ],

    // Segundos máximos de espera al invocar un binario.
    'timeout' => 120,

    'uploads' => [
        'max_pdf_mb' => 40,
        'max_image_mb' => 10,
    ],

    // Mínimos de PHP para que las subidas anteriores funcionen.
    'php' => [
        'memory_limit_mb' => 512,
        // Margen del cuerpo de la petición sobre el archivo más grande.
        'post_margin_mb' => 8,
    ],

];
