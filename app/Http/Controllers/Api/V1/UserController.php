<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * The token's own user and the token itself: what a script checks first.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
