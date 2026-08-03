<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function me(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $role = null;
        try {
            $role = $user->companyRole();
        } catch (\Throwable $e) {
            $role = null;
        }

        return response()->json([
            'user' => $user,
            'company_role' => $role,
        ]);
    }
}
