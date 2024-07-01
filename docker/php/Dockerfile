FROM php:8.2-fpm

RUN apt-get update && \
apt-get install -y libfreetype6-dev libjpeg62-turbo-dev libpng-dev && \
docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ && \
docker-php-ext-install gd && docker-php-ext-install pdo_mysql mysqli

RUN curl -sS https://getcomposer.org/installer | php && \
  mv composer.phar /usr/local/bin/composer
  
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
  RUN install-php-extensions zip