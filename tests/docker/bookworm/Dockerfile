FROM debian:bookworm
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends php8.2-cli php8.2-gd php8.2-imagick php8.2-mbstring imagemagick libheif1 && rm -rf /var/lib/apt/lists/*
