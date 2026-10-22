<?php
namespace AngelMillan\DoctorDutyAssigner;


use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use AngelMillan\DoctorDutyAssigner\Services\SpecialistResolver;


class DoctorDutyAssigner
{
    // Rol del médico sugerido por el calendario (ROL)
    const ROLE_TITULAR = 'TITULAR';     // titular de la guardia de hoy
    const ROLE_SUPLENTE = 'SUPLENTE';   // suplente de la guardia de hoy
    const ROLE_SIGUIENTE = 'SIGUIENTE'; // titular de la siguiente guardia del calendario
    const ROLE_LIBRE = 'LIBRE';         // nadie disponible en el calendario: el personal elige

    const SHIFT_COLUMNS = [
        'id',
        'doctor_phone as Celular',
        'doctor_id as Medico', // temporalmente sera el codigo del medico
        'doctor_name as NombreMedico',
        'speciality_name as desc_esp',
        'date',
        'doctor_code as medicoId', // temporalmente sera el id
    ];

    public static function assign(Request $request, array $config = [])
    {
        $tenant = $config['tenant'] ?? 1;
        $doctorShiftModel = $config['doctorShiftModel'] ?? null;
        $userModel = $config['userModel'] ?? null;
        $redInsuranceKeys = $config['redInsuranceKeys'] ?? [];
        $withDoctorField = $config['withDoctorField'] ?? 'medic_guard';
        $withDoctorKeyField = $config['withDoctorKeyField'] ?? 'medic_guard_key';

        if (!$doctorShiftModel || !$userModel) {
            throw new InvalidArgumentException('Se deben configurar doctorShiftModel y userModel.');
        }

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
        if (in_array($data['insurance_key'] ?? $data['insurance_id'] ?? null, $redInsuranceKeys)) {
            $doctor = SpecialistResolver::getSpecialistsForCompany($data['specialty_id'],$User,$tenant);
            return [
                'success' => true,
                'message' => 'El paciente tiene seguro, se debe contactar a la aseguradora',
                'doctor' => $doctor,
                'assignment_type' => 'RED'
            ];
        }

        // Caso 3: ROL
        // medicsCancel son los médicos que ya cancelaron o no llegaron con este paciente
        $cancelledIds = collect($data['medicsCancel'] ?? [])->filter()->map(fn ($id) => (string) $id);
        $insuranceId = $data['insurance_id'] ?? null;

        // Orden: titular de hoy -> suplente de esa guardia -> titulares de las siguientes guardias
        $candidates = self::dutyCandidates($DoctorShift, $tenant, $data['specialty_id'], now()->format('Y-m-d'));

        foreach ($candidates as [$shift, $role]) {
            $user = self::getUserData($User, $shift->Medico, $tenant);
            $medId = $user?->id_usua ?? $user?->id ?? null;

            if (self::isCancelled($cancelledIds, $shift->Medico, $medId) || self::isBanned($medId, $insuranceId)) {
                continue;
            }

            // se obtienen los datos que faltan, esto se debe correguir
            $shift->Celular = $user?->tel ?? $user?->Celular ?? $shift->Celular;
            $shift->medicoId = $medId;

            return [
                'success' => true,
                'message' => self::roleMessage($role),
                'doctor' => $shift,
                'assignment_type' => 'ROL',
                'assignment_role' => $role,
            ];
        }

        // Nadie disponible en el calendario: el personal elige a cualquier especialista de la especialidad
        $doctors = SpecialistResolver::getSpecialistsForCompany($data['specialty_id'], $User, $tenant)
            ->reject(fn ($doctor) => self::isCancelled($cancelledIds, $doctor->Medico, $doctor->id)
                || self::isBanned($doctor->id, $insuranceId))
            ->each(function ($doctor) {
                // sima trae el código en desc_esp y el nombre en espec; assist solo trae desc_esp
                $doctor->desc_esp = $doctor->espec ?? $doctor->desc_esp;
                $doctor->espec = $doctor->desc_esp;
            })
            ->values();

        if ($doctors->isEmpty()) {
            return [
                'error' => true,
                'message' => 'No hay médicos disponibles para esta especialidad.'
            ];
        }

        return [
            'success' => true,
            'message' => 'No hay médico de guardia disponible; seleccione un especialista.',
            'doctor' => $doctors,
            'assignment_type' => 'ROL',
            'assignment_role' => self::ROLE_LIBRE,
            'free_selection' => true,
        ];
    }

    /**
     * Guardias candidatas en orden de prioridad, como pares [guardia, rol].
     * El suplente se vincula a su titular por substitute_for_id; las siguientes guardias
     * son solo fechas futuras (no se regresa a guardias pasadas).
     */
    protected static function dutyCandidates($DoctorShift, $tenant, $specialtyId, string $today): Collection
    {
        $base = fn () => $DoctorShift::where('tenant_id', $tenant)
            ->where('speciality_code', $specialtyId)
            ->select(self::SHIFT_COLUMNS);

        $candidates = collect();

        $titular = $base()
            ->whereDate('date', $today)
            ->where('is_substitute', false)
            ->orderBy('id')
            ->first();

        if ($titular) {
            $candidates->push([$titular, self::ROLE_TITULAR]);

            $suplente = $base()
                ->whereDate('date', $today)
                ->where('is_substitute', true)
                ->where('substitute_for_id', $titular->id)
                ->orderBy('id')
                ->first();

            if ($suplente) {
                $candidates->push([$suplente, self::ROLE_SUPLENTE]);
            }
        }

        $base()
            ->whereDate('date', '>', $today)
            ->where('is_substitute', false)
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->each(fn ($shift) => $candidates->push([$shift, self::ROLE_SIGUIENTE]));

        return $candidates;
    }

    // Se compara contra la clave del calendario y contra el id de usuario: según el hospital puede venir cualquiera
    protected static function isCancelled(Collection $cancelledIds, $doctorKey, $userId): bool
    {
        return ($doctorKey !== null && $cancelledIds->contains((string) $doctorKey))
            || ($userId !== null && $cancelledIds->contains((string) $userId));
    }

    // valida si el medico esta vetado del seguro
    protected static function isBanned($userId, $insuranceId): bool
    {
        if ($userId === null || $insuranceId === null) {
            return false;
        }

        return DB::table('sar_banned_insurance_doctors')
            ->where('doctor_id', $userId)
            ->where('company_key', $insuranceId)
            ->exists();
    }

    protected static function roleMessage(string $role): string
    {
        return match ($role) {
            self::ROLE_SUPLENTE => 'El titular de la guardia no está disponible; se asigna al suplente.',
            self::ROLE_SIGUIENTE => 'Se asigna al siguiente médico del calendario.',
            default => 'Médico de guardia asignado.',
        };
    }

    protected static function getUserData($UserModel, $doctorId, $tenant)
    {
        if ($tenant == 3) {
            return $UserModel::where('Medico', $doctorId)->first();
        }

        return $UserModel::where('id_usua', $doctorId)->first();
    }
}
