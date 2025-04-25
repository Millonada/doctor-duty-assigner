<?php
namespace AngelMillan\DoctorDutyAssigner\Services;

use Illuminate\Support\Facades\DB;
use AngelMillan\DoctorDutyAssigner\Models\hosimd;

class SpecialistResolver
{
    public static function getRecommendedSpecialist($keyDoctor,$user)
    {
        // Cuando paciente viene ya con medico se extraer la informacion de ese medico
        return $user::select([
            'id',
            'Medico',
            'Especialidad1 as esp',
            DB::raw("CONCAT(RTRIM(hosimd.Nombre), ' ', RTRIM(hosimd.ApellidoPaterno), ' ', RTRIM(hosimd.ApellidoMaterno)) AS NombreMedico"),
            DB::raw("RTRIM(hosimd.Celular) AS Celular"),
            'espe.desc_esp'
        ])
        ->leftJoin('hosesp as espe', 'hosimd.Especialidad1', '=', 'espe.esp')
        ->where('Medico', $keyDoctor)
        ->orderBy('NombreMedico', 'ASC')
        ->get();
    }

    public static function getSpecialistsForCompany($specialtyKey,$user)
    {
        return $user::select([
            'id',
            'Medico',
            'Especialidad1 as esp',
            DB::raw("CONCAT(RTRIM(hosimd.Nombre), ' ', RTRIM(hosimd.ApellidoPaterno), ' ', RTRIM(hosimd.ApellidoMaterno)) AS NombreMedico"),
            DB::raw("RTRIM(hosimd.Celular) AS Celular"),
            'espe.desc_esp'
        ])
        ->leftJoin('hosesp as espe', 'hosimd.Especialidad1', '=', 'espe.esp')
        ->where('Especialidad1', $specialtyKey)
        ->orderBy('NombreMedico', 'ASC')
        ->get();
    }
}
