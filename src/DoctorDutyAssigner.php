<?php
namespace AngelMillan\DoctorDutyAssigner;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Collection;
use App\Models\DoctorShift;
use App\Models\User;
use Illuminate\Http\Request;

class DoctorDutyAssigner
{
    public static function assign(Request $request, int $tenant = 2)
    {
        $data = Validator::make($request->all(), [
            'specialty_id' => 'required',
            'insurance_id' => 'nullable|integer',
            'medicsCancel' => 'nullable|array',
        ])->validate();

        $today = now()->format('Y-m-d');
        $cancelledIds = collect($data['medicsCancel'] ?? []);

        // Turno de hoy
        $doctorToday = DoctorShift::where('tenant_id', $tenant)
            ->where('speciality_code', $data['specialty_id'])
            ->whereDate('date', $today)
            ->select([
                'doctor_phone as Celular',
                'doctor_id as Medico',
                'doctor_name as NombreMedico',
                'speciality_name as desc_esp',
                'date',
                'doctor_id'
            ])
            ->first();

        $isValid = $doctorToday &&
            !$cancelledIds->contains($doctorToday->Medico) &&
            !DB::connection('tenant')->table('sar_company_has_doctor')
                ->where('doctor_id', $doctorToday->Medico)
                ->where('company_key', $data['insurance_id'])
                ->exists();

        if ($isValid) {
            $user = User::where('id_usua', $doctorToday->Medico)->first();
            $doctorToday->Celular = $user->tel ?? null;

            return [
                'success' => true,
                'message' => 'Médico de guardia asignado.',
                'doctor' => $doctorToday,
                'assignment_type' => 'ROL'
            ];
        }

        // Plan B: siguiente turno
        $allShifts = DoctorShift::where('tenant_id', $tenant)
            ->where('speciality_code', $data['specialty_id'])
            ->orderBy('date')
            ->select([
                'doctor_phone as Celular',
                'doctor_id as Medico',
                'doctor_name as NombreMedico',
                'speciality_name as desc_esp',
                'date',
                'doctor_id'
            ])
            ->get();

        $indexToday = $allShifts->search(fn($shift) => $shift->date === $today);
        $rotatedShifts = $allShifts->slice($indexToday + 1)->concat($allShifts->slice(0, $indexToday + 1));

        $nextDoctor = $rotatedShifts->first(function ($shift) use ($cancelledIds, $data) {
            return !$cancelledIds->contains($shift->Medico) &&
                !DB::table('sar_company_has_doctor')
                    ->where('doctor_id', $shift->Medico)
                    ->where('company_key', $data['insurance_id'])
                    ->exists();
        });

        if ($nextDoctor) {
            $user = User::where('id_usua', $nextDoctor->Medico)->first();
            $nextDoctor->Celular = $user->tel ?? null;

            return [
                'success' => true,
                'message' => 'Se ha asignado Médico alterno.',
                'doctor' => $nextDoctor,
                'assignment_type' => 'ROL'
            ];
        }

        return [
            'error' => true,
            'message' => 'No hay médicos disponibles para esta especialidad.'
        ];
    }
}
