#!/bin/bash
# -----------------------------------------------------------------------------
# Arranca ChirpStack para los contadores LoRaWAN.
#
# El MISMO script y la MISMA imagen en este PC (pruebas) y en producción; solo
# cambia el fichero de variables:
#     ./docker-run.sh                        producción   (chirpstack.env)
#     ./docker-run.sh ../chirpstack.local.env   este PC  (fuera de esta carpeta, no se entrega)
#
# Si la imagen no está cargada en Docker, la carga del .tar.gz de esta carpeta.
# Solo publica el puerto UDP 1700 (por ahí se conecta el gateway). Los datos
# van en el volumen chirpstack_contadores_data: volver a lanzar este script o
# actualizar la imagen no borra nada.
# -----------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")"

IMAGEN=modularbox/chirpstack-contadores:1.2.0
FICHERO_IMAGEN=chirpstack-contadores-1.2.0.tar.gz
NOMBRE=chirpstack-contadores
VOLUMEN=chirpstack_contadores_data
VARIABLES="${1:-chirpstack.env}"

if [ ! -f "$VARIABLES" ]; then
  echo "No existe el fichero de variables $VARIABLES" >&2
  exit 1
fi

if ! docker image inspect "$IMAGEN" >/dev/null 2>&1; then
  echo "Cargando la imagen $FICHERO_IMAGEN..."
  docker load -i "$FICHERO_IMAGEN"
fi

docker rm -f "$NOMBRE" >/dev/null 2>&1 || true
docker run -d \
  --name "$NOMBRE" \
  --restart unless-stopped \
  -p 1700:1700/udp \
  -v "$VOLUMEN":/data \
  -v "$PWD/config":/config:ro \
  --env-file "$VARIABLES" \
  --add-host host.docker.internal:host-gateway \
  --log-opt max-size=20m --log-opt max-file=5 \
  "$IMAGEN" >/dev/null

echo "ChirpStack arrancado ($IMAGEN, variables de $VARIABLES)."
echo "Ver que todo va bien: docker logs -f $NOMBRE   (busca las líneas [provisionar])"
