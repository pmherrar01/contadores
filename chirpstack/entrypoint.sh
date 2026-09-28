#!/bin/sh
# Arranque del contenedor: prepara /data la primera vez y lanza supervisord.
set -eu

DATA=/data
PGDATA="$DATA/postgres"

# Sin estas dos variables ChirpStack no sabría a dónde mandar las lecturas:
# mejor no arrancar y decirlo claro en "docker logs".
for VAR in API_URL API_TOKEN; do
  VALOR="$(printenv "$VAR" || true)"
  case "$VALOR" in
    ""|CAMBIAR*)
      echo "[entrypoint] ERROR: falta la variable $VAR (rellénala en chirpstack.env)"
      exit 1
      ;;
  esac
done

mkdir -p "$PGDATA" "$DATA/redis" /run/postgresql
chown postgres:postgres "$PGDATA" /run/postgresql
chmod 700 "$PGDATA"
chown redis:redis "$DATA/redis"

# Secreto con el que ChirpStack firma las sesiones web y las API keys.
# Si cambia, las API keys dejan de valer, así que se genera una vez y se
# guarda en el volumen. Se puede forzar uno pasando CHIRPSTACK_API_SECRET.
if [ -z "${CHIRPSTACK_API_SECRET:-}" ]; then
  if [ ! -s "$DATA/api_secret" ]; then
    od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > "$DATA/api_secret"
    chmod 600 "$DATA/api_secret"
  fi
  CHIRPSTACK_API_SECRET="$(cat "$DATA/api_secret")"
fi
export CHIRPSTACK_API_SECRET

# Contraseña del admin de ChirpStack: nadie entra en la web, pero no se deja la
# de fábrica. Si no se pasa ADMIN_PASSWORD se genera una y se guarda en el
# volumen; provisionar.js la aplica la primera vez.
if [ -z "${ADMIN_PASSWORD:-}" ] && [ ! -s "$DATA/admin_password" ]; then
  od -An -N16 -tx1 /dev/urandom | tr -d ' \n' > "$DATA/admin_password"
  chmod 600 "$DATA/admin_password"
fi

# Primera vez: crear la base de datos de ChirpStack.
if [ ! -s "$PGDATA/PG_VERSION" ]; then
  echo "[entrypoint] Inicializando PostgreSQL en $PGDATA"
  su-exec postgres initdb -D "$PGDATA" -U postgres -E UTF8 --auth-local=trust --auth-host=scram-sha-256 >/dev/null
  echo "listen_addresses = '127.0.0.1'" >> "$PGDATA/postgresql.conf"

  su-exec postgres pg_ctl -D "$PGDATA" -o "-c listen_addresses=''" -w start >/dev/null
  su-exec postgres psql -v ON_ERROR_STOP=1 -q <<-'EOSQL'
		create role chirpstack with login password 'chirpstack';
		create database chirpstack with owner chirpstack;
	EOSQL
  su-exec postgres psql -v ON_ERROR_STOP=1 -q -d chirpstack <<-'EOSQL'
		create extension pg_trgm;
		create extension hstore;
	EOSQL
  su-exec postgres pg_ctl -D "$PGDATA" -m fast -w stop >/dev/null
  echo "[entrypoint] PostgreSQL listo"
fi

exec supervisord -c /etc/supervisord.conf
