<?php

arch('the platform does not depend on business modules')
    ->expect('App\Platform')
    ->not->toUse('Modules');

arch('platform code declares strict types')
    ->expect('App\Platform')
    ->toUseStrictTypes();
