// -----------------------------------------------------------------------------
// Codec de ChirpStack v4 para el contador de agua ultrasónico LoRaWAN
// (serie XKFL-5, DN15/DN20). Protocolo del vendedor: "LoRaWAN IoT Platform
// Communication Protocol" v1.0 (conversacionVendedor/LoRaWan protocol v1.0.pdf).
//
// Tramas del contador (todas empiezan por 0x68 y terminan en 0x16):
//   68 10 01 LL ...   lectura periódica o forzada
//   68 10 02 LL ...   respuesta del contador a un comando de la plataforma
// Números en little-endian; los decimales son float de 32 bits.
// Suma de control (módulo 256) de los bytes anteriores al checksum: desde 0x68
// en las lecturas y desde el código de control en las respuestas a comandos.
//
// Contrato con la API (server_panel/src/routes/contadores.ts): las claves
// volumen_m3, bateria y alarmas van a columnas propias; el objeto completo se
// guarda además en la columna `datos`.
//
// Probar sin ChirpStack:  node herramientas/probar-codec.js codec/contador-agua.js <hex> [fPort]
// -----------------------------------------------------------------------------

function decodeUplink(input) {
  var b = input.bytes;

  if (b.length < 6 || b[0] !== 0x68) {
    return {errors: ['Trama desconocida: no empieza por 0x68 (' + toHex(b) + ')']};
  }
  var largo = b[3];
  var iChecksum = 4 + largo;
  if (b.length < iChecksum + 2) {
    return {errors: ['Trama incompleta: ' + b.length + ' bytes para una longitud de datos ' + largo]};
  }
  if (b[iChecksum + 1] !== 0x16) {
    return {errors: ['Trama sin fin 0x16']};
  }
  var control = b[2];
  var suma = 0;
  for (var i = control === 0x02 ? 2 : 0; i < iChecksum; i++) suma = (suma + b[i]) & 0xff;
  if (suma !== b[iChecksum]) {
    return {errors: ['Suma de control incorrecta: calculada ' + hex2(suma) + ', recibida ' + hex2(b[iChecksum])]};
  }

  if (control === 0x01) return {data: lectura(b)};
  if (control === 0x02) return {data: respuestaComando(b)};
  return {errors: ['Código de control desconocido: 0x' + hex2(control)]};
}

// 68 10 01 1E | estado | válvula | alarmas(2) | batería(f32) | volumen entero(u32) |
// volumen decimales(f32) | saldo(f32) | nº recarga | modo pago | reserva | checksum | 16
function lectura(b) {
  var valvula = b[5];
  var codigoAlarma = b[6] | (b[7] << 8);
  var alarmas = [];
  if (valvula & 0x80) alarmas.push('valvula_averiada');
  if (codigoAlarma !== 0) alarmas.push('alarma_' + hex2(b[7]) + hex2(b[6]));

  return {
    tipo: 'lectura',
    volumen_m3: redondear(uint32LE(b, 12) + float32LE(b, 16), 3),
    bateria: redondear(float32LE(b, 8), 2),
    alarmas: alarmas,
    valvula: estadoValvula(valvula),
    saldo_m3: redondear(float32LE(b, 20), 3),
    recarga_serie: b[24],
    modo_pago: modoPago(b[25]),
    estado_instrumento: b[4],
    codigo_alarma: hex2(b[7]) + hex2(b[6])
  };
}

// 68 10 02 10 | opcode(2) | volumen entero(u32) | volumen decimales(f32) | saldo(f32) |
// nº recarga | modo pago | [reserva] | checksum | 16
function respuestaComando(b) {
  return {
    tipo: 'respuesta_comando',
    comando: hex2(b[4]) + hex2(b[5]),
    volumen_m3: redondear(uint32LE(b, 6) + float32LE(b, 10), 3),
    alarmas: [],
    saldo_m3: redondear(float32LE(b, 14), 3),
    recarga_serie: b[18],
    modo_pago: modoPago(b[19])
  };
}

function estadoValvula(v) {
  if (v & 0x01) return 'abierta';
  if (v & 0x02) return 'cerrada';
  return null;
}

function modoPago(v) {
  if (v === 0x00) return 'pospago';
  if (v === 0x01) return 'prepago';
  return 'desconocido_' + hex2(v);
}

function uint32LE(b, i) {
  return (b[i] | (b[i + 1] << 8) | (b[i + 2] << 16) | (b[i + 3] << 24)) >>> 0;
}

function float32LE(b, i) {
  var bits = uint32LE(b, i);
  var signo = bits >>> 31 ? -1 : 1;
  var exponente = (bits >>> 23) & 0xff;
  var mantisa = bits & 0x7fffff;
  if (exponente === 0) return signo * mantisa * Math.pow(2, -149);
  if (exponente === 0xff) return mantisa ? NaN : signo * Infinity;
  return signo * (1 + mantisa / 8388608) * Math.pow(2, exponente - 127);
}

function redondear(valor, decimales) {
  var f = Math.pow(10, decimales);
  return Math.round(valor * f) / f;
}

function hex2(n) {
  return ('0' + (n & 0xff).toString(16)).slice(-2);
}

function toHex(bytes) {
  var hex = '';
  for (var i = 0; i < bytes.length; i++) hex += hex2(bytes[i]);
  return hex;
}
