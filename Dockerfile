FROM php:8-apache
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

COPY php/ /var/www/html/

#docker build . -t logo_repo:mypep
#docker push logotipiwe/logo_repo:mypep
