@extends('errors::layout')

@section('code', '500')
@section('title', 'Error del servidor')
@section('icon', 'bi-bug')
@section('tone', 'danger')
@section('message', 'Algo falló al procesar la solicitud. Si se repite, avisa al equipo de soporte TIC.')
