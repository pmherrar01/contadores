# -----------------------------------------------------------------------------
# Imagen única de ChirpStack v4 para los contadores de agua LoRaWAN.
# Es la MISMA en este PC (pruebas) y en producción.
#
# Lleva dentro, gestionado por supervisord:
#   - ChirpStack (Network Server)                   -> solo interno (127.0.0.1:8080)
#   - ChirpStack Gateway Bridge (Semtech UDP)       -> puerto 1700/udp (el único publicado)
#   - PostgreSQL, Redis y Mosquitto (solo internos)
#   - Aprovisionamiento automático al arrancar (herramientas/provisionar.js):
#     aplicación, integración HTTP hacia server_panel, gateways y contadores de /config
#   - Estado de los gateways a server_panel cada 5 min (herramientas/estado-gateways.js)
#
# Se construye desde la carpeta contadores/:  ./scripts/construir-imagen.sh
# Datos persistentes en /data (un volumen). Configuración en /config (CSV) y
# en variables de entorno (despliegue/chirpstack.env).
# -----------------------------------------------------------------------------
ARG CHIRPSTACK_VERSION=4.19.2
ARG GATEWAY_BRIDGE_VERSION=4.1.2

FROM chirpstack/chirpstack:${CHIRPSTACK_VERSION} AS chirpstack
FROM chirpstack/chirpstack-gateway-bridge:${GATEWAY_BRIDGE_VERSION} AS gateway-bridge

# Dependencias de Node del aprovisionamiento, con el mismo Node que la imagen final.
FROM alpine:3.24 AS herramientas
RUN apk add --no-cache nodejs npm
WORKDIR /app/herramientas
COPY herramientas/package.json herramientas/package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY herramientas/lib ./lib
COPY herramientas/provisionar.js herramientas/estado-gateways.js herramientas/simulador.json ./

# Misma versión de Alpine que la imagen oficial de ChirpStack (el binario va
# enlazado contra musl).
FROM alpine:3.24

RUN apk add --no-cache \
      postgresql17 postgresql17-contrib \
      redis mosquitto supervisor su-exec tzdata ca-certificates nodejs

COPY --from=chirpstack /usr/bin/chirpstack /usr/bin/chirpstack
COPY --from=gateway-bridge /usr/bin/chirpstack-gateway-bridge /usr/bin/chirpstack-gateway-bridge
COPY --from=herramientas /app/herramientas /app/herramientas
COPY codec/ /app/codec/

COPY chirpstack/config/chirpstack/ /etc/chirpstack/
COPY chirpstack/config/chirpstack-gateway-bridge/ /etc/chirpstack-gateway-bridge/
COPY chirpstack/config/mosquitto/mosquitto.conf /etc/mosquitto/mosquitto.conf
COPY chirpstack/config/supervisord.conf /etc/supervisord.conf
COPY chirpstack/entrypoint.sh /entrypoint.sh
COPY chirpstack/provisionar chirpstack/provisionar-al-arrancar /usr/local/bin/
RUN chmod +x /entrypoint.sh /usr/local/bin/provisionar /usr/local/bin/provisionar-al-arrancar \
 && mkdir -p /config

ENV TZ=Europe/Madrid

VOLUME ["/data"]
EXPOSE 1700/udp

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/ || exit 1

ENTRYPOINT ["/entrypoint.sh"]
