<?php

use Illuminate\Database\Eloquent\Model;

arch('no debug helpers in application code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit', 'eval'])
    ->not->toBeUsed();

arch('models live in App\Models and extend the Eloquent base model')
    ->expect('App\Models')
    ->toExtend(Model::class);

arch('enums are real enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('http middleware stays invokable middleware')
    ->expect('App\Http\Middleware')
    ->toHaveMethod('handle');

arch('strict equality is preferred')
    ->expect('App')
    ->toUseStrictEquality();
