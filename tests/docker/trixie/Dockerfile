FROM debian:trixie
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends php8.4-cli php8.4-gd php8.4-imagick php8.4-mbstring libjxl-tools libavif-bin imagemagick && rm -rf /var/lib/apt/lists/*
