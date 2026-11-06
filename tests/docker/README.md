# Docker images for the image-format tests (1.14.0)

- `fpm/` — the test copy served by PHP-FPM (the newer formats are made only after the response), GD with AVIF, cjxl:

      docker build -t gbx-joomla-fpm:php8.5.12 tests/docker/fpm
      SITE=$(docker inspect gbx-sitemap-test --format '{{(index .Mounts 0).Source}}')
      docker run -d --name gbx-fpm-test --network gbx-test -p 127.0.0.1:18097:80 -v "$SITE":/var/www/html gbx-joomla-fpm:php8.5.12
      MT_CONTAINER=gbx-fpm-test MT_URL=http://127.0.0.1:18097 node tests/integration/image-formats.test.js [--install]

  It uses the files and the database of `gbx-sitemap-test` (mod_php): a package installed on one is on both;
  the same test on `gbx-sitemap-test` checks that mod_php offers WebP only.

- `bookworm/` — PHP 8.2 with ImageMagick 6.9.11-60 (as on the production hosting) and GD with AVIF;
  `trixie/` — PHP 8.4, ImageMagick 7.1.1 (reads AVIF, cannot write it), cjxl, avifenc:

      docker build -t mt-img-bookworm tests/docker/bookworm
      docker run --rm -v "$PWD":/mt:ro -w /mt mt-img-bookworm php tests/ImageFormatsTest.php avif=gd gd:avif=yes imagick:avif=yes imagick:jxl=no
      docker run --rm -v "$PWD":/mt:ro -w /mt mt-img-bookworm php -d disable_functions=imageavif tests/ImageFormatsTest.php avif=imagick-opaque imagick:avif=yes
      docker build -t mt-img-trixie tests/docker/trixie
      docker run --rm -v "$PWD":/mt:ro -w /mt mt-img-trixie php tests/ImageFormatsTest.php avif=gd jxl=cjxl gd:avif=yes imagick:avif=no avifenc:avif=yes cjxl:jxl=yes imagick:jxl=no
