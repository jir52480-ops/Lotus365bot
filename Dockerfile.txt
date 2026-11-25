FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends 
 libcurl4-openssl-dev 
 libonig-dev 
 && docker-php-ext-install curl mbstring 
 && a2enmod rewrite 
 && rm -rf /var/lib/apt/lists/*

ENV PORT=10000
RUN sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 10000
CMD ["apache2-foreground"]
