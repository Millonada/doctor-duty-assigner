<?php
namespace AngelMillan\DoctorDutyAssigner\Services;

use Illuminate\Support\Facades\DB;
use AngelMillan\DoctorDutyAssigner\Models\hosimd;
use Illuminate\Support\Facades\Log;
use PgSql\Lob;

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

    public static function getSpecialistsForCompany($specialtyKey,$user,$tenant)
    {
        // esta funcion funciona para assist y sima
        if($tenant == 3){
            // solo para assist
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
       // el resto de repos sima
        return $user::select([
            'id_usua as id',
            'id_usua as Medico',
            'espe.id_espec as desc_esp',
            'papell AS NombreMedico',
            'tel AS Celular',
            'espe.espec'
        ])
        ->leftJoin('cat_espec as espe', 'reg_usuarios.id_espec', '=', 'espe.id_espec')
        ->where('espe.id_espec', $specialtyKey)
        ->orderBy('NombreMedico', 'ASC')
        ->get();
    }
}
