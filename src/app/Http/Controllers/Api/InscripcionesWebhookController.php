<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use App\Jobs\AssignCostoSemestralFromInscripcion;
use App\Models\Inscripcion;
use App\Models\Estudiante;
use App\Models\Pensum;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

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

		// Log entrada y token
		Log::info('HYDRA webhook hit', [
			'ip' => $request->ip(),
			'token_present' => (bool) $request->header('X-SGA-Token'),
		]);

		try {
			$validated = $request->validate([
			// Inscripción
			'cod_inscrip' => 'required|integer',
			'cod_pensum' => 'required|string',
			'cod_curso' => 'required|string',
			'gestion' => 'required|string',
			'tipo_inscripcion' => 'required|string',
			'cod_ceta' => 'required|integer',

			// Datos opcionales de inscripción
			'tipo_estudiante' => 'sometimes|string|nullable',
			'fecha_inscripcion' => 'sometimes|string|nullable',
			'carrera' => 'sometimes|string|nullable',
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
		} catch (\Illuminate\Validation\ValidationException $ex) {
			Log::error('HYDRA webhook validation failed', [
				'errors' => $ex->errors(),
				'input' => $request->all(),
			]);
			return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $ex->errors()], 422);
		}

		// Persistencia atómica y logging
		DB::beginTransaction();
		try {
			// 1) Upsert Estudiante (respetando columnas existentes)
			$est = Estudiante::firstOrNew(['cod_ceta' => (int) $validated['cod_ceta']]);
			foreach ([
				'ci','nombres','ap_paterno','ap_materno','email','cod_pensum','estado'
			] as $k) {
				if ($request->has($k) && Schema::hasColumn('estudiantes', $k)) {
					$est->{$k} = $validated[$k] ?? null;
				}
			}
			// Si existe la columna cod_pensum, forzar el último valor recibido
			if (Schema::hasColumn('estudiantes', 'cod_pensum')) {
				$est->cod_pensum = $validated['cod_pensum'];
			}
			$est->save();

			// 1.b) Registrar copia del CI en doc_presentados (si hay CI y existe la tabla)
			try {
				if (Schema::hasTable('doc_presentados') && !empty($validated['cod_ceta'])) {
					$numeroDoc = $request->has('ci') ? (string) ($validated['ci'] ?? '') : '';
					if ($numeroDoc !== '') {
						$nombreDoc = 'Carnet de identidad';
						$procedencia = 'sin informacion';
						$entregado = 1;
						$exists = DB::table('doc_presentados')
							->where('cod_ceta', (int) $validated['cod_ceta'])
							->where('nombre_doc', $nombreDoc)
							->where('numero_doc', $numeroDoc)
							->exists();
						if (!$exists) {
							// Verificar si id_doc_presentados es AUTO_INCREMENT
							$isAuto = false;
							try {
								$meta = DB::select("SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'doc_presentados' AND COLUMN_NAME = 'id_doc_presentados' LIMIT 1");
								if (!empty($meta) && isset($meta[0]->EXTRA)) {
									$isAuto = str_contains(strtolower($meta[0]->EXTRA), 'auto_increment');
								}
							} catch (\Throwable $e) { /* metadata opcional */ }

							$data = [
								'cod_ceta' => (int) $validated['cod_ceta'],
								'numero_doc' => $numeroDoc,
								'nombre_doc' => $nombreDoc,
								'procedencia' => $procedencia,
								'entregado' => $entregado,
							];

							$log = [
								'cod_ceta' => (int) $validated['cod_ceta'],
								'numero_doc' => $numeroDoc,
								'nombre_doc' => $nombreDoc,
							];

							if (!$isAuto) {
								// Generar id_doc_presentados incremental GLOBAL con lock para evitar colisiones concurrentes
								try {
									DB::select("SELECT GET_LOCK('doc_presentados_ai', 5) as l");
									$nextId = (int) (DB::table('doc_presentados')->max('id_doc_presentados') ?? 0);
									$nextId = $nextId + 1;
									$data['id_doc_presentados'] = $nextId;
									$log['id_doc_presentados'] = $nextId;
									DB::table('doc_presentados')->insert($data);
								} finally {
									DB::select("SELECT RELEASE_LOCK('doc_presentados_ai') as r");
								}
							}
							else {
								DB::table('doc_presentados')->insert($data);
							}
							Log::info('HYDRA doc_presentados inserted', $log);
						}
					}
				}
			} catch (\Throwable $e) {
				Log::error('HYDRA doc_presentados insert failed', [
					'error' => $e->getMessage(),
				]);
			}

			// 2) upsert Inscripcion con los datos relevantes (clave por source_cod_inscrip [+ carrera])
			$sgacode = (int) $validated['cod_inscrip'];
			// Preparar mapping de pensum y carrera
			$mapped = null;
			$pensumLocal = $validated['cod_pensum'];
			try {
				$mapped = DB::table('pensum_map')
					->where('cod_pensum_sga', $validated['cod_pensum'])
					->first();
			} catch (\Throwable $e) { /* tabla opcional */ }
			$carreraVal = null;
			// Preferir derivar desde pensums -> carrera
			try {
				$pensumLocal = ($mapped && isset($mapped->cod_pensum_local)) ? $mapped->cod_pensum_local : $validated['cod_pensum'];
				$pen = Pensum::with('carrera')->find($pensumLocal);
				if ($pen) {
					// Usar el codigo_carrera para guardar en inscripciones.carrera
					$carreraVal = $pen->codigo_carrera ?? null;
				}
			} catch (\Throwable $e) { /* si falla, caer a fallback */ }
			if (is_null($carreraVal)) {
				if ($mapped && isset($mapped->carrera)) {
					$carreraVal = $mapped->carrera;
				} elseif ($request->has('carrera')) {
					$carreraVal = $validated['carrera'] ?? null;
				}
			}

			// Buscar por source_cod_inscrip (y carrera si existe) para no forzar PK externo
			$insQuery = Inscripcion::query();
			if (Schema::hasColumn('inscripciones', 'source_cod_inscrip')) {
				$insQuery->where('source_cod_inscrip', $sgacode);
				if (Schema::hasColumn('inscripciones', 'carrera') && !is_null($carreraVal)) {
					$insQuery->where('carrera', $carreraVal);
				}
			}
			$ins = $insQuery->first();
			if (!$ins) {
				$ins = new Inscripcion();
			}

			$ins->cod_ceta = (int) $validated['cod_ceta'];
			// Guardar cod_pensum SGA
			if (Schema::hasColumn('inscripciones', 'cod_pensum_sga')) {
				$ins->cod_pensum_sga = $validated['cod_pensum'];
			}
			// Mapear a local si existe mapping
			$ins->cod_pensum = $pensumLocal ?: $validated['cod_pensum'];
			if (Schema::hasColumn('inscripciones', 'carrera') && !is_null($carreraVal)) {
				$ins->carrera = $carreraVal;
			}
			$ins->cod_curso = $validated['cod_curso'];
			$ins->gestion = $validated['gestion'];
			$ins->tipo_inscripcion = $validated['tipo_inscripcion'];
			// tipo_estudiante desde SGA si llega
			if ($request->has('tipo_estudiante') && Schema::hasColumn('inscripciones', 'tipo_estudiante')) {
				$ins->tipo_estudiante = strtoupper($validated['tipo_estudiante']);
			}
			// Traza del id de SGA
			if (Schema::hasColumn('inscripciones', 'source_cod_inscrip')) {
				$ins->source_cod_inscrip = $sgacode;
			}
			// id_usuario por defecto si la columna existe y no se ha seteado
			if (Schema::hasColumn('inscripciones', 'id_usuario') && empty($ins->id_usuario)) {
				$ins->id_usuario = (int) (env('HYDRA_DEFAULT_USER_ID', 1));
			}
			// Log de derivación antes de normalizar fecha
			Log::info('HYDRA webhook derived', [
				'pensumLocal' => $pensumLocal,
				'carreraVal' => $carreraVal,
				'source_cod_inscrip' => $sgacode,
			]);

			// Normalizar fecha antes de guardar
			if (Schema::hasColumn('inscripciones', 'fecha_inscripcion')) {
				if ($request->has('fecha_inscripcion')) {
					try {
						$ins->fecha_inscripcion = Carbon::parse($validated['fecha_inscripcion'])->toDateString();
					} catch (\Throwable $e) {
						$ins->fecha_inscripcion = Carbon::now()->toDateString();
					}
				} else {
					// si no llega, usar fecha actual para evitar nulos
					$ins->fecha_inscripcion = Carbon::now()->toDateString();
				}
			}
			foreach (['nro_materia','nro_materia_aprob'] as $k) {
				if ($request->has($k) && Schema::hasColumn('inscripciones', $k)) {
					$ins->{$k} = $validated[$k] ?? null;
				}
			}
			// Log final previo a guardar
			Log::info('HYDRA webhook saving inscripcion', [
				'cod_ceta' => $ins->cod_ceta,
				'cod_pensum' => $ins->cod_pensum,
				'cod_pensum_sga' => $ins->cod_pensum_sga ?? null,
				'carrera' => $ins->carrera ?? null,
				'cod_curso' => $ins->cod_curso,
				'gestion' => $ins->gestion,
				'tipo_inscripcion' => $ins->tipo_inscripcion,
				'tipo_estudiante' => $ins->tipo_estudiante ?? null,
				'fecha_inscripcion' => $ins->fecha_inscripcion ?? null,
				'source_cod_inscrip' => $ins->source_cod_inscrip ?? null,
			]);
			$ins->save();

			DB::commit();

			// Log de confirmación de persistencia
			Log::info('HYDRA webhook persisted', [
				'db' => DB::getDatabaseName(),
				'cod_inscrip' => $ins->cod_inscrip,
				'cod_ceta' => $ins->cod_ceta,
				'cod_pensum' => $ins->cod_pensum,
				'cod_curso' => $ins->cod_curso,
				'gestion' => $ins->gestion,
				'tipo_inscripcion' => $ins->tipo_inscripcion,
			]);
		} catch (\Throwable $e) {
			DB::rollBack();
			Log::error('HYDRA webhook DB persist failed', [
				'message' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
			return response()->json(['success' => false, 'message' => 'DB error'], 500);
		}

		// 3) Despachar Job para asignación de costo (no crítico)
		try {
			AssignCostoSemestralFromInscripcion::dispatch([
				// Usar el ID local para relaciones internas
				'cod_inscrip' => (string) ($ins->cod_inscrip ?? ''),
				'cod_pensum' => $ins->cod_pensum ?? $validated['cod_pensum'],
				'cod_curso' => $ins->cod_curso ?? $validated['cod_curso'],
				'gestion' => $ins->gestion ?? $validated['gestion'],
				'tipo_inscripcion' => $ins->tipo_inscripcion ?? $validated['tipo_inscripcion'],
				// Opcional: enviar también el source para trazabilidad
				'source_cod_inscrip' => (string) $sgacode,
			])->onQueue('inscripciones');
		} catch (\Throwable $e) {
			Log::error('Queue dispatch failed (inscripciones)', [
				'message' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
		}

		return response()->json(['success' => true]);
	}
}
