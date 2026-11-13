{{-- Documento de texto libre (generador de documentos o escrito legal). $content se imprime escapado. --}}
@extends('pdf.tools.layout')

@section('content')
    <div class="body-text">{{ $content }}</div>
    @if (! empty($notice))
        <div class="notice">{{ $notice }}</div>
    @endif
@endsection
