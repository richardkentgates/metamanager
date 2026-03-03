# Contributing to Metamanager

Thank you for your interest in contributing. This document explains how to get involved.

---

## Ways to Contribute

- **Bug reports** — open an [Issue](https://github.com/richardkentgates/metamanager/issues) with a clear description, steps to reproduce, and your server environment
- **Feature requests** — open an Issue labelled `enhancement` with your use case
- **Code contributions** — fork, branch, and open a Pull Request
- **Documentation** — improvements to README, the website, or code comments are always welcome
- **Testing** — test on different distros, WordPress versions, or server configurations and report your findings

---

## Development Setup

### Requirements

- Linux (Ubuntu 22.04+ or equivalent)
- PHP 8.0+, WordPress 6.0+ (local install)
- `jpegtran`, `optipng`, `cwebp`, `ffmpeg`, `exiftool`, `inotifywait`, `jq`
- `bash` 5+

### Getting Started

```bash
# Fork the repo on GitHub, then:
git clone https://github.com/YOUR-USERNAME/metamanager.git
cd metamanager

# Symlink or copy the plugin into a local WordPress install
ln -s "$(pwd)" /path/to/wordpress/wp-content/plugins/metamanager

# Install daemon dependencies
sudo apt install libjpeg-turbo-progs optipng webp ffmpeg libimage-exiftool-perl inotify-tools jq

# Run metamanager-install.sh to set up daemons
sudo bash metamanager-install.sh --wp-path /path/to/wordpress
```

---

## Code Standards

### PHP

- Code must comply with the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- PHP 8.0+ features (`match`, `named arguments`, union types, etc.) are welcome
- All public functions and classes must have docblocks
- Escape all output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_json_encode()`
- Sanitise all input: `sanitize_text_field()`, `absint()`, `sanitize_key()`, etc.
- All `$wpdb` queries must be prepared with `$wpdb->prepare()` or parameterised
- No direct `echo` outside of template-style methods
- Text domain: `metamanager`

### Bash

- `set -euo pipefail` at the top of every script
- `shellcheck` must pass with no errors (`shellcheck daemons/*.sh`)
- All user-controlled values must be quoted
- Prefer `[[ ]]` over `[ ]`

### JavaScript

- Plain ES6+, no build toolchain required
- No external dependencies beyond jQuery (already bundled with WordPress)
- Use `wp_localize_script()` for all data passed from PHP

---

## Pull Request Process

1. Branch from `main`: `git checkout -b feature/your-feature-name`
2. Make your changes with clear, atomic commits
3. Test on a real WordPress instance with actual images
4. Ensure no PHP errors at `WP_DEBUG = true`
5. Open a PR against `main` with a clear description of what changed and why
6. Reference any related Issues in the PR description

---

## Architecture Principles

Please read and respect these before proposing changes:

1. **PHP coordinates; daemons execute.** PHP must never touch the image bytes directly. Job files are the interface.
2. **Lossless only.** The compression daemon must never re-encode or reduce quality.
3. **No false attribution.** Bulk operations must never set Creator, Copyright, or Owner. These are per-image fields.
4. **No hardcoded paths.** `metamanager-install.sh` patches paths at deploy time. The plugin derives paths from WordPress constants only.
5. **Single hook registrations.** Every WordPress hook must be registered exactly once.
6. **Format-aware tag writing.** Metadata must use each file format's native tag system. Images use EXIF/IPTC/XMP simultaneously; MP3 uses ID3; MP4/M4A/MOV use QuickTime atoms; OGG/FLAC use Vorbis comments; AVI/WAV/WMV/WMA and PDF use XMP-only. MKV/WebM/OGV are read-only. The `WRITE_CAPABILITY` map in `class-mm-metadata.php` is the single source of truth — consult it before adding any new format.

---

## Reporting Security Issues

Do not open public Issues for security vulnerabilities. See [SECURITY.md](SECURITY.md).

---

## License

By contributing, you agree that your contributions will be licensed under the [GPLv3](LICENSE).
