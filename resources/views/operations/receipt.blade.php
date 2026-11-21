@extends('printing.thermal')
@section('title','Recibo de venda #'.$sale->id)
@section('content')
@include('printing.sale')
@endsection
