#!/bin/sh
#
# Container entrypoint for Grocy Modern.
#
# Prepares the data directory (which is a volume and therefore empty or
# pre-existing, never part of the image), applies the timezone and optionally
# remaps www-data to the host's uid/gid before handing over to Apache.

set -eu

APP_DIR=/var/www/html

# Mirrors how public/index.php resolves GROCY_DATAPATH: relative paths are
# relative to the application root
DATA_DIR="${GROCY_DATAPATH:-data}"
case "$DATA_DIR" in
	/*) ;;
	*) DATA_DIR="$APP_DIR/$DATA_DIR" ;;
esac

log() { echo "[grocy-entrypoint] $*"; }

# --- timezone --------------------------------------------------------------
# Grocy has no timezone setting of its own, it uses PHP's. Without this every
# date would be interpreted as UTC, which shifts "due today" by a few hours.
if [ -n "${TZ:-}" ]; then
	if [ -f "/usr/share/zoneinfo/$TZ" ]; then
		ln -snf "/usr/share/zoneinfo/$TZ" /etc/localtime
		echo "$TZ" > /etc/timezone
		echo "date.timezone = $TZ" > /usr/local/etc/php/conf.d/timezone.ini
		log "timezone set to $TZ"
	else
		log "WARNING: unknown timezone '$TZ', falling back to UTC"
	fi
fi

# --- uid/gid remapping for bind mounts -------------------------------------
if [ -n "${PUID:-}" ] && [ "$PUID" != "$(id -u www-data)" ]; then
	log "changing uid of www-data to $PUID"
	usermod -o -u "$PUID" www-data
fi

if [ -n "${PGID:-}" ] && [ "$PGID" != "$(id -g www-data)" ]; then
	log "changing gid of www-data to $PGID"
	groupmod -o -g "$PGID" www-data
fi

# --- data directory --------------------------------------------------------
mkdir -p "$DATA_DIR" "$DATA_DIR/plugins"

if [ ! -f "$DATA_DIR/config.php" ]; then
	log "no config.php found, creating one from config-dist.php"
	cp "$APP_DIR/config-dist.php" "$DATA_DIR/config.php"
fi

# --- 4.7.0 renamed the authentication middleware ---------------------------
# Grocy\Middleware\DefaultAuthMiddleware became
# Grocy\Middleware\Auth\DefaultAuthMiddleware. A config.php written before
# that update still names the old class, and since 4.7.0 Grocy answers *every*
# request with HTTP 500 when AUTH_CLASS does not resolve - so migrate it here
# instead of letting the container come up broken.
CONFIG_FILE="$DATA_DIR/config.php"

if grep -q 'Grocy\\Middleware\\\(Default\|ReverseProxy\)AuthMiddleware' "$CONFIG_FILE" 2>/dev/null; then
	cp "$CONFIG_FILE" "$CONFIG_FILE.bak-$(date +%Y%m%d%H%M%S)"
	sed -i 's|Grocy\\Middleware\\DefaultAuthMiddleware|Grocy\\Middleware\\Auth\\DefaultAuthMiddleware|g; s|Grocy\\Middleware\\ReverseProxyAuthMiddleware|Grocy\\Middleware\\Auth\\ReverseProxyAuthMiddleware|g' "$CONFIG_FILE"
	log "AUTH_CLASS in config.php moved to the Grocy\\Middleware\\Auth namespace (renamed in Grocy 4.7.0), a backup of the previous file is next to it"
fi

# Settings can also come from GROCY_* environment variables, which take
# precedence over config.php - see config-dist.php for the full list.

chown -R www-data:www-data "$DATA_DIR"

log "starting: $*"
exec "$@"
