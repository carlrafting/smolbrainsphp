# PHP Static Package Manager (spm)

A minimal, composer-free PHP package manager supporting versioned static packages with optional features like Git exclusions and PHAR bundling.

## Features

- Simple versioned static packages: Download zip or git tags by version, extracted under vendor/
- Lock file (deps.lock): Tracks installed versions and SHA256 hashes
- Git repo support: Clone specific version tags and optionally exclude dev/test files
- PHAR bundling: Package your app and dependencies into a single executable PHAR archive
- Custom PHAR stub: CLI-friendly entry point with shebang and main.php bootstrap
- Autoloading: PSR-4-ish autoloader from locked dependencies

## Directory Structure

```bash
    /my-app
    ├─ deps.json          # Package dependencies & metadata
    ├─ deps.lock          # Installed package versions and hashes
    ├─ getdeps.php        # Package installer/updater and PHAR builder
    ├─ autoload.php       # Autoloader generated from deps.lock
    ├─ main.php           # PHAR CLI entry point
    ├─ spm                # CLI wrapper script
    ├─ vendor/            # Downloaded packages, versioned folders
    └─ build/             # Output PHAR archive(s)
```

## Usage

### 1. Define dependencies (deps.json)

Example:

```
{
  "vendor/package": {
    "url": "https://github.com/vendor/package/archive/refs/tags/v1.0.0.zip",
    "version": "1.0.0",
    "sha256": "expectedsha256hashhere"
  },
  "another/vendor": {
    "url": "https://github.com/another/vendor.git",
    "version": "1.2.3",
    "git": true,
    "exclude": ["*.md", "tests/*", "docs/*"],
    "sha256": "anotherhashvalue"
  }
}
```

- `url`: HTTP(S) URL or Git repository URL
- `version`: Git tag or zip archive version
- `git: true` if cloning a Git repo, otherwise zip download
- `exclude` (optional): Array of glob patterns to delete from cloned Git repo before zipping
- `sha256` (optional): Validate package integrity

### 2. Install or update packages

Use the CLI wrapper `spm`:

```
./spm install                   # Download and extract all dependencies
./spm install vendor/pkg <url>  # Install package from url with given vendor/pkg
./spm update                    # Force update all packages
./spm update vendor/pkg         # Update one specific package
./spm phar                      # Build PHAR archive with custom stub
```

### 3. Autoloading

Include autoload.php in your app entry:

```php
require __DIR__ . '/autoload.php';

// Now use classes from installed packages:
// e.g. new Vendor\Package\SomeClass();
```

The autoloader maps package namespaces based on folder names and versions locked in deps.lock.

### 4. PHAR archive

Build a PHAR bundle of your app and dependencies with:

```bash
./spm phar
```

- Output: `build/bundle.phar`
- PHAR includes:
    - All `.php` and `.json` files (excluding `/build/`, `/vendor/`, `.git/`, and `.zip` files)
    - `autoload.php`
    - `main.php` CLI entry point

Executable with shebang:

```bash
chmod +x build/bundle.phar
./build/bundle.phar --help
```

5. Custom PHAR stub and `main.php`

- The PHAR stub loads autoload.php and then runs `main.php`.
- `main.php` is a minimal CLI bootstrap where you can handle commands or initialize your app.

Example `main.php` inside PHAR:

```php
<?php
echo "Welcome to your PHAR CLI app!\n";

$args = $_SERVER['argv'] ?? [];

if (in_array('--help', $args) || count($args) < 2) {
    echo "Usage: ./bundle.phar [command]\n";
    exit(0);
}

[$command] = $args;

// Add your CLI commands here
switch ($command) {
    case 'hello':
        echo "Hello from PHAR!\n";
        break;
    default:
        echo "Unknown command: $command\n";
        exit(1);
}
```
