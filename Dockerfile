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
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader

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

# git: bootstrap-combobox is pulled from a GitHub fork
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends git; \
	rm -rf /var/lib/apt/lists/*

# `.yarnrc` uses Yarn 1 options (--modules-folder), Yarn 2+ does not support them
RUN npm install --global --no-fund --no-audit yarn@1

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
		$missing = array_diff(\Grocy\Helpers\REQUIRED_PHP_EXTENSIONS, get_loaded_extensions()); \
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
