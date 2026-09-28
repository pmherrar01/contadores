// -----------------------------------------------------------------------------
// Estado de los gateways LoRaWAN -> server_panel.
//
// Corre dentro del contenedor (lo lanza supervisord): cada
// GATEWAYS_INTERVALO_SEG segundos (300 por defecto) pregunta a ChirpStack por
// sus gateways y manda a server_panel, con el mismo token que las lecturas:
//   POST <API_URL sin /uplink>/gateways/estado   (o API_URL_GATEWAYS si se define)
//   {generado, gateways: [{gatewayEui, nombre, estado, ultimaConexion, rxUltimaHora, ...}]}
//
//   node estado-gateways.js            bucle
//   node estado-gateways.js --una-vez  un solo envío (pruebas)
// -----------------------------------------------------------------------------
'use strict';
const {Timestamp} = require('google-protobuf/google/protobuf/timestamp_pb');
const {cargarConfig} = require('./lib/config');
const {ChirpStack} = require('./lib/chirpstack');
const common = require('@chirpstack/chirpstack-api/common/common_pb');
const tenantPb = require('@chirpstack/chirpstack-api/api/tenant_pb');
const gatewayPb = require('@chirpstack/chirpstack-api/api/gateway_pb');

const config = cargarConfig();
const URL_ESTADO = process.env.API_URL_GATEWAYS || config.integracionHttp.url.replace(/\/uplink\/?$/, '/gateways/estado');
const INTERVALO_SEG = Math.max(60, parseInt(process.env.GATEWAYS_INTERVALO_SEG || '300', 10) || 300);
const ESTADOS = {[gatewayPb.GatewayState.NEVER_SEEN]: 'nunca', [gatewayPb.GatewayState.ONLINE]: 'online', [gatewayPb.GatewayState.OFFLINE]: 'offline'};

function log(...args) {
  console.log('[gateways]', ...args);
}

function iso(ts) {
  return ts ? ts.toDate().toISOString() : null;
}

// Valores por hora de una métrica de ChirpStack (último = hora en curso).
function porHora(metrica) {
  const ds = metrica ? metrica.getDatasetsList() : [];
  return ds.length ? ds[0].getDataList().map(v => Math.round(v)) : [];
}

async function recoger(cs) {
  await cs.login(config.chirpstack.usuario, config.chirpstack.password);

  const tenants = new tenantPb.ListTenantsRequest();
  tenants.setLimit(100);
  tenants.setSearch(config.tenant);
  const tenant = (await cs.call(cs.tenant, 'list', tenants)).getResultList().find(t => t.getName() === config.tenant);
  if (!tenant) throw new Error(`No existe el tenant "${config.tenant}" (¿aún no ha terminado el aprovisionamiento?)`);

  const listar = new gatewayPb.ListGatewaysRequest();
  listar.setTenantId(tenant.getId());
  listar.setLimit(1000);
  const lista = (await cs.call(cs.gateway, 'list', listar)).getResultList();

  const ahora = new Date();
  const gateways = [];
  for (const g of lista) {
    const metricas = new gatewayPb.GetGatewayMetricsRequest();
    metricas.setGatewayId(g.getGatewayId());
    metricas.setStart(Timestamp.fromDate(new Date(ahora.getTime() - 24 * 3600 * 1000)));
    metricas.setEnd(Timestamp.fromDate(ahora));
    metricas.setAggregation(common.Aggregation.HOUR);
    let rx = [];
    let tx = [];
    try {
      const m = await cs.call(cs.gateway, 'getMetrics', metricas);
      rx = porHora(m.getRxPackets());
      tx = porHora(m.getTxPackets());
    } catch (err) {
      log(`sin métricas de ${g.getGatewayId()}: ${err.details || err.message}`);
    }
    const loc = g.getLocation();
    gateways.push({
      gatewayEui: g.getGatewayId(),
      nombre: g.getName(),
      descripcion: g.getDescription(),
      estado: ESTADOS[g.getState()] || 'nunca',
      ultimaConexion: iso(g.getLastSeenAt()),
      rxUltimaHora: rx.length ? rx[rx.length - 1] : null,
      txUltimaHora: tx.length ? tx[tx.length - 1] : null,
      rx24h: rx.reduce((a, b) => a + b, 0),
      tx24h: tx.reduce((a, b) => a + b, 0),
      latitud: loc && (loc.getLatitude() || loc.getLongitude()) ? loc.getLatitude() : null,
      longitud: loc && (loc.getLatitude() || loc.getLongitude()) ? loc.getLongitude() : null,
      altitud: loc && (loc.getLatitude() || loc.getLongitude()) ? loc.getAltitude() : null,
    });
  }
  return {generado: ahora.toISOString(), gateways};
}

async function enviar(datos) {
  const res = await fetch(URL_ESTADO, {
    method: 'POST',
    headers: {'Content-Type': 'application/json', Authorization: 'Bearer ' + config.integracionHttp.token},
    body: JSON.stringify(datos),
    signal: AbortSignal.timeout(15000),
  });
  if (!res.ok) throw new Error(`server_panel respondió ${res.status} en ${URL_ESTADO}`);
}

async function ciclo(cs) {
  try {
    const datos = await recoger(cs);
    await enviar(datos);
    log(`${datos.gateways.length} gateway(s) enviados: ${datos.gateways.map(g => `${g.gatewayEui}=${g.estado}`).join(', ')}`);
    return true;
  } catch (err) {
    log('ERROR:', err.details || err.message || err);
    return false;
  }
}

async function main() {
  const cs = new ChirpStack(config.chirpstack.servidor);
  if (process.argv.includes('--una-vez')) {
    process.exit((await ciclo(cs)) ? 0 : 1);
  }
  log(`cada ${INTERVALO_SEG} s -> ${URL_ESTADO}`);
  await new Promise(r => setTimeout(r, 60 * 1000)); // deja terminar el arranque y el aprovisionamiento
  for (;;) {
    await ciclo(cs);
    await new Promise(r => setTimeout(r, INTERVALO_SEG * 1000));
  }
}

main();
