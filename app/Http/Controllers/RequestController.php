<?php

namespace App\Http\Controllers;

use App\Models\ApprovalModel;
use App\Models\RequestModel;
use App\Models\TripModel;
use App\Models\User;
use App\Models\VehicleUsageModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RequestController extends Controller
{
    /**
     * List requests for a given user, role, or approval level queue.
     */
    public function index(Request $request)
    {
        $userId = $request->query('user_id');
        $role = $request->query('role');
        $status = $request->query('status');
        $targetLevel = $request->query('target_level');

        $query = RequestModel::with(['requester', 'trips', 'approvals', 'vehicleUsage']);

        if ($userId && in_array($role, ['karyawan', 'atasan', 'divisi'], true)) {
            $query->where('RequesterUserId', $userId);
        }

        if ($status) {
            $query->where('Status', $status);
        }

        $allRequests = $query->orderBy('CreatedAt', 'desc')->get();

        // If target_level is specified, filter requests that are currently waiting for that level
        if ($targetLevel) {
            $allRequests = $allRequests->filter(function ($req) use ($targetLevel) {
                return $req->getNextRequiredApprovalLevel() === $targetLevel;
            })->values();
        }

        $formatted = $allRequests->map(function ($r) {
            return $this->formatRequest($r);
        })->values();

        return response()->json($formatted);
    }

    /**
     * Get detail of a single request.
     */
    public function show($id)
    {
        $req = RequestModel::with(['requester', 'trips', 'approvals', 'vehicleUsage'])->find($id);
        if (! $req) {
            return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);
        }

        return response()->json($this->formatRequest($req));
    }

    /**
     * Create a new request.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'karyawan_id' => 'required|integer',
            'transaction_type' => 'required|string|in:gocar,gofood',
            'tanggal_waktu' => 'required|string',
            'tujuan' => 'required|string',
            'destinasi' => 'required|string',
            'nomor_hp' => 'nullable|string',
            'jumlah_trip' => 'required|integer|min:1',
            'kode_referensi' => 'nullable|string|max:100',
        ]);

        $req = RequestModel::create([
            'RequesterUserId' => $data['karyawan_id'],
            'RequestType' => $data['transaction_type'],
            'CreatedAt' => now()->toDateTimeString(),
            'Status' => 'diajukan',
            'Purpose' => $data['tujuan'],
            'NumberOfTrips' => $data['jumlah_trip'],
            'PhoneNumber' => $data['nomor_hp'],
            'Address' => $data['destinasi'],
            'Notes' => $data['tanggal_waktu'],
            'TransportMethod' => $data['kode_referensi'] ?? null,
        ]);

        return response()->json(['message' => 'Berhasil diajukan', 'id' => $req->Id]);
    }

    /**
     * Update an existing request (only if still 'diajukan').
     */
    public function update(Request $request, $id)
    {
        $req = RequestModel::find($id);
        if (! $req || $req->Status !== 'diajukan') {
            return response()->json(['message' => 'Pengajuan tidak dapat diubah'], 400);
        }

        $data = $request->validate([
            'transaction_type' => 'nullable|string|in:gocar,gofood',
            'tanggal_waktu' => 'required|string',
            'tujuan' => 'required|string',
            'destinasi' => 'required|string',
            'nomor_hp' => 'nullable|string',
            'jumlah_trip' => 'required|integer|min:1',
            'kode_referensi' => 'nullable|string|max:100',
        ]);

        $updateData = [
            'Purpose' => $data['tujuan'],
            'Address' => $data['destinasi'],
            'PhoneNumber' => $data['nomor_hp'],
            'NumberOfTrips' => $data['jumlah_trip'],
            'Notes' => $data['tanggal_waktu'],
            'TransportMethod' => $data['kode_referensi'] ?? null,
        ];
        if (! empty($data['transaction_type'])) {
            $updateData['RequestType'] = $data['transaction_type'];
        }
        $req->update($updateData);

        return response()->json(['message' => 'Berhasil diperbarui']);
    }

    /**
     * Approve or reject by SPV (Rule 1 step 1 for Staff).
     */
    public function decideSpv(Request $request, $id)
    {
        $data = $request->validate([
            'approve' => 'required|boolean',
            'catatan' => 'nullable|string',
            'decided_by_id' => 'required|integer',
        ]);

        $req = RequestModel::find($id);
        if (! $req) {
            return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);
        }

        $newStatus = $data['approve'] ? 'disetujui_spv' : 'ditolak';
        $req->update(['Status' => $newStatus]);

        ApprovalModel::create([
            'RequestId' => $req->Id,
            'ApproverUserId' => $data['decided_by_id'],
            'Level' => 1,
            'Status' => $data['approve'] ? 'approved' : 'rejected',
            'Comments' => $data['catatan'] ?? null,
            'ActionAt' => now()->toDateTimeString(),
        ]);

        return response()->json(['message' => 'Keputusan SPV berhasil disimpan']);
    }

    /**
     * Approve or reject by Dept. Head / Manager.
     */
    public function decideAtasan(Request $request, $id)
    {
        $data = $request->validate([
            'approve' => 'required|boolean',
            'catatan' => 'nullable|string',
            'decided_by_id' => 'required|integer',
        ]);

        $req = RequestModel::find($id);
        if (! $req) {
            return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);
        }

        $newStatus = $data['approve'] ? 'disetujui_atasan' : 'ditolak';
        $req->update(['Status' => $newStatus]);

        ApprovalModel::create([
            'RequestId' => $req->Id,
            'ApproverUserId' => $data['decided_by_id'],
            'Level' => 2,
            'Status' => $data['approve'] ? 'approved' : 'rejected',
            'Comments' => $data['catatan'] ?? null,
            'ActionAt' => now()->toDateTimeString(),
        ]);

        return response()->json(['message' => 'Keputusan Dept. Head berhasil disimpan']);
    }

    /**
     * Approve or reject by Divisi (Rule 1 step 3 for Staff/SPV, Rule 2 1x for Manager).
     */
    public function decideDivisi(Request $request, $id)
    {
        $data = $request->validate([
            'approve' => 'required|boolean',
            'catatan' => 'nullable|string',
            'decided_by_id' => 'required|integer',
        ]);

        $req = RequestModel::find($id);
        if (! $req) {
            return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);
        }

        $newStatus = $data['approve'] ? 'disetujui_divisi' : 'ditolak';
        $req->update(['Status' => $newStatus]);

        ApprovalModel::create([
            'RequestId' => $req->Id,
            'ApproverUserId' => $data['decided_by_id'],
            'Level' => 3,
            'Status' => $data['approve'] ? 'approved' : 'rejected',
            'Comments' => $data['catatan'] ?? null,
            'ActionAt' => now()->toDateTimeString(),
        ]);

        return response()->json(['message' => 'Keputusan Divisi berhasil disimpan']);
    }

    /**
     * Approve or reject by Direksi (Rule 3 1x for Manager under Direksi).
     */
    public function decideDireksi(Request $request, $id)
    {
        $data = $request->validate([
            'approve' => 'required|boolean',
            'catatan' => 'nullable|string',
            'decided_by_id' => 'required|integer',
        ]);

        $req = RequestModel::find($id);
        if (! $req) {
            return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);
        }

        $newStatus = $data['approve'] ? 'disetujui_direksi' : 'ditolak';
        $req->update(['Status' => $newStatus]);

        ApprovalModel::create([
            'RequestId' => $req->Id,
            'ApproverUserId' => $data['decided_by_id'],
            'Level' => 4,
            'Status' => $data['approve'] ? 'approved' : 'rejected',
            'Comments' => $data['catatan'] ?? null,
            'ActionAt' => now()->toDateTimeString(),
        ]);

        return response()->json(['message' => 'Keputusan Direksi berhasil disimpan']);
    }

    /**
     * Register GoCorp trip by HRD.
     */
    public function registerTrip(Request $request, $id)
    {
        $data = $request->validate([
            'kode_registrasi' => 'required|string',
        ]);

        $req = RequestModel::with('trips')->find($id);
        if (! $req) {
            return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);
        }

        if ($req->trips->isEmpty()) {
            for ($n = 1; $n <= $req->NumberOfTrips; $n++) {
                TripModel::create([
                    'RequestId' => $req->Id,
                    'Sequence' => $n,
                    'Origin' => 'Kantor',
                    'Destination' => $req->Address,
                    'DepartureDate' => now()->toDateTimeString(),
                    'Vehicle' => 'GoCorp',
                ]);
            }
            $req->refresh();
        }

        $nextTrip = $req->trips->firstWhere('VehicleNumber', null);
        if ($nextTrip) {
            $nextTrip->update([
                'VehicleNumber' => $data['kode_registrasi'],
                'DepartureDate' => now()->toDateTimeString(),
            ]);
        }

        if ($req->Status === 'disetujui_divisi' || $req->Status === 'disetujui_direksi') {
            $req->update(['Status' => 'didaftarkan']);
        }

        return response()->json(['message' => 'Trip GoCorp berhasil didaftarkan']);
    }

    /**
     * Assign operational car by HRD.
     */
    public function assignVehicle(Request $request, $id)
    {
        $data = $request->validate([
            'nomor_mobil' => 'required|string',
            'nama_supir' => 'required|string',
            'km_mobil' => 'required|integer',
            'assigned_by_id' => 'required|integer',
        ]);

        $req = RequestModel::find($id);
        if (! $req) {
            return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);
        }

        $req->update(['Status' => 'mobil_operasional']);

        VehicleUsageModel::create([
            'RequestId' => $req->Id,
            'VehicleNumber' => $data['nomor_mobil'],
            'DriverName' => $data['nama_supir'],
            'DepartureKm' => $data['km_mobil'],
            'FilledByHroUserId' => $data['assigned_by_id'],
            'FilledByHroAt' => now()->toDateTimeString(),
            'CreatedAt' => now()->toDateTimeString(),
        ]);

        return response()->json(['message' => 'Mobil operasional berhasil ditugaskan']);
    }

    /**
     * Complete trip (by karyawan or auto).
     */
    public function completeTrip(Request $request, $tripId)
    {
        $trip = TripModel::find($tripId);
        if (! $trip) {
            return response()->json(['message' => 'Trip tidak ditemukan'], 404);
        }

        $trip->update(['ReturnDate' => now()->toDateTimeString()]);

        $req = RequestModel::with('trips')->find($trip->RequestId);
        if ($req && $req->trips->where('ReturnDate', null)->isEmpty() && $req->trips->count() >= $req->NumberOfTrips) {
            $req->update(['Status' => 'selesai']);
        }

        return response()->json(['message' => 'Trip berhasil ditandai selesai']);
    }

    /**
     * Helper to format RequestModel into standard response structure.
     */
    private function formatRequest(RequestModel $r)
    {
        $spvApproval = $r->approvals->first(fn ($a) => $a->Level == 1 || $a->Level === 'spv');
        $atasanApproval = $r->approvals->first(fn ($a) => $a->Level == 2 || $a->Level === 'atasan');
        $divisiApproval = $r->approvals->first(fn ($a) => $a->Level == 3 || $a->Level === 'divisi');
        $direksiApproval = $r->approvals->first(fn ($a) => $a->Level == 4 || $a->Level === 'direksi');
        $vehicle = $r->vehicleUsage;

        return [
            'id' => $r->Id,
            'karyawan_id' => $r->RequesterUserId,
            'karyawan_name' => $r->requester?->FullName ?? $r->requester?->Username ?? 'Unknown',
            'position_level' => $r->requester?->getPositionLevel() ?? 'staff',
            'superior_level' => $r->requester?->getSuperiorLevel() ?? 'divisi',
            'next_required_level' => $r->getNextRequiredApprovalLevel(),
            'transaction_type' => $r->RequestType ?? 'gocar',
            'kode_referensi' => $r->TransportMethod,
            'tanggal_waktu' => $r->Notes ?? $r->CreatedAt,
            'tujuan' => $r->Purpose,
            'destinasi' => $r->Address,
            'nomor_hp' => $r->PhoneNumber,
            'jumlah_trip' => $r->NumberOfTrips,
            'status' => $r->Status,
            'catatan_spv' => $spvApproval?->Comments,
            'catatan_atasan' => $atasanApproval?->Comments,
            'catatan_divisi' => $divisiApproval?->Comments,
            'catatan_direksi' => $direksiApproval?->Comments,
            'decided_by_spv_name' => $spvApproval?->approver?->FullName,
            'decided_by_name' => $atasanApproval?->approver?->FullName,
            'decided_by_divisi_name' => $divisiApproval?->approver?->FullName,
            'decided_by_direksi_name' => $direksiApproval?->approver?->FullName,
            'mobil_nomor' => $vehicle?->VehicleNumber,
            'mobil_supir' => $vehicle?->DriverName,
            'mobil_km' => $vehicle?->DepartureKm,
            'submitted_at' => $r->CreatedAt,
            'trips' => $r->trips->map(function ($t) {
                return [
                    'id' => $t->Id,
                    'request_id' => $t->RequestId,
                    'trip_number' => $t->Sequence,
                    'kode_registrasi' => $t->VehicleNumber,
                    'registered_at' => $t->DepartureDate,
                    'completed_at' => $t->ReturnDate,
                ];
            }),
        ];
    }
}
