/**
 * The Files page: everything, wherever it lives, and how much room is left.
 */
import { Suspense, lazy, useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useNavigate, useSearch } from '@tanstack/react-router';
import {
	api,
	formatBytes,
	getBoot,
	useQuery,
	useQueryClient,
} from '@cliezoo/data';
import { Button, ErrorState, Skeleton, cx } from '@cliezoo/ui';
import { PageHeader } from '../../shell/Page';
import { FileBrowser } from './FileBrowser';
import { RecentStrip } from '../dashboard/widgets/Recent';

const Library = lazy(
	() => import( /* webpackChunkName: "files" */ './Library' )
);

interface Usage {
	total: number;
	quota: number;
	files: number;
}

export default function FilesScreen() {
	// A folder and a file can both be linked to — from search, from a
	// notification, from an activity line — so the URL is where "where am
	// I" lives, and walking into a folder writes it back.
	const navigate = useNavigate();
	const search = useSearch( { strict: false } ) as {
		folder?: string;
		file?: string;
		new?: string;
	};
	const folder = search.folder ?? '';

	// Walking into a folder is opening it, and the strip of what was
	// opened lately is asked again so it says so.
	const queries = useQueryClient();
	useEffect( () => {
		queries.invalidateQueries( { queryKey: [ 'dashboard', 'recents' ] } );
	}, [ folder, queries ] );
	const [ library, setLibrary ] = useState( false );

	const openFolder = ( uid: string ) =>
		navigate( {
			to: '/files',
			search: uid ? { folder: uid } : {},
			replace: false,
		} );

	return (
		<>
			<PageHeader
				title={ __( 'Files', 'cliezoo' ) }
				actions={
					! getBoot().user?.client && (
						<Button onPress={ () => setLibrary( ! library ) }>
							{ library
								? __( 'Back to folders', 'cliezoo' )
								: __( 'Library', 'cliezoo' ) }
						</Button>
					)
				}
			/>
			<div className="space-y-4 p-4">
				{ /* The folders you were last in, which is how somebody
				     who lives in Files gets back to work. */ }
				<RecentStrip
					types={ [ 'folders' ] }
					label={ __( 'Folders you opened lately', 'cliezoo' ) }
				/>
				<UsageMeter />
				<div className="flex flex-wrap gap-2">
				</div>
				{ library ? (
					<Suspense fallback={ <Skeleton className="h-64" /> }>
						<Library />
					</Suspense>
				) : (
					<FileBrowser
						folder={ folder }
						onOpenFolder={ openFolder }
						openFile={ search.file }
						ask={ search.new }
					/>
				) }
			</div>
		</>
	);
}

/**
 * The bar's colour: amber once it is worth noticing, red once uploads are
 * about to start failing.
 *
 * @param used Percentage used.
 */
function band( used: number ): string {
	if ( used >= 95 ) {
		return 'bg-danger';
	}
	return used >= 80 ? 'bg-warning' : 'bg-accent';
}

/**
 * How much room is used, and how much there is — when the site has been
 * given a limit at all. A meter with no limit behind it is decoration.
 */
function UsageMeter() {
	const staff = ! getBoot().user?.client;
	const usage = useQuery( {
		queryKey: [ 'storage', 'usage' ],
		enabled: staff,
		staleTime: 60_000,
		queryFn: async () =>
			( await api.get< Usage >( '/storage/usage' ) ).data,
	} );

	if ( ! staff ) {
		return null;
	}
	// A meter with no limit behind it is decoration; a meter whose numbers
	// were refused is a failure, and drawing nothing hides it.
	if ( usage.isError ) {
		return (
			<ErrorState
				error={ usage.error }
				title={ __( 'How much room is used is unknown.', 'cliezoo' ) }
				onRetry={ () => usage.refetch() }
			/>
		);
	}
	if ( ! usage.data ) {
		return null;
	}
	const { total, quota } = usage.data;
	if ( ! quota ) {
		return (
			<p className="text-xs text-subtle">
				{ sprintf(
					/* translators: %s: a size, e.g. "1.2 GB". */
					__(
						'%s stored. No limit is set for this site.',
						'cliezoo'
					),
					formatBytes( total )
				) }
			</p>
		);
	}

	const used = Math.min( 100, Math.round( ( total / quota ) * 100 ) );
	return (
		<section
			className="rounded-lg border border-line bg-raised p-3"
			aria-label={ __( 'Storage', 'cliezoo' ) }
		>
			<p className="flex items-baseline justify-between gap-3 text-sm">
				<span className="text-fg">
					{ sprintf(
						/* translators: 1: used size, 2: total size. */
						__( '%1$s of %2$s used', 'cliezoo' ),
						formatBytes( total ),
						formatBytes( quota )
					) }
				</span>
				<span className="text-xs tabular-nums text-muted">
					{ sprintf(
						/* translators: %d: percentage of storage used. */
						__( '%d%%', 'cliezoo' ),
						used
					) }
				</span>
			</p>
			<div
				className="mt-2 h-1.5 overflow-hidden rounded-full bg-sunken"
				role="progressbar"
				aria-label={ __( 'Storage used', 'cliezoo' ) }
				aria-valuenow={ used }
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
			>
				<div
					className={ cx( 'h-full rounded-full', band( used ) ) }
					style={ { width: `${ used }%` } }
				/>
			</div>
			{ used >= 80 && (
				<p className="mt-1 text-xs text-warning">
					{ __(
						'Uploads stop when this is full, so it is worth clearing the trash or asking your host for more.',
						'cliezoo'
					) }
				</p>
			) }
		</section>
	);
}
