FROM php:8.2-apache

# نصب ابزارها و کتابخانه‌های مورد نیاز از جمله libgmp-dev
RUN apt-get update && apt-get install -y \
    libffi-dev \
    libsqlite3-dev \
    zlib1g-dev \
    libgmp-dev \
    git \
    unzip \
    curl \
    && docker-php-ext-install ffi pdo_sqlite sockets bcmath gmp

# فعال‌سازی rewrite در آپاچی
RUN a2enmod rewrite

COPY . /var/www/html/

# دانلود مستقیم madeline.php در زمان ساخت ایمیج
RUN curl -sSL https://phar.madelineproto.org/madeline.php -o /var/www/html/madeline.php

RUN chmod -R 777 /var/www/html

EXPOSE 80