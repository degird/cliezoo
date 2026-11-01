=== Cliezoo ===
Contributors: degird
Donate link: https://degird.com
Tags: crm, clients, contacts, leads, client management
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple client manager inside WordPress: leads, clients, contacts and files, with a home dashboard and an inbox for your team.

== Description ==

Cliezoo keeps the people you work with in one place, on your own site, with your own data.

Your team works in a fast app at `/dash`, separate from wp-admin, with a sidebar, search and a command palette (Ctrl/⌘ K).

**What you get**

* **Home** — a dashboard of what needs your attention today: your reminders, recent records and the numbers you choose.
* **Inbox** — notifications in the app and by email, with quiet hours and a daily digest.
* **Leads** — capture who might become a client, see where each one stands, and turn a lead into a client in one step.
* **Clients** — companies and people you work for, with notes, to-dos, activity, custom fields and their own files.
* **Contacts** — the people at each client, with their details and history.
* **Files** — folders and files with real permissions, previews and safe sharing links.

**Built with care**

* Nothing is capped: no seat limits, no record limits and no trials.
* Every operation goes through a permission check on the server.
* Two-step verification, passkeys, session control and an audit log.
* Stored secrets are encrypted with libsodium.
* Your records stay in your own database and uploads folder.

**Contribute or report a bug**

Cliezoo is developed in the open on GitHub. The full source code is there, and bug reports, ideas and pull requests are welcome in the [Cliezoo repository on GitHub](https://github.com/degird/cliezoo).

Please report security problems privately to hello@degird.com, not in a public issue.

== Installation ==

1. Install and activate Cliezoo.
2. Open **Cliezoo** in your dashboard. The setup wizard takes about a minute.
3. Share the app address with your team. They sign in there, not in wp-admin.

Cliezoo works best with pretty permalinks, but also works without them.

== Frequently Asked Questions ==

= Does Cliezoo send my data anywhere? =

Your leads, clients, contacts and files live in your own database and uploads folder, and Cliezoo sends them to no service of its own. It only connects elsewhere for the optional features an administrator sets up, such as an SMTP server for email. Each one is listed under "External services" below.

= Can an outside app or script administer my site through Cliezoo? =

No. An API key (a WordPress Application Password) lets a script, Zapier or another app work with your leads, clients, contacts and files, with what the key owner's role allows. It cannot change passwords, two-step verification, passkeys or sessions, add or remove people, change roles or who can reach which records, or change any setting: those are refused unless the person is signed in to the app in their browser. The same applies to AI tools that use the WordPress Abilities API.

= How much can I export at once? =

A CSV export is built in your browser, so it exports the first 5,000 rows of the list and tells you if it stopped there. Filter the list for more.

= Will it work with my cache plugin? =

Yes. The app tells caches to leave it alone, and Setup Health checks that they listen.

= What happens to my data when I delete the plugin? =

Deleting Cliezoo always removes what is only temporary: its transients, its scheduled background jobs and its caches. Your records stay unless you first switch on "Delete all Cliezoo data when the plugin is deleted" in Settings → Site and data. With that switch on, deleting the plugin removes every Cliezoo table, setting, user setting, role and capability and the private files folder, on every site of a network. The switch is off by default, so a plugin deleted by mistake never takes your records with it.

= How do I re-key encrypted secrets? =

Secrets (such as two-step verification keys) are encrypted with a key from `CLIEZOO_ENCRYPTION_KEY` in wp-config.php, or, without it, with one derived from your WordPress salts. If you add or change `CLIEZOO_ENCRYPTION_KEY`, or your salts change, run `wp cliezoo rekey` with the old key material (`--from=salts`, `--old-key=…`, or `--old-auth-key=… --old-secure-auth-key=…`). `wp cliezoo rekey --dry-run` shows what would change first.

= Does Cliezoo load avatars from Gravatar? =

No. People without a picture of their own are shown with their initials, so no avatar is requested from an outside service.

== External services ==

Cliezoo has no service of its own: no account, no tracking, no analytics and no licence check. It works without connecting anywhere. The connections below happen only when an administrator turns the feature on, or when a person clicks it.

**SMTP mail server (optional, off by default)**

Without this, Cliezoo sends email through WordPress (`wp_mail`) and your host's mail setup. An administrator can instead send the site's email through an SMTP server of their choice in Settings → Email (for example Gmail, Amazon SES, Mailgun, Brevo or the host's own server). Once it is switched on, every email the site sends through `wp_mail` goes to that server: the sender, recipients, subject, message body and attachments, along with the SMTP username and password the administrator entered (the password is stored encrypted). The "Try the mail server" button connects to the server to check the settings, without sending an email.

The SMTP provider is chosen by the site owner, and their terms and privacy policy apply. For the two providers named as examples in the settings:

* Gmail / Google Workspace (smtp.gmail.com): [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy).
* Amazon SES (email-smtp.*.amazonaws.com): [AWS Service Terms](https://aws.amazon.com/service-terms/), [Privacy Notice](https://aws.amazon.com/privacy/).

**DNS lookups for the sending domain (on request)**

When an administrator clicks the check for the domain email is sent from, Cliezoo asks your server's own DNS resolver for that domain's public SPF, DKIM and DMARC records. Only the domain name is looked up. It is not sent to any service of ours.

**Portal custom domain check (optional)**

If an administrator gives the client portal its own domain and clicks "Verify", Cliezoo makes one HTTPS request to that domain, to confirm it points at this site. The request carries no personal data, only a fixed check parameter. The domain is the administrator's own.

**Help centre link (team members only, on click)**

The account menu has a "Help centre" item that opens the Cliezoo documentation at [cliezoo.com/docs](https://cliezoo.com/docs/) in a new browser tab. Nothing is sent until a team member clicks it, and then only what any browser sends when it opens a web page. Clients using the portal never see it.

**Requests to your own site**

Setup Health sends requests to your own site only, to check that background jobs, the app address, private files and caching work.

**"Powered by Cliezoo"**

Cliezoo shows no credit or link on your public pages, the client portal or emails by default. An administrator can choose to show a plain-text "Powered by Cliezoo" in Settings → General.

== Source code and build ==

**Public source repository: https://github.com/degird/cliezoo**

Everything in `build/` is compiled with webpack. Its human-readable source is published in that public repository, with the build tools, `package.json`, `package-lock.json` and build instructions. The bundles are minified only, never obfuscated, and each compiled file starts with a comment naming where its source is.

**Where each compiled file comes from**

* `build/*.js` (app, data, ui, public), `build/chunks/*.js` and `build/*.css` are built from the TypeScript/React and CSS source in the repository's `app/` folder: `app/shell` (the app's screens), `app/modules` (leads, clients, contacts, files), `app/packages` (shared data, UI and app code), `app/public` (public share and unsubscribe pages) and `app/styles` (CSS). Third-party libraries from npm are bundled into them (see below).
* `build/aria-locales/*.js` hold the built-in interface strings of React Aria (for example "Clear search") in each language. They are generated from the `react-aria` npm package by `tools/scripts/aria-locales.js`; the bundles carry only English, through the loader `tools/webpack/aria-english-only.js`.
* `build/fonts/*.woff2` is the Inter font, from `@fontsource-variable/inter`.

**Rebuilding**

You need Node.js 20 or newer and npm. Clone https://github.com/degird/cliezoo, then in that folder:

1. `npm ci` (or `npm install`) — installs the build tools and the libraries listed in `package.json`, at the versions in `package-lock.json`.
2. `npm run build` — runs webpack through `@wordpress/scripts` (configured in `webpack.config.js` and `postcss.config.js`) and then `tools/scripts/aria-locales.js`, and writes a fresh `build/`.

`npm run dev` rebuilds on every change while you work.

**Third-party libraries in the bundles**

All are open source and installed from npm, where each one's source is published:

* React and React DOM — https://github.com/facebook/react
* React Aria Components (Adobe) — https://github.com/adobe/react-spectrum
* TanStack Query, Router, Table and Virtual — https://github.com/TanStack
* Tiptap (rich-text editor) and ProseMirror — https://github.com/ueberdosis/tiptap , https://github.com/ProseMirror
* highlight.js and lowlight (code highlighting) — https://github.com/highlightjs/highlight.js , https://github.com/wooorm/lowlight
* React Hook Form and its resolvers — https://github.com/react-hook-form/react-hook-form
* Zod — https://github.com/colinhacks/zod
* Zustand — https://github.com/pmndrs/zustand
* date-fns and @date-fns/tz — https://github.com/date-fns/date-fns
* DOMPurify — https://github.com/cure53/DOMPurify
* Lucide icons — https://github.com/lucide-icons/lucide
* qrcode-generator (QR codes for two-step verification) — https://github.com/kazuhikoarase/qrcode-generator
* SimpleWebAuthn browser (passkeys) — https://github.com/MasterKale/SimpleWebAuthn
* Inter font (Fontsource) — https://github.com/fontsource/font-files
* WordPress packages (@wordpress/*), loaded from WordPress itself where it provides them — https://github.com/WordPress/gutenberg

The PHP libraries ship as readable source, installed with Composer (`composer.json`): Action Scheduler in `vendor/` (https://github.com/woocommerce/action-scheduler), and WebAuthn in `vendor-prefixed/` (https://github.com/lbuchs/WebAuthn), renamed into the plugin's own namespace with Strauss so it cannot clash with another plugin's copy.

== Screenshots ==

1. Home: your to-dos and reminders for today, across every client and lead.
2. A client: details, health, the team on it and notes, with contacts, activity and files a tab away.
3. Leads: where each one stands, where it came from and its score.
4. A client's files, in folders, with previews.
5. Contacts: the people at each client.
6. Dark mode, here on the Clients list.

== Changelog ==

= 0.1.2 =
* The source code is published at https://github.com/degird/cliezoo, and the readme links to it.
* Every compiled file now names where its source is.
* The plugin zip no longer carries the source and build tools: they live in the repository.
* Query counts for Cliezoo's developers are written to the debug log only when `CLIEZOO_QUERY_BUDGET` is set, never by default.

= 0.1.1 =
* Security: account, team, permission and settings changes can only be made in the app, never through an API key or the Abilities API.
* Accepting an invitation no longer changes the password of an account that already existed.
* Fixed: files handed to nginx (X-Accel-Redirect) now use a configured internal location, so subdirectory installs and moved folders work.
* Action Scheduler updated to 4.2.0.
* The readme documents the external services and the source code of every compiled file.

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.1.2 =
Documentation of the source code. No change to how the plugin works.

= 0.1.1 =
Security and compatibility fixes. Recommended for everyone.

= 0.1.0 =
First release.
