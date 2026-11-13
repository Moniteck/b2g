{{--
    Documento de texto libre (generador de documentos o escrito legal).
    TextFormatter::toHtml escapa TODO el contenido antes de dar formato a negritas y saltos de línea.
--}}
@extends('pdf.tools.layout')

@section('content')
    <div>{!! \App\Support\Tools\TextFormatter::toHtml($content) !!}</div>
    @if (! empty($notice))
        <div class="notice">{{ $notice }}</div>
    @endif
@endsection
