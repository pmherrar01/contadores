// -----------------------------------------------------------------------------
// Pruebas del endpoint de contadores (server_panel/src/routes/contadores.ts)
// sin ChirpStack: le manda eventos como los que manda ChirpStack y comprueba
// las respuestas.
//
//   node probar-endpoint.js http://localhost:3002 <CHIRPSTACK_HTTP_TOKEN>
//
// Deja un contador de prueba con devEui ffffffff0000xxxx. Para borrarlo:
//   DELETE l FROM lorawan_contadores_lecturas l JOIN lorawan_contadores c ON c.id = l.idContador WHERE c.devEui LIKE 'ffffffff%';
//   DELETE FROM lorawan_contadores WHERE devEui LIKE 'ffffffff%';
// -----------------------------------------------------------------------------
'use strict';
const crypto = require('crypto');
const ejemplo = require('./ejemplos/uplink-simulador.json');

const [base, token] = process.argv.slice(2);
if (!base || !token) {
  console.error('Uso: node probar-endpoint.js <url-base-api> <token>');
  process.exit(1);
}
const DEV_EUI = 'ffffffff0000' + crypto.randomBytes(2).toString('hex');

let fallos = 0;
function comprobar(nombre, ok, detalle) {
  console.log(`${ok ? '✔' : '✘'} ${nombre}${ok ? '' : `  -> ${detalle}`}`);
  if (!ok) fallos++;
}

let fCnt = 100;
function uplink(cambios = {}) {
  const u = JSON.parse(JSON.stringify(ejemplo));
  u.deduplicationId = crypto.randomUUID();
  u.time = new Date().toISOString();
  u.fCnt = fCnt++;
  u.deviceInfo.devEui = DEV_EUI;
  u.deviceInfo.deviceName = 'Contador de prueba (probar-endpoint.js)';
  return Object.assign(u, cambios);
}

async function peticion(metodo, ruta, cuerpo, cabeceras = {}) {
  const res = await fetch(`${base}/contadores${ruta}`, {method: metodo, headers: {'Content-Type': 'application/json', ...cabeceras}, body: cuerpo ? JSON.stringify(cuerpo) : undefined});
  let json = null;
  try {
    json = await res.json();
  } catch (e) {
    /* sin cuerpo */
  }
  return {status: res.status, json};
}

const post = (evento, cuerpo, tok = token) => peticion('POST', `/lorawan/uplink?event=${evento}`, cuerpo, {Authorization: `Bearer ${tok}`});
const get = ruta => peticion('GET', ruta);

async function main() {
  console.log(`API: ${base}   contador de prueba: ${DEV_EUI}\n`);

  // --- webhook -------------------------------------------------------------
  let r = await post('up', uplink(), 'token-malo');
  comprobar('Token incorrecto -> 401', r.status === 401, r.status);

  const primera = uplink({object: {volumen_m3: 100.5, bateria: 3.61, alarmas: []}});
  r = await post('up', primera);
  comprobar('Primera lectura de un contador nuevo -> 200 (lo registra)', r.status === 200 && r.json?.ok && !r.json?.duplicado, JSON.stringify(r));

  r = await post('up', primera);
  comprobar('Misma trama otra vez (mismo deduplicationId) -> duplicado', r.status === 200 && r.json?.duplicado === true, JSON.stringify(r));

  r = await post('up', {...primera, deduplicationId: crypto.randomUUID()});
  comprobar('Reintento del contador (mismo fCnt y contenido, otro deduplicationId) -> duplicado', r.status === 200 && r.json?.duplicado === true, JSON.stringify(r));

  const sinCodec = uplink({data: Buffer.from('0102a0ff', 'hex').toString('base64')});
  delete sinCodec.object;
  r = await post('up', sinCodec);
  comprobar('Lectura sin decodificar (fallo del codec) -> 200', r.status === 200 && r.json?.ok && !r.json?.duplicado, JSON.stringify(r));

  r = await post('up', uplink({time: new Date(Date.now() - 3600e3).toISOString(), object: {volumen_m3: 1, bateria: 3.2, alarmas: ['valvula_averiada']}}));
  comprobar('Lectura atrasada (llega desordenada) -> 200', r.status === 200 && r.json?.ok, JSON.stringify(r));

  r = await post('up', {deviceInfo: {devEui: 'no-es-un-eui'}});
  comprobar('Lectura sin devEui válido -> 400', r.status === 400, r.status);

  for (const ev of ['join', 'status', 'ack', 'txack', 'location', 'integration']) {
    r = await post(ev, {deviceInfo: {devEui: DEV_EUI}, devAddr: '01020304'});
    comprobar(`Evento "${ev}" -> 200 (ignorado)`, r.status === 200, r.status);
  }
  r = await post('log', {deviceInfo: {devEui: DEV_EUI}, level: 'ERROR', code: 'UPLINK_CODEC', description: 'prueba de error de codec'});
  comprobar('Evento "log" de error -> 200 (se escribe en el log)', r.status === 200, r.status);

  // --- consultas -----------------------------------------------------------
  r = await get('/lorawan');
  const contador = Array.isArray(r.json) ? r.json.find(c => c.devEui === DEV_EUI) : null;
  comprobar('GET /lorawan incluye el contador nuevo, sin nodo todavía', r.status === 200 && contador && contador.idNodo === null, JSON.stringify(contador ?? r.json)?.slice(0, 300));
  if (contador) {
    const esperado = DEV_EUI.startsWith('00') ? DEV_EUI.slice(2) : DEV_EUI;
    comprobar('  numSerie = número impreso (devEui sin el "00" inicial)', contador.numSerie === esperado, contador.numSerie);
    comprobar('  última lectura = la más reciente con volumen (100.5 m³), no la atrasada', Number(contador.ultimoVolumenM3) === 100.5, contador.ultimoVolumenM3);
    comprobar('  batería = 3.61', Number(contador.ultimaBateria) === 3.61, contador.ultimaBateria);
  }

  r = await get(`/lorawan/${DEV_EUI}/lecturas`);
  comprobar('GET lecturas -> 3 (los duplicados y reintentos no cuentan)', r.status === 200 && r.json?.length === 3, `${r.status} ${r.json?.length}`);
  if (r.json?.length) {
    const cruda = r.json.find(l => l.payloadHex === '0102a0ff');
    comprobar('  la trama sin decodificar se guarda en bruto con volumen NULL', cruda && cruda.volumenM3 === null && cruda.datos === null, JSON.stringify(cruda));
    comprobar('  "datos" se devuelve como objeto JSON', r.json.some(l => l.datos && typeof l.datos === 'object'), JSON.stringify(r.json[0]?.datos));
    comprobar('  ordenadas de más reciente a más antigua', new Date(r.json[0].fecha) >= new Date(r.json[r.json.length - 1].fecha), `${r.json[0].fecha} / ${r.json[r.json.length - 1].fecha}`);
  }

  r = await get(`/lorawan/${DEV_EUI}/lecturas?limite=1`);
  comprobar('GET lecturas ?limite=1 -> 1 lectura', r.status === 200 && r.json?.length === 1, r.json?.length);

  r = await get('/lorawan/xyz/lecturas');
  comprobar('GET lecturas con devEui inválido -> 400', r.status === 400, r.status);

  const hoy = new Date().toLocaleDateString('sv-SE', {timeZone: 'Europe/Madrid'});
  r = await get(`/lorawan/${DEV_EUI}/lecturas?hasta=${hoy}`);
  comprobar('GET lecturas ?hasta=<hoy> (solo fecha) incluye el día entero', r.status === 200 && r.json?.length === 3, `${r.status} ${r.json?.length}`);
  comprobar('  las fechas salen en hora de Madrid (YYYY-MM-DD HH:mm:ss)', /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(r.json?.[0]?.fecha || ''), r.json?.[0]?.fecha);

  r = await get(`/lorawan/${DEV_EUI}/lecturas?desde=ayer`);
  comprobar('GET lecturas con fecha inválida -> 400', r.status === 400, r.status);

  r = await post('up', uplink({object: {volumen_m3: 5e12, bateria: 99999, alarmas: []}}));
  comprobar('Lectura con valores fuera de rango -> 200 (se guarda con esos campos a NULL)', r.status === 200 && r.json?.ok, JSON.stringify(r));

  // --- estado de los gateways ----------------------------------------------
  const estado = {generado: new Date().toISOString(), gateways: [{gatewayEui: 'AA555A0000000001', nombre: 'Gateway de prueba', estado: 'online', ultimaConexion: new Date().toISOString(), rxUltimaHora: 12, txUltimaHora: 3, rx24h: 200, tx24h: 40}, {gatewayEui: 'no-valido'}]};
  r = await peticion('POST', '/lorawan/gateways/estado', estado, {Authorization: 'Bearer token-malo'});
  comprobar('Estado de gateways con token incorrecto -> 401', r.status === 401, r.status);
  r = await peticion('POST', '/lorawan/gateways/estado', {}, {Authorization: `Bearer ${token}`});
  comprobar('Estado de gateways sin lista -> 400', r.status === 400, r.status);
  r = await peticion('POST', '/lorawan/gateways/estado', estado, {Authorization: `Bearer ${token}`});
  comprobar('Estado de gateways -> 200 (guarda 1, ignora el EUI no válido)', r.status === 200 && r.json?.gateways === 1, JSON.stringify(r));
  r = await get('/lorawan/gateways');
  const gw = Array.isArray(r.json) ? r.json.find(g => g.gatewayEui === 'aa555a0000000001') : null;
  comprobar('GET /lorawan/gateways lo devuelve online, con nombre y rx de la última hora', gw && gw.estado === 'online' && gw.nombre === 'Gateway de prueba' && gw.rxUltimaHora === 12, JSON.stringify(gw));
  comprobar('  y cuenta los contadores cuya última lectura llegó por él (≥ 1)', gw && Number(gw.contadores) >= 1, gw?.contadores);
  r = await get('/lorawan');
  const c2 = Array.isArray(r.json) ? r.json.find(c => c.devEui === DEV_EUI) : null;
  comprobar('El contador queda enlazado con ese gateway (idGateway)', c2 && gw && c2.idGateway === gw.id, `${c2?.idGateway} / ${gw?.id}`);

  r = await get('/lorawan/sin-asignar');
  comprobar('Ya no existe GET /lorawan/sin-asignar', r.status !== 200 || !Array.isArray(r.json), r.status);

  console.log(`\n${fallos === 0 ? 'TODO OK' : `${fallos} FALLO(S)`}`);
  console.log(`Borrar datos de prueba:\n  DELETE l FROM lorawan_contadores_lecturas l JOIN lorawan_contadores c ON c.id = l.idContador WHERE c.devEui LIKE 'ffffffff%';\n  DELETE FROM lorawan_contadores WHERE devEui LIKE 'ffffffff%';`);
  process.exit(fallos === 0 ? 0 : 1);
}

main().catch(err => {
  console.error('ERROR:', err.message);
  process.exit(1);
});
