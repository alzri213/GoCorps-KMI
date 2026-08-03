<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $users = User::all()->map(function ($u) {
            return [
                'id' => $u->Id,
                'name' => $u->FullName ?? $u->Username,
                'email' => $u->Email ?? $u->Username,
                'role' => $u->companyRole(),
                'nomor_hp' => $u->Nip,
            ];
        });

        return response()->json($users);
    }

    public function show($id)
    {
        $u = User::find($id);
        if (! $u) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        return response()->json([
            'id' => $u->Id,
            'name' => $u->FullName ?? $u->Username,
            'email' => $u->Email ?? $u->Username,
            'role' => $u->companyRole(),
            'nomor_hp' => $u->Nip,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'required|string|email',
            'password' => 'required|string|min:6',
            'role' => 'required|string',
            'nomor_hp' => 'nullable|string',
        ]);

        $user = User::create([
            'Username' => explode('@', $data['email'])[0],
            'FullName' => $data['name'],
            'Email' => $data['email'],
            'Role' => strtolower($data['role']),
            'PasswordHash' => Hash::make($data['password']),
            'Nip' => $data['nomor_hp'] ?? null,
            'IsActive' => 1,
        ]);

        // Sync role to CompanyPortalData_DEV
        try {
            DB::connection('company_portal')
                ->table('AdminUserAccount')
                ->updateOrInsert(
                    ['Username' => $user->Username],
                    [
                        'Name' => $data['name'],
                        'Password' => $data['password'],
                        'UserLevel' => ucfirst($data['role']),
                        'IsActive' => 1,
                    ]
                );
        } catch (\Throwable $e) {
            // ignore if company portal is read-only
        }

        return response()->json(['message' => 'Pengguna berhasil dibuat', 'id' => $user->Id]);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);
        if (! $user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'required|string|email',
            'password' => 'nullable|string|min:6',
            'nomor_hp' => 'nullable|string',
        ]);

        $update = [
            'FullName' => $data['name'],
            'Email' => $data['email'],
            'Nip' => $data['nomor_hp'] ?? null,
        ];

        if (! empty($data['password'])) {
            $update['PasswordHash'] = Hash::make($data['password']);
        }

        $user->update($update);

        return response()->json(['message' => 'Pengguna berhasil diperbarui']);
    }

    public function destroy($id)
    {
        $user = User::find($id);
        if (! $user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        $user->delete();

        return response()->json(['message' => 'Pengguna berhasil dihapus']);
    }

    /**
     * Get the position hierarchy (approval chain) for a user.
     */
    public function approvalChain($id)
    {
        $user = User::find($id);
        if (! $user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        $chain = $user->getApprovalChain();
        $approvers = $user->getApproversByLevel();

        return response()->json([
            'user_id' => $user->Id,
            'name' => $user->FullName ?? $user->Username,
            'position_level' => $user->getPositionLevel(),
            'superior_level' => $user->getSuperiorLevel(),
            'chain' => $chain,
            'approvers_by_level' => $approvers,
        ]);
    }
}
