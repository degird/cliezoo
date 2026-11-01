/**
 * Settings → Modules: turn optional modules on or off (hidden modules
 * disappear from the sidebar; their data is kept).
 */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { api, useQuery } from '@cliezoo/data';
import {
	Alert,
	Button,
	EmptyState,
	ErrorState,
	Skeleton,
	Switch,
	toast,
} from '@cliezoo/ui';
import { humanise } from '../../../packages/app/list/cells';

interface ModuleInfo {
	id: string;
	switchable: boolean;
	active: boolean;
	depends_on: string[];
}

export default function Modules() {
	const modules = useQuery( {
		queryKey: [ 'modules' ],
		queryFn: async () =>
			( await api.get< ModuleInfo[] >( '/modules' ) ).data,
	} );
	const [ off, setOff ] = useState< string[] >( [] );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		if ( modules.data ) {
			setOff(
				modules.data
					.filter( ( m ) => m.switchable && ! m.active )
					.map( ( m ) => m.id )
			);
		}
	}, [ modules.data ] );
	if ( modules.isPending ) {
		return <Skeleton className="h-40" />;
	}
	if ( modules.isError ) {
		return (
			<ErrorState
				error={ modules.error }
				onRetry={ () => modules.refetch() }
			/>
		);
	}
	const optional = ( modules.data ?? [] ).filter( ( m ) => m.switchable );
	if ( ! optional.length ) {
		return (
			<EmptyState title={ __( 'No optional modules yet', 'cliezoo' ) }>
				{ __(
					'Modules such as Leads, Projects or Invoices appear here so you can hide the ones you do not use.',
					'cliezoo'
				) }
			</EmptyState>
		);
	}
	const save = async () => {
		setBusy( true );
		try {
			await api.post( '/modules', { off } );
			toast.success( __( 'Modules updated. Reloading…', 'cliezoo' ) );
			window.setTimeout( () => window.location.reload(), 600 );
		} catch ( error ) {
			toast.error( ( error as Error ).message );
			setBusy( false );
		}
	};
	return (
		<div className="space-y-4">
			<Alert tone="info">
				{ __(
					'Turning a module off hides it for everyone. Its data is kept and comes back when you turn it on again.',
					'cliezoo'
				) }
			</Alert>
			<ul className="divide-y divide-line rounded-lg border border-line bg-raised">
				{ optional.map( ( module ) => (
					<li
						key={ module.id }
						className="flex items-center justify-between gap-4 px-4 py-3"
					>
						<div>
							<p className="text-sm font-medium">
								{ humanise( module.id ) }
							</p>
							{ !! module.depends_on.length && (
								<p className="text-xs text-muted">
									{ __( 'Needs:', 'cliezoo' ) }{ ' ' }
									{ module.depends_on
										.map( humanise )
										.join( ', ' ) }
								</p>
							) }
						</div>
						<Switch
							isSelected={ ! off.includes( module.id ) }
							onChange={ ( on ) =>
								setOff(
									on
										? off.filter(
												( id ) => id !== module.id
											)
										: [ ...off, module.id ]
								)
							}
						>
							<span className="sr-only">
								{ humanise( module.id ) }
							</span>
						</Switch>
					</li>
				) ) }
			</ul>
			<div className="flex justify-end">
				<Button variant="primary" isPending={ busy } onPress={ save }>
					{ __( 'Save changes', 'cliezoo' ) }
				</Button>
			</div>
		</div>
	);
}
