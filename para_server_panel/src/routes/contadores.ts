import crypto from 'crypto';
import {promises as fs} from 'fs';
import {Router} from 'express';
import moment from 'moment-timezone';
import BD from '../bd/bd_pool';
import {printError, printInfo} from '../utils/logs';

const router = Router();

/* Contadores de agua LoRaWAN (tablas en sql_new/contadores_lorawan.sql).
   ChirpStack llama a POST /contadores/lorawan/uplink?event=<tipo> con la
   cabecera "Authorization: Bearer <CHIRPSTACK_HTTP_TOKEN>". Solo se guardan
   los eventos "up" (lecturas); el resto se contesta 200 para que ChirpStack
   no los marque como error.

   La primera lectura de un contador lo registra en lorawan_contadores con
   idNodo NULL. El nodo de cada contador se enlaza después a mano en la BD
   (UPDATE lorawan_contadores SET idNodo = ... WHERE devEui = ...).

   El contenedor de ChirpStack manda además cada 5 min el estado de los
   gateways a POST /contadores/lorawan/gateways/estado (mismo token).

   ChirpStack no reintenta si le contestamos con error: cada escritura se
   reintenta aquí y, si aun así falla, el evento se guarda en
   CONTADORES_PENDIENTES (contadores_pendientes.jsonl) para reinyectarlo con
   herramientas/reinyectar-pendientes.js. */

const ZONA_HORARIA = 'Europe/Madrid';
const DEV_EUI = /^[0-9a-f]{16}$/;
const ESTADOS_GATEWAY = ['online', 'offline', 'nunca'];
const FORMATO_FECHA = `'%Y-%m-%d %H:%i:%s'`;
const FICHERO_PENDIENTES = process.env.CONTADORES_PENDIENTES || 'contadores_pendientes.jsonl';

function tokenValido(cabecera?: string): boolean {
  const token = process.env.CHIRPSTACK_HTTP_TOKEN;
  if (!token || !cabecera) {
    return false;
  }
  const recibido = Buffer.from(cabecera);
  const esperado = Buffer.from(`Bearer ${token}`);
  return recibido.length === esperado.length && crypto.timingSafeEqual(recibido, esperado);
}

// Número dentro del rango que admite su columna; si no, null (el valor original queda en `datos`).
function enRango(valor: any, min: number, max: number, entero = false): number | null {
  if (typeof valor !== 'number' || !Number.isFinite(valor) || valor < min || valor > max) {
    return null;
  }
  return entero ? Math.round(valor) : valor;
}

// ChirpStack manda la hora en UTC (ISO 8601); en la BD va en hora de Madrid como el resto de tablas.
function fechaLocal(iso?: string | null): string | null {
  if (!iso) {
    return null;
  }
  const fecha = moment(iso);
  return fecha.isValid() ? fecha.tz(ZONA_HORARIA).format('YYYY-MM-DD HH:mm:ss') : null;
}

function ahoraLocal(): string {
  return moment().tz(ZONA_HORARIA).format('YYYY-MM-DD HH:mm:ss');
}

function parsearDatos(fila: any) {
  if (typeof fila.datos === 'string') {
    try {
      fila.datos = JSON.parse(fila.datos);
    } catch (error) {
      /* se deja como texto */
    }
  }
  return fila;
}

const esperar = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));

// Repite una escritura si falla (deadlock, corte breve de la BD...). Todas las escrituras de aquí son idempotentes.
async function conReintentos(operacion: () => Promise<{success: boolean; datos?: any}>, intentos = 3) {
  let resultado = await operacion();
  for (let i = 1; i < intentos && !resultado.success; i++) {
    await esperar(i * 300);
    resultado = await operacion();
  }
  return resultado;
}

// Último recurso para no perder un evento: una línea JSON por evento, para reinyectarlo después.
async function guardarPendiente(ruta: string, cuerpo: any, motivo: string) {
  try {
    await fs.appendFile(FICHERO_PENDIENTES, JSON.stringify({fecha: new Date().toISOString(), ruta, motivo, cuerpo}) + '\n');
    printError(`Contadores: evento guardado en ${FICHERO_PENDIENTES} (${motivo})`);
  } catch (error) {
    printError(`Contadores: no se pudo guardar el evento pendiente en ${FICHERO_PENDIENTES}: ${error}`);
  }
}

// Id del contador en lorawan_contadores; si es su primera lectura, lo registra (sin nodo).
async function idContador(bd: BD, devEui: string): Promise<number | null> {
  const numSerie = devEui.startsWith('00') ? devEui.slice(2) : devEui;
  const alta = await conReintentos(() => bd.modificacionesTable(`INSERT IGNORE INTO lorawan_contadores (devEui, numSerie) VALUES (?, ?)`, [devEui, numSerie], 'contadores registrar'));
  if (alta.success && alta.datos.affectedRows === 1) {
    printInfo(`Contadores: nuevo contador ${devEui} registrado (pendiente de enlazar con su nodo)`);
  }
  for (let i = 0; i < 3; i++) {
    const filas = await bd.sql(`SELECT id FROM lorawan_contadores WHERE devEui = ?`, [devEui]);
    if (filas && filas.length) {
      return filas[0].id;
    }
    await esperar((i + 1) * 300);
  }
  return null;
}

// Fecha de filtro de los GET: 'YYYY-MM-DD' o 'YYYY-MM-DD HH:mm:ss'. Devuelve null si no viene y undefined si no es válida.
function fechaFiltro(valor: any, finDelDia: boolean): string | null | undefined {
  if (valor === undefined || valor === null || valor === '') {
    return null;
  }
  const texto = String(valor);
  const fecha = moment(texto, ['YYYY-MM-DD', 'YYYY-MM-DD HH:mm:ss', 'YYYY-MM-DD HH:mm'], true);
  if (!fecha.isValid()) {
    return undefined;
  }
  // Solo la fecha en "hasta": se incluye el día entero.
  if (finDelDia && texto.length === 10) {
    fecha.endOf('day');
  }
  return fecha.format('YYYY-MM-DD HH:mm:ss');
}

export function contadores(bd: BD) {
  /* Webhook de ChirpStack (integración HTTP de la aplicación "Contadores agua") */
  router.post('/lorawan/uplink', async (req: any, res: any) => {
    if (!tokenValido(req.headers.authorization)) {
      printError('/contadores/lorawan/uplink: token incorrecto o CHIRPSTACK_HTTP_TOKEN sin configurar');
      res.status(401).json({error: 'No autorizado'});
      return;
    }

    const evento = req.query.event;
    const up = req.body ?? {};

    if (evento === 'join') {
      printInfo(`LoRaWAN join ${up.deviceInfo?.devEui} devAddr ${up.devAddr}`);
      res.status(200).json({ok: true});
      return;
    }
    if (evento === 'log' && up.level === 'ERROR') {
      // Aquí llegan, entre otros, los fallos del codec (code UPLINK_CODEC).
      printError(`LoRaWAN log ${up.deviceInfo?.devEui} ${up.code}: ${up.description}`);
    }
    if (evento !== 'up') {
      res.status(200).json({ok: true});
      return;
    }

    const devEui = String(up.deviceInfo?.devEui ?? '').toLowerCase();
    if (!DEV_EUI.test(devEui) || !up.deduplicationId) {
      res.status(400).json({error: 'Uplink sin devEui o deduplicationId'});
      return;
    }

    // Si varios gateways oyen la trama, nos quedamos con el que mejor la oyó.
    const rx = (up.rxInfo ?? []).reduce((mejor: any, r: any) => (mejor === null || r.rssi > mejor.rssi ? r : mejor), null);
    const objeto = up.object && typeof up.object === 'object' ? up.object : null;
    // Cada valor dentro del rango de su columna; si no cabe se guarda NULL (sigue en `datos`) en vez de perder la lectura.
    const volumenM3 = enRango(objeto?.volumen_m3, 0, 999999999.999);
    const bateria = enRango(objeto?.bateria, 0, 20);
    const alarmas = Array.isArray(objeto?.alarmas) && objeto.alarmas.length > 0 ? objeto.alarmas.join(',').slice(0, 255) : null;
    const rssi = enRango(rx?.rssi, -32768, 32767, true);
    const snr = enRango(rx?.snr, -999.99, 999.99);
    const fCnt = enRango(up.fCnt, 0, 4294967295, true);
    const fPort = enRango(up.fPort, 0, 255, true);
    const frecuencia = enRango(up.txInfo?.frequency, 0, 4294967295, true);
    const fecha = fechaLocal(up.time) ?? ahoraLocal();
    const payloadHex = Buffer.from(up.data ?? '', 'base64').toString('hex').slice(0, 512);
    const gatewayEui = typeof rx?.gatewayId === 'string' && DEV_EUI.test(rx.gatewayId.toLowerCase()) ? rx.gatewayId.toLowerCase() : null;

    try {
      const id = await idContador(bd, devEui);
      if (id === null) {
        throw new Error('No se pudo registrar el contador');
      }

      // Reintento del contador: cuando no le llega la confirmación repite la misma trama (mismo fCnt
      // y contenido) con otro deduplicationId. Se busca con una consulta normal, sin bloqueos.
      const repetida = await bd.sql(
        `SELECT 1 FROM lorawan_contadores_lecturas
          WHERE deduplicationId = ?
             OR (idContador = ? AND fCnt <=> ? AND payloadHex = ? AND fecha BETWEEN ? - INTERVAL 10 MINUTE AND ? + INTERVAL 10 MINUTE)
          LIMIT 1`,
        [up.deduplicationId, id, fCnt, payloadHex, fecha, fecha]
      );
      let duplicado = Array.isArray(repetida) && repetida.length > 0;

      if (!duplicado) {
        // El UNIQUE de deduplicationId evita duplicar si ChirpStack entrega la misma trama dos veces a la vez.
        const lectura = await conReintentos(() =>
          bd.modificacionesTable(
            `INSERT IGNORE INTO lorawan_contadores_lecturas
               (idContador, deduplicationId, fecha, volumenM3, bateria, alarmas, datos, payloadHex, fCnt, fPort, rssi, snr, gatewayId, frecuencia)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
            [id, up.deduplicationId, fecha, volumenM3, bateria, alarmas, objeto ? JSON.stringify(objeto) : null, payloadHex, fCnt, fPort, rssi, snr, gatewayEui, frecuencia],
            'contadores lectura'
          )
        );
        if (!lectura.success) {
          throw new Error('No se pudo guardar la lectura');
        }
        duplicado = lectura.datos.affectedRows === 0;
      }

      // Gateway que recibió la lectura: se registra si aún no está (su estado llega aparte).
      if (gatewayEui) {
        await conReintentos(() => bd.modificacionesTable(`INSERT IGNORE INTO lorawan_gateways (gatewayEui) VALUES (?)`, [gatewayEui], 'contadores registrar gateway'));
      }

      // Última lectura del contador, solo si es la más reciente (las tramas pueden llegar desordenadas).
      // También en los duplicados: es idempotente y repara ultimo* si una vez falló.
      const ultima = await conReintentos(() =>
        bd.modificacionesTable(
          `UPDATE lorawan_contadores
              SET ultimaLecturaAt = ?, ultimoVolumenM3 = COALESCE(?, ultimoVolumenM3), ultimaBateria = COALESCE(?, ultimaBateria), ultimoRssi = ?, ultimoSnr = ?,
                  idGateway = COALESCE((SELECT g.id FROM lorawan_gateways g WHERE g.gatewayEui = ?), idGateway)
            WHERE id = ? AND (ultimaLecturaAt IS NULL OR ultimaLecturaAt <= ?)`,
          [fecha, volumenM3, bateria, rssi, snr, gatewayEui, id, fecha],
          'contadores ultima lectura'
        )
      );
      if (!ultima.success) {
        printError(`/contadores/lorawan/uplink ${devEui}: no se pudo actualizar su última lectura (se corregirá con la siguiente)`);
      }

      res.status(200).json(duplicado ? {ok: true, duplicado: true} : {ok: true});
    } catch (error: any) {
      printError(`/contadores/lorawan/uplink ${devEui}: ${error.message}`);
      await guardarPendiente('/contadores/lorawan/uplink?event=up', up, error.message);
      res.status(500).json({error: 'Error al guardar la lectura'});
    }
  });

  /* Estado de los gateways, lo manda el contenedor de ChirpStack cada 5 min.
     Body: {generado, gateways: [{gatewayEui, nombre, descripcion, estado, ultimaConexion, rxUltimaHora, txUltimaHora, rx24h, tx24h, latitud, longitud, altitud}]} */
  router.post('/lorawan/gateways/estado', async (req: any, res: any) => {
    if (!tokenValido(req.headers.authorization)) {
      printError('/contadores/lorawan/gateways/estado: token incorrecto o CHIRPSTACK_HTTP_TOKEN sin configurar');
      res.status(401).json({error: 'No autorizado'});
      return;
    }
    const gateways = Array.isArray(req.body?.gateways) ? req.body.gateways.slice(0, 1000) : null;
    if (!gateways) {
      res.status(400).json({error: 'Falta la lista de gateways'});
      return;
    }

    const ahora = ahoraLocal();
    let guardados = 0;
    try {
      for (const g of gateways) {
        const eui = String(g?.gatewayEui ?? '').toLowerCase();
        if (!DEV_EUI.test(eui)) {
          continue;
        }
        const estado = ESTADOS_GATEWAY.includes(g.estado) ? g.estado : 'nunca';
        const ultimaConexion = fechaLocal(g.ultimaConexion);
        const rxHora = enRango(g.rxUltimaHora, 0, 4294967295, true);
        const txHora = enRango(g.txUltimaHora, 0, 4294967295, true);

        const guardado = await conReintentos(() =>
          bd.modificacionesTable(
            `INSERT INTO lorawan_gateways (gatewayEui, nombre, descripcion, estado, ultimaConexion, rxUltimaHora, txUltimaHora, rx24h, tx24h, latitud, longitud, altitud, estadoRecibidoAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion), estado = VALUES(estado), ultimaConexion = VALUES(ultimaConexion),
               rxUltimaHora = VALUES(rxUltimaHora), txUltimaHora = VALUES(txUltimaHora), rx24h = VALUES(rx24h), tx24h = VALUES(tx24h),
               latitud = VALUES(latitud), longitud = VALUES(longitud), altitud = VALUES(altitud), estadoRecibidoAt = VALUES(estadoRecibidoAt)`,
            [eui, g.nombre ? String(g.nombre).slice(0, 100) : null, g.descripcion ? String(g.descripcion).slice(0, 255) : null, estado, ultimaConexion, rxHora, txHora, enRango(g.rx24h, 0, 4294967295, true), enRango(g.tx24h, 0, 4294967295, true), enRango(g.latitud, -90, 90), enRango(g.longitud, -180, 180), enRango(g.altitud, -99999.9, 99999.9), ahora],
            'contadores estado gateway'
          )
        );
        if (!guardado.success) {
          throw new Error(`No se pudo guardar el gateway ${eui}`);
        }
        await conReintentos(() =>
          bd.modificacionesTable(
            `INSERT INTO lorawan_gateways_historial (idGateway, fecha, estado, ultimaConexion, rxUltimaHora, txUltimaHora)
             SELECT id, ?, ?, ?, ?, ? FROM lorawan_gateways WHERE gatewayEui = ?`,
            [ahora, estado, ultimaConexion, rxHora, txHora, eui],
            'contadores historial gateway'
          )
        );
        guardados++;
      }
      res.status(200).json({ok: true, gateways: guardados});
    } catch (error: any) {
      printError(`/contadores/lorawan/gateways/estado: ${error.message}`);
      res.status(500).json({error: 'Error al guardar el estado de los gateways'});
    }
  });

  /* Gateways con su último estado y cuántos contadores tienen su última lectura por él */
  router.get('/lorawan/gateways', async (req: any, res: any) => {
    const filas = await bd.sql(
      `SELECT g.id, g.gatewayEui, g.nombre, g.descripcion, g.estado,
              DATE_FORMAT(g.ultimaConexion, ${FORMATO_FECHA}) AS ultimaConexion,
              g.rxUltimaHora, g.txUltimaHora, g.rx24h, g.tx24h, g.latitud, g.longitud, g.altitud,
              DATE_FORMAT(g.estadoRecibidoAt, ${FORMATO_FECHA}) AS estadoRecibidoAt,
              (SELECT COUNT(*) FROM lorawan_contadores c WHERE c.idGateway = g.id) AS contadores
         FROM lorawan_gateways g
        ORDER BY g.nombre, g.gatewayEui`
    );
    if (filas === null) {
      res.status(500).json({error: 'Error al consultar los gateways'});
      return;
    }
    res.status(200).json(filas);
  });

  /* Todos los contadores con su última lectura y, si ya lo tienen, los datos de su nodo */
  router.get('/lorawan', async (req: any, res: any) => {
    const filas = await bd.sql(
      `SELECT c.id, c.devEui, c.idNodo, c.idGateway, c.numSerie, c.activo,
              DATE_FORMAT(c.ultimaLecturaAt, ${FORMATO_FECHA}) AS ultimaLecturaAt,
              c.ultimoVolumenM3, c.ultimaBateria, c.ultimoRssi, c.ultimoSnr,
              DATE_FORMAT(c.createdAt, ${FORMATO_FECHA}) AS createdAt,
              n.nombre, n.idusuario, n.ubicacion, n.direccion
         FROM lorawan_contadores c
         LEFT JOIN safey_nodos n ON n.id = c.idNodo
        WHERE n.id IS NULL OR n.borrado <> 's'
        ORDER BY c.numSerie, c.devEui`
    );
    if (filas === null) {
      res.status(500).json({error: 'Error al consultar los contadores'});
      return;
    }
    res.status(200).json(filas);
  });

  /* Lecturas de un contador: ?desde=YYYY-MM-DD[ HH:mm:ss]&hasta=...&limite=500 (máx. 5000), de más reciente a más antigua.
     "hasta" con solo la fecha incluye ese día entero. Las fechas van en hora de Madrid. */
  router.get('/lorawan/:devEui/lecturas', async (req: any, res: any) => {
    const devEui = String(req.params.devEui).toLowerCase();
    if (!DEV_EUI.test(devEui)) {
      res.status(400).json({error: 'devEui inválido'});
      return;
    }
    const desde = fechaFiltro(req.query.desde, false);
    const hasta = fechaFiltro(req.query.hasta, true);
    if (desde === undefined || hasta === undefined) {
      res.status(400).json({error: 'Fecha inválida: usa YYYY-MM-DD o YYYY-MM-DD HH:mm:ss'});
      return;
    }
    const limite = Math.min(Math.max(parseInt(req.query.limite, 10) || 500, 1), 5000);

    const filas = await bd.sql(
      `SELECT l.id, DATE_FORMAT(l.fecha, ${FORMATO_FECHA}) AS fecha, l.volumenM3, l.bateria, l.alarmas, l.datos, l.payloadHex, l.fCnt, l.fPort, l.rssi, l.snr, l.gatewayId
         FROM lorawan_contadores_lecturas l
         JOIN lorawan_contadores c ON c.id = l.idContador
        WHERE c.devEui = ?
          AND (? IS NULL OR l.fecha >= ?)
          AND (? IS NULL OR l.fecha <= ?)
        ORDER BY l.fecha DESC, l.id DESC
        LIMIT ?`,
      [devEui, desde, desde, hasta, hasta, limite]
    );
    if (filas === null) {
      res.status(500).json({error: 'Error al consultar las lecturas'});
      return;
    }
    res.status(200).json(filas.map(parsearDatos));
  });

  return router;
}
