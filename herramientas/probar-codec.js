// Ejecuta un codec de ChirpStack v4 sobre una trama, sin necesidad de ChirpStack.
// Sirve para escribir el decoder real con las tramas de ejemplo del vendedor.
//
//   node probar-codec.js ../codec/contador-agua.js 0102a0ff 1
//   node probar-codec.js ../codec/simulador.js 00003039570001
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const [codecPath, hex, fPort = '1'] = process.argv.slice(2);
if (!codecPath || !hex) {
  console.error('Uso: node probar-codec.js <codec.js> <trama-hex> [fPort]');
  process.exit(1);
}

const limpio = hex.replace(/[\s:]/g, '');
if (!/^([0-9a-fA-F]{2})*$/.test(limpio)) {
  console.error('La trama tiene que ser hexadecimal con un número par de caracteres');
  process.exit(1);
}

const codigo = fs.readFileSync(path.resolve(codecPath), 'utf8');
const contexto = vm.createContext({});
vm.runInContext(codigo, contexto, {filename: codecPath});
if (typeof contexto.decodeUplink !== 'function') {
  console.error('El codec no define decodeUplink(input)');
  process.exit(1);
}

const input = {
  bytes: Array.from(Buffer.from(limpio, 'hex')),
  fPort: parseInt(fPort, 10),
  recvTime: new Date(),
  variables: {}
};
console.log(JSON.stringify(contexto.decodeUplink(input), null, 2));
