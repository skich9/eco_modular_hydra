<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Carrera;
use App\Models\Pensum;
use App\Models\Materia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CarreraController extends Controller
{
	/**
	 * Muestra la vista principal de la carrera con sus pensums y materias
	 */
	public function show($codigo)
	{
		try {
			// Obtener la carrera
			$carrera = Carrera::where('codigo_carrera', $codigo)->firstOrFail();
			
			// Obtener los pensums relacionados con la carrera
			$pensums = Pensum::where('codigo_carrera', $codigo)->get();
			
			return view('academico.carrera.show', compact('carrera', 'pensums'));
		} catch (\Exception $e) {
			Log::error('Error al mostrar carrera: ' . $e->getMessage());
			return redirect()->route('dashboard')->with('error', 'No se encontró la carrera solicitada');
		}
	}
	
	/**
	 * API: Obtener todos los pensums de una carrera
	 */
	public function getPensums($codigoCarrera)
	{
		try {
			$pensums = Pensum::where('codigo_carrera', $codigoCarrera)->get();
			return response()->json([
				'success' => true,
				'data' => $pensums
			]);
		} catch (\Exception $e) {
			Log::error('Error al obtener pensums de la carrera: ' . $e->getMessage());
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener pensums de la carrera',
				'error' => $e->getMessage()
			], 500);
		}
	}
	
	/**
	 * API: Obtener todas las materias de un pensum
	 */
	public function getMaterias($codPensum)
	{
		try {
			$materias = Materia::where('cod_pensum', $codPensum)->get();
			return response()->json([
				'success' => true,
				'data' => $materias
			]);
		} catch (\Exception $e) {
			Log::error('Error al obtener materias del pensum: ' . $e->getMessage());
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener materias del pensum',
				'error' => $e->getMessage()
			], 500);
		}
	}
	
	/**
	 * API: Almacenar una nueva materia en el pensum
	 */
	public function storeMateria(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'sigla_materia' => 'required|string',
				'cod_pensum' => 'required|string',
				'nombre_materia' => 'required|string',
				'nro_creditos' => 'required|integer',
			]);
			
			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Error de validación',
					'errors' => $validator->errors()
				], 422);
			}
			
			// Verificar si ya existe la materia con la misma sigla y pensum
			$existente = Materia::where('sigla_materia', $request->sigla_materia)
				->where('cod_pensum', $request->cod_pensum)
				->first();
				
			if ($existente) {
				return response()->json([
					'success' => false,
					'message' => 'Ya existe una materia con esta sigla y pensum',
				], 422);
			}
			
			$materia = new Materia([
				'sigla_materia' => $request->sigla_materia,
				'cod_pensum' => $request->cod_pensum,
				'nombre_materia' => $request->nombre_materia,
				'nombre_material_oficial' => $request->nombre_material_oficial ?? $request->nombre_materia,
				'estado' => $request->estado ?? 1,
				'orden' => $request->orden ?? 0,
				'descripcion' => $request->descripcion,
				'id_parametro_economico' => $request->id_parametro_economico ?? null,
				'nro_creditos' => $request->nro_creditos,
			]);
			
			$materia->save();
			
			return response()->json([
				'success' => true,
				'message' => 'Materia creada correctamente',
				'data' => $materia
			], 201);
		} catch (\Exception $e) {
			Log::error('Error al crear materia: ' . $e->getMessage());
			return response()->json([
				'success' => false,
				'message' => 'Error al crear materia',
				'error' => $e->getMessage()
			], 500);
		}
	}
	
	/**
	 * API: Actualizar una materia específica
	 */
	public function updateMateria(Request $request, $sigla, $codPensum)
	{
		try {
			$validator = Validator::make($request->all(), [
				'nombre_materia' => 'required|string',
				'nro_creditos' => 'required|integer',
			]);
			
			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Error de validación',
					'errors' => $validator->errors()
				], 422);
			}
			
			$materia = Materia::where('sigla_materia', $sigla)
				->where('cod_pensum', $codPensum)
				->firstOrFail();
				
			$materia->update([
				'nombre_materia' => $request->nombre_materia,
				'nombre_material_oficial' => $request->nombre_material_oficial ?? $materia->nombre_material_oficial,
				'estado' => $request->estado ?? $materia->estado,
				'orden' => $request->orden ?? $materia->orden,
				'descripcion' => $request->descripcion,
				'id_parametro_economico' => $request->id_parametro_economico ?? $materia->id_parametro_economico,
				'nro_creditos' => $request->nro_creditos,
			]);
			
			return response()->json([
				'success' => true,
				'message' => 'Materia actualizada correctamente',
				'data' => $materia
			]);
		} catch (\Exception $e) {
			Log::error('Error al actualizar materia: ' . $e->getMessage());
			return response()->json([
				'success' => false,
				'message' => 'Error al actualizar materia',
				'error' => $e->getMessage()
			], 500);
		}
	}
	
	/**
	 * API: Eliminar una materia específica
	 */
	public function destroyMateria($sigla, $codPensum)
	{
		try {
			$materia = Materia::where('sigla_materia', $sigla)
				->where('cod_pensum', $codPensum)
				->firstOrFail();
				
			$materia->delete();
			
			return response()->json([
				'success' => true,
				'message' => 'Materia eliminada correctamente'
			]);
		} catch (\Exception $e) {
			Log::error('Error al eliminar materia: ' . $e->getMessage());
			return response()->json([
				'success' => false,
				'message' => 'Error al eliminar materia',
				'error' => $e->getMessage()
			], 500);
		}
	}
	
	/**
	 * API: Cambiar el estado de una materia
	 */
	public function toggleStatusMateria($sigla, $codPensum)
	{
		try {
			$materia = Materia::where('sigla_materia', $sigla)
				->where('cod_pensum', $codPensum)
				->firstOrFail();
				
			$materia->estado = !$materia->estado;
			$materia->save();
			
			return response()->json([
				'success' => true,
				'message' => 'Estado actualizado correctamente',
				'data' => $materia
			]);
		} catch (\Exception $e) {
			Log::error('Error al cambiar estado de la materia: ' . $e->getMessage());
			return response()->json([
				'success' => false,
				'message' => 'Error al cambiar estado',
				'error' => $e->getMessage()
			], 500);
		}
	}
}
