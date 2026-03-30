FROM alpine:3.21

LABEL Name="running-tracker"
LABEL Version="1.0.0"

# System packages requested + PHP 8.4/Apache runtime for Symfony.
RUN apk add --no-cache \
    bash \
    openrc \
    rsync \
    apache2 \
    apache2-ssl \
    apache2-utils \
    apache2-webdav \
    font-noto \
    fontconfig \
    g++ \
    gcc \
    git \
    jpeg-dev \
    libffi-dev \
    mlocate \
    musl-dev \
    nodejs \
    npm \
    openjpeg-dev \
    openssl \
    pango \
    py3-brotli \
    py3-cffi \
    py3-pillow \
    py3-pip \
    python3-dev \
    sudo \
    terminus-font \
    ttf-freefont \
    util-linux \
    yarn \
    zlib-dev \
    composer \
    php84 \
    php84-apache2 \
    php84-ctype \
    php84-iconv \
    php84-opcache \
    php84-pdo \
    php84-pdo_pgsql \
    php84-intl \
    php84-zip \
    php84-mbstring \
    php84-tokenizer \
    php84-xml \
    php84-dom \
    php84-fileinfo \
    php84-phar \
    php84-openssl \
    php84-session \
    php84-simplexml \
    php84-xmlwriter \
    php84-curl \
    php84-json \
    php84-pcntl \
    php84-sodium

ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
ENV PHP_INI_SCAN_DIR=/etc/php84/conf.d

ARG INSTALL_REMOTE_PROJECT=false
ARG branch_project_name=main
ARG git_project_account
ARG git_project_account_secret
ARG project_env
ARG git_project_repo_url=https://github.com/Ella-rep/running-tracker.git

# Apache setup for Symfony: enable rewrite/ssl/dav modules and point to public/.
RUN sed -i 's|^#LoadModule rewrite_module|LoadModule rewrite_module|g' /etc/apache2/httpd.conf \
    && sed -i 's|^#LoadModule ssl_module|LoadModule ssl_module|g' /etc/apache2/httpd.conf \
    && sed -i 's|^#LoadModule dav_module|LoadModule dav_module|g' /etc/apache2/httpd.conf \
    && sed -i 's|^#LoadModule dav_fs_module|LoadModule dav_fs_module|g' /etc/apache2/httpd.conf \
    && sed -i 's|^#LoadModule socache_shmcb_module|LoadModule socache_shmcb_module|g' /etc/apache2/httpd.conf \
    && sed -i 's|^#ServerName www.example.com:80|ServerName localhost:80|g' /etc/apache2/httpd.conf \
    && sed -i 's|/var/www/localhost/htdocs|/var/www/app/public|g' /etc/apache2/httpd.conf \
    && printf '%s\n' \
       '<Directory "/var/www/app/public">' \
       '    AllowOverride All' \
       '    Require all granted' \
       '    Options FollowSymLinks' \
       '</Directory>' \
       > /etc/apache2/conf.d/symfony.conf

WORKDIR /var/www/app

COPY . /var/www/app

# Installation projet + Configuration NPM Projet + build Symfony.
RUN mkdir -p /appli/apache_2.4/htdocs \
    && ln -sf /usr/bin/composer /usr/local/bin/composer \
    && if [ "$INSTALL_REMOTE_PROJECT" = "true" ]; then \
        git config --global http.sslVerify false \
                && if [ -n "$git_project_account" ] && [ -n "$git_project_account_secret" ]; then \
                         CLONE_URL="https://${git_project_account}:${git_project_account_secret}@github.com/Ella-rep/running-tracker.git"; \
                     else \
                         CLONE_URL="$git_project_repo_url"; \
                     fi \
        && GIT_SSL_NO_VERIFY=true git clone --single-branch --branch "$branch_project_name" \
                        "$CLONE_URL" \
            /appli/apache_2.4/htdocs/symfony \
        && if [ -n "$project_env" ] \
            && [ -f "/appli/apache_2.4/htdocs/symfony/.env.${project_env}" ]; then \
               cp -p "/appli/apache_2.4/htdocs/symfony/.env.${project_env}" /appli/apache_2.4/htdocs/symfony/.env; \
           elif [ -n "$project_env" ] \
              && [ -f "/appli/apache_2.4/htdocs/symfony/deploiement/ucn/appli/.env.${project_env}" ]; then \
               cp -p "/appli/apache_2.4/htdocs/symfony/deploiement/ucn/appli/.env.${project_env}" /appli/apache_2.4/htdocs/symfony/.env; \
           fi \
        && chmod +x /appli/apache_2.4/htdocs/symfony/bin/console \
        && chown apache:apache /appli/apache_2.4/htdocs/symfony/ -R \
        && cd /appli/apache_2.4/htdocs/symfony \
                && composer dump-autoload \
        && /usr/bin/npm run build; \
      fi \
    && ln -sf /usr/bin/php84 /usr/bin/php \
    && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && mkdir -p var/cache var/log config/jwt /run/apache2 \
    && chown -R apache:apache /var/www/app

EXPOSE 80

CMD ["sh", "-c", "set -e; PASSPHRASE=\"${JWT_PASSPHRASE:-changeme}\"; mkdir -p config/jwt var/cache var/log /run/apache2; if [ ! -f config/jwt/private.pem ] || [ ! -f config/jwt/public.pem ]; then openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 -pass pass:\"$PASSPHRASE\"; openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:\"$PASSPHRASE\"; fi; php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true; php bin/console cache:clear --no-warmup || true; php bin/console cache:warmup || true; exec httpd -D FOREGROUND"]
