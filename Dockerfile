FROM webdevops/php-apache:8.1-alpine

# Dependências do sistema e extensões PHP
RUN apk update && apk upgrade
RUN apk add --no-cache oniguruma-dev libxml2-dev wget imagemagick imagemagick-dev libtool
RUN docker-php-ext-install bcmath
RUN docker-php-ext-install ctype
RUN docker-php-ext-install fileinfo
RUN docker-php-ext-install mbstring
RUN docker-php-ext-install pdo
RUN docker-php-ext-install calendar

# Imagick
RUN set -ex \
    && apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS \
    && if ! php -m | grep -qi '^imagick$'; then \
         pecl install imagick-3.7.0 && docker-php-ext-enable imagick ; \
       fi \
    && apk del .phpize-deps

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ENV APP_ENV=production
ENV PHP_DATE_TIMEZONE=America/Maceio
ENV WEB_DOCUMENT_ROOT=/app/public

WORKDIR /app

# Copia a aplicação
COPY . /app

# Instala dependências da aplicação no build
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Garante diretórios necessários do Laravel
RUN mkdir -p /app/storage/logs \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/bootstrap/cache

# Ajusta permissões para o usuário web
RUN chown -R application:application /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

# Limpeza
RUN rm -rf /tmp/.zip /var/cache/apk/ /tmp/pear/

EXPOSE 80
