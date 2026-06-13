FROM php:8.2-apache

# Enable Apache modules commonly used by PHP apps and .htaccess routing.
RUN a2enmod rewrite headers

# Allow .htaccess overrides under /var/www/html
RUN sed -i 's/AllowOverride[[:space:]]\\+None/AllowOverride All/g' /etc/apache2/apache2.conf

# Install PostgreSQL dev headers for pdo_pgsql
RUN apt-get update && apt-get install -y libpq-dev && rm -rf /var/lib/apt/lists/*

# PDO + PostgreSQL driver
RUN docker-php-ext-install pdo pdo_pgsql

# Production upload limits for larger firmware and software packages.
RUN { \
    echo 'upload_max_filesize=256M'; \
    echo 'post_max_size=256M'; \
    echo 'memory_limit=512M'; \
    echo 'max_execution_time=300'; \
    echo 'max_input_time=300'; \
  } > /usr/local/etc/php/conf.d/translink-upload-limits.ini

WORKDIR /var/www/html
COPY extracted/ /var/www/html/
RUN mv /var/www/html/Translink\ file\ library/website/* /var/www/html/ \
    && mv /var/www/html/Translink\ file\ library/website/.* /var/www/html/ 2>/dev/null || true \
    && rm -rf /var/www/html/Translink\ file\ library

# Keep a copy of bundled uploads outside the future Render disk mount.
RUN mkdir -p /opt/translink-seed/uploads \
  && cp -a /var/www/html/uploads/. /opt/translink-seed/uploads/ 2>/dev/null || true

# Ensure runtime paths and startup scripts are ready.
RUN mkdir -p /var/www/html/uploads /var/www/html/tmp/sessions \
  && chmod +x /var/www/html/render-start.sh \
  && chown -R www-data:www-data /var/www/html/uploads /var/www/html/tmp

EXPOSE 10000

CMD ["/var/www/html/render-start.sh"]
