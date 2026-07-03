FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libssl-dev libzip-dev default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql calendar zip \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

# Sessioni su DB (sopravvivono allo sleep/deploy della macchina Fly).
RUN { \
      echo 'auto_prepend_file=/var/www/html/includes/sessione.php'; \
      echo 'session.gc_maxlifetime=43200'; \
    } > /usr/local/etc/php/conf.d/zz-sessioni.ini

EXPOSE 8080

RUN sed -i 's/80/8080/g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/80/8080/g' /etc/apache2/ports.conf \
    && echo "DirectoryIndex index.php index.html" > /etc/apache2/conf-available/dirindex.conf \
    && a2enconf dirindex \
    && a2enmod rewrite

CMD ["apache2-foreground"]
