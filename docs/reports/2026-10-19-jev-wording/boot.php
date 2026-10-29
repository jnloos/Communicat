<?php

use Illuminate\Contracts\Console\Kernel;

// Bootstraps the app read-only for the wording experiment.
chdir('/home/janniclas/Projekte/LA-Communicat');
require '/home/janniclas/Projekte/LA-Communicat/vendor/autoload.php';
$app = require '/home/janniclas/Projekte/LA-Communicat/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
