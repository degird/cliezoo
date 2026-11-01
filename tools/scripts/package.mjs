#!/usr/bin/env node
/**
 * Builds a release zip.
 *
 *   node tools/scripts/package.mjs
 *
 * What ships is what WordPress runs — PHP, the built bundles,
 * templates, config, languages and the runtime part of vendor. The source
 * the bundles were built from, and the tools that build them, are
 * published at https://github.com/degird/cliezoo, which the readme links
 * to (WordPress.org guideline 4). Tests and developer tooling stay
 * behind. Nothing is uploaded anywhere:
 * the zip is written to dist/ for a person to check and publish.
 */
import { execFileSync } from 'node:child_process';
import {
	cpSync,
	existsSync,
	mkdirSync,
	readFileSync,
	rmSync,
	writeFileSync,
	readdirSync,
	statSync,
} from 'node:fs';
import { basename, dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '../..' );
const WHICH = 'free';
const PLUGIN = 'cliezoo-wp-org';
const SLUG = 'cliezoo';
const SOURCE = ROOT;
const DIST = join( ROOT, 'dist' );
const STAGE = join( DIST, SLUG );

/** Files and folders that never ship. */
const EXCLUDE = new Set( [
	'tests',
	'node_modules',
	'.cache',
	'package-lock.json',
	'phpunit.xml.dist',
	// Written by tools/scripts/phpunit-config.mjs for the test runner; it
	// was shipping in the release zip.
	'phpunit.files.xml',
	'.phpunit.result.cache',
	'composer.lock',
	'README.md',
	'dist',
	'.cache',
	'.wp-env.json',
	// The bundles' source and build setup: published in the repository.
	'app',
	'tools',
	'package.json',
	'webpack.config.js',
	'postcss.config.js',
] );

/**
 * What a library ships for its own development and a site never runs.
 * One of these was a web-reachable PHP file with no ABSPATH guard.
 */
const VENDOR_JUNK = new Set( [
	'_test',
	'test',
	'tests',
	'examples',
	'example',
	'docs',
	'doc',
	'.github',
	'phpunit.xml',
	'phpunit.xml.dist',
	'Makefile',
	// Notes for AI coding assistants that Action Scheduler ships.
	'AGENTS.md',
	'CLAUDE.md',
] );

/** Vendor packages the plugin needs at runtime. */
const VENDOR = [ 'autoload.php', 'composer', 'woocommerce' ];

if ( ! existsSync( SOURCE ) ) {
	process.stdout.write( `Nothing to package: ${ PLUGIN } does not exist.\n` );
	process.exit( 1 );
}
if ( ! existsSync( join( SOURCE, 'build' ) ) ) {
	process.stdout.write(
		'Run `npm run build` first: the bundles are missing.\n'
	);
	process.exit( 1 );
}

rmSync( STAGE, { recursive: true, force: true } );
mkdirSync( STAGE, { recursive: true } );

/**
 * Dot-files are development leftovers: none of them belong in a release.
 *
 * @param {string} name File or directory name.
 * @return {boolean} Whether it ships.
 */
const shipped = ( name ) => ! name.startsWith( '.' );

for ( const entry of readdirSync( SOURCE ) ) {
	// Both vendor folders are copied below, each with its own filter.
	if (
		EXCLUDE.has( entry ) ||
		entry.startsWith( 'vendor' ) ||
		! shipped( entry )
	) {
		continue;
	}
	cpSync( join( SOURCE, entry ), join( STAGE, entry ), {
		recursive: true,
		// A bundled library's own tests and tools (lib/pdf/tests) never ship.
		filter: ( from ) =>
			shipped( basename( from ) ) &&
			! (
				'tests' === basename( from ) &&
				from.includes( `${ sep }lib${ sep }` )
			),
	} );
}

// Libraries renamed into the plugin's own namespace (Strauss). They
// ship whole: their autoloader is separate from the one in vendor/, and
// the plugin requires it beside it.
const prefixed = join( SOURCE, 'vendor-prefixed' );
if ( existsSync( prefixed ) ) {
	cpSync( prefixed, join( STAGE, 'vendor-prefixed' ), {
		recursive: true,
		filter: ( path ) =>
			shipped( basename( path ) ) &&
			! VENDOR_JUNK.has( basename( path ) ),
	} );
} else if ( 'pro' !== WHICH ) {
	// Pro has no libraries of its own to rename: it uses Cliezoo's.
	process.stdout.write(
		'vendor-prefixed/ is missing: run "composer prefix-vendor" in the plugin before packaging.\n'
	);
	process.exit( 1 );
}

const vendor = join( SOURCE, 'vendor' );
if ( existsSync( vendor ) ) {
	for ( const entry of VENDOR ) {
		const from = join( vendor, entry );
		if ( existsSync( from ) ) {
			cpSync( from, join( STAGE, 'vendor', entry ), {
				recursive: true,
				filter: ( path ) =>
					shipped( basename( path ) ) &&
					! VENDOR_JUNK.has( basename( path ) ) &&
					// vendor/composer is the autoloader — the files at its
					// top. The folders under it are Composer's own packages
					// (composer/composer, pcre, semver…), there because a
					// development tool needs them, and a site never runs
					// them: 361 files of them were shipping.
					! (
						'composer' === entry &&
						dirname( path ) === from &&
						statSync( path ).isDirectory()
					),
			} );
		}
	}
}

// The classmap in the working tree maps PHPUnit's classes as well, and
// the zip carries none of those files: a class_exists() on any of them
// would fatal. The staged copy gets an autoloader dumped without them —
// in the stage, so the working tree keeps the one its own tests need.
if ( existsSync( join( STAGE, 'vendor', 'composer' ) ) ) {
	try {
		execFileSync(
			'docker',
			[
				'run',
				'--rm',
				'-v',
				`${ STAGE }:/app`,
				'-w',
				'/app',
				'composer:2',
				'composer',
				'dump-autoload',
				'--no-dev',
				'--optimize',
			],
			{ stdio: 'inherit', env: { ...process.env, MSYS_NO_PATHCONV: '1' } }
		);
	} catch {
		process.stdout.write(
			'Could not dump a production autoloader: the release would carry the development one.\n'
		);
		process.exit( 1 );
	}
}

// A plugin's own version is the one in its header: the zip is named for it.
const main = join( STAGE, 'pro' === WHICH ? 'cliezoo-pro.php' : 'cliezoo.php' );
const header = readFileSync( main, 'utf8' );
const version =
	( header.match( /^\s*\*\s*Version:\s*(.+)$/m ) || [] )[ 1 ]?.trim() ??
	'0.0.0';

// A release never carries a development flag.
writeFileSync( join( STAGE, 'index.php' ), '<?php\n// Silence is golden.\n' );

const zip = join( DIST, `${ SLUG }-${ version }.zip` );
rmSync( zip, { force: true } );
/**
 * Zips the staged folder with whatever this machine has: `zip`, or on
 * Windows the bsdtar that ships with it. Not PowerShell's Compress-Archive:
 * on Windows PowerShell 5.1 it writes backslashes into the paths inside the
 * zip, which WordPress then unpacks as file names with backslashes in them.
 *
 * @return {boolean} Whether a zip was made.
 */
function makeZip() {
	try {
		execFileSync( 'zip', [ '-rq', zip, SLUG ], {
			cwd: DIST,
			stdio: 'inherit',
		} );
		return true;
	} catch {
		// Carried on to the next way below.
	}
	if ( 'win32' === process.platform ) {
		const bsdtar = join(
			process.env.SystemRoot ?? 'C:\\Windows',
			'System32',
			'tar.exe'
		);
		try {
			execFileSync( bsdtar, [ '-a', '-c', '-f', zip, SLUG ], {
				cwd: DIST,
				stdio: 'inherit',
			} );
			return true;
		} catch {
			// Nothing else to try.
		}
	}
	return false;
}

if ( ! makeZip() ) {
	process.stdout.write(
		`Staged ${ relative( ROOT, STAGE ).split( sep ).join( '/' ) } (no zip command available).\n`
	);
	process.exit( 0 );
}

const size = statSync( zip ).size;
process.stdout.write(
	`${ relative( ROOT, zip ).split( sep ).join( '/' ) } — ${ ( size / 1024 / 1024 ).toFixed( 2 ) } MB\n`
);
