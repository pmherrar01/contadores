# Qué poner en server_panel

Todo lo de esta carpeta está probado contra una copia de `bd_pool.ts`,
`config.ts` y `logs.ts` de server_panel, con una MariaDB 10.6 limpia y la misma
estructura de `safey_nodos` y `services`. Pasa la comprobación de tipos con el
`tsconfig.json` de server_panel.

**Diseño:**
- `lorawan_contadores` tiene su propio `id` y una columna `idNodo` → `safey_nodos.id`.
- La **primera lectura** de un contador (al instalarlo y pulsar Sensor 5 s) lo
  registra solo, con `idNodo` vacío. No hay que darlo de alta en ningún panel.
- Las lecturas van a `lorawan_contadores_lecturas` con `idContador`.
- Los **nodos los crea y enlaza a mano** tu compañero en la BD (ver el
  apartado 6). La BD impide enlazar un nodo que no existe o el mismo nodo a dos
  contadores.

## ⚠️ Antes de probar en local

El `.env` de server_panel tiene que apuntar a tu BD local, no a producción
(`192.168.0.56`):

```env
DB_HOST=127.0.0.1
DB_USER=root
DB_PASS=<tu contraseña local>
DB=panel_bd_prod
PORT_SERVER=3002
```

`PORT_SERVER=3002` hace falta porque en este PC el puerto 3000 lo ocupa
server_puntopadel. Ojo: `config.ts` lee `PORT_SERVER`, no `SERVER_PORT`.

## 1. Tablas (SQL)

Copia `sql_new/contadores_lorawan.sql` a `server_panel/sql_new/`.

**En tu BD local**, que ya tiene las tablas de la versión anterior (solo con
datos de prueba y las lecturas de prueba del DN20), bórralas primero:

```sql
DROP TABLE lorawan_contadores_lecturas;
DROP TABLE lorawan_contadores;
```

Después, en local y más adelante en producción:

```bash
mysql -h 127.0.0.1 -uroot -p panel_bd_prod < server_panel/sql_new/contadores_lorawan.sql
```

Crea el servicio "Contadores" en `services` (opcional, para distinguir en el
panel los nodos de contadores), `lorawan_contadores` (con `idNodo` →
`safey_nodos.id`) y `lorawan_contadores_lecturas` (con `idContador` →
`lorawan_contadores.id`). No toca ninguna otra tabla (las `contadores_*` son de
otro proyecto).

## 2. Endpoint

Copia `src/routes/contadores.ts` a `server_panel/src/routes/contadores.ts`,
sustituyendo el anterior.

## 3. server.ts

Ya lo tienes hecho. Son estas dos líneas:

```ts
import {contadores} from './routes/contadores';
app.use('/contadores', contadores(bd));
```

## 4. .env

```env
# Local (igual que API_TOKEN de chirpstack.local.env)
CHIRPSTACK_HTTP_TOKEN=token-local-de-pruebas-cambiar
```

En producción usa el token que hay en `despliegue/chirpstack.env` (`API_TOKEN`).
Sin esta variable, el endpoint rechaza todo con 401.

## 5. Probar en local

1. Reinicia server_panel (`npm run dev`).
2. Pruebas del endpoint (sin ChirpStack):
   ```bash
   cd ~/Escritorio/contadores/herramientas
   node probar-endpoint.js http://localhost:3002 token-local-de-pruebas-cambiar
   ```
   Tiene que acabar en `TODO OK`.
3. Cadena completa (contador simulado → ChirpStack → server_panel → BD), con
   ChirpStack arrancado en este PC (`./despliegue/docker-run.sh ../chirpstack.local.env`):
   ```bash
   node simulador.js varias 3
   ```
   El contador simulado aparece en `GET http://localhost:3002/contadores/lorawan`.

Para borrar después los datos de prueba:

```sql
DELETE l FROM lorawan_contadores_lecturas l JOIN lorawan_contadores c ON c.id = l.idContador
 WHERE c.devEui LIKE 'ffffffff%' OR c.devEui = 'a0a0a0a000000001';
DELETE FROM lorawan_contadores WHERE devEui LIKE 'ffffffff%' OR devEui = 'a0a0a0a000000001';
```

## 6. Enlazar cada contador con su nodo (tu compañero, en la BD)

Cuando los contadores ya se han registrado solos (han comunicado al menos una
vez), se crea el nodo de cada uno en el panel y se enlaza:

```sql
-- Contadores que aún no tienen nodo
SELECT id, devEui, numSerie, ultimaLecturaAt FROM lorawan_contadores WHERE idNodo IS NULL ORDER BY numSerie;

-- Enlazar uno (idNodo = id del nodo nuevo en safey_nodos)
UPDATE lorawan_contadores SET idNodo = 1234 WHERE devEui = '0080202609010010';
```

Si el nodo no existe o ya tiene otro contador, la BD rechaza el `UPDATE`.

## Endpoints

| Método | Ruta | Para qué |
|---|---|---|
| POST | `/contadores/lorawan/uplink?event=...` | Webhook de ChirpStack. Solo lo llama ChirpStack, con token. |
| GET | `/contadores/lorawan` | Todos los contadores con su última lectura, y `idNodo`, `nombre`, `idusuario`, `ubicacion` y `direccion` del nodo si ya lo tienen (si no, a `null`). Excluye los de nodos con `borrado = 's'`. |
| GET | `/contadores/lorawan/:devEui/lecturas?desde=&hasta=&limite=` | Lecturas de un contador, de la más reciente a la más antigua (límite 500 por defecto, máximo 5000). |

Los GET no llevan autenticación, igual que el resto de rutas del panel. Las fechas salen en UTC (`...Z`) y los decimales como texto
(`"90.00"`): así los devuelve `mysql2`.

Qué hace el webhook con cada trama (`event=up`):

- Si es un contador nuevo, lo registra en `lorawan_contadores` (sin nodo).
- Guarda la lectura, tenga nodo o no. La trama en bruto (`payloadHex`) va
  siempre; el volumen, la batería y las alarmas solo si el codec los devuelve
  (`volumen_m3`, `bateria`, `alarmas`).
- No guarda dos veces la misma trama: ni si ChirpStack la repite (mismo
  `deduplicationId`), ni si el contador la reintenta porque no le llegó la
  confirmación (mismo `fCnt` y contenido en ±10 minutos).
- Actualiza los campos `ultimo*` del contador solo si la lectura es la más
  reciente, porque las tramas pueden llegar desordenadas.
- Los demás eventos (`join`, `status`, `log`…) contestan 200. Los `log` de
  error, como un fallo del codec, se escriben en el log del servidor.

## Qué trae cada lectura (para las estadísticas)

Columnas propias de `lorawan_contadores_lecturas`:

| Columna | Qué es |
|---|---|
| `volumenM3` | Lectura acumulada del contador en m³ (3 decimales). El consumo de un periodo es la diferencia entre dos lecturas. |
| `bateria` | Voltios (3,6 V nueva). |
| `alarmas` | `valvula_averiada` y/o `alarma_XXXX` (código del contador), separadas por comas. NULL si no hay. |
| `rssi`, `snr` | Cobertura: con RSSI por debajo de -120 dBm se pierden tramas. |
| `fecha` | Hora de recepción (Madrid). |

La columna `datos` (JSON) trae además, según el protocolo del vendedor:

```json
{
  "tipo": "lectura",
  "volumen_m3": 34.596,
  "bateria": 3.74,
  "alarmas": [],
  "valvula": "abierta",
  "saldo_m3": -22.6,
  "modo_pago": "prepago",
  "recarga_serie": 1,
  "estado_instrumento": 0,
  "codigo_alarma": "0000"
}
```

`valvula` es `abierta`, `cerrada` o `null` (sin dato). `saldo_m3` y
`modo_pago` solo tienen sentido si se usa el modo prepago del contador.
