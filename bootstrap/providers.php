<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Study\StudyServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    // Study-only module (app/Study/README.md): deleting the folder is enough, this line then adds nothing.
    ...(class_exists(StudyServiceProvider::class) ? [StudyServiceProvider::class] : []),
];
