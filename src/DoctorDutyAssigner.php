<?php
namespace AngelMillan\DoctorDutyAssigner;


use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use AngelMillan\DoctorDutyAssigner\Services\SpecialistResolver;


class DoctorDutyAssigner
{
    public static function assign(Request $request, array $config = [])
    {
        $tenant = $config['tenant'] ?? 1;
        $doctorShiftModel = $config['doctorShiftModel'] ?? DoctorShift::class;
        $userModel = $config['userModel'] ?? User::class;
        $redInsuranceKeys = $config['redInsuranceKeys'] ?? [];
        $withDoctorField = $config['withDoctorField'] ?? 'medic_guard';
        $withDoctorKeyField = $config['withDoctorKeyField'] ?? 'medic_guard_key';

        $DoctorShift = new $doctorShiftModel;
        $User = new $userModel;

        $data = Validator::make($request->all(), [
            'specialty_id' => 'required',
            'insurance_id' => 'nullable|integer',
            'insurance_key' => 'nullable',
            'medicsCancel' => 'nullable|array',
            $withDoctorField => 'nullable|string',
            $withDoctorKeyField => 'nullable|string',
        ])->validate();

        // Caso 1: Con Médico asignado
        if (!empty($data[$withDoctorField]) && $data[$withDoctorField] !== '. . MEDICO URGENCIAS') {
            $doctor = SpecialistResolver::getRecommendedSpecialist($data[$withDoctorKeyField],$User);
            return [
                'success' => true,
                'message' => 'El paciente ya viene con médico asignado',
                'doctor' => $doctor,
                'assignment_type' => 'CM'
            ];
        }

        // Caso 2: RED por aseguradora
        if (in_array($data['insurance_key'] ?? $data['insurance_id'], $redInsuranceKeys)) {
            $doctor = SpecialistResolver::getSpecialistsForCompany($data['specialty_id'],$User,$tenant);
            return [
                'success' => true,
                'message' => 'El paciente tiene seguro, se debe contactar a la aseguradora',
                'doctor' => $doctor,
                'assignment_type' => 'RED'
            ];
        }

        // Caso 3: ROL
        $today = now()->format('Y-m-d');
        $cancelledIds = collect($data['medicsCancel'] ?? []);

        // se busca al doctor de guardia
        $doctorToday = $DoctorShift::where('tenant_id', $tenant)
            ->where('speciality_code', $data['specialty_id'])
            ->whereDate('date', $today)
            ->select([
                'doctor_phone as Celular',
                'doctor_id as Medico', // temporalmente sera el codigo del medico
                'doctor_name as NombreMedico',
                'speciality_name as desc_esp',
                'date',
                'doctor_code as medicoId' // temporalmente sera el id 
            ])
            ->first();
           // se obtienen los datos que faltan, esto se debe correguir
            $user = self::getUserData($User, $doctorToday->Medico, $tenant);
            $doctorToday->Celular = $user?->tel ?? $user?->Celular ?? null;
            $doctorToday->medicoId = $user?->id_usua ?? $user?->id ?? null;
            
            // esta funcion valida si el medico del dia esta vetado del seguro
        $isValid = $doctorToday &&
            !$cancelledIds->contains($doctorToday->Medico) &&
            !DB::table('sar_banned_insurance_doctors')
                ->where('doctor_id', $doctorToday->medicoId)
                ->where('company_key', $data['insurance_id'])
                ->exists();

        if ($isValid) {
        // si no esta vetado se retorna

            return [
                'success' => true,
                'message' => 'Médico de guardia asignado.',
                'doctor' => $doctorToday,
                'assignment_type' => 'ROL'
            ];
        }

        // si el medico esta vetado se obtienen todos los turnos de esa especialidad
        // Siguiente turno (rotación)
        $allShifts = $DoctorShift::where('tenant_id', $tenant)
            ->where('speciality_code', $data['specialty_id'])
            ->select([
                'doctor_phone as Celular',
                'doctor_id as Medico', // temporalmente sera el codigo del medico
                'doctor_name as NombreMedico',
                'speciality_name as desc_esp',
                'date',
                'doctor_code as medicoId' // temporalmente sera el id 
            ])
            ->orderBy('date')
            ->get();

        $indexToday = $allShifts->search(fn($shift) => $shift->date === $today);
        $rotatedShifts = $allShifts->slice($indexToday + 1)->concat($allShifts->slice(0, $indexToday + 1));

        // se obtiene el siguiente en turno
        $nextDoctor = $rotatedShifts->first(function ($shift) use ($cancelledIds, $data,$User,$tenant) {
            $user = self::getUserData($User, $shift->Medico, $tenant);
            $medId = $user?->id_usua ?? $user?->id ?? null;
            return !$cancelledIds->contains($shift->Medico) &&
                !DB::table('sar_banned_insurance_doctors')
                    ->where('doctor_id', $medId)
                    ->where('company_key', $data['insurance_id'])
                    ->exists();
        });

        

        if ($nextDoctor) {
            $user = self::getUserData($User, $nextDoctor->Medico, $tenant);
            $nextDoctor->Celular = $user?->tel ?? $user?->Celular ?? null;
            $nextDoctor->medicoId = $user?->id_usua ?? $user?->id ?? null;

            return [
                'success' => true,
                'message' => 'Médico de guardia asignado.',
                'doctor' => $nextDoctor,
                'assignment_type' => 'ROL'
            ];
        }

        return [
            'error' => true,
            'message' => 'No hay médicos disponibles para esta especialidad.'
        ];
    }
    protected static function getUserData($UserModel, $doctorId, $tenant)
    {
        if ($tenant == 3) {
            return $UserModel::where('Medico', $doctorId)->first();
        }

        return $UserModel::where('id_usua', $doctorId)->first();
    }
}
