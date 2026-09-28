// -----------------------------------------------------------------------------
// Deja ChirpStack configurado para los contadores. Lo ejecuta el contenedor
// solo al arrancar; también a mano sin reiniciar:
//     docker exec chirpstack-contadores provisionar
// Se puede ejecutar las veces que haga falta: crea lo que falta y actualiza lo
// que ya existe. La configuración sale de lib/config.js (variables de entorno
// y CSV de /config).
//
// Qué hace:
//   1. Login como admin (si la contraseña configurada aún no vale y la de
//      fábrica "admin" sí, la cambia).
//   2. Tenant, perfiles de dispositivo (con su codec), aplicación.
//   3. Integración HTTP de la aplicación hacia nuestra API.
//   4. Gateways de gateways.csv.
//   5. Contadores de contadores.csv (y el contador simulado si SIMULADOR=1).
// -----------------------------------------------------------------------------
'use strict';
const fs = require('fs');
const {cargarConfig} = require('./lib/config');
const {ChirpStack, esNoEncontrado} = require('./lib/chirpstack');

const common = require('@chirpstack/chirpstack-api/common/common_pb');
const tenantPb = require('@chirpstack/chirpstack-api/api/tenant_pb');
const userPb = require('@chirpstack/chirpstack-api/api/user_pb');
const deviceProfilePb = require('@chirpstack/chirpstack-api/api/device_profile_pb');
const applicationPb = require('@chirpstack/chirpstack-api/api/application_pb');
const gatewayPb = require('@chirpstack/chirpstack-api/api/gateway_pb');
const devicePb = require('@chirpstack/chirpstack-api/api/device_pb');

const config = cargarConfig();
const cs = new ChirpStack(config.chirpstack.servidor);

function log(...args) {
  console.log('[provisionar]', ...args);
}

async function login() {
  const {usuario, password} = config.chirpstack;
  try {
    await cs.login(usuario, password);
    log(`Login en ${config.chirpstack.servidor} como ${usuario}`);
    return;
  } catch (err) {
    if (password === 'admin') throw err;
  }

  // Primera vez en un ChirpStack nuevo: entra con la de fábrica y la cambia.
  await cs.login(usuario, 'admin');
  const perfil = await cs.perfil();
  const req = new userPb.UpdateUserPasswordRequest();
  req.setUserId(perfil.getUser().getId());
  req.setPassword(password);
  await cs.call(cs.user, 'updatePassword', req);
  await cs.login(usuario, password);
  log('Contraseña de admin cambiada de la de fábrica a la de la config');
}

async function asegurarTenant() {
  const req = new tenantPb.ListTenantsRequest();
  req.setLimit(100);
  req.setSearch(config.tenant);
  const res = await cs.call(cs.tenant, 'list', req);
  const existente = res.getResultList().find(t => t.getName() === config.tenant);
  if (existente) {
    log(`Tenant "${config.tenant}" ya existe`);
    return existente.getId();
  }

  const tenant = new tenantPb.Tenant();
  tenant.setName(config.tenant);
  tenant.setCanHaveGateways(true);
  tenant.setPrivateGatewaysUp(false);
  tenant.setPrivateGatewaysDown(false);
  const crear = new tenantPb.CreateTenantRequest();
  crear.setTenant(tenant);
  const creado = await cs.call(cs.tenant, 'create', crear);
  log(`Tenant "${config.tenant}" creado`);
  return creado.getId();
}

async function asegurarPerfil(tenantId, perfil) {
  const macVersion = common.MacVersion[perfil.macVersion];
  const regParams = common.RegParamsRevision[perfil.regParamsRevision];
  if (macVersion === undefined) throw new Error(`macVersion desconocida: ${perfil.macVersion}`);
  if (regParams === undefined) throw new Error(`regParamsRevision desconocida: ${perfil.regParamsRevision}`);

  const dp = new deviceProfilePb.DeviceProfile();
  dp.setTenantId(tenantId);
  dp.setName(perfil.nombre);
  dp.setRegion(common.Region.EU868);
  dp.setMacVersion(macVersion);
  dp.setRegParamsRevision(regParams);
  dp.setAdrAlgorithmId('default');
  dp.setSupportsOtaa(true);
  dp.setUplinkInterval(perfil.uplinkIntervalSeg);
  dp.setFlushQueueOnActivate(true);
  dp.setDeviceStatusReqInterval(1);
  dp.setPayloadCodecRuntime(deviceProfilePb.CodecRuntime.JS);
  dp.setPayloadCodecScript(fs.readFileSync(perfil.codec, 'utf8'));

  const listar = new deviceProfilePb.ListDeviceProfilesRequest();
  listar.setTenantId(tenantId);
  listar.setLimit(100);
  listar.setSearch(perfil.nombre);
  const res = await cs.call(cs.deviceProfile, 'list', listar);
  const existente = res.getResultList().find(p => p.getName() === perfil.nombre);

  if (existente) {
    dp.setId(existente.getId());
    const actualizar = new deviceProfilePb.UpdateDeviceProfileRequest();
    actualizar.setDeviceProfile(dp);
    await cs.call(cs.deviceProfile, 'update', actualizar);
    log(`Perfil "${perfil.nombre}" actualizado (codec ${perfil.codec})`);
    return existente.getId();
  }

  const crear = new deviceProfilePb.CreateDeviceProfileRequest();
  crear.setDeviceProfile(dp);
  const creado = await cs.call(cs.deviceProfile, 'create', crear);
  log(`Perfil "${perfil.nombre}" creado (codec ${perfil.codec})`);
  return creado.getId();
}

async function asegurarAplicacion(tenantId) {
  const listar = new applicationPb.ListApplicationsRequest();
  listar.setTenantId(tenantId);
  listar.setLimit(100);
  listar.setSearch(config.aplicacion);
  const res = await cs.call(cs.application, 'list', listar);
  const existente = res.getResultList().find(a => a.getName() === config.aplicacion);
  if (existente) {
    log(`Aplicación "${config.aplicacion}" ya existe`);
    return existente.getId();
  }

  const app = new applicationPb.Application();
  app.setTenantId(tenantId);
  app.setName(config.aplicacion);
  app.setDescription('Contadores de agua LoRaWAN. Las lecturas se envían a server_panel por HTTP.');
  const crear = new applicationPb.CreateApplicationRequest();
  crear.setApplication(app);
  const creado = await cs.call(cs.application, 'create', crear);
  log(`Aplicación "${config.aplicacion}" creada`);
  return creado.getId();
}

async function asegurarIntegracionHttp(applicationId) {
  const {url, token} = config.integracionHttp;
  const integracion = new applicationPb.HttpIntegration();
  integracion.setApplicationId(applicationId);
  integracion.setEncoding(applicationPb.Encoding.JSON);
  integracion.setEventEndpointUrl(url);
  integracion.getHeadersMap().set('Authorization', 'Bearer ' + token);

  const get = new applicationPb.GetHttpIntegrationRequest();
  get.setApplicationId(applicationId);
  let existe = true;
  try {
    await cs.call(cs.application, 'getHttpIntegration', get);
  } catch (err) {
    if (!esNoEncontrado(err)) throw err;
    existe = false;
  }

  if (existe) {
    const req = new applicationPb.UpdateHttpIntegrationRequest();
    req.setIntegration(integracion);
    await cs.call(cs.application, 'updateHttpIntegration', req);
  } else {
    const req = new applicationPb.CreateHttpIntegrationRequest();
    req.setIntegration(integracion);
    await cs.call(cs.application, 'createHttpIntegration', req);
  }
  log(`Integración HTTP ${existe ? 'actualizada' : 'creada'} -> ${url}`);
}

async function asegurarGateway(tenantId, gw) {
  const id = gw.id.toLowerCase();
  const get = new gatewayPb.GetGatewayRequest();
  get.setGatewayId(id);
  try {
    await cs.call(cs.gateway, 'get', get);
    log(`Gateway ${id} (${gw.nombre}) ya existe`);
    return;
  } catch (err) {
    if (!esNoEncontrado(err)) throw err;
  }

  const gateway = new gatewayPb.Gateway();
  gateway.setGatewayId(id);
  gateway.setTenantId(tenantId);
  gateway.setName(gw.nombre);
  gateway.setDescription(gw.descripcion || '');
  gateway.setStatsInterval(30);
  const crear = new gatewayPb.CreateGatewayRequest();
  crear.setGateway(gateway);
  await cs.call(cs.gateway, 'create', crear);
  log(`Gateway ${id} (${gw.nombre}) creado`);
}

async function asegurarDispositivo(applicationId, deviceProfileId, esLorawan11, d) {
  const device = new devicePb.Device();
  device.setDevEui(d.devEui);
  device.setJoinEui(d.joinEui);
  device.setName(d.nombre);
  device.setDescription(d.descripcion || '');
  device.setApplicationId(applicationId);
  device.setDeviceProfileId(deviceProfileId);
  // Como en el ChirpStack del vendedor: el contador puede reiniciar su contador
  // de tramas y no queremos que ChirpStack descarte esas lecturas.
  device.setSkipFcntCheck(true);

  const get = new devicePb.GetDeviceRequest();
  get.setDevEui(d.devEui);
  let existe = true;
  try {
    await cs.call(cs.device, 'get', get);
  } catch (err) {
    if (!esNoEncontrado(err)) throw err;
    existe = false;
  }

  if (existe) {
    const req = new devicePb.UpdateDeviceRequest();
    req.setDevice(device);
    await cs.call(cs.device, 'update', req);
  } else {
    const req = new devicePb.CreateDeviceRequest();
    req.setDevice(device);
    await cs.call(cs.device, 'create', req);
  }

  // En LoRaWAN 1.0.x ChirpStack guarda la AppKey en el campo nwk_key.
  const keys = new devicePb.DeviceKeys();
  keys.setDevEui(d.devEui);
  if (esLorawan11) {
    keys.setNwkKey(d.nwkKey);
    keys.setAppKey(d.appKey);
  } else {
    keys.setNwkKey(d.appKey);
  }

  const getKeys = new devicePb.GetDeviceKeysRequest();
  getKeys.setDevEui(d.devEui);
  let hayKeys = true;
  try {
    await cs.call(cs.device, 'getKeys', getKeys);
  } catch (err) {
    if (!esNoEncontrado(err)) throw err;
    hayKeys = false;
  }
  if (hayKeys) {
    const req = new devicePb.UpdateDeviceKeysRequest();
    req.setDeviceKeys(keys);
    await cs.call(cs.device, 'updateKeys', req);
  } else {
    const req = new devicePb.CreateDeviceKeysRequest();
    req.setDeviceKeys(keys);
    await cs.call(cs.device, 'createKeys', req);
  }
  log(`Contador ${d.devEui} (${d.nombre}) ${existe ? 'actualizado' : 'creado'}`);
}

// Líneas de un CSV sin comentarios ni vacías, con su número de línea.
function lineasCsv(ruta) {
  if (!fs.existsSync(ruta)) return [];
  return fs
    .readFileSync(ruta, 'utf8')
    .split(/\r?\n/)
    .map((texto, i) => ({texto: texto.trim(), n: i + 1}))
    .filter(l => l.texto && !l.texto.startsWith('#'))
    .map(l => ({n: l.n, campos: l.texto.split(',').map(c => c.trim())}));
}

function leerGateways(ruta) {
  const errores = [];
  const gateways = lineasCsv(ruta).map(({n, campos}) => {
    const [id = '', nombre = '', descripcion = ''] = campos;
    if (!/^[0-9a-fA-F]{16}$/.test(id)) errores.push(`línea ${n}: gatewayId inválido`);
    return {id: id.toLowerCase(), nombre: nombre || id.toLowerCase(), descripcion};
  });
  if (errores.length) throw new Error(`Errores en ${ruta}:\n  ${errores.join('\n  ')}`);
  return gateways;
}

function leerCsv(ruta, esLorawan11) {
  const errores = [];
  const contadores = lineasCsv(ruta).map(({n, campos}) => {
    const [devEui = '', joinEui = '', appKey = '', nwkKey = '', nombre = '', descripcion = ''] = campos;
    const d = {devEui: devEui.toLowerCase(), joinEui: joinEui.toLowerCase(), appKey: appKey.toLowerCase(), nwkKey: nwkKey.toLowerCase(), nombre: nombre || devEui.toLowerCase(), descripcion};
    if (!/^[0-9a-f]{16}$/.test(d.devEui)) errores.push(`línea ${n}: devEui inválido`);
    if (!/^[0-9a-f]{16}$/.test(d.joinEui)) errores.push(`línea ${n}: joinEui inválido`);
    if (!/^[0-9a-f]{32}$/.test(d.appKey)) errores.push(`línea ${n}: appKey inválida`);
    if (esLorawan11 && !/^[0-9a-f]{32}$/.test(d.nwkKey)) errores.push(`línea ${n}: nwkKey obligatoria en LoRaWAN 1.1`);
    return d;
  });
  if (errores.length) throw new Error(`Errores en ${ruta}:\n  ${errores.join('\n  ')}`);
  return contadores;
}

async function main() {
  await login();

  const tenantId = await asegurarTenant();
  const perfilContadorId = await asegurarPerfil(tenantId, config.perfilContador);
  const applicationId = await asegurarAplicacion(tenantId);
  await asegurarIntegracionHttp(applicationId);

  const gateways = leerGateways(config.gatewaysCsv);
  for (const gw of gateways) {
    await asegurarGateway(tenantId, gw);
  }
  if (gateways.length === 0) log(`Sin gateways en ${config.gatewaysCsv}`);

  const esLorawan11 = config.perfilContador.macVersion === 'LORAWAN_1_1_0';
  const contadores = leerCsv(config.contadoresCsv, esLorawan11);
  for (const d of contadores) {
    await asegurarDispositivo(applicationId, perfilContadorId, esLorawan11, d);
  }
  if (contadores.length === 0) log(`Sin contadores en ${config.contadoresCsv}`);

  const sim = config.simulador;
  if (sim && sim.activo) {
    await asegurarGateway(tenantId, {id: sim.gatewayId, nombre: 'Gateway simulado', descripcion: 'herramientas/simulador.js'});
    await asegurarDispositivo(applicationId, perfilContadorId, esLorawan11, {devEui: sim.devEui, joinEui: sim.joinEui, appKey: sim.appKey, nombre: 'Contador simulado', descripcion: 'herramientas/simulador.js'});
  }

  log(`Listo: ${gateways.length} gateway(s), ${contadores.length} contador(es), integración -> ${config.integracionHttp.url}`);
}

main().catch(err => {
  console.error('[provisionar] ERROR:', err.details || err.message || err);
  process.exit(1);
});
