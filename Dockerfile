FROM php:8.2-apache

# نصب پیش‌نیازهای لازم برای پردازش چانک‌ها و فایل‌های حجیم
RUN apt-get update && apt-get install -y \
    libffi-dev \
    libsqlite3-dev \
    zlib1g-dev \
    libpng-dev \
    libjpeg-dev \
    git \
    unzip \
    && docker-php-ext-install ffi pdo_sqlite sockets bcmath

# فعال‌سازی ماژول rewrite در آپاچی
RUN a2enmod rewrite

COPY . /var/www/html/
RUN chmod -R 777 /var/www/html

EXPOSE 80