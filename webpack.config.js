/**
 * Cliezoo build: one webpack config per bundle.
 *
 * - data   → window.cliezoo.data   (API client, query cache, stores)   handle cliezoo-data
 * - ui     → window.cliezoo.ui     (design system + app CSS)           handle cliezoo-ui
 * - app    → window.cliezoo.app    (shell, router, extension registry) handle cliezoo-app
 * - public → standalone small bundle for /cz token pages               handle cliezoo-public
 *
 * `@cliezoo/*` imports become externals (`window.cliezoo.*`, handle
 * `cliezoo-*`), so singletons such as the query cache exist once and Cliezoo
 * Pro can use the same packages without shipping Free's code (Blueprint §23).
 * WordPress packages and React come from core script handles.
 */
const path = require( 'path' );
const webpack = require( 'webpack' );
const defaults = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const base = Array.isArray( defaults ) ? defaults[ 0 ] : defaults;

/**
 * Says at the top of every compiled file where its source is. Added after
 * minification (the REPORT stage), so the minifier cannot strip it.
 */
const sourceBanner = new webpack.BannerPlugin( {
	banner: '/*! Cliezoo, compiled with webpack. Source: https://github.com/degird/cliezoo, in app/ (npm ci && npm run build). */',
	raw: true,
	test: /\.(js|css)$/,
	stage: webpack.Compilation.PROCESS_ASSETS_STAGE_REPORT,
} );
const PACKAGES = [ 'data', 'ui', 'app' ];

/**
 * Dependency extraction with the @cliezoo/* mapping.
 *
 * @param {boolean} standalone Bundle everything Cliezoo (public pages).
 */
const extraction = ( standalone ) =>
	new DependencyExtractionWebpackPlugin( {
		requestToExternal( request ) {
			const match = /^@cliezoo\/(data|ui|app)$/.exec( request );
			if ( match && ! standalone ) {
				return [ 'cliezoo', match[ 1 ] ];
			}
			return undefined;
		},
		requestToHandle( request ) {
			const match = /^@cliezoo\/(data|ui|app)$/.exec( request );
			if ( match && ! standalone ) {
				return 'cliezoo-' + match[ 1 ];
			}
			return undefined;
		},
	} );

const keepPlugin = ( plugin ) => {
	const name = plugin?.constructor?.name;
	return ! [
		'DependencyExtractionWebpackPlugin',
		'CopyPlugin',
		'PhpFilePathsPlugin',
	].includes( name );
};

/**
 * One bundle config.
 *
 * @param {string}  name       Entry name.
 * @param {string}  file       Entry file (relative to app/).
 * @param {boolean} library    Expose as window.cliezoo.{name}.
 * @param {boolean} standalone Resolve @cliezoo/* from source instead of externals.
 */
const bundle = ( name, file, library, standalone = false ) => ( {
	...base,
	name,
	entry: {
		[ name ]: {
			import: path.resolve( __dirname, 'app', file ),
			...( library && {
				library: { name: [ 'cliezoo', name ], type: 'window' },
			} ),
		},
	},
	output: {
		...base.output,
		path: path.resolve( __dirname, 'build' ),
		filename: '[name].js',
		chunkFilename: 'chunks/' + name + '.[name].[contenthash:8].js',
		uniqueName: 'cliezoo_' + name,
		clean: false,
	},
	resolve: {
		...base.resolve,
		extensions: [ '.tsx', '.ts', '.js', '.jsx', '.json' ],
		alias: {
			...( base.resolve?.alias || {} ),
			...( standalone &&
				Object.fromEntries(
					PACKAGES.map( ( pkg ) => [
						'@cliezoo/' + pkg + '$',
						path.resolve( __dirname, 'app/packages', pkg ),
					] )
				) ),
		},
	},
	module: {
		...base.module,
		rules: [
			...base.module.rules,
			{
				// English only in the bundle; other React Aria locales load per site language.
				test: /react-aria[\\/]dist[\\/]private[\\/][^\\/]+[\\/]intlStrings\.mjs$/,
				use: [
					path.resolve(
						__dirname,
						'tools/webpack/aria-english-only.js'
					),
				],
			},
		],
	},
	optimization: {
		...base.optimization,
		// Only across lazy chunks, never into the entry: the forms and the
		// overlays both use React Aria, and without this each would carry
		// its own copy. The entry is measured against the start-up budget
		// and must stay exactly what it is.
		splitChunks: {
			chunks: 'async',
			minSize: 20000,
			cacheGroups: {
				default: false,
				defaultVendors: false,
				// The doc editor's libraries (Tiptap, ProseMirror, highlight.js)
				// apart from its own code: the string extractor reads the
				// editor's words from a chunk it can parse, and the libraries
				// stay cached across releases that only change the editor.
				editorLibraries: {
					name: 'docs-editor-libs',
					// …and a document text block's editor, which uses some of them.
					chunks: ( chunk ) =>
						[ 'docs-editor', 'text-editor' ].includes( chunk.name ),
					test: /[\\/]node_modules[\\/]/,
					priority: 10,
					enforce: true,
				},
				// The sanitiser is a third of everything the screens
				// share, and every screen that shows written text wants
				// it. On its own it is fetched once, in parallel with
				// whatever asked for it, and stays cached across releases
				// that leave it alone.
				sanitiser: {
					name: 'sanitiser',
					chunks: 'async',
					test: /[\\/]node_modules[\\/]dompurify[\\/]/,
					priority: 30,
					enforce: true,
					reuseExistingChunk: true,
				},
				// A QR code is arithmetic on a string, drawn on exactly two
				// screens — two-step verification and a form's address — and
				// as soon as the second one arrived it was shared by enough
				// chunks to be pulled into the one every screen fetches. On
				// its own it is fetched beside whichever of them asked.
				qr: {
					name: 'qr',
					chunks: 'async',
					test: /[\\/]node_modules[\\/]qrcode-generator[\\/]/,
					priority: 30,
					enforce: true,
					reuseExistingChunk: true,
				},
				// The list engine itself. Every list screen wants it and
				// no other screen does, so in the chunk every screen
				// fetches it was fifty kilobytes of table shipped to the
				// settings pages and the doc editor. On its own it is
				// fetched beside whichever list asked for it and stays
				// cached across releases that leave it alone.
				entityList: {
					name: 'entity-list',
					chunks: 'async',
					test: /[\\/]app[\\/]packages[\\/]app[\\/]list[\\/](EntityList|cells|ListLayouts)\.tsx$/,
					priority: 20,
					enforce: true,
					reuseExistingChunk: true,
				},
				// The record form and the inputs it draws. Every screen
				// that lists something can also make one, so as soon as a
				// third list arrived this was shared by enough chunks to
				// be pulled into the one every screen fetches — eighteen
				// kilobytes of form on screens where nobody opens one.
				// A form is opened on purpose, so it is fetched then.
				recordForm: {
					name: 'record-form',
					chunks: 'async',
					test: /[\\/]app[\\/]packages[\\/]app[\\/]form[\\/](RecordForm|FieldInput|CustomInputs|MarkdownField)\.tsx$/,
					priority: 20,
					enforce: true,
					reuseExistingChunk: true,
				},
				// Parts opened rarely — the saved-views menu, the filter builder, the column
				// picker, bulk edit, the sample-data offer, the phone
				// control with its dialling codes — kept out of the chunk
				// every screen shares and fetched when they are opened.
				// Enforced, because each is smaller on its own than the
				// minimum a split otherwise needs.
				listTools: {
					name: 'list-parts',
					chunks: 'async',
					test: /[\\/]app[\\/]packages[\\/]app[\\/](list[\\/](FilterBuilder|ColumnPicker|BulkEdit|SampleDataOffer|ViewsMenu)|form[\\/]PhoneInput|import[\\/]ImportDialog)\.tsx$/,
					priority: 20,
					enforce: true,
					reuseExistingChunk: true,
				},
				// Starting a conversation: offered on five screens, so it was
				// pulled into the chunk every screen fetches, and opened on
				// none of them until somebody asks. Fetched beside whichever
				// screen offers it.
				newConversation: {
					name: 'new-conversation',
					chunks: 'async',
					test: /[\\/]app[\\/]modules[\\/]conversations[\\/]NewConversation\.tsx$/,
					priority: 20,
					enforce: true,
					reuseExistingChunk: true,
				},
				// The question editor: forms and request types both write
				// questions with it, which put it in the chunk every screen
				// fetches. It is only ever drawn where questions are written.
				questionEditor: {
					name: 'question-editor',
					chunks: 'async',
					test: /[\\/]app[\\/]modules[\\/]forms[\\/]QuestionEditor\.tsx$/,
					priority: 20,
					enforce: true,
					reuseExistingChunk: true,
				},
				// Two more drawn on a few screens each and opened on purpose there:
				// the catalogue to pick a line from, and the form a payment
				// processor is connected with. Each fetched when its screen is.
				cataloguePicker: {
					name: 'catalogue-picker',
					chunks: 'async',
					test: /[\\/]app[\\/]modules[\\/]catalogue[\\/]CataloguePicker\.tsx$/,
					priority: 20,
					enforce: true,
					reuseExistingChunk: true,
				},
				connectProcessor: {
					name: 'connect-processor',
					chunks: 'async',
					test: /[\\/]app[\\/]shell[\\/]settings[\\/]sections[\\/]ConnectProcessor\.tsx$/,
					priority: 20,
					enforce: true,
					reuseExistingChunk: true,
				},
				shared: {
					name: 'shared',
					chunks: 'async',
					minChunks: 2,
					reuseExistingChunk: true,
				},
			},
		},
	},
	plugins: [
		...base.plugins.filter( keepPlugin ),
		extraction( standalone ),
		sourceBanner,
	],
} );

module.exports = [
	bundle( 'data', 'packages/data/index.ts', true ),
	bundle( 'ui', 'packages/ui/index.ts', true ),
	bundle( 'app', 'packages/app/index.tsx', true ),
	bundle( 'public', 'public/index.tsx', false, true ),
];
