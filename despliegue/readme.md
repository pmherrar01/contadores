# ChirpStack de los contadores de agua: instalación en el servidor

Este contenedor recibe por el gateway LoRaWAN las tramas de los contadores de
agua y reenvía cada lectura a server_panel (`POST /contadores/lorawan/uplink`).
No tiene web que consultar: se configura solo al arrancar con lo que hay en
esta carpeta.

## Qué necesita el servidor

- **Docker.**
- **Puerto UDP 1700 abierto de entrada.** Es el único; por ahí se conecta el
  gateway. Hay que abrirlo en el cortafuegos del servidor (o en Plesk, si es
  quien lo gestiona) y en el del proveedor, si lo hay. Tiene que ser **UDP**:
  abrirlo solo en TCP no vale.

## Instalar

1. Copia esta carpeta al servidor. Tiene que contener:
   `docker-run.sh`, `chirpstack.env`, `config/` y `chirpstack-contadores-<versión>.tar.gz`.
2. En `chirpstack.env`, comprueba que `API_URL` y `API_TOKEN` están rellenos
   (ninguno empieza por `CAMBIAR`).
3. Arranca:
   ```bash
   ./docker-run.sh
   ```
   La primera vez carga la imagen del `.tar.gz` (tarda un poco).
4. Comprueba que ha arrancado bien:
   ```bash
   docker ps --filter name=chirpstack-contadores     # tiene que salir "(healthy)" al minuto
   docker logs chirpstack-contadores | grep provisionar
   ```
   La última línea tiene que ser `[provisionar] Listo: ...`.

   Si en vez de eso sale `[entrypoint] ERROR: falta la variable ...`, falta
   rellenar esa variable en `chirpstack.env`. Tras corregirla, vuelve a lanzar
   `./docker-run.sh`.

Cuando el gateway se apunte a este servidor (IP o dominio, puertos 1700/1700),
en `docker logs chirpstack-contadores` irán apareciendo sus mensajes cada
30 segundos.

## Día a día

| Qué | Cómo |
|---|---|
| Dar de alta contadores | Añadir líneas a `config/contadores.csv` y `docker exec chirpstack-contadores provisionar` (no hace falta reiniciar). |
| Añadir un gateway | Igual, en `config/gateways.csv`. |
| Actualizar la imagen | Sustituir el `.tar.gz` y `docker-run.sh` por los nuevos y `./docker-run.sh`. No se pierde nada. |
| Ver qué pasa | `docker logs -f chirpstack-contadores` |
| Copia de seguridad | `docker exec chirpstack-contadores su-exec postgres pg_dump -d chirpstack \| gzip > chirpstack-$(date +%F).sql.gz` |
| Parar | `docker rm -f chirpstack-contadores` (los datos se quedan en el volumen `chirpstack_contadores_data`). |

## Notas

- Los datos (contadores dados de alta, sesiones, claves) viven en el volumen
  Docker `chirpstack_contadores_data`. No lo borres.
- `chirpstack.env` lleva el token con el que ChirpStack se identifica ante
  server_panel. No lo subas a ningún repositorio.
- Si server_panel corre en este mismo servidor, `API_URL` también puede ser
  `http://host.docker.internal:<puerto>/contadores/lorawan/uplink`.
