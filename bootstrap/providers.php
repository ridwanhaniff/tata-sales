<?php

use App\Providers\AppServiceProvider;
use App\Providers\CrmServiceProvider;
use App\Providers\LLMServiceProvider;

return [
    AppServiceProvider::class,
    LLMServiceProvider::class,
    CrmServiceProvider::class,
];
