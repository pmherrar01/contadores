<?php

/**
 * Contadores de agua LoRaWAN: consultas y cálculos comunes a las dos tarjetas
 * del panel (card_contadores_lorawan.php, la general, y
 * card_contador_lorawan_nodo.php, la de un nodo). Se incluye con require_once.
 *
 * Tablas: lorawan_contadores, lorawan_contadores_lecturas, lorawan_gateways y
 * lorawan_gateways_historial (las rellena server_panel), más safey_nodos,
 * usuarios y services para el nodo, el cliente y el servicio.
 */

if (!defined('CT_HORAS_SIN_COMUNICAR')) {
  define('CT_HORAS_SIN_COMUNICAR', 26);  // el contador informa una vez al día
  define('CT_BATERIA_BAJA', 3.40);       // V (batería de litio 3,6 V)
  define('CT_BATERIA_CRITICA', 3.30);    // V
  define('CT_RSSI_DEBIL', -115);         // dBm
  define('CT_SNR_DEBIL', -10);           // dB
  define('CT_DIAS_FUGA', 7);             // días seguidos analizados para la posible fuga
  define('CT_M3_MIN_FUGA', 0.25);        // m³/día: si en esos días nunca baja de 250 L, posible fuga
  define('CT_DIAS_PARADO', 7);           // días sin consumo comunicando
  define('CT_MAX_DIAS_RANGO', 366);
  define('CT_MIN_SIN_INFORME_GATEWAY', 20); // min sin recibir el estado del gateway desde ChirpStack
}

if (!function_exists('ctCargarDatos')) {

  /**
   * Rango de fechas de los parámetros GET ct_desde / ct_hasta (YYYY-MM-DD).
   * Por defecto, los últimos 30 días. Devuelve [desde, hasta].
   */
  function ctRangoGet()
  {
    $valida = fn($f) => (is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f)) ? $f : null;
    $hoy = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');
    $desde = $valida($_GET['ct_desde'] ?? null) ?? date('Y-m-d', strtotime("$hoy -29 days"));
    $hasta = $valida($_GET['ct_hasta'] ?? null) ?? $hoy;
    if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
    if ((strtotime($hasta) - strtotime($desde)) / 86400 >= CT_MAX_DIAS_RANGO) {
      $desde = date('Y-m-d', strtotime("$hasta -" . (CT_MAX_DIAS_RANGO - 1) . ' days'));
    }
    return [$desde, $hasta];
  }

  /**
   * Consultas a la BD. Devuelve los datos en bruto que procesa ctProcesar().
   * $idUsuario: solo contadores de nodos de ese usuario. $idContador: solo ese contador (vista por nodo).
   */
  function ctCargarDatos($db, $desde, $hasta, $idUsuario, $idContador = null)
  {
    $ini = "'$desde 00:00:00'";
    $fin = "'" . date('Y-m-d', strtotime("$hasta +1 day")) . " 00:00:00'";
    $u = $idUsuario !== null ? (int)$idUsuario : null;
    $k = $idContador !== null ? (int)$idContador : null;

    // Con filtro de usuario solo entran los contadores enlazados a nodos suyos.
    $joinUsuario = $u !== null ? "JOIN safey_nodos nu ON nu.id = c.idNodo AND nu.idusuario = $u" : '';
    $soloContador = $k !== null ? "AND c.id = $k" : '';
    $lecturas = "lorawan_contadores_lecturas l JOIN lorawan_contadores c ON c.id = l.idContador $soloContador $joinUsuario";

    $contadores = $db->query("SELECT c.id, c.devEui, c.idNodo, c.idGateway, c.numSerie, c.activo, c.createdAt,
        gw.gatewayEui AS gatewayEuiAsignado, gw.nombre AS nombreGateway, gw.estado AS estadoGateway,
        n.nombre AS nombreNodo, n.idusuario AS idUsuario, n.ubicacion, n.direccion, n.borrado AS nodoBorrado,
        CONCAT_WS(' ', u.nombre, u.apellidos) AS nombreUsuario,
        s.value AS servicio,
        st.primeraLectura, st.ultimaLectura, st.totalLecturas,
        ul.bateria, ul.alarmas, ul.rssi, ul.snr, ul.gatewayId, ul.datos, ul.fCnt,
        uv.volumenM3 AS volumen, uv.fecha AS fechaVolumen
      FROM lorawan_contadores c
      $joinUsuario
      LEFT JOIN safey_nodos n ON n.id = c.idNodo
      LEFT JOIN usuarios u ON u.id = n.idusuario
      LEFT JOIN services s ON s.id = n.idService
      LEFT JOIN (SELECT idContador, MIN(fecha) AS primeraLectura, MAX(fecha) AS ultimaLectura, COUNT(*) AS totalLecturas,
                        MAX(id) AS ultimoId, MAX(CASE WHEN volumenM3 IS NOT NULL THEN id END) AS ultimoIdVolumen
                   FROM lorawan_contadores_lecturas GROUP BY idContador) st ON st.idContador = c.id
      LEFT JOIN lorawan_contadores_lecturas ul ON ul.id = st.ultimoId
      LEFT JOIN lorawan_contadores_lecturas uv ON uv.id = st.ultimoIdVolumen
      LEFT JOIN lorawan_gateways gw ON gw.id = c.idGateway
      WHERE 1 = 1 $soloContador");

    $diario = $db->query("SELECT c.devEui, DATE(l.fecha) AS dia, MAX(l.volumenM3) AS volMax, MIN(l.volumenM3) AS volMin,
        COUNT(*) AS lecturas, MIN(l.bateria) AS bateriaMin, AVG(l.rssi) AS rssiMedio, AVG(l.snr) AS snrMedio,
        SUM(l.alarmas IS NOT NULL) AS lecturasConAlarma
      FROM $lecturas
      WHERE l.fecha >= $ini AND l.fecha < $fin
      GROUP BY c.devEui, DATE(l.fecha)");

    // Última lectura acumulada antes del rango: base para el consumo del primer día.
    $volPrevio = $db->query("SELECT c.devEui, MAX(l.volumenM3) AS vol
      FROM $lecturas
      WHERE l.fecha < $ini AND l.volumenM3 IS NOT NULL
      GROUP BY c.devEui");

    $horas = $db->query("SELECT HOUR(l.fecha) AS hora, COUNT(*) AS lecturas
      FROM $lecturas
      WHERE l.fecha >= $ini AND l.fecha < $fin
      GROUP BY HOUR(l.fecha)");

    $eventos = $db->query("SELECT l.fecha, c.devEui, l.alarmas, l.bateria, l.volumenM3, l.datos
      FROM $lecturas
      WHERE l.fecha >= $ini AND l.fecha < $fin AND l.alarmas IS NOT NULL
      ORDER BY l.fecha DESC LIMIT 300");

    // Gateways con su último estado (lo manda ChirpStack cada 5 min), su actividad en el
    // periodo y los informes de estado del historial para la disponibilidad.
    $filtroGateways = '';
    if ($k !== null) {
      $filtroGateways = "WHERE r.lecturas IS NOT NULL OR g.id = (SELECT idGateway FROM lorawan_contadores WHERE id = $k)";
    } elseif ($u !== null) {
      $filtroGateways = "WHERE r.lecturas IS NOT NULL OR EXISTS (SELECT 1 FROM lorawan_contadores c3 JOIN safey_nodos n3 ON n3.id = c3.idNodo WHERE c3.idGateway = g.id AND n3.idusuario = $u)";
    }
    $gateways = $db->query("SELECT g.id, g.gatewayEui, g.nombre, g.descripcion, g.estado, g.ultimaConexion, g.rxUltimaHora, g.txUltimaHora,
        g.rx24h, g.tx24h, g.latitud, g.longitud, g.altitud, g.estadoRecibidoAt,
        (SELECT COUNT(*) FROM lorawan_contadores c2 WHERE c2.idGateway = g.id) AS contadoresAsignados,
        h.informes, h.informesOnline,
        r.lecturas, r.contadores, r.ultimaLectura, r.rssiMedio, r.snrMedio
      FROM lorawan_gateways g
      LEFT JOIN (SELECT idGateway, COUNT(*) AS informes, SUM(estado = 'online') AS informesOnline
                   FROM lorawan_gateways_historial
                  WHERE fecha >= $ini AND fecha < $fin
                  GROUP BY idGateway) h ON h.idGateway = g.id
      LEFT JOIN (SELECT l.gatewayId, COUNT(*) AS lecturas, COUNT(DISTINCT l.idContador) AS contadores,
                        MAX(l.fecha) AS ultimaLectura, AVG(l.rssi) AS rssiMedio, AVG(l.snr) AS snrMedio
                   FROM $lecturas
                  WHERE l.fecha >= $ini AND l.fecha < $fin AND l.gatewayId IS NOT NULL
                  GROUP BY l.gatewayId) r ON r.gatewayId = g.gatewayEui
      $filtroGateways
      ORDER BY g.nombre, g.gatewayEui");

    $gatewaysDiario = $db->query("SELECT idGateway, DATE(fecha) AS dia, COUNT(*) AS informes, SUM(estado = 'online') AS informesOnline
      FROM lorawan_gateways_historial
      WHERE fecha >= $ini AND fecha < $fin
      GROUP BY idGateway, DATE(fecha)");

    $clientes = $db->query("SELECT DISTINCT n.idusuario AS id, CONCAT_WS(' ', u.nombre, u.apellidos) AS nombre
      FROM lorawan_contadores c
      JOIN safey_nodos n ON n.id = c.idNodo
      LEFT JOIN usuarios u ON u.id = n.idusuario
      ORDER BY nombre");

    return compact('contadores', 'diario', 'volPrevio', 'horas', 'eventos', 'gateways', 'gatewaysDiario', 'clientes');
  }

  function ctModelo($devEui)
  {
    if (strpos($devEui, '008015') === 0) return 'DN15';
    if (strpos($devEui, '008020') === 0) return 'DN20';
    if (strpos($devEui, '008025') === 0) return 'DN25';
    return '—';
  }

  function ctNum($v, $dec = null)
  {
    if ($v === null || $v === '') return null;
    return $dec === null ? (float)$v : round((float)$v, $dec);
  }

  /** Calcula estado, consumos, incidencias, KPIs y series de las gráficas. */
  function ctProcesar(array $raw, $desde, $hasta)
  {
    $zona = new DateTimeZone('Europe/Madrid');
    $ahora = new DateTime('now', $zona);
    $hoy = $ahora->format('Y-m-d');

    $dias = [];
    for ($d = new DateTime($desde, $zona); $d->format('Y-m-d') <= $hasta; $d->modify('+1 day')) {
      $dias[] = $d->format('Y-m-d');
    }
    $nDias = count($dias);

    $diarioPorContador = [];
    foreach ($raw['diario'] as $f) {
      $diarioPorContador[$f['devEui']][$f['dia']] = $f;
    }
    $volPrevio = array_column($raw['volPrevio'], 'vol', 'devEui');

    $contadores = [];
    $incidencias = [];
    $serieFlota = array_fill_keys($dias, ['consumo' => 0.0, 'lecturas' => 0, 'contadores' => 0]);
    $heatmap = [];

    foreach ($raw['contadores'] as $c) {
      $dev = $c['devEui'];
      $datos = !empty($c['datos']) ? json_decode($c['datos'], true) : [];
      $datos = is_array($datos) ? $datos : [];
      $modelo = ctModelo($dev);
      $numero = $c['numSerie'] ?: (strpos($dev, '00') === 0 ? substr($dev, 2) : $dev);

      // Consumo por día: lectura acumulada máxima del día menos la del día anterior con datos.
      $prev = isset($volPrevio[$dev]) ? (float)$volPrevio[$dev] : null;
      $consumoDias = [];
      $retrocesos = 0;
      $diasConDatos = 0;
      $lecturasRango = 0;
      $bateriaMinRango = null;
      $sumRssi = 0;
      $nRssi = 0;
      $alarmasRango = 0;
      foreach ($dias as $dia) {
        $f = $diarioPorContador[$dev][$dia] ?? null;
        $consumo = null;
        if ($f) {
          $diasConDatos++;
          $lecturasRango += (int)$f['lecturas'];
          $alarmasRango += (int)$f['lecturasConAlarma'];
          if ($f['bateriaMin'] !== null) $bateriaMinRango = $bateriaMinRango === null ? (float)$f['bateriaMin'] : min($bateriaMinRango, (float)$f['bateriaMin']);
          if ($f['rssiMedio'] !== null) { $sumRssi += (float)$f['rssiMedio']; $nRssi++; }
          if ($f['volMax'] !== null) {
            $max = (float)$f['volMax'];
            $consumo = $prev !== null ? $max - $prev : $max - (float)$f['volMin'];
            if ($consumo < -0.0005) { $retrocesos++; $consumo = 0.0; }
            $consumo = max(0.0, round($consumo, 3));
            $prev = $max;
            $serieFlota[$dia]['consumo'] += $consumo;
          }
          $serieFlota[$dia]['lecturas'] += (int)$f['lecturas'];
          $serieFlota[$dia]['contadores']++;
        }
        $consumoDias[$dia] = $consumo;
      }

      // Días en los que debía comunicar: desde su primera lectura (o el inicio del rango).
      $inicioBase = $c['primeraLectura'] ? max($desde, substr($c['primeraLectura'], 0, 10)) : $desde;
      $diasBase = count(array_filter($dias, fn($dia) => $dia >= $inicioBase && $dia <= $hoy));

      $consumos = array_values(array_filter($consumoDias, fn($v) => $v !== null));
      $consumoRango = round(array_sum($consumos), 3);
      $mediaDiaria = count($consumos) ? round($consumoRango / count($consumos), 3) : null;
      $ultimoDia = null;
      foreach (array_reverse($dias) as $dia) {
        if ($consumoDias[$dia] !== null) { $ultimoDia = ['dia' => $dia, 'consumo' => $consumoDias[$dia]]; break; }
      }

      // Estado de comunicación.
      $horasSin = null;
      if ($c['ultimaLectura']) {
        $ultima = new DateTime($c['ultimaLectura'], $zona);
        $horasSin = round(($ahora->getTimestamp() - $ultima->getTimestamp()) / 3600, 1);
      }
      $estado = $horasSin === null ? 'nunca' : ($horasSin <= CT_HORAS_SIN_COMUNICAR ? 'online' : 'offline');

      $bateria = ctNum($c['bateria'], 2);
      $rssi = ctNum($c['rssi']);
      $snr = ctNum($c['snr'], 1);
      $alarmas = $c['alarmas'] ? explode(',', $c['alarmas']) : [];

      // Posibles averías e incidencias (de más a menos grave).
      $inc = [];
      $add = function ($tipo, $nivel, $texto) use (&$inc) {
        $inc[] = ['tipo' => $tipo, 'nivel' => $nivel, 'texto' => $texto];
      };
      if ($estado === 'offline') $add('sin_comunicar', 'danger', 'Sin comunicar desde hace ' . ($horasSin >= 48 ? round($horasSin / 24) . ' días' : round($horasSin) . ' h'));
      if ($estado === 'nunca') $add('nunca', 'warning', 'Registrado pero sin lecturas');
      if (in_array('valvula_averiada', $alarmas, true)) $add('valvula', 'danger', 'Válvula averiada');
      foreach ($alarmas as $a) {
        if (strpos($a, 'alarma_') === 0) $add('alarma', 'danger', 'Alarma del contador (código ' . substr($a, 7) . ')');
      }
      if ($bateria !== null && $bateria < CT_BATERIA_CRITICA) $add('bateria', 'danger', "Batería crítica ({$bateria} V)");
      elseif ($bateria !== null && $bateria < CT_BATERIA_BAJA) $add('bateria', 'warning', "Batería baja ({$bateria} V)");
      if ($retrocesos > 0) $add('retroceso', 'danger', 'La lectura acumulada ha bajado (¿contador sustituido o reiniciado?)');

      $ultimosN = array_slice($consumos, -CT_DIAS_FUGA);
      if (count($ultimosN) >= CT_DIAS_FUGA && min($ultimosN) >= CT_M3_MIN_FUGA) {
        $add('fuga', 'warning', 'Posible fuga: en ' . CT_DIAS_FUGA . ' días nunca ha bajado de ' . round(min($ultimosN) * 1000) . ' L/día');
      }
      if ($ultimoDia && $mediaDiaria !== null && count($consumos) >= 4 && $ultimoDia['consumo'] > max(3 * $mediaDiaria, 0.5)) {
        $add('pico', 'warning', 'Consumo anómalo el ' . date('d/m', strtotime($ultimoDia['dia'])) . ': ' . $ultimoDia['consumo'] . ' m³ (media ' . $mediaDiaria . ' m³/día)');
      }
      $ultimosParado = array_slice($consumos, -CT_DIAS_PARADO);
      if ($estado === 'online' && count($ultimosParado) >= CT_DIAS_PARADO && max($ultimosParado) < 0.001) {
        $add('parado', 'info', 'Sin consumo en ' . CT_DIAS_PARADO . ' días (¿vivienda vacía o contador bloqueado?)');
      }
      if (($rssi !== null && $rssi < CT_RSSI_DEBIL) || ($snr !== null && $snr < CT_SNR_DEBIL)) {
        $add('cobertura', 'warning', "Cobertura débil (RSSI {$rssi} dBm, SNR {$snr} dB)");
      }
      if (($datos['valvula'] ?? null) === 'cerrada') $add('valvula_cerrada', 'info', 'Válvula cerrada');
      if (($datos['modo_pago'] ?? null) === 'prepago' && isset($datos['saldo_m3']) && $datos['saldo_m3'] <= 0) $add('saldo', 'warning', 'Prepago sin saldo (' . $datos['saldo_m3'] . ' m³)');
      if (!$c['idNodo']) $add('sin_nodo', 'info', 'Sin nodo: pendiente de enlazar en la base de datos');
      if ($c['idNodo'] && $c['nodoBorrado'] === 's') $add('nodo_borrado', 'warning', 'Su nodo está borrado en el panel');

      $nivelMax = 'ok';
      foreach (['danger', 'warning', 'info'] as $n) {
        if (in_array($n, array_column($inc, 'nivel'), true)) { $nivelMax = $n; break; }
      }

      $item = [
        'devEui' => $dev,
        'numero' => $numero,
        'modelo' => $modelo,
        'idNodo' => $c['idNodo'] !== null ? (int)$c['idNodo'] : null,
        'nombreNodo' => $c['nombreNodo'],
        'idUsuario' => $c['idUsuario'] !== null ? (int)$c['idUsuario'] : null,
        'usuario' => $c['nombreUsuario'] ?: null,
        'servicio' => $c['servicio'],
        'ubicacion' => $c['ubicacion'],
        'direccion' => $c['direccion'],
        'estado' => $estado,
        'horasSinComunicar' => $horasSin,
        'primeraLectura' => $c['primeraLectura'],
        'ultimaLectura' => $c['ultimaLectura'],
        'totalLecturas' => (int)$c['totalLecturas'],
        'volumen' => ctNum($c['volumen'], 3),
        'bateria' => $bateria,
        'bateriaMinRango' => $bateriaMinRango !== null ? round($bateriaMinRango, 2) : null,
        'rssi' => $rssi,
        'snr' => $snr,
        'rssiMedio' => $nRssi ? round($sumRssi / $nRssi) : null,
        'gatewayId' => $c['gatewayId'],
        'idGateway' => $c['idGateway'] !== null ? (int)$c['idGateway'] : null,
        'nombreGateway' => $c['nombreGateway'] ?: $c['gatewayEuiAsignado'],
        'estadoGateway' => $c['estadoGateway'],
        'idContador' => isset($c['id']) ? (int)$c['id'] : null,
        'valvula' => $datos['valvula'] ?? null,
        'saldo' => isset($datos['saldo_m3']) ? (float)$datos['saldo_m3'] : null,
        'modoPago' => $datos['modo_pago'] ?? null,
        'alarmas' => $alarmas,
        'consumoRango' => $consumoRango,
        'mediaDiaria' => $mediaDiaria,
        'consumoUltimoDia' => $ultimoDia,
        'consumoDias' => $consumoDias,
        'lecturasRango' => $lecturasRango,
        'alarmasRango' => $alarmasRango,
        'disponibilidad' => $diasBase ? min(100, round(100 * $diasConDatos / $diasBase)) : 0,
        'incidencias' => $inc,
        'nivel' => $nivelMax,
      ];
      $contadores[] = $item;

      foreach ($inc as $i) {
        $incidencias[] = $i + ['devEui' => $dev, 'numero' => $numero, 'modelo' => $modelo, 'nodo' => $c['nombreNodo'], 'usuario' => $item['usuario'], 'ultimaLectura' => $c['ultimaLectura']];
      }

      $fila = [];
      foreach (array_slice($dias, -31) as $dia) {
        $fila[] = ['x' => date('d/m', strtotime($dia)), 'y' => (int)($diarioPorContador[$dev][$dia]['lecturas'] ?? 0)];
      }
      $heatmap[] = ['name' => $numero, 'data' => $fila, 'nivel' => $nivelMax];
    }

    usort($contadores, fn($a, $b) => strcmp($a['numero'], $b['numero']));
    $ordenNivel = ['danger' => 0, 'warning' => 1, 'info' => 2];
    usort($incidencias, fn($a, $b) => [$ordenNivel[$a['nivel']], $a['numero']] <=> [$ordenNivel[$b['nivel']], $b['numero']]);

    // KPIs de la flota.
    $col = fn($k) => array_column($contadores, $k);
    $contar = fn($k, $v) => count(array_filter($contadores, fn($c) => $c[$k] === $v));
    $baterias = array_values(array_filter($col('bateria'), fn($v) => $v !== null));
    $rssis = array_values(array_filter($col('rssi'), fn($v) => $v !== null));
    $consumoTotal = round(array_sum($col('consumoRango')), 3);
    $diasConConsumo = count(array_filter($serieFlota, fn($d) => $d['contadores'] > 0));
    $comunicando = count(array_filter($contadores, fn($c) => $c['estado'] !== 'nunca'));

    $kpis = [
      'total' => count($contadores),
      'online' => $contar('estado', 'online'),
      'offline' => $contar('estado', 'offline'),
      'nunca' => $contar('estado', 'nunca'),
      'conNodo' => count(array_filter($contadores, fn($c) => $c['idNodo'] !== null)),
      'sinNodo' => count(array_filter($contadores, fn($c) => $c['idNodo'] === null)),
      'conAveria' => $contar('nivel', 'danger'),
      'conAviso' => $contar('nivel', 'warning'),
      'consumoTotal' => $consumoTotal,
      'consumoMedioDia' => $diasConConsumo ? round($consumoTotal / $diasConConsumo, 3) : 0,
      'consumoMedioContador' => $comunicando ? round($consumoTotal / $comunicando, 3) : 0,
      'consumoHoy' => round($serieFlota[$hoy]['consumo'] ?? 0, 3),
      'lecturas' => array_sum($col('lecturasRango')),
      'bateriaMedia' => $baterias ? round(array_sum($baterias) / count($baterias), 2) : null,
      'bateriaMin' => $baterias ? min($baterias) : null,
      'rssiMedio' => $rssis ? round(array_sum($rssis) / count($rssis)) : null,
      'disponibilidad' => $comunicando ? round(array_sum(array_column(array_filter($contadores, fn($c) => $c['estado'] !== 'nunca'), 'disponibilidad')) / $comunicando) : 0,
      'volumenTotal' => round(array_sum(array_filter($col('volumen'), fn($v) => $v !== null)), 3),
    ];

    // Distribuciones para las gráficas.
    $tramosBateria = ['< 3,30 V' => 0, '3,30-3,40' => 0, '3,40-3,50' => 0, '3,50-3,60' => 0, '≥ 3,60 V' => 0];
    foreach ($baterias as $b) {
      $k = $b < 3.30 ? '< 3,30 V' : ($b < 3.40 ? '3,30-3,40' : ($b < 3.50 ? '3,40-3,50' : ($b < 3.60 ? '3,50-3,60' : '≥ 3,60 V')));
      $tramosBateria[$k]++;
    }
    $tramosCobertura = ['Excelente (≥ -90)' => 0, 'Buena (-90 a -105)' => 0, 'Regular (-105 a -115)' => 0, 'Débil (< -115)' => 0];
    foreach ($rssis as $r) {
      $k = $r >= -90 ? 'Excelente (≥ -90)' : ($r >= -105 ? 'Buena (-90 a -105)' : ($r >= -115 ? 'Regular (-105 a -115)' : 'Débil (< -115)'));
      $tramosCobertura[$k]++;
    }
    $porHora = array_fill(0, 24, 0);
    foreach ($raw['horas'] as $h) $porHora[(int)$h['hora']] = (int)$h['lecturas'];

    $porCliente = [];
    foreach ($contadores as $c) {
      $k = $c['usuario'] ?: ($c['idNodo'] ? 'Sin usuario' : 'Sin nodo');
      $porCliente[$k] = round(($porCliente[$k] ?? 0) + $c['consumoRango'], 3);
    }
    arsort($porCliente);

    $top = array_filter($contadores, fn($c) => $c['consumoRango'] > 0);
    usort($top, fn($a, $b) => $b['consumoRango'] <=> $a['consumoRango']);
    $top = array_slice($top, 0, 10);

    $numeroPorDev = array_column($contadores, 'numero', 'devEui');
    $eventos = array_map(function ($e) use ($numeroPorDev) {
      $d = !empty($e['datos']) ? json_decode($e['datos'], true) : [];
      return ['fecha' => $e['fecha'], 'devEui' => $e['devEui'], 'numero' => $numeroPorDev[$e['devEui']] ?? $e['devEui'], 'alarmas' => explode(',', $e['alarmas']), 'bateria' => ctNum($e['bateria'], 2), 'volumen' => ctNum($e['volumenM3'], 3), 'valvula' => $d['valvula'] ?? null];
    }, $raw['eventos']);

    // Estado LoRaWAN: gateways, su disponibilidad por día y sus incidencias.
    $diarioGateway = [];
    foreach ($raw['gatewaysDiario'] ?? [] as $f) {
      $diarioGateway[$f['idGateway']][$f['dia']] = (int)$f['informes'] ? round(100 * (int)$f['informesOnline'] / (int)$f['informes']) : null;
    }
    $gateways = [];
    $incidenciasGateway = [];
    foreach ($raw['gateways'] as $g) {
      $recibido = $g['estadoRecibidoAt'] ? new DateTime($g['estadoRecibidoAt'], $zona) : null;
      $minSinInforme = $recibido ? round(($ahora->getTimestamp() - $recibido->getTimestamp()) / 60) : null;
      $estado = ($minSinInforme === null || $minSinInforme > CT_MIN_SIN_INFORME_GATEWAY) ? 'sin_datos' : $g['estado'];
      $ultima = $g['ultimaConexion'] ? new DateTime($g['ultimaConexion'], $zona) : null;
      $minSinConexion = $ultima ? round(($ahora->getTimestamp() - $ultima->getTimestamp()) / 60) : null;
      $nombre = $g['nombre'] ?: $g['gatewayEui'];
      $item = [
        'id' => (int)$g['id'],
        'gatewayEui' => $g['gatewayEui'],
        'nombre' => $nombre,
        'descripcion' => $g['descripcion'],
        'estado' => $estado,
        'estadoChirpstack' => $g['estado'],
        'ultimaConexion' => $g['ultimaConexion'],
        'minSinConexion' => $minSinConexion,
        'estadoRecibidoAt' => $g['estadoRecibidoAt'],
        'rxUltimaHora' => $g['rxUltimaHora'] !== null ? (int)$g['rxUltimaHora'] : null,
        'txUltimaHora' => $g['txUltimaHora'] !== null ? (int)$g['txUltimaHora'] : null,
        'rx24h' => $g['rx24h'] !== null ? (int)$g['rx24h'] : null,
        'tx24h' => $g['tx24h'] !== null ? (int)$g['tx24h'] : null,
        'latitud' => ctNum($g['latitud']),
        'longitud' => ctNum($g['longitud']),
        'contadoresAsignados' => (int)$g['contadoresAsignados'],
        'lecturas' => (int)($g['lecturas'] ?? 0),
        'contadores' => (int)($g['contadores'] ?? 0),
        'ultimaLectura' => $g['ultimaLectura'],
        'rssiMedio' => ctNum($g['rssiMedio'], 0),
        'snrMedio' => ctNum($g['snrMedio'], 1),
        'disponibilidad' => (int)$g['informes'] ? round(100 * (int)$g['informesOnline'] / (int)$g['informes']) : null,
        'disponibilidadDias' => array_map(fn($dia) => $diarioGateway[$g['id']][$dia] ?? null, $dias),
      ];
      $gateways[] = $item;
      if ($estado === 'offline') {
        $incidenciasGateway[] = ['nivel' => 'danger', 'gatewayEui' => $g['gatewayEui'], 'texto' => "$nombre desconectado" . ($minSinConexion !== null ? ' desde hace ' . ($minSinConexion >= 120 ? round($minSinConexion / 60) . ' h' : $minSinConexion . ' min') : '')];
      } elseif ($estado === 'sin_datos') {
        $incidenciasGateway[] = ['nivel' => 'warning', 'gatewayEui' => $g['gatewayEui'], 'texto' => "No llega el estado de $nombre desde ChirpStack" . ($minSinInforme !== null ? ' (último informe hace ' . ($minSinInforme >= 120 ? round($minSinInforme / 60) . ' h' : $minSinInforme . ' min') . ')' : '') . '. ¿Está parado el contenedor de ChirpStack?'];
      } elseif ($estado === 'online' && $item['rx24h'] === 0) {
        $incidenciasGateway[] = ['nivel' => 'warning', 'gatewayEui' => $g['gatewayEui'], 'texto' => "$nombre conectado pero sin recibir nada por radio en 24 h (¿antena?)"];
      } elseif ($estado === 'nunca') {
        $incidenciasGateway[] = ['nivel' => 'warning', 'gatewayEui' => $g['gatewayEui'], 'texto' => "$nombre dado de alta en ChirpStack pero nunca se ha conectado"];
      }
    }

    $kpis['gateways'] = count($gateways);
    $kpis['gatewaysOnline'] = count(array_filter($gateways, fn($g) => $g['estado'] === 'online'));

    return [
      'rango' => ['desde' => $desde, 'hasta' => $hasta, 'dias' => $nDias, 'hoy' => $hoy, 'generado' => $ahora->format('Y-m-d H:i:s')],
      'kpis' => $kpis,
      'contadores' => $contadores,
      'incidencias' => $incidencias,
      'serie' => [
        'dias' => array_map(fn($d) => date('d/m', strtotime($d)), $dias),
        'consumo' => array_map(fn($d) => round($d['consumo'], 3), array_values($serieFlota)),
        'lecturas' => array_column(array_values($serieFlota), 'lecturas'),
        'contadores' => array_column(array_values($serieFlota), 'contadores'),
      ],
      'bateria' => ['etiquetas' => array_keys($tramosBateria), 'valores' => array_values($tramosBateria)],
      'cobertura' => ['etiquetas' => array_keys($tramosCobertura), 'valores' => array_values($tramosCobertura)],
      'porHora' => $porHora,
      'porCliente' => ['etiquetas' => array_keys($porCliente), 'valores' => array_values($porCliente)],
      'top' => array_map(fn($c) => ['numero' => $c['numero'], 'nodo' => $c['nombreNodo'], 'consumo' => $c['consumoRango']], $top),
      'heatmap' => $heatmap,
      'eventos' => $eventos,
      'gateways' => $gateways,
      'incidenciasGateway' => $incidenciasGateway,
      'clientes' => array_map(fn($c) => ['id' => (int)$c['id'], 'nombre' => $c['nombre'] ?: ('Usuario ' . $c['id'])], $raw['clientes']),
    ];
  }
}
