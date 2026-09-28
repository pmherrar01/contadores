# Contadores de agua LoRaWAN

Recogida de lecturas de los contadores de agua ultrasónicos LoRaWAN con
**nuestro propio ChirpStack**, sin depender de la plataforma del vendedor.

```
Contador ──LoRa──► Gateway Rime ──UDP 1700──► ChirpStack ──HTTP POST──► server_panel ──► MySQL
 (DN20)           (RGWC868LA)                 (Docker)     (con token)   /contadores/      lorawan_*
```

- **Gateway**: Rime RGWC868LA, EUI `082767fffef8e878`, EU868. En la oficina
  está en `192.168.0.129`; web con usuario `guest` y contraseña `rimelink`.
- **ChirpStack**: una sola imagen Docker que se **configura sola al arrancar**
  (aplicación, integración HTTP, gateways y contadores). No hay web que mirar
  y solo publica el UDP 1700.
- **API**: `server_panel/src/routes/contadores.ts`. Ver
  `para_server_panel/INSTRUCCIONES.md`.

**Este PC funciona exactamente como el servidor de producción**: misma imagen,
mismo `docker-run.sh` y misma carpeta `config/`. Solo cambia el fichero de
variables (`chirpstack.local.env` aquí, `chirpstack.env` en producción).

## Qué hay en esta carpeta

| Ruta | Qué es |
|---|---|
| `despliegue/` | **Lo que se pasa al servidor**: `docker-run.sh`, la imagen (`.tar.gz`), `chirpstack.env`, `config/` (CSV de gateways y contadores) y `readme.md` con los pasos. |
| `chirpstack/` | Dockerfile y configuración de la imagen (ChirpStack 4.19.2 + Gateway Bridge + PostgreSQL + Redis + Mosquitto + aprovisionamiento). |
| `codec/` | `contador-agua.js`: decoder de ChirpStack con el protocolo del vendedor. |
| `herramientas/` | `provisionar.js` (va dentro de la imagen), simulador (manda tramas del protocolo real), `excel-a-csv.py`, pruebas del endpoint y del codec, receptor de prueba. |
| `conversacionVendedor/` | Protocolo, manual, Excel de claves y capturas que mandó el vendedor. |
| `para_server_panel/` | `contadores.ts`, el SQL de las tablas e instrucciones para server_panel. |
| `scripts/` | `construir-imagen.sh` (la versión está en `config.sh`). |

## En este PC

```bash
./scripts/construir-imagen.sh                          # solo si cambias chirpstack/, codec/ o herramientas/
./despliegue/docker-run.sh ../chirpstack.local.env        # arranca ChirpStack como en producción
docker logs chirpstack-contadores | grep provisionar   # tiene que terminar en "[provisionar] Listo"

cd herramientas && npm install                         # una vez, para el simulador y las pruebas
node simulador.js varias 3                             # contador simulado: join OTAA + 3 lecturas con el protocolo real
node probar-endpoint.js http://localhost:3002 token-local-de-pruebas-cambiar
```

`chirpstack.local.env` apunta a tu server_panel local
(`http://host.docker.internal:3002/...`) y activa el simulador (`SIMULADOR=1`).
El gateway Rime de la oficina está apuntado a este PC (`192.168.0.126`, puertos
`1700`/`1700`). Para devolverlo al servidor del vendedor: `8.217.30.113`,
puertos `1701`/`1701`.

## Producción

1. `./scripts/construir-imagen.sh` (deja la imagen en `despliegue/`).
2. Rellena `API_URL` en `despliegue/chirpstack.env`: la URL del server_panel de
   producción. El `API_TOKEN` ya está generado y tiene que coincidir con
   `CHIRPSTACK_HTTP_TOKEN` del `.env` de server_panel en producción.
3. Pasa la carpeta `despliegue/` al servidor. Allí basta con `./docker-run.sh`
   y abrir el UDP 1700 (pasos en `despliegue/readme.md`).
4. En el gateway: `server_address` = IP o dominio del servidor, puertos
   `1700`/`1700`.

## Protocolo y contadores (datos del vendedor)

Todo lo que mandó el vendedor está en `conversacionVendedor/`.

- **Decoder** (`codec/contador-agua.js`): implementa su protocolo ("LoRaWAN IoT
  Platform Communication Protocol" v1.0). Tramas `68 10 01 ... 16` por el
  puerto 8, confirmadas; comprueba la suma de control. Devuelve:

  | Clave | Qué es |
  |---|---|
  | `volumen_m3` | Lectura acumulada (m³, 3 decimales) → columna `volumenM3` |
  | `bateria` | Voltios (típico 3,6 V) → columna `bateria` |
  | `alarmas` | `valvula_averiada`, `alarma_XXXX` (código crudo) → columna `alarmas` |
  | `valvula` | `abierta` / `cerrada` / `null` |
  | `saldo_m3` | Saldo restante (modo prepago) |
  | `modo_pago` | `prepago` / `pospago` |
  | `recarga_serie`, `estado_instrumento`, `codigo_alarma`, `tipo` | Resto de campos de la trama |

  Todo el objeto se guarda en la columna `datos`. También decodifica las
  respuestas del contador a comandos (`tipo: respuesta_comando`).
  Probarlo con una trama: `node herramientas/probar-codec.js codec/contador-agua.js <hex> 8`.
- **Perfil**: LoRaWAN 1.0.2, revisión A, un envío por hora y sin validar el
  contador de tramas, igual que en el ChirpStack del vendedor.
- **Contadores**: `despliegue/config/contadores.csv`, con los 100 del Excel
  (50 DN15 y 50 DN20; DevEUI = `00` + número impreso). Para un Excel nuevo:
  `python3 herramientas/excel-a-csv.py <excel>` y después
  `docker exec chirpstack-contadores provisionar`.
- **Forzar un envío**: en la segunda pantalla del contador, mantener pulsado
  "Sensor" 5 segundos y soltar. Pantallas: se despierta con un imán en la
  esquina inferior izquierda (logo CPA).
- **Instalación**: posiciones A, B o C del esquema del vendedor (horizontal de
  lado, a 45° o vertical de abajo arriba). No mide el flujo en sentido
  contrario.
- **Pendiente**: el contador de la oficina seguía con su sesión de fábrica
  (DevAddr `0132c325`) y no hacía un join nuevo. Si con el envío forzado sigue
  igual, hay que preguntar al vendedor cómo forzar un *rejoin* OTAA.
- **Comandos** (válvula, frecuencia de envío, recargas, sincronizar la hora):
  están en el protocolo, pero no se usan todavía.

## Si algo no llega

| Síntoma | Dónde mirar |
|---|---|
| El contenedor se reinicia sin parar | `docker logs chirpstack-contadores`: `[entrypoint] ERROR: falta la variable ...` |
| El gateway no conecta | Log del gateway (web): `PULL_DATA` al 0 % → IP o puerto mal, o UDP 1700 cerrado. |
| El contador transmite pero no llega a la API | `docker logs chirpstack-contadores`: `No device-session` → el contador no ha hecho join con nuestro servidor; `Posting event failed` → `API_URL` o token mal. |
| Llega pero sin volumen | Falta el decoder (`codec/contador-agua.js`) o devuelve otras claves. |
