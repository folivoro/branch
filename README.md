# 🦥 folivoro/branch

> Local package development for Composer without touching composer.json

## The Problem

When developing packages locally, you typically need to either:

1. **Modify `composer.json`** - Add path repositories, remember to remove them before committing
2. **Use `composer link`** - Extra setup, can get confused with global installs

Both approaches are error-prone and easy to forget.

## The Solution

Drop a `branch-local.json` next to your `composer.json` and you're done. No modifications needed.

```bash
cd ~/projects/my-app
composer global require folivoro/branch
# Allow the plugin once in ~/.composer/composer.json
echo '{"config":{"allow-plugins":{"folivoro/branch":true}}}' | jq -s '.[0].config.allow-plugins += input.config.allow-plugins' ~/.composer/composer.json ~/.composer/composer.json > tmp && mv tmp ~/.composer/composer.json
```

Create `branch-local.json`:
```json
{
    "acme/widget": "../acme-widget",
    "acme/gizmo": "/Users/dev/acme-gizmo"
}
```

Run `composer install` or `composer update`. That's it.

## How It Works

The plugin reads `branch-local.json` at `pre-install` and `pre-update` time and registers each path as a Composer `PathRepository`. It automatically extracts the version constraint from your `composer.json`'s `require` block and uses the lower bound, so your local branches can be named anything.

### Version Resolution

| Consumer require | Local version used |
|-----------------|-------------------|
| `^1.0.0` | `1.0.0` |
| `~2.1.0` | `2.1.0` |
| `>=3.0.0` | `3.0.0` |
| `dev-main` | `dev-main` |

### Path Formats

- **Relative**: `../my-package` (relative to working directory)
- **Absolute**: `/Users/dev/packages/my-package`

## Installation

### Global (Recommended)

```bash
composer global require folivoro/branch
```

Allow the plugin:
```bash
composer config --global allow-plugins.folivoro/branch true
```

### Per-project

```bash
composer require --dev folivoro/branch
```

## Configuration

The plugin reads `branch-local.json` from the project root:

```json
{
    "packages": {
        "vendor/package-name": "../path/to/package"
    }
}
```

### Global vs Per-project

| Mode | branch-local.json location | Use case |
|------|---------------------|----------|
| **Global** | Project root | Works across all projects |
| **Per-project** | Inside a project | Project-specific overrides |

## Requirements

- PHP 8.1+
- Composer 2.0+

## License

MIT
