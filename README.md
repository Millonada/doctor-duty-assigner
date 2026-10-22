# doctor-duty-assigner

Sugiere el médico especialista para un paciente de urgencias.

```php
use AngelMillan\DoctorDutyAssigner\DoctorDutyAssigner;

$result = DoctorDutyAssigner::assign($request, [
    'tenant' => $tenantId,
    'doctorShiftModel' => DoctorShift::class, // tabla doctor_shifts
    'userModel' => User::class,
    'redInsuranceKeys' => $redKeys,
]);
```

## Tipos de asignación

- **CM**: el paciente ya viene con médico (`medic_guard`).
- **RED**: el seguro está en `redInsuranceKeys`; se devuelve la lista de especialistas.
- **ROL**: calendario de guardias (`doctor_shifts`). Se descarta a los médicos de `medicsCancel`
  (ya cancelaron o no llegaron con el paciente) y a los vetados por la aseguradora, en este orden:
  1. `TITULAR`: titular de la guardia de hoy (`is_substitute = false`).
  2. `SUPLENTE`: suplente de esa guardia (`is_substitute = true`, `substitute_for_id` = id del titular).
  3. `SIGUIENTE`: titulares de las guardias de fechas futuras, por fecha.
  4. `LIBRE`: si no queda nadie, `doctor` es la lista de especialistas de la especialidad
     (`free_selection = true`) para que el personal elija.

`assignment_role` indica cuál de estos casos se aplicó. Solo se devuelve `error` si la especialidad
no tiene ningún especialista disponible.
