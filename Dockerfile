FROM php:8.2-fpm-bookworm

# --------------------------------------------------
# System packages
#
# ffmpeg (which also ships ffprobe) is required for video transcoding and
# video thumbnails. It lands at /usr/bin/ffmpeg and /usr/bin/ffprobe.
# --------------------------------------------------
RUN apt-get update && apt-get install -y \
    nginx \
    supervisor \
    cron \
    unzip \
    git \
    ffmpeg \
    libcurl4-openssl-dev \
    libonig-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# --------------------------------------------------
# PHP extensions
# --------------------------------------------------
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        curl \
        sockets \
        gd \
        zip \
        opcache

# --------------------------------------------------
# Composer
# --------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# --------------------------------------------------
# Download fyuhls from the configured repository
#
# Make sure your customizations (popunder ads, external embeds, video
# transcoding, etc.) are committed and pushed to this repository, otherwise
# the image will be built from stock fyuhls.
# --------------------------------------------------
RUN git clone --depth 1 --branch main https://github.com/themovieguy12211/fyuhls.git /app

# --------------------------------------------------
# Configure fyuhls for piracy.cloud
# --------------------------------------------------
RUN sed -i \
    -e "s|'base_url' => 'http://localhost'|'base_url' => 'https://piracy.cloud'|" \
    -e "s|'allowed_hosts' => \['localhost'\]|'allowed_hosts' => ['piracy.cloud']|" \
    /app/config/app.php

# --------------------------------------------------
# Composer dependencies
# --------------------------------------------------
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# --------------------------------------------------
# Runtime directories
# --------------------------------------------------
RUN mkdir -p \
        /app/storage/uploads \
        /app/storage/logs \
        /app/storage/cache \
        /app/storage/cache/tmp \
        /app/storage/sessions \
        /var/log/supervisor \
        /run/php \
        /fyuhls_secure

# --------------------------------------------------
# Installer permissions
#
# Fyuhls' installer requires:
#   /app           writable
#   /app/config    writable
#   /fyuhls_secure writable
#
# The PHP-FPM worker runs as www-data. The cron heartbeat also runs as
# www-data (see /etc/cron.d/fyuhls below), so the transcoding worker can
# write its temporary ffmpeg workspace under /app/storage/cache/tmp.
# --------------------------------------------------
RUN chown -R www-data:www-data /app/storage \
    && chmod -R 775 /app/storage \
    && chown www-data:www-data /app \
    && chmod 775 /app \
    && chown -R www-data:www-data /app/config \
    && chmod -R 775 /app/config \
    && chown www-data:www-data /fyuhls_secure \
    && chmod 700 /fyuhls_secure

# --------------------------------------------------
# PHP configuration
#
# shell_exec is intentionally left ENABLED so fyuhls can invoke ffmpeg for
# video thumbnails and transcoding. Do not add shell_exec to disable_functions.
# --------------------------------------------------
RUN cat > /usr/local/etc/php/conf.d/fyuhls.ini <<'EOF'
upload_max_filesize=256M
post_max_size=300M
max_execution_time=3600
max_input_time=3600
memory_limit=512M
max_file_uploads=100
session.gc_maxlifetime=86400
opcache.enable=1
opcache.validate_timestamps=0
EOF

# --------------------------------------------------
# Nginx configuration
# --------------------------------------------------
RUN rm -f /etc/nginx/sites-enabled/default \
    && cat > /etc/nginx/nginx.conf <<'EOF'
worker_processes auto;

events {
    worker_connections 4096;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;

    client_max_body_size 300M;

    access_log /dev/stdout;
    error_log /dev/stderr warn;

    server {
        listen 80;
        server_name piracy.cloud;

        root /app/public;
        index index.php;

        # --------------------------------------------------
        # Application routing
        # --------------------------------------------------
        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        # --------------------------------------------------
        # fyuhls installer
        # --------------------------------------------------
        location = /install.php {
            include fastcgi_params;

            fastcgi_param HTTPS on;
            fastcgi_param HTTP_HOST $host;

            fastcgi_param SCRIPT_FILENAME /app/public/install.php;
            fastcgi_param DOCUMENT_ROOT /app/public;

            fastcgi_pass 127.0.0.1:9000;

            fastcgi_read_timeout 3600;
            fastcgi_send_timeout 3600;
        }

        # --------------------------------------------------
        # Post-install check
        # --------------------------------------------------
        location = /post_install_check.php {
            include fastcgi_params;

            fastcgi_param HTTPS on;
            fastcgi_param HTTP_HOST $host;

            fastcgi_param SCRIPT_FILENAME /app/public/post_install_check.php;
            fastcgi_param DOCUMENT_ROOT /app/public;

            fastcgi_pass 127.0.0.1:9000;

            fastcgi_read_timeout 3600;
            fastcgi_send_timeout 3600;
        }

        # --------------------------------------------------
        # Main PHP front controller
        # --------------------------------------------------
        location = /index.php {
            include fastcgi_params;

            fastcgi_param HTTPS on;
            fastcgi_param HTTP_HOST $host;

            fastcgi_param SCRIPT_FILENAME /app/public/index.php;
            fastcgi_param DOCUMENT_ROOT /app/public;

            fastcgi_pass 127.0.0.1:9000;

            fastcgi_read_timeout 3600;
            fastcgi_send_timeout 3600;
        }

        # --------------------------------------------------
        # Block all other PHP files
        # --------------------------------------------------
        location ~ \.php$ {
            return 404;
        }

        # --------------------------------------------------
        # Protect application internals
        # --------------------------------------------------
        location ~* ^/(config|src|storage|vendor|database|docs)/ {
            deny all;
        }

        location ^~ /storage/ {
            deny all;
        }

        location ^~ /uploads/ {
            deny all;
        }

        # --------------------------------------------------
        # Protect hidden files
        # --------------------------------------------------
        location ~ /\. {
            deny all;
        }
    }
}
EOF

# --------------------------------------------------
# fyuhls heartbeat / cron
#
# Runs every minute as www-data. This is also what drives video transcoding
# (the video_transcode task) and the schema health check.
# --------------------------------------------------
RUN cat > /etc/cron.d/fyuhls <<'EOF'
* * * * * www-data cd /app && php /app/src/Cron/Run.php >> /app/storage/cron.log 2>&1
EOF

RUN chmod 0644 /etc/cron.d/fyuhls

# --------------------------------------------------
# Supervisor
# --------------------------------------------------
RUN cat > /etc/supervisor/conf.d/supervisord.conf <<'EOF'
[supervisord]
nodaemon=true
user=root

[program:php-fpm]
command=/usr/local/sbin/php-fpm --nodaemonize
priority=10
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:nginx]
command=/usr/sbin/nginx -g "daemon off;"
priority=20
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:cron]
command=/usr/sbin/cron -f
priority=30
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
EOF

# --------------------------------------------------
# HTTP port
# --------------------------------------------------
EXPOSE 80

# --------------------------------------------------
# Start services
# --------------------------------------------------
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
