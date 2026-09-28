<?php

/**
 * Contadores de agua LoRaWAN: estado, consumo, posibles averías y alta.
 *
 * Lee las tablas lorawan_contadores / lorawan_contadores_lecturas (las rellena
 * server_panel con lo que manda ChirpStack) y safey_nodos para el nodo, el
 * usuario y la dirección de cada contador.
 *
 * @var object $db     Instancia de la base de datos ($db->query($sql) devuelve un array de filas)
 * @var int    $idUser ID del usuario activo
 *
 * Opcional, antes de incluir este archivo:
 *   $contadoresSoloUsuario = true;          solo los contadores de los nodos de $idUser
 *   window.CONTADORES_API = 'https://...';  (JS) base de server_panel si no es el mismo dominio
 *   $contadoresProveedorDatos = callable;   sustituye a las consultas (vista previa / demo)
 *
 * Parámetros GET: ct_desde, ct_hasta (YYYY-MM-DD) y ct_cliente (id de usuario).
 */

require_once __DIR__ . '/contadores_lorawan_comun.php';

// ---------------------------------------------------------------- datos ----
$ctFecha = fn($f) => (is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f)) ? $f : null;
$ctHoy = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');
$ctDesde = $ctFecha($_GET['ct_desde'] ?? null) ?? date('Y-m-d', strtotime("$ctHoy -29 days"));
$ctHasta = $ctFecha($_GET['ct_hasta'] ?? null) ?? $ctHoy;
if ($ctDesde > $ctHasta) [$ctDesde, $ctHasta] = [$ctHasta, $ctDesde];
if ((strtotime($ctHasta) - strtotime($ctDesde)) / 86400 >= CT_MAX_DIAS_RANGO) {
  $ctDesde = date('Y-m-d', strtotime("$ctHasta -" . (CT_MAX_DIAS_RANGO - 1) . ' days'));
}
$ctSoloUsuario = !empty($contadoresSoloUsuario);
$ctCliente = (!$ctSoloUsuario && isset($_GET['ct_cliente']) && ctype_digit((string)$_GET['ct_cliente'])) ? (int)$_GET['ct_cliente'] : null;
$ctFiltroUsuario = $ctSoloUsuario ? (int)$idUser : $ctCliente;

$ctRaw = isset($contadoresProveedorDatos) && is_callable($contadoresProveedorDatos)
  ? $contadoresProveedorDatos($ctDesde, $ctHasta, $ctFiltroUsuario)
  : ctCargarDatos($db, $ctDesde, $ctHasta, $ctFiltroUsuario);

$ctDatos = ctProcesar($ctRaw, $ctDesde, $ctHasta);
$ctDatos['config'] = [
  'soloUsuario' => $ctSoloUsuario,
  'cliente' => $ctCliente,
  'horasSinComunicar' => CT_HORAS_SIN_COMUNICAR,
  'bateriaBaja' => CT_BATERIA_BAJA,
  'demo' => isset($contadoresProveedorDatos),
];
$ctJson = json_encode($ctDatos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<script>
  // Metronic ya trae ApexCharts; si no está (p. ej. vista previa), se carga del CDN.
  if (!window.ApexCharts) document.write('<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.1/dist/apexcharts.min.js"><\/script>');
</script>

<script>
  const storeContadoresLorawan = {
    d: <?= $ctJson ?>,
    api: (window.CONTADORES_API || '').replace(/\/$/, ''),

    filtro: {texto: '', estado: 'todos', modelo: 'todos', nivel: 'todos', nodo: 'todos'},
    orden: {campo: 'numero', asc: true},
    pagina: 1,
    porPagina: 25,
    tipoIncidencia: 'todas',
    rango: {desde: '', hasta: '', cliente: ''},
    seleccionado: null,
    lecturas: [],
    cargandoLecturas: false,
    errorLecturas: null,
    autoRefresco: false,
    _temporizador: null,
    _graficas: {},

    colores: {primary: '#3699FF', success: '#1BC5BD', danger: '#F64E60', warning: '#FFA800', info: '#8950FC', gris: '#E4E6EF', oscuro: '#3F4254'},

    init() {
      this.rango = {desde: this.d.rango.desde, hasta: this.d.rango.hasta, cliente: this.d.config.cliente ?? ''};
      try {
        this.autoRefresco = localStorage.getItem('ctAutoRefresco') === '1';
      } catch (e) {}
      this.programarRefresco();
      this.$watch('filtro', () => (this.pagina = 1), {deep: true});
      this.$nextTick(() => this.pintarGraficas());
    },

    // ------------------------------------------------------------ listas ----
    get filtrados() {
      const t = this.filtro.texto.trim().toLowerCase();
      let lista = this.d.contadores.filter(c => {
        if (this.filtro.estado !== 'todos' && c.estado !== this.filtro.estado) return false;
        if (this.filtro.modelo !== 'todos' && c.modelo !== this.filtro.modelo) return false;
        if (this.filtro.nivel === 'incidencias' && c.nivel !== 'danger' && c.nivel !== 'warning') return false;
        if (this.filtro.nivel !== 'todos' && this.filtro.nivel !== 'incidencias' && c.nivel !== this.filtro.nivel) return false;
        if (this.filtro.nodo === 'conNodo' && !c.idNodo) return false;
        if (this.filtro.nodo === 'sinNodo' && c.idNodo) return false;
        if (t && ![c.numero, c.devEui, c.nombreNodo, c.usuario, c.direccion, c.ubicacion].some(v => (v || '').toLowerCase().includes(t))) return false;
        return true;
      });
      const {campo, asc} = this.orden;
      const numericos = ['volumen', 'consumoRango', 'mediaDiaria', 'bateria', 'rssi', 'disponibilidad', 'horasSinComunicar', 'lecturasRango'];
      const gravedad = {danger: 0, warning: 1, info: 2, ok: 3};
      const valor = c => (campo === 'nivel' ? gravedad[c.nivel] : numericos.includes(campo) ? c[campo] ?? -Infinity : c[campo] ?? '');
      lista.sort((a, b) => {
        const va = valor(a), vb = valor(b);
        const r = va === vb ? 0 : typeof va === 'number' && typeof vb === 'number' ? (va < vb ? -1 : 1) : String(va).localeCompare(String(vb), 'es', {numeric: true});
        return asc ? r : -r;
      });
      return lista;
    },
    get totalPaginas() {
      return Math.max(1, Math.ceil(this.filtrados.length / this.porPagina));
    },
    get paginados() {
      const i = (this.pagina - 1) * this.porPagina;
      return this.filtrados.slice(i, i + this.porPagina);
    },
    nombresIncidencia: {sin_comunicar: 'Sin comunicar', nunca: 'Sin lecturas', valvula: 'Válvula averiada', alarma: 'Alarma', bateria: 'Batería baja', retroceso: 'Retroceso de lectura', fuga: 'Posible fuga', pico: 'Consumo anómalo', parado: 'Sin consumo', cobertura: 'Cobertura débil', valvula_cerrada: 'Válvula cerrada', saldo: 'Sin saldo', sin_nodo: 'Sin nodo', nodo_borrado: 'Nodo borrado'},
    get tiposIncidencia() {
      const cuenta = {};
      this.d.incidencias.forEach(i => (cuenta[i.tipo] = (cuenta[i.tipo] || 0) + 1));
      return Object.keys(cuenta).map(k => ({tipo: k, nombre: this.nombresIncidencia[k] || k, n: cuenta[k]}));
    },
    get incidenciasFiltradas() {
      return this.d.incidencias.filter(i => this.tipoIncidencia === 'todas' || i.tipo === this.tipoIncidencia);
    },
    ordenar(campo) {
      this.orden = this.orden.campo === campo ? {campo, asc: !this.orden.asc} : {campo, asc: true};
    },
    iconoOrden(campo) {
      return this.orden.campo !== campo ? 'fa-sort text-muted' : this.orden.asc ? 'fa-sort-up text-primary' : 'fa-sort-down text-primary';
    },
    verIncidencias(nivel) {
      this.filtro.nivel = nivel;
      this.$nextTick(() => document.getElementById('ctTabla')?.scrollIntoView({behavior: 'smooth'}));
    },
    filtrarEstado(estado) {
      this.filtro.estado = estado;
      this.$nextTick(() => document.getElementById('ctTabla')?.scrollIntoView({behavior: 'smooth'}));
    },

    // ----------------------------------------------------- rango y recarga ----
    iso(fecha) {
      const z = n => String(n).padStart(2, '0');
      return `${fecha.getFullYear()}-${z(fecha.getMonth() + 1)}-${z(fecha.getDate())}`;
    },
    preset(p) {
      const hoy = new Date();
      let desde = new Date(hoy), hasta = new Date(hoy);
      if (p === 'hoy') {
      } else if (p === 'ayer') {
        desde.setDate(hoy.getDate() - 1);
        hasta = new Date(desde);
      } else if (['7', '30', '90'].includes(p)) desde.setDate(hoy.getDate() - (parseInt(p) - 1));
      else if (p === 'mes') desde = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
      else if (p === 'mesAnterior') {
        desde = new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1);
        hasta = new Date(hoy.getFullYear(), hoy.getMonth(), 0);
      } else if (p === 'anio') desde = new Date(hoy.getFullYear(), 0, 1);
      this.rango.desde = this.iso(desde);
      this.rango.hasta = this.iso(hasta);
      this.aplicarRango();
    },
    presetActivo(p) {
      const hoy = this.iso(new Date());
      const dias = Math.round((new Date(this.d.rango.hasta) - new Date(this.d.rango.desde)) / 86400000) + 1;
      return this.d.rango.hasta === hoy && ((p === 'hoy' && dias === 1) || String(dias) === p);
    },
    aplicarRango() {
      const url = new URL(window.location.href);
      url.searchParams.set('ct_desde', this.rango.desde);
      url.searchParams.set('ct_hasta', this.rango.hasta);
      if (this.rango.cliente) url.searchParams.set('ct_cliente', this.rango.cliente);
      else url.searchParams.delete('ct_cliente');
      window.location.href = url.toString();
    },
    recargar() {
      window.location.reload();
    },
    programarRefresco() {
      clearInterval(this._temporizador);
      try {
        localStorage.setItem('ctAutoRefresco', this.autoRefresco ? '1' : '0');
      } catch (e) {}
      if (this.autoRefresco) this._temporizador = setInterval(() => this.recargar(), 5 * 60 * 1000);
    },

    // ------------------------------------------------------------ formato ----
    num(v, dec = 3) {
      return v === null || v === undefined ? '—' : Number(v).toLocaleString('es-ES', {minimumFractionDigits: dec, maximumFractionDigits: dec});
    },
    m3(v) {
      return v === null || v === undefined ? '—' : this.num(v, 3) + ' m³';
    },
    litros(v) {
      return v === null || v === undefined ? '—' : Math.round(v * 1000).toLocaleString('es-ES') + ' L';
    },
    fecha(f, conHora = true) {
      if (!f) return '—';
      const [d, h = ''] = String(f).split(/[ T]/);
      const [y, m, dd] = d.split('-');
      return `${dd}/${m}/${y}` + (conHora && h ? ' ' + h.slice(0, 5) : '');
    },
    fechaIso(iso) {
      return iso ? new Date(iso).toLocaleString('es-ES', {day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '—';
    },
    hace(horas) {
      if (horas === null || horas === undefined) return 'Nunca';
      if (horas < 1) return 'hace ' + Math.max(1, Math.round(horas * 60)) + ' min';
      if (horas < 48) return 'hace ' + Math.round(horas) + ' h';
      return 'hace ' + Math.round(horas / 24) + ' días';
    },
    estadoTexto(e) {
      return {online: 'Comunicando', offline: 'Sin comunicar', nunca: 'Nunca'}[e] || e;
    },
    estadoClase(e) {
      return {online: 'label-light-success', offline: 'label-light-danger', nunca: 'label-light-dark'}[e] || 'label-light';
    },
    estadoPunto(e) {
      return {online: 'bg-success', offline: 'bg-danger', nunca: 'bg-secondary'}[e] || 'bg-secondary';
    },
    nivelClase(n) {
      return {danger: 'label-light-danger', warning: 'label-light-warning', info: 'label-light-info', ok: 'label-light-success'}[n] || 'label-light';
    },
    nivelIcono(n) {
      return {danger: 'fa-exclamation-circle text-danger', warning: 'fa-exclamation-triangle text-warning', info: 'fa-info-circle text-info'}[n] || 'fa-check-circle text-success';
    },
    bateriaClase(v) {
      if (v === null) return 'text-muted';
      return v < 3.3 ? 'text-danger' : v < this.d.config.bateriaBaja ? 'text-warning' : 'text-success';
    },
    bateriaPct(v) {
      // 3,0 V vacía - 3,65 V llena (aproximado para Li-SOCl2)
      return v === null ? 0 : Math.max(0, Math.min(100, Math.round(((v - 3.0) / 0.65) * 100)));
    },
    coberturaClase(rssi) {
      if (rssi === null) return 'label-light';
      return rssi >= -90 ? 'label-light-success' : rssi >= -105 ? 'label-light-primary' : rssi >= -115 ? 'label-light-warning' : 'label-light-danger';
    },
    coberturaTexto(rssi) {
      if (rssi === null) return '—';
      return rssi >= -90 ? 'Excelente' : rssi >= -105 ? 'Buena' : rssi >= -115 ? 'Regular' : 'Débil';
    },
    valvulaTexto(v) {
      return v === 'abierta' ? 'Abierta' : v === 'cerrada' ? 'Cerrada' : '—';
    },
    aviso(titulo, texto, icono = 'info') {
      if (window.Swal) Swal.fire(titulo, texto, icono);
      else alert(titulo + '\n' + texto);
    },

    // --------------------------------------------------------- exportar ----
    exportarCsv() {
      const cab = ['Nº contador', 'DevEUI', 'Modelo', 'Nodo', 'Usuario', 'Dirección', 'Estado', 'Última lectura', 'Lectura actual (m³)', `Consumo ${this.fecha(this.d.rango.desde, false)}-${this.fecha(this.d.rango.hasta, false)} (m³)`, 'Media diaria (m³)', 'Batería (V)', 'RSSI (dBm)', 'SNR (dB)', 'Válvula', 'Disponibilidad (%)', 'Incidencias'];
      const esc = v => `"${String(v ?? '').replace(/"/g, '""')}"`;
      const dec = v => (v === null || v === undefined ? '' : String(v).replace('.', ','));
      const filas = this.filtrados.map(c => [c.numero, c.devEui, c.modelo, c.nombreNodo, c.usuario, c.direccion, this.estadoTexto(c.estado), this.fecha(c.ultimaLectura), dec(c.volumen), dec(c.consumoRango), dec(c.mediaDiaria), dec(c.bateria), c.rssi, dec(c.snr), this.valvulaTexto(c.valvula), c.disponibilidad, c.incidencias.map(i => i.texto).join(' | ')]);
      const csv = '﻿' + [cab, ...filas].map(f => f.map(esc).join(';')).join('\r\n');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'}));
      a.download = `contadores_${this.d.rango.desde}_${this.d.rango.hasta}.csv`;
      a.click();
      URL.revokeObjectURL(a.href);
    },

    // ----------------------------------------------------------- detalle ----
    abrirDetalle(c) {
      this.seleccionado = c;
      this.lecturas = [];
      this.errorLecturas = null;
      $('#ctModalDetalle').modal('show');
      this.cargarLecturas(c);
    },
    async cargarLecturas(c) {
      if (this.d.config.demo) {
        this.errorLecturas = 'Vista previa en modo demo: las lecturas una a una se piden a server_panel y aquí no hay datos reales.';
        return;
      }
      this.cargandoLecturas = true;
      try {
        const q = new URLSearchParams({desde: this.d.rango.desde, hasta: this.d.rango.hasta + ' 23:59:59', limite: 1000});
        const res = await fetch(`${this.api}/contadores/lorawan/${c.devEui}/lecturas?${q}`);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        this.lecturas = await res.json();
      } catch (e) {
        this.errorLecturas = 'No se pudieron cargar las lecturas desde server_panel (' + e.message + ').';
      } finally {
        this.cargandoLecturas = false;
      }
    },

    // ---------------------------------------------------------- gráficas ----
    grafica(id, opciones) {
      const el = document.getElementById(id);
      if (!el || !window.ApexCharts) return;
      if (this._graficas[id]) this._graficas[id].destroy();
      this._graficas[id] = new ApexCharts(el, {dataLabels: {enabled: false}, ...opciones, chart: {fontFamily: 'Poppins, Helvetica, sans-serif', toolbar: {show: false}, ...opciones.chart}});
      this._graficas[id].render();
    },
    pintarGraficas() {
      const c = this.colores, d = this.d;
      this.grafica('ctGraficaConsumo', {
        chart: {type: 'line', height: 320, stacked: false},
        series: [{name: 'Consumo (m³)', type: 'column', data: d.serie.consumo}, {name: 'Lecturas recibidas', type: 'line', data: d.serie.lecturas}],
        xaxis: {categories: d.serie.dias, labels: {rotate: -45}},
        yaxis: [{title: {text: 'm³'}, labels: {formatter: v => this.num(v, 2)}}, {opposite: true, title: {text: 'Lecturas'}, labels: {formatter: v => Math.round(v)}}],
        colors: [c.primary, c.success],
        stroke: {width: [0, 3], curve: 'smooth'},
        plotOptions: {bar: {columnWidth: '60%', borderRadius: 3}},
        tooltip: {shared: true, y: {formatter: (v, o) => (o.seriesIndex === 0 ? this.m3(v) : v + ' lecturas')}},
        legend: {position: 'top'},
      });
      this.grafica('ctGraficaEstado', {
        chart: {type: 'donut', height: 320, events: {dataPointSelection: (e, ctx, cfg) => this.filtrarEstado(['online', 'offline'][cfg.dataPointIndex])}},
        series: [d.kpis.online, d.kpis.offline],
        labels: ['Comunicando', 'Sin comunicar'],
        colors: [c.success, c.danger],
        legend: {position: 'bottom'},
        plotOptions: {pie: {donut: {size: '65%', labels: {show: true, total: {show: true, label: 'Contadores', formatter: () => d.kpis.total}}}}},
      });
      this.grafica('ctGraficaTop', {
        chart: {type: 'bar', height: 320},
        series: [{name: 'Consumo', data: d.top.map(t => t.consumo)}],
        xaxis: {categories: d.top.map(t => t.nodo ? `${t.numero} · ${t.nodo}` : t.numero), labels: {formatter: v => this.num(v, 1)}},
        plotOptions: {bar: {horizontal: true, borderRadius: 3, barHeight: '65%'}},
        colors: [c.info],
        tooltip: {y: {formatter: v => this.m3(v)}},
        noData: {text: 'Sin consumo en el periodo'},
      });
      this.grafica('ctGraficaBateria', {
        chart: {type: 'bar', height: 260},
        series: [{name: 'Contadores', data: d.bateria.valores}],
        xaxis: {categories: d.bateria.etiquetas},
        colors: [c.danger, c.warning, c.primary, c.success, c.success],
        plotOptions: {bar: {distributed: true, borderRadius: 3, columnWidth: '55%'}},
        legend: {show: false},
      });
      this.grafica('ctGraficaCobertura', {
        chart: {type: 'bar', height: 260},
        series: [{name: 'Contadores', data: d.cobertura.valores}],
        xaxis: {categories: d.cobertura.etiquetas},
        colors: [c.success, c.primary, c.warning, c.danger],
        plotOptions: {bar: {distributed: true, borderRadius: 3, columnWidth: '55%'}},
        legend: {show: false},
      });
      this.grafica('ctGraficaHoras', {
        chart: {type: 'bar', height: 260},
        series: [{name: 'Lecturas', data: d.porHora}],
        xaxis: {categories: d.porHora.map((_, h) => String(h).padStart(2, '0') + 'h')},
        colors: [c.primary],
        plotOptions: {bar: {borderRadius: 2, columnWidth: '70%'}},
      });
      this.grafica('ctGraficaClientes', {
        chart: {type: 'bar', height: 260},
        series: [{name: 'Consumo', data: d.porCliente.valores}],
        xaxis: {categories: d.porCliente.etiquetas},
        colors: [c.warning],
        plotOptions: {bar: {borderRadius: 3, columnWidth: '55%'}},
        yaxis: {labels: {formatter: v => this.num(v, 1)}},
        tooltip: {y: {formatter: v => this.m3(v)}},
      });
      const filas = [...d.heatmap].reverse();
      this.grafica('ctGraficaMapa', {
        chart: {type: 'heatmap', height: Math.max(220, filas.length * 20 + 70)},
        series: filas,
        colors: [c.success],
        plotOptions: {heatmap: {radius: 2, enableShades: false, colorScale: {ranges: [{from: 0, to: 0, color: '#EBEDF3', name: 'Sin lecturas'}, {from: 1, to: 2, color: '#9BE7E2', name: '1-2 lecturas'}, {from: 3, to: 100000, color: c.success, name: '3 o más'}]}}},
        stroke: {width: 1, colors: ['#fff']},
        xaxis: {position: 'top'},
        legend: {position: 'bottom'},
        tooltip: {y: {formatter: v => v + ' lecturas'}},
      });
    },
    pintarDetalle() {
      const s = this.seleccionado;
      if (!s) return;
      const dias = Object.keys(s.consumoDias);
      this.grafica('ctGraficaDetalle', {
        chart: {type: 'bar', height: 260},
        series: [{name: 'Consumo', data: dias.map(k => s.consumoDias[k])}],
        xaxis: {categories: dias.map(k => this.fecha(k, false).slice(0, 5)), labels: {rotate: -45}},
        colors: [this.colores.primary],
        plotOptions: {bar: {borderRadius: 3, columnWidth: '60%'}},
        yaxis: {labels: {formatter: v => this.num(v, 3)}},
        tooltip: {y: {formatter: v => (v === null ? 'Sin datos' : this.m3(v))}},
        noData: {text: 'Sin lecturas en el periodo'},
      });
    },
  };
</script>

<div x-data="storeContadoresLorawan" class="ct-contadores">

  <!-- ============================================================ Cabecera -->
  <div class="card card-custom gutter-b">
    <div class="card-header flex-wrap py-3">
      <div class="card-title">
        <h3 class="card-label font-weight-bolder text-dark">
          <i class="fa fa-tint text-primary mr-2"></i>Contadores de agua LoRaWAN
          <span class="d-block text-muted pt-2 font-size-sm">
            Del <span x-text="fecha(d.rango.desde, false)"></span> al <span x-text="fecha(d.rango.hasta, false)"></span>
            (<span x-text="d.rango.dias"></span> días) · actualizado <span x-text="fecha(d.rango.generado)"></span>
          </span>
        </h3>
      </div>
      <div class="card-toolbar flex-wrap">
        <div class="btn-group btn-group-sm mr-2 mb-2" role="group">
          <template x-for="p in [{k:'hoy',t:'Hoy'},{k:'ayer',t:'Ayer'},{k:'7',t:'7 días'},{k:'30',t:'30 días'},{k:'90',t:'90 días'},{k:'mes',t:'Este mes'},{k:'mesAnterior',t:'Mes anterior'},{k:'anio',t:'Este año'}]" :key="p.k">
            <button type="button" class="btn btn-light-primary font-weight-bold" :class="presetActivo(p.k) && 'active'" @click="preset(p.k)" x-text="p.t"></button>
          </template>
        </div>
        <div class="d-flex align-items-center mr-2 mb-2">
          <input type="date" class="form-control form-control-sm form-control-solid" style="width: 150px" x-model="rango.desde">
          <span class="mx-2 text-muted">a</span>
          <input type="date" class="form-control form-control-sm form-control-solid" style="width: 150px" x-model="rango.hasta">
        </div>
        <template x-if="!d.config.soloUsuario && d.clientes.length">
          <select class="form-control form-control-sm form-control-solid mr-2 mb-2" style="width: 200px" x-model="rango.cliente">
            <option value="">Todos los clientes</option>
            <template x-for="cl in d.clientes" :key="cl.id">
              <option :value="cl.id" x-text="cl.nombre" :selected="String(cl.id) === String(rango.cliente)"></option>
            </template>
          </select>
        </template>
        <button type="button" class="btn btn-sm btn-primary font-weight-bold mr-2 mb-2" @click="aplicarRango()"><i class="fa fa-filter"></i> Aplicar</button>
        <button type="button" class="btn btn-sm btn-light font-weight-bold mr-2 mb-2" @click="recargar()" title="Actualizar"><i class="fa fa-sync-alt"></i></button>
        <label class="checkbox checkbox-sm mr-3 mb-2 d-flex align-items-center" title="Recargar cada 5 minutos">
          <input type="checkbox" class="mr-1" x-model="autoRefresco" @change="programarRefresco()"> Auto
        </label>
        <button type="button" class="btn btn-sm btn-light-success font-weight-bold mr-2 mb-2" @click="exportarCsv()"><i class="fa fa-file-csv"></i> Exportar</button>
      </div>
    </div>
    <template x-if="d.config.demo">
      <div class="alert alert-custom alert-light-warning m-5 mb-0 py-3">
        <div class="alert-icon"><i class="fa fa-flask text-warning"></i></div>
        <div class="alert-text">Vista previa con <b>datos de demostración</b> generados en memoria (no son lecturas reales).</div>
      </div>
    </template>
  </div>

  <!-- ================================================================ KPIs -->
  <div class="row">
    <template x-for="k in [
        {t:'Contadores', v:d.kpis.total, s:d.kpis.conNodo + ' con nodo · ' + d.kpis.sinNodo + ' sin nodo', i:'fa-tachometer-alt', c:'primary', a:() => filtrarEstado('todos')},
        {t:'Comunicando', v:d.kpis.online, s:'en las últimas ' + d.config.horasSinComunicar + ' h', i:'fa-wifi', c:'success', a:() => filtrarEstado('online')},
        {t:'Sin comunicar', v:d.kpis.offline, s:'más de ' + d.config.horasSinComunicar + ' h sin datos', i:'fa-plug', c:'danger', a:() => filtrarEstado('offline')},
        {t:'Posibles averías', v:d.kpis.conAveria, s:d.kpis.conAviso + ' con avisos', i:'fa-tools', c:'danger', a:() => verIncidencias('danger')},
        {t:'Consumo del periodo', v:m3(d.kpis.consumoTotal), s:'media ' + m3(d.kpis.consumoMedioDia) + '/día', i:'fa-water', c:'info', a:null},
        {t:'Consumo hoy', v:m3(d.kpis.consumoHoy), s:'media por contador ' + m3(d.kpis.consumoMedioContador), i:'fa-calendar-day', c:'primary', a:null},
        {t:'Lecturas recibidas', v:d.kpis.lecturas.toLocaleString('es-ES'), s:'disponibilidad ' + d.kpis.disponibilidad + ' %', i:'fa-signal', c:'success', a:null},
        {t:'Batería media', v:d.kpis.bateriaMedia === null ? '—' : num(d.kpis.bateriaMedia, 2) + ' V', s:'mínima ' + (d.kpis.bateriaMin === null ? '—' : num(d.kpis.bateriaMin, 2) + ' V'), i:'fa-battery-three-quarters', c:'warning', a:null},
        {t:'Cobertura media', v:d.kpis.rssiMedio === null ? '—' : d.kpis.rssiMedio + ' dBm', s:coberturaTexto(d.kpis.rssiMedio) + ' · ' + d.kpis.gateways + ' gateway(s)', i:'fa-broadcast-tower', c:'info', a:null},
        {t:'Volumen acumulado', v:m3(d.kpis.volumenTotal), s:'suma de las lecturas actuales', i:'fa-database', c:'dark', a:null},
        {t:'Sin nodo', v:d.kpis.sinNodo, s:'pendientes de enlazar en la BD', i:'fa-link', c:'warning', a:() => { filtro.nodo = 'sinNodo'; $nextTick(() => document.getElementById('ctTabla').scrollIntoView({behavior: 'smooth'})); }},
        {t:'Con avisos', v:d.kpis.conAviso, s:'batería, fugas, cobertura...', i:'fa-exclamation-triangle', c:'warning', a:() => verIncidencias('warning')},
      ]" :key="k.t">
      <div class="col-xl-2 col-lg-3 col-md-4 col-6">
        <div class="card card-custom card-stretch gutter-b" :class="k.a && 'ct-clic'" @click="k.a && k.a()">
          <div class="card-body p-5">
            <div class="d-flex align-items-center justify-content-between">
              <span class="text-muted font-weight-bold font-size-sm" x-text="k.t"></span>
              <span class="symbol symbol-35" :class="'symbol-light-' + k.c"><span class="symbol-label"><i class="fa" :class="k.i + ' text-' + k.c"></i></span></span>
            </div>
            <div class="font-weight-bolder font-size-h3 text-dark-75 mt-2" x-text="k.v"></div>
            <div class="text-muted font-size-xs mt-1" x-text="k.s"></div>
          </div>
        </div>
      </div>
    </template>
  </div>

  <!-- ============================================ Consumo diario y estado -->
  <div class="row">
    <div class="col-xl-8">
      <div class="card card-custom card-stretch gutter-b">
        <div class="card-header border-0 pt-5">
          <h3 class="card-title align-items-start flex-column">
            <span class="card-label font-weight-bolder text-dark">Consumo diario de todos los contadores</span>
            <span class="text-muted mt-2 font-weight-bold font-size-sm">m³ por día y lecturas recibidas</span>
          </h3>
        </div>
        <div class="card-body pt-0"><div id="ctGraficaConsumo"></div></div>
      </div>
    </div>
    <div class="col-xl-4">
      <div class="card card-custom card-stretch gutter-b">
        <div class="card-header border-0 pt-5">
          <h3 class="card-title align-items-start flex-column">
            <span class="card-label font-weight-bolder text-dark">Estado de conexión</span>
            <span class="text-muted mt-2 font-weight-bold font-size-sm">Pulsa un sector para filtrar la tabla</span>
          </h3>
        </div>
        <div class="card-body pt-0"><div id="ctGraficaEstado"></div></div>
      </div>
    </div>
  </div>

  <!-- ================================================ Averías y alarmas -->
  <div class="row">
    <div class="col-xl-7">
      <div class="card card-custom card-stretch gutter-b">
        <div class="card-header border-0 pt-5 flex-wrap">
          <h3 class="card-title align-items-start flex-column">
            <span class="card-label font-weight-bolder text-dark">Posibles averías e incidencias</span>
            <span class="text-muted mt-2 font-weight-bold font-size-sm"><span x-text="d.incidencias.length"></span> detectadas en el periodo</span>
          </h3>
          <div class="card-toolbar">
            <select class="form-control form-control-sm form-control-solid" x-model="tipoIncidencia">
              <option value="todas">Todas</option>
              <template x-for="t in tiposIncidencia" :key="t.tipo">
                <option :value="t.tipo" x-text="`${t.nombre} (${t.n})`"></option>
              </template>
            </select>
          </div>
        </div>
        <div class="card-body pt-2 ct-scroll" style="max-height: 460px">
          <template x-if="!incidenciasFiltradas.length">
            <div class="text-center text-muted py-10"><i class="fa fa-check-circle text-success fa-2x mb-3 d-block"></i>Sin incidencias en el periodo</div>
          </template>
          <template x-for="(i, n) in incidenciasFiltradas" :key="i.devEui + i.tipo + n">
            <div class="d-flex align-items-center py-3 border-bottom ct-clic" @click="abrirDetalle(d.contadores.find(c => c.devEui === i.devEui))">
              <i class="fa fa-lg mr-4" :class="nivelIcono(i.nivel)"></i>
              <div class="flex-grow-1">
                <div class="font-weight-bolder text-dark-75" x-text="i.texto"></div>
                <div class="text-muted font-size-sm">
                  <span x-text="i.modelo + ' ' + i.numero"></span>
                  <span x-show="i.nodo" x-text="' · ' + i.nodo"></span>
                  <span x-show="i.usuario" x-text="' · ' + i.usuario"></span>
                  · última lectura <span x-text="fecha(i.ultimaLectura)"></span>
                </div>
              </div>
              <span class="label label-inline font-weight-bold" :class="nivelClase(i.nivel)" x-text="{danger: 'Avería', warning: 'Aviso', info: 'Info'}[i.nivel]"></span>
            </div>
          </template>
        </div>
      </div>
    </div>

    <div class="col-xl-5">
      <div class="card card-custom card-stretch gutter-b">
        <div class="card-header border-0 pt-5">
          <h3 class="card-title align-items-start flex-column">
            <span class="card-label font-weight-bolder text-dark">Alarmas recibidas de los contadores</span>
            <span class="text-muted mt-2 font-weight-bold font-size-sm">Últimas <span x-text="d.eventos.length"></span> del periodo</span>
          </h3>
        </div>
        <div class="card-body pt-0 ct-scroll" style="max-height: 380px">
          <table class="table table-sm table-vertical-center mb-0">
            <thead><tr class="text-muted"><th>Fecha</th><th>Contador</th><th>Alarmas</th><th>Lectura</th><th>Batería</th><th>Válvula</th></tr></thead>
            <tbody>
              <template x-for="(e, n) in d.eventos" :key="n">
                <tr>
                  <td class="text-nowrap" x-text="fecha(e.fecha)"></td>
                  <td class="text-nowrap font-weight-bold" x-text="e.numero"></td>
                  <td><template x-for="a in e.alarmas" :key="a"><span class="label label-sm label-light-danger label-inline mr-1" x-text="a"></span></template></td>
                  <td class="text-nowrap" x-text="m3(e.volumen)"></td>
                  <td class="text-nowrap" x-text="e.bateria === null ? '—' : num(e.bateria, 2) + ' V'"></td>
                  <td x-text="valvulaTexto(e.valvula)"></td>
                </tr>
              </template>
              <template x-if="!d.eventos.length"><tr><td colspan="6" class="text-center text-muted py-5">Ninguna alarma en el periodo</td></tr></template>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ===================================================== Distribuciones -->
  <div class="row">
    <template x-for="g in [
        {id:'ctGraficaTop', t:'Los que más consumen', s:'Top 10 del periodo', col:'col-xl-4'},
        {id:'ctGraficaBateria', t:'Estado de las baterías', s:'Última lectura (nueva: 3,6 V)', col:'col-xl-4'},
        {id:'ctGraficaCobertura', t:'Cobertura radio', s:'RSSI de la última lectura (dBm)', col:'col-xl-4'},
        {id:'ctGraficaHoras', t:'¿A qué hora informan?', s:'Lecturas recibidas por hora del día', col:'col-xl-6'},
        {id:'ctGraficaClientes', t:'Consumo por cliente', s:'m³ en el periodo', col:'col-xl-6'},
      ]" :key="g.id">
      <div :class="g.col">
        <div class="card card-custom card-stretch gutter-b">
          <div class="card-header border-0 pt-5">
            <h3 class="card-title align-items-start flex-column">
              <span class="card-label font-weight-bolder text-dark" x-text="g.t"></span>
              <span class="text-muted mt-2 font-weight-bold font-size-sm" x-text="g.s"></span>
            </h3>
          </div>
          <div class="card-body pt-0"><div :id="g.id"></div></div>
        </div>
      </div>
    </template>
  </div>

  <!-- ============================================================= Tabla -->
  <div class="card card-custom gutter-b" id="ctTabla">
    <div class="card-header flex-wrap border-0 pt-5">
      <h3 class="card-title align-items-start flex-column">
        <span class="card-label font-weight-bolder text-dark">Todos los contadores</span>
        <span class="text-muted mt-2 font-weight-bold font-size-sm"><span x-text="filtrados.length"></span> de <span x-text="d.contadores.length"></span></span>
      </h3>
      <div class="card-toolbar flex-wrap">
        <div class="input-icon mr-2 mb-2">
          <input type="text" class="form-control form-control-sm form-control-solid" placeholder="Nº, DevEUI, nodo, cliente, dirección..." style="width: 260px" x-model.debounce.250ms="filtro.texto">
          <span><i class="fa fa-search text-muted"></i></span>
        </div>
        <select class="form-control form-control-sm form-control-solid mr-2 mb-2" style="width: 150px" x-model="filtro.estado">
          <option value="todos">Todos los estados</option>
          <option value="online">Comunicando</option>
          <option value="offline">Sin comunicar</option>
        </select>
        <select class="form-control form-control-sm form-control-solid mr-2 mb-2" style="width: 150px" x-model="filtro.nivel">
          <option value="todos">Cualquier incidencia</option>
          <option value="incidencias">Averías y avisos</option>
          <option value="danger">Solo averías</option>
          <option value="warning">Solo avisos</option>
          <option value="ok">Sin incidencias</option>
        </select>
        <select class="form-control form-control-sm form-control-solid mr-2 mb-2" style="width: 120px" x-model="filtro.modelo">
          <option value="todos">DN15 y DN20</option>
          <option value="DN15">DN15</option>
          <option value="DN20">DN20</option>
        </select>
        <select class="form-control form-control-sm form-control-solid mb-2" style="width: 140px" x-model="filtro.nodo">
          <option value="todos">Con y sin nodo</option>
          <option value="conNodo">Con nodo</option>
          <option value="sinNodo">Sin nodo</option>
        </select>
      </div>
    </div>
    <div class="card-body pt-0">
      <div class="table-responsive">
        <table class="table table-head-custom table-head-bg table-vertical-center table-hover">
          <thead>
            <tr class="text-left text-uppercase">
              <template x-for="h in [{k:'nivel',t:''},{k:'numero',t:'Contador'},{k:'nombreNodo',t:'Nodo / cliente'},{k:'ultimaLectura',t:'Última comunicación'},{k:'volumen',t:'Lectura'},{k:'consumoRango',t:'Consumo periodo'},{k:'mediaDiaria',t:'Media/día'},{k:'bateria',t:'Batería'},{k:'rssi',t:'Cobertura'},{k:'disponibilidad',t:'Disponib.'}]" :key="h.k">
                <th class="ct-clic text-nowrap" @click="ordenar(h.k)"><span x-text="h.t"></span> <i class="fa" :class="iconoOrden(h.k)"></i></th>
              </template>
              <th>Incidencias</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <template x-for="c in paginados" :key="c.devEui">
              <tr>
                <td><i class="fa" :class="nivelIcono(c.nivel)"></i></td>
                <td class="text-nowrap">
                  <span class="font-weight-bolder text-dark-75 ct-clic" @click="abrirDetalle(c)" x-text="c.numero"></span>
                  <span class="label label-sm label-light-primary label-inline ml-1" x-text="c.modelo"></span>
                  <div class="text-muted font-size-xs" x-text="c.devEui"></div>
                </td>
                <td style="min-width: 200px">
                  <template x-if="c.idNodo">
                    <div>
                      <div class="font-weight-bold text-dark-75" x-text="c.nombreNodo"></div>
                      <div class="text-muted font-size-xs" x-text="[c.usuario, c.direccion].filter(Boolean).join(' · ')"></div>
                    </div>
                  </template>
                  <template x-if="!c.idNodo">
                    <span class="label label-inline label-light-warning font-weight-bold" title="Pendiente de enlazar con su nodo en la base de datos"><i class="fa fa-unlink mr-1"></i> Sin nodo</span>
                  </template>
                </td>
                <td class="text-nowrap">
                  <span class="label label-inline font-weight-bold" :class="estadoClase(c.estado)" x-text="estadoTexto(c.estado)"></span>
                  <div class="text-muted font-size-xs mt-1" x-text="c.ultimaLectura ? fecha(c.ultimaLectura) + ' · ' + hace(c.horasSinComunicar) : '—'"></div>
                </td>
                <td class="text-nowrap font-weight-bold" x-text="m3(c.volumen)"></td>
                <td class="text-nowrap">
                  <span class="font-weight-bolder text-primary" x-text="m3(c.consumoRango)"></span>
                  <div class="text-muted font-size-xs" x-show="c.consumoUltimoDia" x-text="c.consumoUltimoDia ? 'último día ' + litros(c.consumoUltimoDia.consumo) : ''"></div>
                </td>
                <td class="text-nowrap" x-text="litros(c.mediaDiaria)"></td>
                <td class="text-nowrap" style="min-width: 110px">
                  <span class="font-weight-bold" :class="bateriaClase(c.bateria)" x-text="c.bateria === null ? '—' : num(c.bateria, 2) + ' V'"></span>
                  <div class="progress mt-1" style="height: 4px"><div class="progress-bar" :class="c.bateria !== null && c.bateria < d.config.bateriaBaja ? 'bg-warning' : 'bg-success'" :style="`width: ${bateriaPct(c.bateria)}%`"></div></div>
                </td>
                <td class="text-nowrap">
                  <span class="label label-inline font-weight-bold" :class="coberturaClase(c.rssi)" x-text="coberturaTexto(c.rssi)"></span>
                  <div class="text-muted font-size-xs mt-1" x-text="c.rssi === null ? '' : c.rssi + ' dBm · SNR ' + num(c.snr, 1)"></div>
                </td>
                <td class="text-nowrap">
                  <span class="font-weight-bold" :class="c.disponibilidad >= 90 ? 'text-success' : c.disponibilidad >= 60 ? 'text-warning' : 'text-danger'" x-text="c.estado === 'nunca' ? '—' : c.disponibilidad + ' %'"></span>
                </td>
                <td style="min-width: 180px">
                  <template x-for="(i, n) in c.incidencias.slice(0, 3)" :key="n">
                    <span class="label label-inline font-weight-bold mr-1 mb-1 text-nowrap" :class="nivelClase(i.nivel)" :title="i.texto" x-text="nombresIncidencia[i.tipo] || i.tipo"></span>
                  </template>
                  <span class="text-muted font-size-xs" x-show="c.incidencias.length > 3" x-text="'+' + (c.incidencias.length - 3)"></span>
                </td>
                <td class="text-right text-nowrap">
                  <button type="button" class="btn btn-icon btn-light-primary btn-sm" title="Ver detalle" @click="abrirDetalle(c)"><i class="fa fa-chart-bar"></i></button>
                </td>
              </tr>
            </template>
            <template x-if="!paginados.length">
              <tr><td colspan="12" class="text-center text-muted py-10">No hay contadores con estos filtros</td></tr>
            </template>
          </tbody>
        </table>
      </div>
      <div class="d-flex justify-content-between align-items-center flex-wrap">
        <div class="d-flex align-items-center">
          <select class="form-control form-control-sm form-control-solid mr-2" style="width: 80px" x-model.number="porPagina" @change="pagina = 1">
            <option value="25">25</option><option value="50">50</option><option value="100">100</option><option value="500">500</option>
          </select>
          <span class="text-muted font-size-sm">por página</span>
        </div>
        <div class="d-flex align-items-center">
          <button type="button" class="btn btn-icon btn-sm btn-light mr-2" :disabled="pagina <= 1" @click="pagina--"><i class="fa fa-angle-left"></i></button>
          <span class="font-weight-bold mr-2">Página <span x-text="pagina"></span> de <span x-text="totalPaginas"></span></span>
          <button type="button" class="btn btn-icon btn-sm btn-light" :disabled="pagina >= totalPaginas" @click="pagina++"><i class="fa fa-angle-right"></i></button>
        </div>
      </div>
    </div>
  </div>

  <!-- ================================================ Mapa de comunicaciones -->
  <div class="card card-custom gutter-b">
    <div class="card-header border-0 pt-5">
      <h3 class="card-title align-items-start flex-column">
        <span class="card-label font-weight-bolder text-dark">Mapa de comunicaciones</span>
        <span class="text-muted mt-2 font-weight-bold font-size-sm">Lecturas recibidas por contador y día (últimos 31 días del periodo). Un hueco gris es un día sin comunicar.</span>
      </h3>
    </div>
    <div class="card-body pt-0 ct-scroll" style="max-height: 560px"><div id="ctGraficaMapa"></div></div>
  </div>

  <!-- ============================================================ Gateways -->
  <div class="row">
    <div class="col-xl-12">
      <div class="card card-custom card-stretch gutter-b">
        <div class="card-header border-0 pt-5">
          <h3 class="card-title align-items-start flex-column">
            <span class="card-label font-weight-bolder text-dark">Gateways</span>
            <span class="text-muted mt-2 font-weight-bold font-size-sm">Qué antena recibe cada lectura</span>
          </h3>
        </div>
        <div class="card-body pt-0">
          <template x-for="g in d.gateways" :key="g.gatewayId">
            <div class="d-flex align-items-center py-3 border-bottom">
              <span class="symbol symbol-40 symbol-light-info mr-4"><span class="symbol-label"><i class="fa fa-broadcast-tower text-info"></i></span></span>
              <div class="flex-grow-1">
                <div class="font-weight-bolder text-dark-75" x-text="g.gatewayId"></div>
                <div class="text-muted font-size-sm"><span x-text="g.contadores"></span> contadores · <span x-text="g.lecturas.toLocaleString('es-ES')"></span> lecturas · última <span x-text="fecha(g.ultimaLectura)"></span></div>
              </div>
              <span class="label label-inline font-weight-bold" :class="coberturaClase(g.rssiMedio)" x-text="g.rssiMedio + ' dBm'"></span>
            </div>
          </template>
          <template x-if="!d.gateways.length"><div class="text-center text-muted py-5">Ningún gateway ha recibido lecturas en el periodo</div></template>
        </div>
      </div>
    </div>
  </div>

  <!-- =========================================================== Detalle -->
  <div class="modal fade" id="ctModalDetalle" tabindex="-1" role="dialog" aria-hidden="true" x-init="$($el).on('shown.bs.modal', () => pintarDetalle())">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
      <div class="modal-content">
        <template x-if="seleccionado">
          <div>
            <div class="modal-header">
              <h5 class="modal-title">
                <span class="ct-punto mr-2" :class="estadoPunto(seleccionado.estado)"></span>
                <span x-text="seleccionado.modelo + ' ' + seleccionado.numero"></span>
                <span class="text-muted font-size-sm ml-2" x-text="seleccionado.devEui"></span>
              </h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><i aria-hidden="true" class="ki ki-close"></i>&times;</button>
            </div>
            <div class="modal-body">
              <div class="row mb-4">
                <template x-for="k in [
                    {t:'Estado', v:estadoTexto(seleccionado.estado), s:hace(seleccionado.horasSinComunicar)},
                    {t:'Lectura actual', v:m3(seleccionado.volumen), s:seleccionado.totalLecturas + ' lecturas en total'},
                    {t:'Consumo del periodo', v:m3(seleccionado.consumoRango), s:'media ' + litros(seleccionado.mediaDiaria) + '/día'},
                    {t:'Batería', v:seleccionado.bateria === null ? '—' : num(seleccionado.bateria, 2) + ' V', s:'mínima del periodo ' + (seleccionado.bateriaMinRango === null ? '—' : num(seleccionado.bateriaMinRango, 2) + ' V')},
                    {t:'Cobertura', v:seleccionado.rssi === null ? '—' : seleccionado.rssi + ' dBm', s:'SNR ' + num(seleccionado.snr, 1) + ' · media ' + (seleccionado.rssiMedio ?? '—') + ' dBm'},
                    {t:'Válvula / pago', v:valvulaTexto(seleccionado.valvula), s:(seleccionado.modoPago || '—') + (seleccionado.modoPago === 'prepago' ? ' · saldo ' + m3(seleccionado.saldo) : '')},
                  ]" :key="k.t">
                  <div class="col-md-2 col-6 mb-3">
                    <div class="bg-light rounded p-3 h-100">
                      <div class="text-muted font-size-xs" x-text="k.t"></div>
                      <div class="font-weight-bolder text-dark-75" x-text="k.v"></div>
                      <div class="text-muted font-size-xs" x-text="k.s"></div>
                    </div>
                  </div>
                </template>
              </div>
              <div class="row">
                <div class="col-md-8">
                  <div class="font-weight-bolder mb-2">Consumo por día</div>
                  <div id="ctGraficaDetalle"></div>
                </div>
                <div class="col-md-4">
                  <div class="font-weight-bolder mb-2">Datos</div>
                  <table class="table table-sm font-size-sm">
                    <tr><td class="text-muted">Nodo</td><td x-text="seleccionado.nombreNodo || 'Sin nodo (pendiente de enlazar)'"></td></tr>
                    <tr><td class="text-muted">Cliente</td><td x-text="seleccionado.usuario || '—'"></td></tr>
                    <tr><td class="text-muted">Dirección</td><td x-text="seleccionado.direccion || '—'"></td></tr>
                    <tr><td class="text-muted">Servicio</td><td x-text="seleccionado.servicio || '—'"></td></tr>
                    <tr><td class="text-muted">Primera lectura</td><td x-text="fecha(seleccionado.primeraLectura)"></td></tr>
                    <tr><td class="text-muted">Última lectura</td><td x-text="fecha(seleccionado.ultimaLectura)"></td></tr>
                    <tr><td class="text-muted">Disponibilidad</td><td x-text="seleccionado.disponibilidad + ' % de los días'"></td></tr>
                    <tr><td class="text-muted">Gateway</td><td x-text="seleccionado.gatewayId || '—'"></td></tr>
                  </table>
                </div>
              </div>
              <template x-if="seleccionado.incidencias.length">
                <div class="mt-4">
                  <div class="font-weight-bolder mb-2">Incidencias</div>
                  <template x-for="(i, n) in seleccionado.incidencias" :key="n">
                    <div class="mb-1"><i class="fa mr-2" :class="nivelIcono(i.nivel)"></i><span x-text="i.texto"></span></div>
                  </template>
                </div>
              </template>
              <div class="mt-5">
                <div class="font-weight-bolder mb-2">Lecturas una a una <span class="text-muted font-weight-normal" x-show="lecturas.length" x-text="'(' + lecturas.length + ')'"></span></div>
                <div x-show="cargandoLecturas" class="text-muted py-3"><i class="fa fa-spinner fa-spin"></i> Cargando...</div>
                <div x-show="errorLecturas" class="alert alert-light-warning py-2" x-text="errorLecturas"></div>
                <div class="table-responsive ct-scroll" style="max-height: 300px" x-show="lecturas.length">
                  <table class="table table-sm table-striped font-size-sm mb-0">
                    <thead><tr class="text-muted"><th>Recibida</th><th>Lectura</th><th>Batería</th><th>Válvula</th><th>Alarmas</th><th>RSSI / SNR</th><th>Trama</th><th>Trama en bruto</th></tr></thead>
                    <tbody>
                      <template x-for="l in lecturas" :key="l.id">
                        <tr>
                          <td class="text-nowrap" x-text="fechaIso(l.fecha)"></td>
                          <td class="text-nowrap" x-text="m3(l.volumenM3)"></td>
                          <td class="text-nowrap" x-text="l.bateria === null ? '—' : num(l.bateria, 2) + ' V'"></td>
                          <td x-text="valvulaTexto(l.datos && l.datos.valvula)"></td>
                          <td x-text="l.alarmas || '—'"></td>
                          <td class="text-nowrap" x-text="(l.rssi ?? '—') + ' / ' + num(l.snr, 1)"></td>
                          <td x-text="l.fCnt"></td>
                          <td class="text-monospace text-muted" style="font-size: 10px" x-text="l.payloadHex"></td>
                        </tr>
                      </template>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>

</div>

<style>
  .ct-contadores .ct-clic { cursor: pointer; }
  .ct-contadores .ct-scroll { overflow-y: auto; }
  .ct-contadores .ct-punto { display: inline-block; width: 10px; height: 10px; border-radius: 50%; }
  .ct-contadores .card.ct-clic:hover { box-shadow: 0 0 20px 0 rgba(54, 153, 255, .25); }
</style>
