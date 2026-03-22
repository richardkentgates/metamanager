# Credits

Metamanager would not exist without the following open source tools and projects. Full credit, respect, and gratitude to their authors and maintainers.

---

## ExifTool
**Author:** Phil Harvey
**License:** [Perl Artistic License / GPL v1+](https://exiftool.org/#license)
**Website:** https://exiftool.org · **Repository:** https://github.com/exiftool/exiftool

The backbone of all metadata work in Metamanager. ExifTool reads and writes EXIF, IPTC, and XMP tags across virtually every image format in existence. Used for both metadata import on upload and write-back to the file.

---

## libjpeg-turbo / jpegtran
**Maintainer:** libjpeg-turbo Project · **Original author:** Independent JPEG Group (IJG)
**License:** [BSD 3-Clause / IJG License / zlib](https://github.com/libjpeg-turbo/libjpeg-turbo/blob/main/LICENSE.md)
**Website:** https://libjpeg-turbo.org · **Repository:** https://github.com/libjpeg-turbo/libjpeg-turbo

`jpegtran` performs lossless JPEG optimisation — reordering Huffman tables and enabling progressive scan without decoding or re-encoding a single pixel.

> This software is based in part on the work of the Independent JPEG Group.

---

## optipng
**Author:** Cosmin Truța
**License:** [zlib/libpng License](https://optipng.sourceforge.net/pngtech/optipng.html)
**Website:** https://optipng.sourceforge.net

Lossless PNG compression by trying multiple DEFLATE parameters and filter combinations to find the smallest lossless representation. Used with `-o2 -preserve`.

---

## libwebp / cwebp
**Author:** Google
**License:** [BSD 3-Clause](https://chromium.googlesource.com/webm/libwebp/+/refs/heads/main/COPYING)
**Website:** https://developers.google.com/speed/webp

`cwebp -lossless` recompresses WebP images without any quality change. Files are only replaced if the result is smaller.

---

## FFmpeg
**License:** [LGPL v2.1+ / GPL v2+](https://ffmpeg.org/legal.html)
**Website:** https://ffmpeg.org

Used for video container remux with `-c copy` — bitstream-identical output, no transcoding, no quality change.

---

## inotify-tools
**License:** [GPL v2](https://github.com/inotify-tools/inotify-tools/blob/master/COPYING)
**Repository:** https://github.com/inotify-tools/inotify-tools

`inotifywait` is what makes Metamanager's daemons instant-response rather than polling. The Linux kernel's inotify subsystem does the watching; inotify-tools provides the userspace interface.

---

## jq
**Original author:** Stephen Dolan · **Maintainer:** [jqlang organisation](https://github.com/jqlang)
**License:** [MIT](https://github.com/jqlang/jq/blob/master/COPYING)
**Website:** https://jqlang.github.io/jq/ · **Repository:** https://github.com/jqlang/jq

Used inside the Bash daemons to parse job JSON files written by PHP.

---

## systemd
**License:** [LGPL v2.1+](https://github.com/systemd/systemd/blob/main/LICENSE.LGPL2.1)
**Website:** https://systemd.io · **Repository:** https://github.com/systemd/systemd

Manages the lifecycle of both daemons — process restart on failure, boot-time start, and journal-based logging. A PID-file pattern is used for health checks so the WordPress plugin does not require `systemctl` privileges.

---

## WordPress
**Maintainer:** [WordPress Foundation](https://wordpressfoundation.org)
**License:** [GPL v2+](https://wordpress.org/about/license/)
**Website:** https://wordpress.org · **Repository:** https://github.com/WordPress/WordPress

Metamanager is a WordPress plugin and relies entirely on the WordPress API. WordPress is itself GPL v2+, which is why Metamanager is licensed under GPL v3 (a compatible and later version).
