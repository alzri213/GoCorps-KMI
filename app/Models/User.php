<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'AdminUserAccount';
    protected $primaryKey = 'Id';
    public $timestamps = false;

    protected $fillable = [
        'Username',
        'FullName',
        'Email',
        'Department',
        'Division',
        'Nip',
        'Role',
        'PasswordHash',
        'IsActive',
        'IsApprover',
        'ApprovalLevel',
    ];

    protected $hidden = [
        'PasswordHash',
        'Password',
    ];

    public function getConnectionName(): ?string
    {
        return env('DB_AUTH_CONNECTION', 'gocorps');
    }

    public function getAuthPasswordName(): string
    {
        return 'PasswordHash';
    }

    public function getAuthPassword(): string
    {
        return $this->PasswordHash ?? $this->Password ?? '';
    }

    /**
     * Map a position name to its approval level key.
     */
    public static function mapPositionToLevel(?string $positionName): string
    {
        $pos = strtolower(trim((string) $positionName));

        if (str_contains($pos, 'directorate') || str_contains($pos, 'director') || str_contains($pos, 'chief') || str_contains($pos, 'direksi')) {
            return 'direksi';
        }
        if (str_contains($pos, 'division head') || str_contains($pos, 'division') || str_contains($pos, 'divisi')) {
            return 'divisi';
        }
        if (str_contains($pos, 'department head') || str_contains($pos, 'dep. head') || str_contains($pos, 'manager') || str_contains($pos, 'kabag')) {
            return 'manager';
        }
        if (str_contains($pos, 'section head') || str_contains($pos, 'supervisor') || str_contains($pos, 'spv') || str_contains($pos, 'leader')) {
            return 'spv';
        }

        return 'staff';
    }

    /**
     * Get position level strictly from MasterPosition: 'staff', 'spv', 'manager', 'divisi', 'direksi'
     */
    public function getPositionLevel(): string
    {
        $connection = env('COMPANY_DB_CONNECTION', 'company_portal');

        try {
            $user = DB::connection($connection)
                ->table('AdminUserAccount')
                ->where('Username', $this->Username)
                ->first();

            if ($user && !empty($user->MasterEmployeeID)) {
                $emp = DB::connection($connection)
                    ->table('MasterEmployee as emp')
                    ->leftJoin('MasterPosition as pos', 'emp.MasterPositionID', '=', 'pos.ID')
                    ->where('emp.ID', $user->MasterEmployeeID)
                    ->select('pos.PositionName')
                    ->first();

                if ($emp && !empty($emp->PositionName)) {
                    return self::mapPositionToLevel($emp->PositionName);
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return 'staff';
    }

    /**
     * Get superior level strictly checking MasterPosition.PositionResponsibleToID: 'divisi' or 'direksi'
     */
    public function getSuperiorLevel(): string
    {
        $connection = env('COMPANY_DB_CONNECTION', 'company_portal');

        try {
            $user = DB::connection($connection)
                ->table('AdminUserAccount')
                ->where('Username', $this->Username)
                ->first();

            if ($user && !empty($user->MasterEmployeeID)) {
                $posInfo = DB::connection($connection)
                    ->table('MasterEmployee as emp')
                    ->join('MasterPosition as pos', 'emp.MasterPositionID', '=', 'pos.ID')
                    ->leftJoin('MasterPosition as parentPos', 'pos.PositionResponsibleToID', '=', 'parentPos.ID')
                    ->where('emp.ID', $user->MasterEmployeeID)
                    ->select('parentPos.PositionName as ParentPositionName')
                    ->first();

                if ($posInfo && !empty($posInfo->ParentPositionName)) {
                    $parentPos = strtolower(trim($posInfo->ParentPositionName));
                    if (
                        str_contains($parentPos, 'directorate') ||
                        str_contains($parentPos, 'director') ||
                        str_contains($parentPos, 'chief') ||
                        str_contains($parentPos, 'direksi') ||
                        str_contains($parentPos, 'president') ||
                        str_contains($parentPos, 'coo')
                    ) {
                        return 'direksi';
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return 'divisi';
    }

    /**
     * Get user-friendly company position level string
     */
    public function getCompanyUserLevel(): string
    {
        $posLevel = $this->getPositionLevel();
        if ($posLevel === 'manager') return 'Department Head';
        if ($posLevel === 'divisi') return 'Division Head';
        if ($posLevel === 'direksi') return 'Directorate Head';
        if ($posLevel === 'spv') return 'Section Head / Supervisor';
        return 'Staff';
    }

    /**
     * Resolve company role directly from MasterPosition
     */
    public function companyRole(): string
    {
        $level = $this->getPositionLevel();
        if ($level === 'manager') return 'atasan';
        if ($level === 'divisi') return 'divisi';
        if ($level === 'direksi') return 'hrd';
        return 'karyawan';
    }

    /**
     * Get the position hierarchy (approval chain) walking up MasterPosition.PositionResponsibleToID.
     * Index 0 = the user's own position, then direct superior, up to the top.
     */
    public function getApprovalChain(): array
    {
        $connection = env('COMPANY_DB_CONNECTION', 'company_portal');

        try {
            $user = DB::connection($connection)
                ->table('AdminUserAccount')
                ->where('Username', $this->Username)
                ->first();

            if (! $user || empty($user->MasterEmployeeID)) {
                return [];
            }

            $rows = DB::connection($connection)
                ->select(
                    'WITH Chain AS (
                        SELECT pos.ID AS position_id, pos.PositionName AS position_name,
                               pos.PositionResponsibleToID AS parent_id, 1 AS chain_level
                        FROM MasterPosition pos
                        INNER JOIN MasterEmployee emp ON emp.MasterPositionID = pos.ID
                        WHERE emp.ID = ?
                        UNION ALL
                        SELECT parent.ID AS position_id, parent.PositionName AS position_name,
                               parent.PositionResponsibleToID AS parent_id, c.chain_level + 1
                        FROM MasterPosition parent
                        INNER JOIN Chain c ON parent.ID = c.parent_id
                        WHERE c.parent_id IS NOT NULL AND c.chain_level < 20
                    )
                    SELECT * FROM Chain ORDER BY chain_level ASC',
                    [$user->MasterEmployeeID]
                );

            return array_map(function ($r) {
                return [
                    'position_id' => $r->position_id,
                    'position_name' => $r->position_name,
                    'chain_level' => (int) $r->chain_level,
                ];
            }, $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get all company users grouped by approval level ('spv', 'atasan', 'divisi', 'direksi').
     */
    public function getApproversByLevel(): array
    {
        $connection = env('COMPANY_DB_CONNECTION', 'company_portal');
        $approvers = ['spv' => [], 'atasan' => [], 'divisi' => [], 'direksi' => []];
        $levelMap = ['spv' => 'spv', 'manager' => 'atasan', 'divisi' => 'divisi', 'direksi' => 'direksi'];

        try {
            $rows = DB::connection($connection)
                ->table('AdminUserAccount as u')
                ->join('MasterEmployee as emp', 'u.MasterEmployeeID', '=', 'emp.ID')
                ->join('MasterPosition as pos', 'emp.MasterPositionID', '=', 'pos.ID')
                ->select('u.Id', 'u.Username', 'u.FullName', 'pos.PositionName')
                ->get();

            foreach ($rows as $u) {
                $level = self::mapPositionToLevel($u->PositionName ?? '');
                $key = $levelMap[$level] ?? null;
                if (! $key) {
                    continue;
                }
                $approvers[$key][] = [
                    'id' => $u->Id,
                    'username' => $u->Username,
                    'name' => $u->FullName ?? $u->Username,
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $approvers;
    }
}
