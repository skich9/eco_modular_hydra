<?php

namespace Database\Seeders;

use App\Models\AsignacionCostos;
use App\Models\CostoSemestral;
use App\Models\Inscripcion;
use App\Models\Cuota;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class AsignacionCostosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        try {
            $this->command->info("=== INICIANDO SEEDER DE ASIGNACION COSTOS ===");
            
            // Verificar si existen inscripciones
            $this->command->info("Consultando inscripciones...");
            
            $countInscripciones = DB::table('inscripciones')->count();
            $this->command->info("Total inscripciones en BD: " . $countInscripciones);
            
            // Contar solo las de gestión 2026 en adelante
            $countInscripciones2026 = DB::table('inscripciones')
                ->where(function($q) {
                    $q->where('gestion', 'LIKE', '%/2026')
                      ->orWhere('gestion', 'LIKE', '%/2027')
                      ->orWhere('gestion', 'LIKE', '%/2028')
                      ->orWhere('gestion', 'LIKE', '%/2029')
                      ->orWhere('gestion', 'LIKE', '%/203%');
                })
                ->count();
            $this->command->info("Inscripciones de gestión 2026 en adelante: " . $countInscripciones2026);
            
            if ($countInscripciones === 0) {
                $this->command->warn("No hay inscripciones en la base de datos.");
                return;
            }
        
        // Verificar si existen costos semestrales
        $countCostos = DB::table('costo_semestral')->count();
        $this->command->info("Total costos semestrales en BD: " . $countCostos);
        
        if ($countCostos === 0) {
            $this->command->info('No hay costos semestrales disponibles para crear asignaciones.');
            return;
        }

        // Configuración de mapeo desde config/hydra_assign.php
        $tipoCostoMap = (array) config('hydra_assign.tipo_costo_map', []);
        $turnoMapCfg = (array) config('hydra_assign.turno_map', []);
        $fallbackAnyTipo = (bool) config('hydra_assign.fallback_any_tipo', false);
        $forbiddenWhenNormal = array_map('strtolower', (array) config('hydra_assign.forbidden_when_normal', []));

        $this->command->info("Configuración cargada:");
        $this->command->info("  - tipo_costo_map: " . json_encode($tipoCostoMap));
        $this->command->info("  - turno_map: " . json_encode($turnoMapCfg));
        $this->command->info("  - fallback_any_tipo: " . ($fallbackAnyTipo ? 'true' : 'false'));

        $created = 0;
        $skipped = 0;
        $noMatch = 0;
        $processed = 0;
        
        $this->command->info("\nProcesando inscripciones en lotes de 100...\n");

        // Procesar en chunks para evitar memory overflow con 80k registros
        // FILTRAR: Solo inscripciones de gestión 2026 en adelante (donde hay costos)
        Inscripcion::select('cod_inscrip', 'cod_pensum', 'gestion', 'cod_curso', 'tipo_inscripcion')
            ->where(function($q) {
                $q->where('gestion', 'LIKE', '%/2026')
                  ->orWhere('gestion', 'LIKE', '%/2027')
                  ->orWhere('gestion', 'LIKE', '%/2028')
                  ->orWhere('gestion', 'LIKE', '%/2029')
                  ->orWhere('gestion', 'LIKE', '%/203%');
            })
            ->chunk(100, function($inscripciones) use (&$created, &$skipped, &$noMatch, &$processed, $tipoCostoMap, $turnoMapCfg, $fallbackAnyTipo, $forbiddenWhenNormal) {
                
                foreach ($inscripciones as $inscripcion) {
                    $processed++;
                    
                    // Logs detallados solo para las primeras 5
                    $verbose = ($processed <= 5);
                    
                    if ($verbose) {
                        $this->command->info("\n=== Inscripción #{$processed} ===");
                        $this->command->info("cod_inscrip: {$inscripcion->cod_inscrip}");
                        $this->command->info("cod_pensum: {$inscripcion->cod_pensum}");
                        $this->command->info("gestion: {$inscripcion->gestion}");
                        $this->command->info("cod_curso: {$inscripcion->cod_curso}");
                        $this->command->info("tipo_inscripcion: {$inscripcion->tipo_inscripcion}");
                    }
                    
                    // Mostrar progreso cada 500 registros
                    if ($processed % 500 === 0) {
                        $this->command->info("Procesados: {$processed} | Creados: {$created} | Omitidos: {$skipped} | Sin match: {$noMatch}");
                    }

            // Verificar si ya existe una asignación para esta inscripción
            $existingAssignment = AsignacionCostos::where('cod_inscrip', $inscripcion->cod_inscrip)->first();
            if ($existingAssignment) {
                if ($verbose) $this->command->warn("Ya existe asignación, saltando...");
                $skipped++;
                continue;
            }

            // Extraer semestre y turno del cod_curso
            $semestre = null;
            $turnoCandidates = [];

            if (!empty($inscripcion->cod_curso)) {
                $segment = $inscripcion->cod_curso;
                if (str_contains($inscripcion->cod_curso, '-')) {
                    $parts = explode('-', $inscripcion->cod_curso);
                    $segment = end($parts) ?: $inscripcion->cod_curso;
                }
                $segment = trim($segment);
                $turnoChar = strtoupper(substr($segment, -1));
                $turnoCandidates = $turnoMapCfg[$turnoChar] ?? [];

                $digits = preg_replace('/\D+/', '', $segment);
                $semestre = $digits !== '' ? intval(substr($digits, 0, 1)) : null;
                
                if ($verbose) {
                    $this->command->info("Extracción:");
                    $this->command->info("  segment: {$segment}");
                    $this->command->info("  turnoChar: {$turnoChar}");
                    $this->command->info("  semestre: " . ($semestre ?? 'null'));
                    $this->command->info("  turnoCandidates: " . json_encode($turnoCandidates));
                }
            }

            // Mapear tipo_inscripcion -> tipo_costo
            $tipoIns = strtoupper((string) ($inscripcion->tipo_inscripcion ?? 'NORMAL'));
            $tipoCostoCandidates = $tipoCostoMap[$tipoIns] ?? $tipoCostoMap['NORMAL'] ?? ['costo_mensual', 'costo_semestral'];
            $tipoCostoCandidatesNorm = array_map(function($s){ return str_replace(' ', '_', strtolower($s)); }, $tipoCostoCandidates);
            
            if ($verbose) {
                $this->command->info("Mapeo tipo_costo:");
                $this->command->info("  tipoIns: {$tipoIns}");
                $this->command->info("  tipoCostoCandidates: " . json_encode($tipoCostoCandidates));
            }

            // Buscar costo semestral que coincida
            // NOTA: Ignoramos gestión porque solo hay costos para 2026 pero inscripciones históricas
            $costo = null;
            
            // Intentar match exacto por pensum y gestión
            if ($verbose) $this->command->info("Búsqueda 1: Match exacto por pensum+gestión");
            foreach ($tipoCostoCandidatesNorm as $tipoLowerNorm) {
                $qc = CostoSemestral::query()
                    ->whereRaw('TRIM(cod_pensum) = ?', [trim($inscripcion->cod_pensum)])
                    ->whereRaw('TRIM(gestion) = ?', [trim((string)$inscripcion->gestion)])
                    ->whereRaw("REPLACE(LOWER(tipo_costo), ' ', '_') = ?", [$tipoLowerNorm]);
                
                if ($semestre) { $qc->where('semestre', $semestre); }
                if (!empty($turnoCandidates)) { 
                    $qc->whereIn(DB::raw('UPPER(turno)'), array_map('strtoupper', $turnoCandidates)); 
                }
                
                if ($verbose) {
                    $this->command->info("  Buscando: tipo={$tipoLowerNorm}, semestre={$semestre}, turno=" . json_encode($turnoCandidates));
                    $this->command->info("  SQL: " . $qc->toSql());
                }
                
                $costo = $qc->first();
                if ($costo) {
                    if ($verbose) $this->command->info("  ✓ Encontrado: ID={$costo->id_costo_semestral}");
                    break;
                } else {
                    if ($verbose) $this->command->info("  ✗ No encontrado");
                }
            }
            
            // Si no encuentra, buscar por pensum base con misma gestión (ej: MEA -> buscar en cualquier MEA-XX)
            if (!$costo) {
                foreach ($tipoCostoCandidatesNorm as $tipoLowerNorm) {
                    $qc = CostoSemestral::query()
                        ->whereRaw('cod_pensum LIKE ?', [trim($inscripcion->cod_pensum) . '%'])
                        ->whereRaw('TRIM(gestion) = ?', [trim((string)$inscripcion->gestion)])
                        ->whereRaw("REPLACE(LOWER(tipo_costo), ' ', '_') = ?", [$tipoLowerNorm]);
                    
                    if ($semestre) { $qc->where('semestre', $semestre); }
                    if (!empty($turnoCandidates)) { 
                        $qc->whereIn(DB::raw('UPPER(turno)'), array_map('strtoupper', $turnoCandidates)); 
                    }
                    
                    $costo = $qc->first();
                    if ($costo) break;
                }
            }

            // Fallback: ignorar tipo_costo si está habilitado
            if (!$costo && $fallbackAnyTipo && $semestre && !empty($turnoCandidates)) {
                $q = CostoSemestral::query()
                    ->whereRaw('cod_pensum LIKE ?', [trim($inscripcion->cod_pensum) . '%'])
                    ->whereRaw('TRIM(gestion) = ?', [trim((string)$inscripcion->gestion)])
                    ->where('semestre', $semestre)
                    ->whereIn('turno', $turnoCandidates);
                
                if ($tipoIns === 'NORMAL' && !empty($forbiddenWhenNormal)) {
                    $placeholders = implode(',', array_fill(0, count($forbiddenWhenNormal), '?'));
                    $q->whereRaw('LOWER(tipo_costo) NOT IN ('.$placeholders.')', $forbiddenWhenNormal);
                }
                
                $costo = $q->first();
            }

            // Fallback para ARRASTRE: buscar por pensum base con misma gestión
            if (!$costo && $tipoIns === 'ARRASTRE') {
                $costo = CostoSemestral::query()
                    ->whereRaw('cod_pensum LIKE ?', [trim($inscripcion->cod_pensum) . '%'])
                    ->whereRaw('TRIM(gestion) = ?', [trim((string)$inscripcion->gestion)])
                    ->whereRaw('LOWER(tipo_costo) = ?', ['materia'])
                    ->first();
            }

            // Fallback para NORMAL: buscar por pensum base sin semestre/turno pero con misma gestión
            if (!$costo && $tipoIns === 'NORMAL') {
                foreach ($tipoCostoCandidatesNorm as $tipoLowerNorm) {
                    $costo = CostoSemestral::query()
                        ->whereRaw('cod_pensum LIKE ?', [trim($inscripcion->cod_pensum) . '%'])
                        ->whereRaw('TRIM(gestion) = ?', [trim((string)$inscripcion->gestion)])
                        ->whereRaw("REPLACE(LOWER(tipo_costo), ' ', '_') = ?", [$tipoLowerNorm])
                        ->first();
                    if ($costo) break;
                }
            }

            if (!$costo) {
                if ($verbose) $this->command->error("✗ NO SE ENCONTRÓ COSTO PARA ESTA INSCRIPCIÓN");
                $noMatch++;
                continue;
            }

            // Generar cuotas como lo hace AssignCostoSemestralFromInscripcion
            if ($verbose) $this->command->info("Generando cuotas con costo ID={$costo->id_costo_semestral}...");
            
            $pensumExact = $inscripcion->cod_pensum;
            $cuotasDefaults = (array) config('hydra_assign.cuotas_defaults', []);
            $cuotasTipoMap = (array) config('hydra_assign.cuotas_tipo_map', []);
            
            // Determinar tipo de costo para buscar plantilla
            $tipoCostoLower = strtolower((string) $costo->tipo_costo);
            $tipoCostoText = preg_replace('/\s+/', ' ', trim($tipoCostoLower));
            $tipoCostoKey = str_replace(' ', '_', $tipoCostoText);
            if (str_contains($tipoCostoText, 'materia')) { $tipoCostoKey = 'materia'; }
            elseif (str_contains($tipoCostoText, 'mensual')) { $tipoCostoKey = 'costo_mensual'; }
            elseif (str_contains($tipoCostoText, 'semestral')) { $tipoCostoKey = 'costo_semestral'; }
            $cuotaTipos = (array) ($cuotasTipoMap[$tipoCostoKey] ?? []);
            
            // Buscar plantilla de cuotas
            $plantilla = collect();
            if ($semestre && !empty($turnoCandidates)) {
                $qCuota = Cuota::query()
                    ->whereRaw('TRIM(gestion) = ?', [trim((string)$inscripcion->gestion)])
                    ->whereRaw('TRIM(cod_pensum) = ?', [$pensumExact])
                    ->where('semestre', $semestre)
                    ->whereIn(DB::raw('UPPER(turno)'), array_map('strtoupper', $turnoCandidates));
                if (!empty($cuotaTipos)) {
                    $qCuota->whereIn(DB::raw('LOWER(tipo)'), array_map('strtolower', $cuotaTipos));
                }
                $plantilla = $qCuota->orderBy('fecha_vencimiento')->orderBy('id_cuota')->get();
            }
            
            $cuotasCreated = 0;
            $cuotasUpdated = 0;
            
            try {
                if ($plantilla->count() > 0) {
                    // Usar plantilla de cuotas
                    if ($verbose) $this->command->info("  Usando plantilla con {$plantilla->count()} cuotas");
                    $idx = 0;
                    foreach ($plantilla as $tpl) {
                        if (trim((string)$tpl->cod_pensum) !== trim((string)$pensumExact)) continue;
                        $idx++;
                        $vals = [
                            'monto' => (float) $tpl->monto,
                            'estado' => true,
                            'fecha_vencimiento' => $tpl->fecha_vencimiento,
                            'estado_pago' => 'pendiente',
                            'monto_pagado' => 0,
                            'id_cuota_template' => $tpl->id_cuota,
                        ];
                        $unique = [
                            'cod_pensum' => $pensumExact,
                            'cod_inscrip' => (int) $inscripcion->cod_inscrip,
                            'id_costo_semestral' => $costo->id_costo_semestral,
                            'numero_cuota' => $idx,
                        ];
                        $model = AsignacionCostos::query()->where($unique)->first();
                        if ($model) {
                            $model->fill($vals)->save();
                            $cuotasUpdated++;
                        } else {
                            AsignacionCostos::create($unique + $vals);
                            $cuotasCreated++;
                        }
                    }
                    
                    // Completar cuotas faltantes hasta el deseado (solo para NORMAL)
                    if ($tipoIns !== 'ARRASTRE') {
                        $desired = max(1, (int)($cuotasDefaults['count'] ?? 5));
                        $firstDay = (int)($cuotasDefaults['first_due_day'] ?? 15);
                        $intervalDays = (int)($cuotasDefaults['interval_days'] ?? 30);
                        $useCalendarMonth = (bool)($cuotasDefaults['use_calendar_month'] ?? true);
                        
                        if ($idx < $desired) {
                            $lastDue = null;
                            foreach ($plantilla as $tpl) {
                                if (trim((string)$tpl->cod_pensum) === trim((string)$pensumExact) && !empty($tpl->fecha_vencimiento)) {
                                    $ld = Carbon::parse($tpl->fecha_vencimiento);
                                    if (!$lastDue || $ld->greaterThan($lastDue)) { $lastDue = $ld; }
                                }
                            }
                            $baseDate = $lastDue ?: Carbon::now();
                            
                            for ($i = 1; $i <= ($desired - $idx); $i++) {
                                if ($useCalendarMonth) {
                                    $due = (clone $baseDate)->addMonths($i)->day($firstDay);
                                } else {
                                    $due = (clone $baseDate)->addDays($i * max(1, $intervalDays));
                                }
                                $newIdx = $idx + $i;
                                $vals = [
                                    'monto' => (float) $costo->monto_semestre,
                                    'estado' => true,
                                    'fecha_vencimiento' => $due->toDateString(),
                                    'estado_pago' => 'pendiente',
                                    'monto_pagado' => 0,
                                    'id_cuota_template' => null,
                                ];
                                $unique = [
                                    'cod_pensum' => $pensumExact,
                                    'cod_inscrip' => (int) $inscripcion->cod_inscrip,
                                    'id_costo_semestral' => $costo->id_costo_semestral,
                                    'numero_cuota' => $newIdx,
                                ];
                                $model = AsignacionCostos::query()->where($unique)->first();
                                if ($model) {
                                    $model->fill($vals)->save();
                                    $cuotasUpdated++;
                                } else {
                                    AsignacionCostos::create($unique + $vals);
                                    $cuotasCreated++;
                                }
                            }
                        }
                    }
                } else {
                    // Sin plantilla: generar cuotas por defaults
                    $count = max(1, (int)($cuotasDefaults['count'] ?? 5));
                    if ($tipoIns === 'ARRASTRE') { $count = 1; }
                    
                    $firstDay = (int)($cuotasDefaults['first_due_day'] ?? 15);
                    $intervalDays = (int)($cuotasDefaults['interval_days'] ?? 30);
                    $useCalendarMonth = (bool)($cuotasDefaults['use_calendar_month'] ?? true);
                    $baseDate = Carbon::now();
                    
                    if ($verbose) $this->command->info("  Generando {$count} cuotas por defaults");
                    
                    for ($i = 1; $i <= $count; $i++) {
                        if ($useCalendarMonth) {
                            $due = (clone $baseDate)->startOfMonth()->addMonths($i-1)->day($firstDay);
                            if ($due->lessThan($baseDate)) { $due->addMonth(); }
                        } else {
                            $due = (clone $baseDate)->addDays(($i-1) * max(1,$intervalDays));
                        }
                        $vals = [
                            'monto' => (float) $costo->monto_semestre,
                            'estado' => true,
                            'fecha_vencimiento' => $due->toDateString(),
                            'estado_pago' => 'pendiente',
                            'monto_pagado' => 0,
                            'id_cuota_template' => null,
                        ];
                        $unique = [
                            'cod_pensum' => $pensumExact,
                            'cod_inscrip' => (int) $inscripcion->cod_inscrip,
                            'id_costo_semestral' => $costo->id_costo_semestral,
                            'numero_cuota' => $i,
                        ];
                        $model = AsignacionCostos::query()->where($unique)->first();
                        if ($model) {
                            $model->fill($vals)->save();
                            $cuotasUpdated++;
                        } else {
                            AsignacionCostos::create($unique + $vals);
                            $cuotasCreated++;
                        }
                    }
                }
                
                if ($verbose) $this->command->info("✓ Cuotas: creadas={$cuotasCreated}, actualizadas={$cuotasUpdated}");
                $created += $cuotasCreated;
            } catch (\Exception $e) {
                if ($verbose) $this->command->error("✗ Error al crear cuotas: " . $e->getMessage());
                $skipped++;
            }
        }
            }); // Fin del chunk

        $this->command->info("\n=== RESUMEN FINAL ===");
        $this->command->info("Total procesadas: {$processed}");
        $this->command->info("Asignaciones creadas: {$created}");
        $this->command->info("Ya existían (omitidas): {$skipped}");
        $this->command->info("Sin costo matching: {$noMatch}");
        
        } catch (\Exception $e) {
            $this->command->error("ERROR FATAL EN SEEDER:");
            $this->command->error("Mensaje: " . $e->getMessage());
            $this->command->error("Archivo: " . $e->getFile() . ":" . $e->getLine());
            $this->command->error("Trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
}
