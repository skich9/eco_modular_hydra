<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use App\Jobs\AssignCostoSemestralFromInscripcion;
use App\Models\Inscripcion;
use App\Models\Estudiante;

class InscripcionesWebhookController extends Controller
{
	/**
	 * Webhook llamado por el SGA cuando se crea una inscripción.
	 * Valida el payload y despacha un Job en cola para asignar el costo.
	 */
	public function created(Request $request)
	{
		// Seguridad opcional por token
		$expected = env('SGA_WEBHOOK_TOKEN');
		if ($expected && $request->header('X-SGA-Token') !== $expected) {
			return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
		}

		$validated = $request->validate([
			// Inscripción
			'cod_inscrip' => 'required',
			'cod_pensum' => 'required|string',
			'cod_curso' => 'required|string',
			'gestion' => 'required|string',
			'tipo_inscripcion' => 'required|string',
			'cod_ceta' => 'required',

			// Datos opcionales de inscripción
			'tipo_estudiante' => 'sometimes|string|nullable',
			'fecha_inscripcion' => 'sometimes|string|nullable',
			'nro_materia' => 'sometimes|integer|nullable',
			'nro_materia_aprob' => 'sometimes|integer|nullable',

			// Datos opcionales del estudiante
			'ci' => 'sometimes|string|nullable',
			'nombres' => 'sometimes|string|nullable',
			'ap_paterno' => 'sometimes|string|nullable',
			'ap_materno' => 'sometimes|string|nullable',
			'email' => 'sometimes|email|nullable',
			'estado' => 'sometimes|boolean|nullable',
		]);

		// 1) Upsert Estudiante (si es nuevo)
		$est = Estudiante::firstOrNew(['cod_ceta' => $validated['cod_ceta']]);
		foreach (['ci','nombres','ap_paterno','ap_materno','email','cod_pensum','estado'] as $k) {
			if ($request->has($k)) {
				$est->{$k} = $validated[$k] ?? null;
			}
		}
		// Asegurar que mantengamos el pensum más reciente si viene en el payload
		$est->cod_pensum = $validated['cod_pensum'];
		$est->save();

		// 2) Upsert Inscripcion con los datos relevantes
		$ins = Inscripcion::firstOrNew(['cod_inscrip' => (string) $validated['cod_inscrip']]);
		$ins->cod_ceta = $validated['cod_ceta'];
		$ins->cod_pensum = $validated['cod_pensum'];
		$ins->cod_curso = $validated['cod_curso'];
		$ins->gestion = $validated['gestion'];
		$ins->tipo_inscripcion = strtoupper($validated['tipo_inscripcion']);
		foreach (['tipo_estudiante','fecha_inscripcion','nro_materia','nro_materia_aprob'] as $k) {
			if ($request->has($k)) {
				$ins->{$k} = $validated[$k] ?? null;
			}
		}
		$ins->save();

		// 3) Despachar Job para asignación de costo en background
		AssignCostoSemestralFromInscripcion::dispatch([
			'cod_inscrip' => (string) $validated['cod_inscrip'],
			'cod_pensum' => $validated['cod_pensum'],
			'cod_curso' => $validated['cod_curso'],
			'gestion' => $validated['gestion'],
			'tipo_inscripcion' => strtoupper($validated['tipo_inscripcion']),
		])->onQueue('inscripciones');

		return response()->json(['success' => true]);
	}
}
