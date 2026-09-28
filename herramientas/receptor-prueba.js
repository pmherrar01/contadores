// -----------------------------------------------------------------------------
// Receptor HTTP de prueba: hace de "nuestra API" para comprobar que ChirpStack
// envía bien los eventos, sin arrancar server_panel ni tocar ninguna BD.
//
//   node receptor-prueba.js                 (puerto 3100)
//   PUERTO=3200 TOKEN=xxx node receptor-prueba.js
//
// Para usarlo: en chirpstack.local.env pon
//   API_URL=http://host.docker.internal:3100/contadores/lorawan/uplink
// y vuelve a lanzar ./despliegue/docker-run.sh ../chirpstack.local.env
// Cada evento se imprime resumido y se guarda completo en recibidos/.
// -----------------------------------------------------------------------------
'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');

const PUERTO = parseInt(process.env.PUERTO || '3100', 10);
const TOKEN = process.env.TOKEN || '';
const DIR = path.join(__dirname, 'recibidos');
fs.mkdirSync(DIR, {recursive: true});

http
  .createServer((req, res) => {
    let cuerpo = '';
    req.on('data', c => (cuerpo += c));
    req.on('end', () => {
      const url = new URL(req.url, 'http://x');
      const evento = url.searchParams.get('event') || '-';
      const auth = req.headers['authorization'] || '';

      if (TOKEN && auth !== 'Bearer ' + TOKEN) {
        console.log(`[${new Date().toISOString()}] ${req.method} ${req.url} -> 401 (token incorrecto: "${auth}")`);
        res.writeHead(401).end();
        return;
      }

      let json = null;
      try {
        json = JSON.parse(cuerpo);
      } catch (e) {
        /* no es JSON */
      }

      const fichero = path.join(DIR, `${Date.now()}-${evento}.json`);
      fs.writeFileSync(fichero, JSON.stringify({metodo: req.method, url: req.url, cabeceras: req.headers, cuerpo: json ?? cuerpo}, null, 2));

      let resumen = '';
      if (json && evento === 'up') {
        const rx = (json.rxInfo || [])[0] || {};
        resumen = `devEui=${json.deviceInfo?.devEui} fCnt=${json.fCnt} fPort=${json.fPort} data=${json.data} object=${JSON.stringify(json.object)} rssi=${rx.rssi} snr=${rx.snr}`;
      } else if (json && evento === 'join') {
        resumen = `devEui=${json.deviceInfo?.devEui} devAddr=${json.devAddr}`;
      } else if (json && evento === 'log') {
        resumen = `${json.level} ${json.code}: ${json.description}`;
      }
      console.log(`[${new Date().toISOString()}] ${req.method} ${url.pathname} event=${evento} ${resumen}`);
      res.writeHead(200, {'Content-Type': 'application/json'}).end('{"ok":true}');
    });
  })
  .listen(PUERTO, () => console.log(`Receptor de prueba escuchando en :${PUERTO} ${TOKEN ? '(con token)' : '(sin token)'}`));
