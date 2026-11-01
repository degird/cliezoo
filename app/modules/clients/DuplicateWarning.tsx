/**
 * "You may already have this client."
 *
 * A warning, never a refusal: two companies really can share a name, and
 * the person adding the record knows which case this is. It waits for the
 * typing to stop before asking.
 */
import { useEffect, useState } from 'react';
import { __, _n } from '@wordpress/i18n';
import { Link } from '@tanstack/react-router';
import { api, useQuery } from '@cliezoo/data';
import { Alert } from '@cliezoo/ui';

interface Match {
	uid: string;
	name: string;
	email: string;
	number: string;
}

/**
 * Waits for a value to stop changing.
 * @param value
 * @param delay
 */
function useSettled< T >( value: T, delay = 400 ): T {
	const [ settled, setSettled ] = useState( value );
	useEffect( () => {
		const timer = window.setTimeout( () => setSettled( value ), delay );
		return () => window.clearTimeout( timer );
	}, [ value, delay ] );
	return settled;
}

export function DuplicateWarning( {
	draft,
	exclude,
}: {
	draft: Record< string, unknown >;
	/** The client being edited, which is never its own duplicate. */
	exclude?: string;
} ) {
	const name = String( draft.name ?? '' ).trim();
	const email = String( draft.email ?? '' ).trim();
	const query = useSettled( `${ name }|${ email }` );
	const [ typedName, typedEmail ] = query.split( '|' );

	const matches = useQuery( {
		queryKey: [ 'clients', 'duplicates', query, exclude ?? '' ],
		enabled: typedName.length > 2 || typedEmail.length > 4,
		staleTime: 30_000,
		queryFn: async () =>
			(
				await api.get< { matches: Match[] } >( '/clients/duplicates', {
					name: typedName,
					email: typedEmail,
					...( exclude ? { exclude } : {} ),
				} )
			).data.matches,
	} );

	const rows = matches.data ?? [];
	if ( ! rows.length ) {
		return null;
	}

	return (
		<Alert
			tone="warning"
			title={ _n(
				'You may already have this client',
				'You may already have one of these clients',
				rows.length,
				'cliezoo'
			) }
		>
			<ul className="space-y-1">
				{ rows.slice( 0, 5 ).map( ( match ) => (
					<li key={ match.uid }>
						<Link
							to={ `/clients/${ match.uid }` }
							className="text-accent-fg hover:underline"
						>
							{ match.name }
						</Link>
						{ '' !== match.email && (
							<span className="text-muted">
								{ ' ' }
								· { match.email }
							</span>
						) }
					</li>
				) ) }
			</ul>
			<p className="mt-1">
				{ __( 'Carry on if this is a different company.', 'cliezoo' ) }
			</p>
		</Alert>
	);
}
