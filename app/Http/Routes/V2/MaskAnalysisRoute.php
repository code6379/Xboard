<?php

namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\MaskAnalysisController;
use Illuminate\Contracts\Routing\Registrar;

class MaskAnalysisRoute
{
    public function map(Registrar $router): void
    {
        $router->group([
            'prefix' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
        ], function (Registrar $router): void {
            $router->get('mask-analysis', [MaskAnalysisController::class, 'page']);
            $router->post('mask-analysis/login', [MaskAnalysisController::class, 'login']);
            $router->post('mask-analysis/logout', [MaskAnalysisController::class, 'logout']);
            $router->get('mask-analysis/data', [MaskAnalysisController::class, 'data']);
        });
    }
}
