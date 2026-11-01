/**
 * Public token pages (/cz/…): a small standalone bundle with no app code.
 * Page types (share, unsubscribe) register renderers below; unknown paths
 * show a branded "not found".
 */
import '../styles/public.css';
import { createRoot } from 'react-dom/client';
import { __ } from '@wordpress/i18n';
import { applyTheme, getBoot } from '@cliezoo/data';
import SharePage from './share';
import UnsubscribePage from './unsubscribe';
import { PoweredBy } from '../shell/PoweredBy';
import {
	accentFor,
	applyBranding,
	setAccent,
} from '../packages/app/appearance';

function NotAvailable() {
	return (
		<div className="max-w-sm rounded-xl border border-line bg-raised p-8 text-center shadow-md">
			<h1 className="text-lg font-semibold text-fg">
				{ __( 'This link is not available', 'cliezoo' ) }
			</h1>
			<p className="mt-2 text-sm text-muted">
				{ __(
					'It may have expired or been turned off. Ask the sender for a new link.',
					'cliezoo'
				) }
			</p>
		</div>
	);
}

/**
 * The token in this page's address.
 *
 * Read from the address bar and nowhere else. On a token page the address
 * *is* the credential, so the server sends down which kind of page this is
 * and not the token itself — a second copy of it in the markup is a second
 * place it can leak from.
 *
 * @param kind The page type, e.g. "s".
 */
function tokenFor( kind: string ): string {
	const here =
		window.location.pathname + decodeURIComponent( window.location.search );
	const found = new RegExp( `/${ kind }/([A-Za-z0-9_-]{20,100})` ).exec(
		here
	);
	return found ? found[ 1 ] : '';
}

/**
 * Which page this address is. The shell says the kind; the address says
 * which one.
 */
function Page() {
	const kind = getBoot().routes.path;
	const token = tokenFor( kind );

	// The way out of marketing email: signed by address, not a token.
	if ( 'unsubscribe' === kind ) {
		return <UnsubscribePage />;
	}
	if ( 's' === kind && token ) {
		return <SharePage token={ token } />;
	}
	return <NotAvailable />;
}

function start() {
	const element = document.getElementById( 'cliezoo-root' );
	if ( ! element ) {
		return;
	}
	applyTheme( getBoot().theme );
	// The workspace's own look, as its app wears it; a page with a look of
	// its own (a form) puts its accent over this once it has loaded.
	applyBranding( getBoot().branding );
	setAccent( accentFor( getBoot().branding ) );
	createRoot( element ).render(
		<main
			className="flex min-h-dvh flex-col items-center justify-center bg-surface px-4 py-10"
		>
			<Page />
			{ /* Only when the workspace has turned the credit on. */ }
			<PoweredBy className="mt-6" />
		</main>
	);
	element.removeAttribute( 'aria-busy' );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', start, { once: true } );
} else {
	start();
}
