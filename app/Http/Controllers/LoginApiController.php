<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LoginApiController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'nullable|string',
            'email' => 'nullable|string',
            'password' => 'required|string',
        ]);

        $username = trim($data['username'] ?? $data['email'] ?? '');
        $inputPassword = trim($data['password']);

        if (empty($username)) {
            return response()->json(['message' => 'Username wajib diisi.'], 422);
        }

        // 1. Authenticate directly against CompanyPortalData_DEV database -> AdminUserAccount table
        $companyUser = DB::connection('company_portal')
            ->table('AdminUserAccount')
            ->where('Username', $username)
            ->first();

        $valid = false;
        $fullName = $username;

        if ($companyUser) {
            $storedPassword = trim($companyUser->Password ?? '');
            if ($storedPassword !== '') {
                if (
                    $inputPassword === $storedPassword ||
                    Hash::check($inputPassword, $storedPassword) ||
                    md5($inputPassword) === strtolower($storedPassword) ||
                    sha1($inputPassword) === strtolower($storedPassword)
                ) {
                    $valid = true;
                    $fullName = $companyUser->Name ?? $username;
                }
            }
        }

        // 2. Fallback check in GoCorps_DEV database if not found in CompanyPortalData_DEV
        if (! $valid) {
            $goCorpsUser = User::where('Username', $username)->orWhere('Email', $username)->first();
            if ($goCorpsUser) {
                $storedHash = trim($goCorpsUser->PasswordHash ?? '');
                if ($storedHash !== '') {
                    if (
                        $inputPassword === $storedHash ||
                        Hash::check($inputPassword, $storedHash) ||
                        md5($inputPassword) === strtolower($storedHash)
                    ) {
                        $valid = true;
                        $fullName = $goCorpsUser->FullName ?? $username;
                    }
                }
            }
        }

        if (! $valid) {
            return response()->json(['message' => 'Username atau password salah.'], 401);
        }

        // 3. Ensure user exists in GoCorps_DEV database for request relations (without triggering PDO lastInsertId)
        $user = User::where('Username', $username)->first();
        if (! $user) {
            DB::connection('gocorps')->table('AdminUserAccount')->insert([
                'Username' => $username,
                'FullName' => $fullName,
                'Email' => str_contains($username, '@') ? $username : ($username . '@gocorp.local'),
                'Role' => 'karyawan',
                'PasswordHash' => Hash::make($inputPassword),
                'IsActive' => 1,
            ]);
            $user = User::where('Username', $username)->first();
        }

        // 4. Resolve company role using ERD relationship (AdminApplicationRole, MasterEmployee, UserLevel, etc.)
        $role = $user->companyRole();
        $userLevel = $user->getCompanyUserLevel();
        DB::connection('gocorps')->table('AdminUserAccount')->where('Id', $user->Id)->update(['Role' => $role]);

        return response()->json([
            'user' => [
                'id' => $user->Id,
                'name' => $user->FullName ?? $user->Username,
                'username' => $user->Username,
                'email' => $user->Email ?? $user->Username,
            ],
            'company_role' => $role,
            'user_level' => $userLevel,
        ]);
    }
}
