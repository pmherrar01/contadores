#!/bin/bash
# Construye la imagen de ChirpStack y la deja exportada en despliegue/, junto a
# docker-run.sh, lista para pasar al servidor. También actualiza la versión en
# despliegue/docker-run.sh.
set -euo pipefail
cd "$(dirname "$0")/.."
source scripts/config.sh

docker build -f chirpstack/Dockerfile -t "$IMAGEN:$VERSION" -t "$IMAGEN:latest" .

rm -f despliegue/chirpstack-contadores-*.tar.gz
SALIDA="despliegue/chirpstack-contadores-$VERSION.tar.gz"
docker save "$IMAGEN:$VERSION" | gzip > "$SALIDA"

sed -i -e "s#^IMAGEN=.*#IMAGEN=$IMAGEN:$VERSION#" -e "s#^FICHERO_IMAGEN=.*#FICHERO_IMAGEN=chirpstack-contadores-$VERSION.tar.gz#" despliegue/docker-run.sh

echo "Imagen: $IMAGEN:$VERSION"
echo "Exportada: $SALIDA ($(du -h "$SALIDA" | cut -f1))"
