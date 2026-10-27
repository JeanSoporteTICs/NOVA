@extends('errors::layout')

@section('code', '401')
@section('title', 'Sesión requerida')
@section('icon', 'bi-person-lock')
@section('tone', 'warning')
@section('message', 'Debes iniciar sesión para ver esta página.')
@section('actions')
    <a class="btn btn-primary" href="{{ url('/login') }}"><i class="bi bi-box-arrow-in-right"></i> Iniciar sesión</a>
@endsection
