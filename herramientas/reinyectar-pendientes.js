// -----------------------------------------------------------------------------
// Reinyecta en server_panel los eventos que no se pudieron guardar (el endpoint
// los deja en contadores_pendientes.jsonl, una línea JSON por evento, cuando la
// BD falla incluso tras reintentar).
//
//   node reinyectar-pendientes.js <fichero.jsonl> <url-base-api> <CHIRPSTACK_HTTP_TOKEN>
//   node reinyectar-pendientes.js ~/server_panel/contadores_pendientes.jsonl http://localhost:3000 <token>
//
// Los que entran se quitan del fichero; los que vuelven a fallar se quedan.
// Se puede lanzar las veces que haga falta: el endpoint no duplica lecturas.
// -----------------------------------------------------------------------------
'use strict';
const fs = require('fs');

const [fichero, base, token] = process.argv.slice(2);
if (!fichero || !base || !token) {
  console.error('Uso: node reinyectar-pendientes.js <fichero.jsonl> <url-base-api> <token>');
  process.exit(1);
}

async function main() {
  if (!fs.existsSync(fichero)) {
    console.log('No hay fichero de pendientes: nada que reinyectar.');
    return;
  }
  const lineas = fs.readFileSync(fichero, 'utf8').split('\n').filter(l => l.trim());
  const quedan = [];
  let ok = 0;
  for (const linea of lineas) {
    let pendiente;
    try {
      pendiente = JSON.parse(linea);
    } catch (e) {
      console.log('Línea ilegible, se deja en el fichero:', linea.slice(0, 80));
      quedan.push(linea);
      continue;
    }
    try {
      const res = await fetch(base.replace(/\/$/, '') + pendiente.ruta, {method: 'POST', headers: {'Content-Type': 'application/json', Authorization: 'Bearer ' + token}, body: JSON.stringify(pendiente.cuerpo)});
      if (!res.ok) throw new Error('HTTP ' + res.status);
      ok++;
    } catch (e) {
      console.log(`Sigue fallando (${e.message}): ${pendiente.cuerpo?.deviceInfo?.devEui} ${pendiente.fecha}`);
      quedan.push(linea);
    }
  }
  fs.writeFileSync(fichero, quedan.length ? quedan.join('\n') + '\n' : '');
  console.log(`Reinyectados: ${ok}. Pendientes: ${quedan.length}.`);
}

main().catch(e => {
  console.error('ERROR:', e.message);
  process.exit(1);
});
