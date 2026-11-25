FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libonig-dev \
    && docker-php-ext-install curl mbstring \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

RUN mkdir -p /app/data && chmod -R 777 /app/data

EXPOSE 10000
CMD ["php", "-S", "0.0.0.0:10000", "-t", "/app"]
