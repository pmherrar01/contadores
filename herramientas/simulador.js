// -----------------------------------------------------------------------------
// Simulador de gateway + contador LoRaWAN para probar toda la cadena sin el
// contador real:
//
//   simulador (Semtech UDP, como el gateway Rime) -> ChirpStack -> integración
//   HTTP -> API
//
// Hace un join OTAA de verdad (LoRaWAN 1.0.x, con la AppKey de simulador.json) y
// manda lecturas cifradas con el protocolo del vendedor (68 10 01 ... 16,
// puerto 8, confirmadas), igual que el contador real. Las decodifica el codec
// real (codec/contador-agua.js).
//
//   node simulador.js                         join + 1 lectura
//   node simulador.js join                    solo el join
//   node simulador.js lectura --litros 12345 --bateria 3.3 --valvula cerrada --saldo 10 --alarma 0001
//   node simulador.js varias 5 --cada 3       join + 5 lecturas, una cada 3 s
//
// Opciones de lectura: --litros (acumulado), --bateria (V), --valvula abierta|cerrada|averiada,
// --saldo (m³), --prepago, --alarma (código hex de 2 bytes).
//
// Cada ejecución empieza con un join (así funciona aunque se haya recreado el
// ChirpStack). En .simulador-estado.json se guardan el DevNonce, que no se
// puede repetir, y los litros acumulados.
// -----------------------------------------------------------------------------
'use strict';
const dgram = require('dgram');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const lora = require('lora-packet');
const {cargarSimulador} = require('./lib/config');

// Mismos datos que da de alta el contenedor con SIMULADOR=1 (simulador.json).
const sim = cargarSimulador();

const ESTADO = path.join(__dirname, '.simulador-estado.json');
const [HOST, PUERTO] = sim.servidorUdp.split(':');
const GATEWAY_EUI = Buffer.from(sim.gatewayId, 'hex');
const APP_KEY = Buffer.from(sim.appKey, 'hex');

// ---------------------------------------------------------------- estado ----
function leerEstado() {
  try {
    return JSON.parse(fs.readFileSync(ESTADO, 'utf8'));
  } catch (e) {
    return {devNonce: Math.floor(Math.random() * 1000), litros: 12000};
  }
}
function guardarEstado(e) {
  fs.writeFileSync(ESTADO, JSON.stringify(e, null, 2));
}

// ------------------------------------------------ protocolo Semtech UDP ----
// Cabecera: versión(2) + token(2) + identificador(1) [+ EUI del gateway(8)]
const PUSH_DATA = 0x00;
const PUSH_ACK = 0x01;
const PULL_DATA = 0x02;
const PULL_RESP = 0x03;
const PULL_ACK = 0x04;
const TX_ACK = 0x05;

const socket = dgram.createSocket('udp4');
const esperas = [];
let downlinkHandler = null;

socket.on('message', msg => {
  const id = msg[3];
  if (id === PUSH_ACK || id === PULL_ACK) {
    const token = msg.readUInt16BE(1);
    const i = esperas.findIndex(w => w.token === token && w.id === id);
    if (i >= 0) esperas.splice(i, 1)[0].resolve();
  } else if (id === PULL_RESP) {
    const txpk = JSON.parse(msg.slice(4).toString()).txpk;
    // Confirmamos al servidor que "hemos transmitido" el downlink.
    const ack = Buffer.concat([Buffer.from([2, msg[1], msg[2], TX_ACK]), GATEWAY_EUI, Buffer.from(JSON.stringify({txpk_ack: {error: 'NONE'}}))]);
    socket.send(ack, PUERTO, HOST);
    if (downlinkHandler) downlinkHandler(Buffer.from(txpk.data, 'base64'), txpk);
  }
});

function enviar(id, json) {
  const token = crypto.randomBytes(2).readUInt16BE(0);
  const partes = [Buffer.from([2, token >> 8, token & 0xff, id]), GATEWAY_EUI];
  if (json) partes.push(Buffer.from(JSON.stringify(json)));
  const ackId = id === PULL_DATA ? PULL_ACK : PUSH_ACK;
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error(`Sin respuesta del servidor ${sim.servidorUdp} (¿está arrancado el contenedor chirpstack-contadores?)`)), 5000);
    esperas.push({token, id: ackId, resolve: () => (clearTimeout(timeout), resolve())});
    socket.send(Buffer.concat(partes), PUERTO, HOST);
  });
}

function rxpk(phyPayload) {
  return {
    rxpk: [
      {
        time: new Date().toISOString(),
        tmst: Math.floor(Date.now() * 1000) % 2 ** 32,
        chan: 0,
        rfch: 0,
        freq: 868.1,
        stat: 1,
        modu: 'LORA',
        datr: 'SF7BW125',
        codr: '4/5',
        rssi: -60 - Math.floor(Math.random() * 20),
        lsnr: 7.5,
        size: phyPayload.length,
        data: phyPayload.toString('base64')
      }
    ]
  };
}

function esperarDownlink(ms) {
  return new Promise(resolve => {
    const t = setTimeout(() => ((downlinkHandler = null), resolve(null)), ms);
    downlinkHandler = (phy, txpk) => {
      clearTimeout(t);
      downlinkHandler = null;
      resolve({phy, txpk});
    };
  });
}

// El gateway tiene que hacer PULL_DATA para que el servidor sepa a dónde
// mandar los downlinks, y un "stat" para aparecer online.
async function conectarGateway() {
  await enviar(PULL_DATA);
  const ahora = new Date().toISOString().replace('T', ' ').replace(/\..+/, ' GMT');
  await enviar(PUSH_DATA, {stat: {time: ahora, rxnb: 1, rxok: 1, rxfw: 1, ackr: 100, dwnb: 0, txnb: 0}});
}

// ------------------------------------------------------------ LoRaWAN ----
async function join(estado) {
  estado.devNonce = (estado.devNonce + 1) & 0xffff;
  const devNonce = Buffer.alloc(2);
  devNonce.writeUInt16BE(estado.devNonce);

  const peticion = lora.fromFields({MType: 'Join Request', AppEUI: Buffer.from(sim.joinEui, 'hex'), DevEUI: Buffer.from(sim.devEui, 'hex'), DevNonce: devNonce}, undefined, undefined, APP_KEY);

  console.log(`→ Join Request (DevEUI ${sim.devEui}, DevNonce ${estado.devNonce})`);
  const espera = esperarDownlink(8000);
  await enviar(PUSH_DATA, rxpk(peticion.getPHYPayload()));
  const respuesta = await espera;
  if (!respuesta) throw new Error('No llegó el Join Accept. ¿El contenedor está arrancado con SIMULADOR=1? Mira: docker logs chirpstack-contadores');

  const aceptado = lora.fromWire(lora.decryptJoinAccept(lora.fromWire(respuesta.phy), APP_KEY));
  if (!lora.verifyMIC(aceptado, undefined, APP_KEY)) throw new Error('Join Accept con MIC incorrecto: la AppKey no coincide');

  const claves = lora.generateSessionKeys10(APP_KEY, aceptado.NetID, aceptado.AppNonce, devNonce);
  Object.assign(estado, {devAddr: aceptado.DevAddr.toString('hex'), nwkSKey: claves.NwkSKey.toString('hex'), appSKey: claves.AppSKey.toString('hex'), fCnt: 0});
  guardarEstado(estado);
  console.log(`← Join Accept: DevAddr ${estado.devAddr} (frecuencia ${respuesta.txpk.freq} MHz)`);
}

// Trama de lectura del protocolo del vendedor (68 10 01 1E ... checksum 16).
function tramaLectura(o) {
  const b = Buffer.alloc(36);
  b.writeUInt8(0x68, 0); // cabecera
  b.writeUInt8(0x10, 1); // contador de agua fría
  b.writeUInt8(0x01, 2); // lectura
  b.writeUInt8(0x1e, 3); // 30 bytes de datos
  b.writeUInt8(0x00, 4); // estado del instrumento
  b.writeUInt8({abierta: 0x01, cerrada: 0x02, averiada: 0x81}[o.valvula] ?? 0x01, 5);
  b.writeUInt16LE(o.alarma, 6);
  b.writeFloatLE(o.bateria, 8);
  const m3 = o.litros / 1000;
  b.writeUInt32LE(Math.floor(m3), 12);
  b.writeFloatLE(m3 - Math.floor(m3), 16);
  b.writeFloatLE(o.saldo, 20);
  b.writeUInt8(1, 24); // nº de recarga
  b.writeUInt8(o.prepago ? 0x01 : 0x00, 25);
  // 26..33 reserva a cero
  let suma = 0;
  for (let i = 0; i < 34; i++) suma = (suma + b[i]) & 0xff;
  b.writeUInt8(suma, 34);
  b.writeUInt8(0x16, 35);
  return b;
}

async function lectura(estado, opciones) {
  const litros = opciones.litros ?? (estado.litros += 5 + Math.floor(Math.random() * 45));
  estado.litros = litros;
  const o = {litros, bateria: opciones.bateria ?? 3.62, valvula: opciones.valvula ?? 'abierta', saldo: opciones.saldo ?? 0, prepago: !!opciones.prepago, alarma: opciones.alarma ?? 0};

  const paquete = lora.fromFields(
    {MType: 'Confirmed Data Up', DevAddr: Buffer.from(estado.devAddr, 'hex'), FCtrl: {ADR: false}, FCnt: estado.fCnt, FPort: 8, payload: tramaLectura(o)},
    Buffer.from(estado.appSKey, 'hex'),
    Buffer.from(estado.nwkSKey, 'hex')
  );
  console.log(`→ Lectura fCnt=${estado.fCnt}: ${litros / 1000} m³, batería ${o.bateria} V, válvula ${o.valvula}, saldo ${o.saldo} m³${o.alarma ? `, alarma ${o.alarma.toString(16).padStart(4, '0')}` : ''}`);

  const espera = esperarDownlink(3000);
  await enviar(PUSH_DATA, rxpk(paquete.getPHYPayload()));
  estado.fCnt++;
  guardarEstado(estado);

  const downlink = await espera;
  if (downlink) console.log(`← Confirmación del servidor (${downlink.phy.length} bytes)`);
}

// ------------------------------------------------------------------ CLI ----
function opcionesDe(args) {
  const o = {};
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--litros') o.litros = parseInt(args[++i], 10);
    else if (args[i] === '--bateria') o.bateria = parseFloat(args[++i]);
    else if (args[i] === '--valvula') o.valvula = args[++i];
    else if (args[i] === '--saldo') o.saldo = parseFloat(args[++i]);
    else if (args[i] === '--prepago') o.prepago = true;
    else if (args[i] === '--alarma') o.alarma = parseInt(args[++i], 16);
    else if (args[i] === '--cada') o.cada = parseFloat(args[++i]);
  }
  return o;
}

async function main() {
  const [comando = 'lectura', ...resto] = process.argv.slice(2);
  const opciones = opcionesDe(resto);
  const estado = leerEstado();

  socket.bind(0);
  await conectarGateway();

  await join(estado);

  if (comando === 'lectura') {
    await lectura(estado, opciones);
  } else if (comando === 'varias') {
    const n = parseInt(resto[0], 10) || 3;
    for (let i = 0; i < n; i++) {
      if (i > 0) await new Promise(r => setTimeout(r, (opciones.cada ?? 2) * 1000));
      await lectura(estado, {...opciones, litros: undefined});
    }
  } else if (comando !== 'join') {
    throw new Error(`Comando desconocido: ${comando}`);
  }
  socket.close();
}

main().catch(err => {
  console.error('ERROR:', err.message);
  process.exit(1);
});
