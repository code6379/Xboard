<?php

use App\Utils\IP2Location;
use Illuminate\Foundation\Console\Kernel as BaseKernel;

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';

class Kernel extends BaseKernel
{
    public function handle($input, $output = null)
    {
        try {
            $this->bootstrap();
        } catch (Throwable $e) {
            $this->reportException($e);
            $this->renderException($output, $e);
            return 1;
        }
    }
}

$kernel = $app->make(Kernel::class);
$input  = new Symfony\Component\Console\Input\ArgvInput;
$status = $kernel->handle($input);
$kernel->terminate($input, $status);