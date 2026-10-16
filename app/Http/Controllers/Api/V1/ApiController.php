<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    /**
     * The page size a list asked for with ?limit=, 50 by default and never more than 100.
     */
    protected function limit(Request $request): int
    {
        return max(1, min($request->integer('limit', 50), 100));
    }
}
