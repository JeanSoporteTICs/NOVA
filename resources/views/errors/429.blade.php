@extends('errors::layout')

@section('code', '429')
@section('title', 'Demasiados intentos')
@section('icon', 'bi-speedometer2')
@section('tone', 'warning')
@section('message', 'Hiciste muchas solicitudes seguidas. Espera un momento antes de intentarlo otra vez.')
