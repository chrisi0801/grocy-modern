# syntax=docker/dockerfile:1
#
# Grocy Modern - container image
#
# The Composer and Yarn dependencies are not committed to this repository, so
# they are built here in separate stages and only the results end up in the
# final image.
#
#   docker build -t grocy-modern:local .

ARG PHP_VERSION=8.5

# ---------------------------------------------------------------------------
# 1) PHP dependencies -> /app/packages
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli-trixie AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# git: two dependencies (lessql, php-gettext) are installed from VCS forks
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends git unzip; \
	rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Install first with only the manifests present, so this layer stays cached
# as long as the dependencies themselves don't change
COPY composer.json composer.lock ./
# The lock file requires ext-gd and ext-intl, which the CLI image doesn't ship.
# Both are only needed to *run* Grocy, not to download and unpack the
# dependencies - compiling them here just to throw the stage away would be
# wasted build time. The runtime stage installs and verifies them for real.
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader \
	--ignore-platform-req=ext-gd \
	--ignore-platform-req=ext-intl

# The autoloader needs the actual source to build its classmap
COPY helpers/ ./helpers/
COPY services/ ./services/
COPY controllers/ ./controllers/
COPY middleware/ ./middleware/
RUN composer dump-autoload --no-dev --optimize

# ---------------------------------------------------------------------------
# 2) Frontend dependencies -> /app/public/packages
# ---------------------------------------------------------------------------
FROM node:22-trixie-slim AS assets

# bootstrap-combobox is pulled from a GitHub fork, so yarn shells out to git.
#
# ca-certificates is required as well and is NOT present in the node image:
# it gets installed there only temporarily and the `apt-mark auto '.*'` +
# `apt-get purge --auto-remove` cleanup drops it again (only packages providing
# shared libraries the node binaries link against survive). npm/yarn themselves
# use Node's built-in CA store and don't notice, but git does - without it the
# clone fails TLS verification and yarn exits with git's code 128.
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends git ca-certificates; \
	rm -rf /var/lib/apt/lists/*

# The node image already ships Yarn 1.22 (symlinked into /usr/local/bin), so
# installing it again via npm fails with EEXIST. Only install if it is really
# missing, and insist on Yarn 1: `.yarnrc` uses --modules-folder, which Yarn 2+
# no longer supports.
RUN set -eux; \
	if ! command -v yarn > /dev/null 2>&1; then \
		npm install --global --force --no-fund --no-audit yarn@1.22.22; \
	fi; \
	yarn_version="$(yarn --version)"; \
	case "$yarn_version" in \
		1.*) echo "using yarn $yarn_version" ;; \
		*) echo "yarn 1.x required, found $yarn_version" >&2; exit 1 ;; \
	esac

WORKDIR /app
COPY package.json yarn.lock .yarnrc ./
RUN yarn install --frozen-lockfile --non-interactive

# ---------------------------------------------------------------------------
# 3) Runtime
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-apache-trixie AS runtime

# Of the extensions Grocy requires, only gd and intl are missing from the
# official PHP image - the rest (pdo_sqlite, mbstring, iconv, zlib, fileinfo,
# ctype, filter, tokenizer, json) is compiled in. The verification step further
# down fails the build if that ever stops being true.
#
# The -dev packages are deliberately not purged afterwards: removing them also
# drags out the runtime libraries the freshly compiled extensions link against.
# Correctness over ~150 MB of image size.
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends \
		libicu-dev \
		libpng-dev \
		libjpeg-dev \
		libfreetype6-dev \
		libwebp-dev \
		tzdata; \
	docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp; \
	docker-php-ext-install -j"$(nproc)" gd intl; \
	rm -rf /var/lib/apt/lists/*

RUN set -eux; \
	a2enmod rewrite; \
	printf 'memory_limit = 256M\nupload_max_filesize = 32M\npost_max_size = 32M\nexpose_php = Off\n' \
		> /usr/local/etc/php/conf.d/grocy.ini; \
	mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/apache-grocy.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Application code, then the two dependency trees
COPY . .
COPY --from=vendor /app/packages ./packages
COPY --from=assets /app/public/packages ./public/packages

COPY docker/entrypoint.sh /usr/local/bin/grocy-entrypoint
RUN chmod +x /usr/local/bin/grocy-entrypoint

# Fail the build - not the user's first request - if a requirement is missing
RUN set -eux; \
	php -r ' \
		require "/var/www/html/helpers/PrerequisiteChecker.php"; \
		$required = array_unique(array_merge( \
			\Grocy\Helpers\REQUIRED_PHP_EXTENSIONS, \
			["libxml", "simplexml"] \
		)); \
		/* get_loaded_extensions() reports e.g. "SimpleXML", so compare lowercased */ \
		$loaded = array_map("strtolower", get_loaded_extensions()); \
		$missing = []; \
		foreach ($required as $ext) { if (!in_array(strtolower($ext), $loaded, true)) { $missing[] = $ext; } } \
		if ($missing) { fwrite(STDERR, "Missing PHP extension(s): " . implode(", ", $missing) . "\n"); exit(1); } \
		$sqlite = (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetch()[0]; \
		if (version_compare($sqlite, \Grocy\Helpers\REQUIRED_SQLITE_VERSION, "<")) { \
			fwrite(STDERR, "SQLite " . \Grocy\Helpers\REQUIRED_SQLITE_VERSION . " required, image has " . $sqlite . "\n"); exit(1); } \
		if (version_compare(PHP_VERSION, \Grocy\Helpers\REQUIRED_PHP_VERSION, "<")) { \
			fwrite(STDERR, "PHP " . \Grocy\Helpers\REQUIRED_PHP_VERSION . " required, image has " . PHP_VERSION . "\n"); exit(1); } \
		printf("OK: PHP %s, SQLite %s, all required extensions present\n", PHP_VERSION, $sqlite); \
	'; \
	test -f /var/www/html/packages/autoload.php; \
	test -d /var/www/html/public/packages/bootstrap

EXPOSE 80

# `/login` renders a full page through PHP and Blade without touching the
# database, which makes it a cheap end-to-end check
HEALTHCHECK --interval=60s --timeout=10s --start-period=20s --retries=3 \
	CMD php -r '$c = @file_get_contents("http://127.0.0.1/login"); exit($c === false ? 1 : 0);'

ENTRYPOINT ["grocy-entrypoint"]
CMD ["apache2-foreground"]
