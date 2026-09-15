@extends('layouts.site')

@section('content')
    @include('site.partials.hero')
    @include('site.partials.stats')
    @include('site.partials.solutions')
    @include('site.partials.journey')
    @include('site.partials.locations')
    @include('site.partials.meeting')
    @include('site.partials.amenities')
    @include('site.partials.pricing')
    @include('site.partials.blog')
    @include('site.partials.lead-form')
@endsection
