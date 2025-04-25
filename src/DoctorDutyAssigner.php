<?php
namespace AngelMillan\DoctorDutyAssigner;


use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;


class DoctorDutyAssigner
{
    public static function assign(Request $request, int $tenant,string $doctorShiftModel = DoctorShift::class, string $userModel = User::class)
    {
        $DoctorShift = new $doctorShiftModel;
        $User = new $userModel;

        $data = Validator::make($request->all(), [
            'specialty_id' => 'required',
            'insurance_id' => 'nullable|integer',
            'medicsCancel' => 'nullable|array',
        ])->validate();

        $today = now()->format('Y-m-d');
        $cancelledIds = collect($data['medicsCancel'] ?? []);

        // Turno de hoy
        $doctorToday = $DoctorShift::where('tenant_id', $tenant)
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
            // validamos, si es erp = 3, tiene diferente estructura en su tabla
            if($tenant != 3){
                $user = $User::where('id_usua', $doctorToday->Medico)->first();
                $doctorToday->Celular = $user->tel ?? null;

                return [
                    'success' => true,
                    'message' => 'Médico de guardia asignado.',
                    'doctor' => $doctorToday,
                    'assignment_type' => 'ROL'
                ];
            }else{
                $user = $User::where('Medico', $doctorToday->Medico)->first();
                $doctorToday->Celular = $user->Celular ?? null;

                return [
                    'success' => true,
                    'message' => 'Médico de guardia asignado.',
                    'doctor' => $doctorToday,
                    'assignment_type' => 'ROL'
                ];
            }
            
        }

        // Plan B: siguiente turno
        $allShifts = $DoctorShift::where('tenant_id', $tenant)
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
            if($tenant != 3){
                $user = $User::where('id_usua', $doctorToday->Medico)->first();
                $doctorToday->Celular = $user->tel ?? null;

                return [
                    'success' => true,
                    'message' => 'Médico de guardia asignado.',
                    'doctor' => $doctorToday,
                    'assignment_type' => 'ROL'
                ];
            }else{
                $user = $User::where('Medico', $doctorToday->Medico)->first();
                $doctorToday->Celular = $user->Celular ?? null;

                return [
                    'success' => true,
                    'message' => 'Médico de guardia asignado.',
                    'doctor' => $doctorToday,
                    'assignment_type' => 'ROL'
                ];
            }
        }

        return [
            'error' => true,
            'message' => 'No hay médicos disponibles para esta especialidad.'
        ];
    }
}
