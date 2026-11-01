# Cliezoo

A simple client manager inside WordPress: leads, clients, contacts and files, with a home dashboard and an inbox for your team.

This is the public source code of the Cliezoo WordPress plugin (slug `cliezoo`). Website: [cliezoo.com](https://cliezoo.com/) · Documentation: [cliezoo.com/docs](https://cliezoo.com/docs/).

Licensed under the GPL, version 2 or later (see `LICENSE`).

## What is where

| Folder / file | What it holds |
|---|---|
| `cliezoo.php` | The main plugin file |
| `src/` | PHP: modules, REST API, permissions, storage, mail |
| `templates/` | The HTML shells the app and public pages are drawn in |
| `app/` | TypeScript/React and CSS source of everything in `build/` |
| `app/shell/` | The app's screens, settings and account pages |
| `app/modules/` | Leads, clients, contacts and files |
| `app/packages/` | Shared code: `data` (API client, cache), `ui` (design system), `app` (router, lists, forms) |
| `app/public/` | Public share and unsubscribe pages |
| `app/styles/` | CSS (Tailwind) |
| `tools/webpack/` | A webpack loader that keeps only English in React Aria's string modules |
| `tools/scripts/aria-locales.js` | Writes `build/aria-locales/*.js`, React Aria's interface strings for each other language |
| `tools/scripts/package.mjs` | Builds the release zip |
| `languages/` | Translation template |

`build/`, `vendor/` and `vendor-prefixed/` are generated and are not in this repository.

## Building the JavaScript and CSS

You need Node.js 20 or newer and npm.

```bash
npm ci
npm run build
```

`npm run build` runs webpack through `@wordpress/scripts` (configured in `webpack.config.js` and `postcss.config.js`) and then `tools/scripts/aria-locales.js`, and writes `build/`. While you work, `npm run dev` rebuilds on every change.

Every compiled file starts with a comment naming this repository as its source. The bundles are minified, never obfuscated.

## PHP libraries

You need Composer and PHP 8.1 or newer.

```bash
composer install
composer prefix-vendor
```

This installs [Action Scheduler](https://github.com/woocommerce/action-scheduler) into `vendor/`, and [WebAuthn](https://github.com/lbuchs/WebAuthn) into `vendor-prefixed/`, renamed into the plugin's namespace with [Strauss](https://github.com/BrianHenryIE/strauss) so it cannot clash with another plugin's copy.

## Making the release zip

After both steps above:

```bash
node tools/scripts/package.mjs
```

The zip is written to `dist/cliezoo-<version>.zip`. It needs Docker, which it uses to write a production autoloader. The zip carries the PHP, the built files and the runtime libraries. The source in `app/` and the build tools stay in this repository.

## Trying it locally

`.wp-env.json` runs WordPress with the packaged plugin (`dist/cliezoo`) and [Plugin Check](https://wordpress.org/plugins/plugin-check/):

```bash
npx wp-env start
npx wp-env run cli wp plugin check cliezoo
```

## Third-party libraries in the bundles

All are open source and installed from npm: React, [React Aria Components](https://github.com/adobe/react-spectrum), [TanStack](https://github.com/TanStack) Query, Router, Table and Virtual, [Tiptap](https://github.com/ueberdosis/tiptap) and [ProseMirror](https://github.com/ProseMirror), [highlight.js](https://github.com/highlightjs/highlight.js) and [lowlight](https://github.com/wooorm/lowlight), [React Hook Form](https://github.com/react-hook-form/react-hook-form), [Zod](https://github.com/colinhacks/zod), [Zustand](https://github.com/pmndrs/zustand), [date-fns](https://github.com/date-fns/date-fns), [DOMPurify](https://github.com/cure53/DOMPurify), [Lucide](https://github.com/lucide-icons/lucide), [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator), [SimpleWebAuthn](https://github.com/MasterKale/SimpleWebAuthn) and the [Inter](https://github.com/fontsource/font-files) font. WordPress packages (`@wordpress/*`) and React are loaded from WordPress itself.

## Contributing

Bug reports, ideas and pull requests are welcome. Open an [issue](https://github.com/degird/cliezoo/issues) or a pull request.

## Security

Please report security problems to hello@degird.com, not in a public issue.
