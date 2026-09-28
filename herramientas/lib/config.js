// Configuración del aprovisionamiento de ChirpStack.
//
// Dentro del contenedor sale de las variables de entorno (chirpstack.env /
// chirpstack.local.env) y de los CSV de /config (despliegue/config/):
//   API_URL, API_TOKEN          obligatorias: endpoint de server_panel y su token
//   ADMIN_PASSWORD              opcional: si no, la genera entrypoint.sh en /data/admin_password
//   LORAWAN_MAC_VERSION         opcional (LORAWAN_1_0_2, la del vendedor)
//   LORAWAN_REG_PARAMS          opcional (A, la del vendedor)
//   SIMULADOR=1                 da de alta también el gateway y el contador del simulador
//   /config/gateways.csv        gateways
//   /config/contadores.csv      contadores
//   /config/contador-agua.js    si existe, sustituye al codec que va dentro de la imagen
'use strict';
const fs = require('fs');
const path = require('path');

const DIR = path.join(__dirname, '..');
const CONFIG_DIR = process.env.CONFIG_DIR || '/config';
const CODEC_DIR = process.env.CODEC_DIR || path.join(DIR, '..', 'codec');

function leer(ruta) {
  try {
    return fs.readFileSync(ruta, 'utf8').trim();
  } catch (e) {
    return '';
  }
}

function cargarConfig() {
  const faltan = ['API_URL', 'API_TOKEN'].filter(v => !process.env[v] || process.env[v].startsWith('CAMBIAR'));
  if (faltan.length) {
    console.error(`[provisionar] ERROR: faltan las variables ${faltan.join(', ')} (ver chirpstack.env)`);
    process.exit(1);
  }

  const codecPropio = path.join(CONFIG_DIR, 'contador-agua.js');
  return {
    chirpstack: {
      servidor: process.env.CHIRPSTACK_API || '127.0.0.1:8080',
      usuario: 'admin',
      password: process.env.ADMIN_PASSWORD || leer('/data/admin_password') || 'admin'
    },
    tenant: 'Modularbox',
    aplicacion: 'Contadores agua',
    integracionHttp: {url: process.env.API_URL, token: process.env.API_TOKEN},
    perfilContador: {
      nombre: 'Contador agua ultrasonico EU868',
      macVersion: process.env.LORAWAN_MAC_VERSION || 'LORAWAN_1_0_2',
      regParamsRevision: process.env.LORAWAN_REG_PARAMS || 'A',
      uplinkIntervalSeg: 3600,
      codec: fs.existsSync(codecPropio) ? codecPropio : path.join(CODEC_DIR, 'contador-agua.js')
    },
    gatewaysCsv: path.join(CONFIG_DIR, 'gateways.csv'),
    contadoresCsv: path.join(CONFIG_DIR, 'contadores.csv'),
    simulador: process.env.SIMULADOR === '1' ? {...cargarSimulador(), activo: true} : {activo: false}
  };
}

// Gateway y contador simulados (herramientas/simulador.js). Los mismos datos
// los usa el contenedor para darlos de alta cuando SIMULADOR=1; el contador
// simulado usa el mismo perfil y codec que los reales.
function cargarSimulador() {
  return JSON.parse(fs.readFileSync(path.join(DIR, 'simulador.json'), 'utf8'));
}

module.exports = {cargarConfig, cargarSimulador};
