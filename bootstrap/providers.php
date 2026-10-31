<?php

use App\Platform\PlatformServiceProvider;

return [
    // Shared technical platform (config validation, logging, metrics, worker lifecycle).
    PlatformServiceProvider::class,

    // Business modules register their own providers below, e.g.
    // Modules\User\UserServiceProvider::class, (BE-004)
];
