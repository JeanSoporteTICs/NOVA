@extends('errors::layout')

@section('code', '419')
@section('title', 'La sesión expiró')
@section('icon', 'bi-hourglass-bottom')
@section('tone', 'warning')
@section('message', 'Por seguridad, el formulario caducó. Vuelve a cargar la página e inténtalo de nuevo.')
@section('actions')
    <button type="button" class="btn btn-outline-secondary" onclick="history.back()"><i class="bi bi-arrow-left"></i> Volver</button>
    <a class="btn btn-primary" href="{{ url('/login') }}"><i class="bi bi-box-arrow-in-right"></i> Iniciar sesión</a>
@endsection
