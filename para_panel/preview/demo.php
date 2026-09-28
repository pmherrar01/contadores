<?php
// Datos de demostración para la vista previa (?demo=1): 100 contadores
// ficticios con el mismo formato que devuelven las consultas de
// ctCargarDatos(). No se lee ni se escribe ninguna BD.

function ctDemoDatos($desde, $hasta, $filtroUsuario)
{
  mt_srand(20260928);
  $zona = new DateTimeZone('Europe/Madrid');
  $hoy = (new DateTime('now', $zona))->format('Y-m-d');
  $ahoraHora = (int)(new DateTime('now', $zona))->format('H');
  $r = fn($a, $b) => $a + mt_rand() / mt_getrandmax() * ($b - $a);

  $clientes = [
    ['id' => 9101, 'nombre' => 'Ayuntamiento Demo'],
    ['id' => 9102, 'nombre' => 'Comunidad Las Flores'],
    ['id' => 9103, 'nombre' => 'Residencial El Prado'],
    ['id' => 9104, 'nombre' => 'Polideportivo Municipal'],
    ['id' => 9105, 'nombre' => 'Colegio San José'],
  ];
  $calles = ['C/ Mayor', 'Av. de la Constitución', 'C/ Real', 'Pl. de España', 'C/ del Agua', 'C/ San Roque', 'Av. de Extremadura'];

  $devs = [];
  for ($i = 1; $i <= 50; $i++) $devs[] = sprintf('00801526090100%02d', $i);
  for ($i = 1; $i <= 50; $i++) $devs[] = sprintf('00802026090100%02d', $i);

  // Casos especiales repartidos por la flota.
  $especial = [
    3 => 'fuga', 17 => 'fuga', 64 => 'fuga',
    8 => 'pico', 71 => 'pico',
    12 => 'bateria_baja', 45 => 'bateria_critica', 88 => 'bateria_baja',
    22 => 'cobertura', 90 => 'cobertura',
    30 => 'valvula', 55 => 'alarma', 60 => 'retroceso',
    33 => 'parado', 77 => 'parado', 40 => 'cerrada', 66 => 'sin_saldo',
    5 => 'offline', 26 => 'offline', 49 => 'offline', 81 => 'offline', 95 => 'offline', 99 => 'offline',
  ];
  $nunca = []; // con el diseño actual un contador solo existe si ya ha comunicado
  $sinAsignar = [19, 28, 42, 51, 62, 69, 79, 86, 93, 96, 100, 7];

  $diasHist = [];
  $ini = new DateTime(date('Y-m-d', strtotime("$desde -60 days")), $zona);
  for ($d = clone $ini; $d->format('Y-m-d') <= min($hasta, $hoy); $d->modify('+1 day')) $diasHist[] = $d->format('Y-m-d');

  $raw = ['contadores' => [], 'diario' => [], 'volPrevio' => [], 'horas' => [], 'eventos' => [], 'gateways' => [], 'clientes' => $clientes];
  $horas = array_fill(0, 24, 0);
  $gw = ['082767fffef8e878' => ['lecturas' => 0, 'contadores' => [], 'rssi' => [], 'snr' => [], 'ultima' => null]];

  foreach ($devs as $n => $dev) {
    $k = $n + 1;
    $tipo = $especial[$k] ?? 'normal';
    $asignado = !in_array($k, $sinAsignar, true);
    $cliente = $clientes[$k % count($clientes)];
    $idNodo = $asignado ? 9000 + $k : null;
    if ($filtroUsuario !== null && (!$asignado || $cliente['id'] !== (int)$filtroUsuario)) continue;

    $fila = [
      'id' => $k, 'devEui' => $dev, 'idNodo' => $idNodo, 'numSerie' => substr($dev, 2), 'activo' => 1, 'createdAt' => null,
      'nombreNodo' => $asignado ? 'Contador ' . ($k <= 50 ? 'vivienda ' : 'local ') . $k : null,
      'idUsuario' => $asignado ? $cliente['id'] : null, 'ubicacion' => $asignado ? 'Arqueta ' . $k : null,
      'direccion' => $asignado ? $calles[$k % count($calles)] . ' ' . (($k * 7) % 60 + 1) : null, 'nodoBorrado' => 'n',
      'nombreUsuario' => $asignado ? $cliente['nombre'] : null, 'servicio' => $asignado ? 'Contadores' : null,
      'primeraLectura' => null, 'ultimaLectura' => null, 'totalLecturas' => 0, 'bateria' => null, 'alarmas' => null,
      'rssi' => null, 'snr' => null, 'gatewayId' => null, 'datos' => null, 'fCnt' => null, 'volumen' => null, 'fechaVolumen' => null,
    ];

    if (in_array($k, $nunca, true)) {
      if ($asignado) $raw['contadores'][] = $fila;
      continue;
    }

    $gatewayId = '082767fffef8e878';
    $rssiBase = $tipo === 'cobertura' ? -119 : $r(-108, -62);
    $bateria = ['bateria_baja' => 3.36, 'bateria_critica' => 3.27][$tipo] ?? $r(3.55, 3.68);
    $vol = $r(0, 45);
    $base = $r(0.05, 0.22);
    $diasOffline = $tipo === 'offline' ? mt_rand(2, 9) : 0;
    $diasActivo = $asignado ? 999 : mt_rand(1, 6);
    $fCnt = 0;
    $prevVol = null;

    foreach ($diasHist as $idx => $dia) {
      $diasDesdeHoy = (int)round((strtotime($hoy) - strtotime($dia)) / 86400);
      if ($diasDesdeHoy < $diasOffline || $diasDesdeHoy >= $diasActivo) continue;
      if ($dia === $hoy && $ahoraHora < 11) continue; // hoy aún no ha informado
      if (mt_rand(1, 100) <= 2) continue; // algún día suelto sin comunicar

      $finde = in_array((int)date('N', strtotime($dia)), [6, 7], true) ? 1.35 : 1.0;
      $consumo = $base * $finde * $r(0.5, 1.5);
      if ($tipo === 'fuga') $consumo = $r(0.32, 0.45) + $base;
      if ($tipo === 'parado' && $diasDesdeHoy < 12) $consumo = 0;
      if ($tipo === 'pico' && $diasDesdeHoy <= 1 && $dia === min($hasta, $hoy)) $consumo = $r(2.5, 3.5);
      if ($tipo === 'retroceso' && $diasDesdeHoy === 4) $vol = 0.5;
      $vol += $consumo;

      $alarma = null;
      if ($tipo === 'valvula' && $diasDesdeHoy <= 3) $alarma = 'valvula_averiada';
      if ($tipo === 'alarma' && $diasDesdeHoy <= 2) $alarma = 'alarma_0004';
      $rssi = (int)round($rssiBase + $r(-4, 4));
      $snr = round($tipo === 'cobertura' ? $r(-13, -9) : $r(-2, 11), 1);
      $bat = round($bateria - $diasDesdeHoy * 0.0001, 2);

      if ($dia >= $desde && $dia <= $hasta) {
        $raw['diario'][] = ['devEui' => $dev, 'dia' => $dia, 'volMax' => round($vol, 3), 'volMin' => round($vol - 0.001, 3), 'lecturas' => 3, 'bateriaMin' => $bat, 'rssiMedio' => $rssi, 'snrMedio' => $snr, 'lecturasConAlarma' => $alarma ? 3 : 0];
        $h = 11 + ($k % 5 === 0 ? 1 : 0);
        $horas[$h] += 3;
        $gw[$gatewayId]['lecturas'] += 3;
        $gw[$gatewayId]['contadores'][$dev] = true;
        $gw[$gatewayId]['rssi'][] = $rssi;
        $gw[$gatewayId]['snr'][] = $snr;
        $gw[$gatewayId]['ultima'] = max($gw[$gatewayId]['ultima'] ?? '', "$dia 11:27:0" . ($k % 10));
        if ($alarma) $raw['eventos'][] = ['fecha' => "$dia 11:26:48", 'devEui' => $dev, 'alarmas' => $alarma, 'bateria' => $bat, 'volumenM3' => round($vol, 3), 'datos' => json_encode(['valvula' => 'abierta'])];
      } elseif ($dia < $desde) {
        $prevVol = round($vol, 3);
      }

      $fCnt += 3;
      $fila['primeraLectura'] = $fila['primeraLectura'] ?? "$dia 11:26:48";
      $fila['ultimaLectura'] = "$dia 11:27:" . sprintf('%02d', 20 + $k % 30);
      $fila['totalLecturas'] += 3;
      $fila['volumen'] = round($vol, 3);
      $fila['bateria'] = $bat;
      $fila['rssi'] = $rssi;
      $fila['snr'] = $snr;
      $fila['gatewayId'] = $gatewayId;
      $fila['alarmas'] = $alarma;
      $fila['fCnt'] = $fCnt;
      $fila['datos'] = json_encode([
        'tipo' => 'lectura', 'volumen_m3' => round($vol, 3), 'bateria' => $bat, 'alarmas' => $alarma ? [$alarma] : [],
        'valvula' => $tipo === 'cerrada' ? 'cerrada' : 'abierta',
        'saldo_m3' => $tipo === 'sin_saldo' ? -1.2 : 2, 'modo_pago' => $tipo === 'sin_saldo' ? 'prepago' : 'pospago',
      ]);
    }
    if ($prevVol !== null) $raw['volPrevio'][] = ['devEui' => $dev, 'vol' => $prevVol];
    $raw['contadores'][] = $fila;
  }

  foreach ($horas as $h => $v) if ($v) $raw['horas'][] = ['hora' => $h, 'lecturas' => $v];
  foreach ($gw as $id => $g) {
    if (!$g['lecturas']) continue;
    $raw['gateways'][] = ['gatewayId' => $id, 'lecturas' => $g['lecturas'], 'contadores' => count($g['contadores']), 'ultimaLectura' => $g['ultima'], 'rssiMedio' => array_sum($g['rssi']) / count($g['rssi']), 'snrMedio' => array_sum($g['snr']) / count($g['snr'])];
  }
  usort($raw['eventos'], fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
  return $raw;
}
