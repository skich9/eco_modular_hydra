{{-- Solo el cuerpo de la tabla — mPDF maneja header/footer/paginación automáticamente --}}
<table class="data-table">
  <thead>
    <tr>
      <th class="col-num">Nº</th>
      <th class="col-trans">N/TRANSACCIÓN</th>
      <th class="col-fecha">FECHA</th>
      <th class="col-ref">REFERENCIA</th>
      <th class="col-monto">INGRESOS</th>
      <th class="col-monto">EGRESOS</th>
      <th class="col-monto">SALDO</th>
    </tr>
  </thead>
  <tbody>

    {{-- Saldo anterior --}}
    <tr class="row-saldo-ant">
      <td></td>
      <td colspan="3"><strong>Saldo anterior</strong></td>
      <td class="text-right"></td>
      <td class="text-right"></td>
      <td class="text-right">{{ number_format($saldo_anterior, 2) }}</td>
    </tr>

    @if ($movimientos->isEmpty())
    <tr>
      <td colspan="7" class="text-center" style="padding:8px;">Sin movimientos para este mes.</td>
    </tr>
    @endif

    @foreach ($movimientos as $i => $m)
    <tr class="{{ $m->tipo === 'egreso' ? 'row-egreso' : '' }}">
      <td class="col-num">{{ $i + 1 }}</td>
      <td>{{ $m->correlativo }}</td>
      <td class="text-center">{{ $m->fecha }}</td>
      <td>{{ $m->descripcion }}</td>
      <td class="text-right">{{ $m->ingreso > 0 ? number_format($m->ingreso, 2) : '0.00' }}</td>
      <td class="text-right">{{ $m->egreso  > 0 ? number_format($m->egreso,  2) : '0.00' }}</td>
      <td class="text-right">{{ number_format($m->saldo, 2) }}</td>
    </tr>
    @endforeach

    {{-- Subtotales --}}
    <tr class="row-subtotales">
      <td colspan="4" class="text-right">Sub Totales</td>
      <td class="text-right">{{ number_format($total_ingresos, 2) }}</td>
      <td class="text-right">{{ number_format($total_egresos,  2) }}</td>
      <td></td>
    </tr>
    <tr class="row-saldo-mes">
      <td colspan="6" class="text-right">Saldo del mes {{ $mes }}</td>
      <td class="text-right">{{ number_format($saldo_final, 2) }}</td>
    </tr>

  </tbody>
</table>
