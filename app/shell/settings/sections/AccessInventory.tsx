/**
 * Settings → Access: everything one person or client can open, and a way
 * to take it all back at once.
 *
 * This is the screen you want on the day somebody leaves, so it is built
 * around that: pick a person or a client, see the list, select, revoke.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { api, useQuery } from '@cliezoo/data';
import { Select } from '@cliezoo/ui';
import { AccessList } from '../../../packages/app/access/AccessList';

export default function AccessInventory() {
	const [ kind, setKind ] = useState< 'user' | 'client' >( 'user' );
	const [ principal, setPrincipal ] = useState( '' );

	const people = useQuery( {
		queryKey: [ 'members', 'list', { picker: true } ],
		staleTime: 60_000,
		queryFn: async () =>
			(
				await api.get< Array< { uid: string; name: string } > >(
					'/members',
					{ limit: 200, fields: 'uid,name' }
				)
			).data,
	} );
	const clients = useQuery( {
		queryKey: [ 'clients', 'list', { picker: true } ],
		enabled: 'client' === kind,
		staleTime: 60_000,
		queryFn: async () =>
			(
				await api.get< Array< { uid: string; name: string } > >(
					'/clients',
					{ limit: 200, fields: 'uid,name', sort: 'name' }
				)
			).data,
	} );
	const choices = 'user' === kind ? people.data : clients.data;

	return (
		<div className="space-y-4">
			<div className="flex flex-wrap items-end gap-3">
				<Select
					label={ __( 'Who', 'cliezoo' ) }
					className="w-40"
					items={ [
						{ id: 'user', label: __( 'A person', 'cliezoo' ) },
						{ id: 'client', label: __( 'A client', 'cliezoo' ) },
					] }
					selectedKey={ kind }
					onSelectionChange={ ( k ) => {
						setKind( String( k ) as 'user' | 'client' );
						setPrincipal( '' );
					} }
				/>
				<Select
					label={
						'user' === kind
							? __( 'Person', 'cliezoo' )
							: __( 'Client', 'cliezoo' )
					}
					className="min-w-56 flex-1"
					items={ ( choices ?? [] ).map( ( one ) => ( {
						id: one.uid,
						label: one.name,
					} ) ) }
					selectedKey={ principal || null }
					onSelectionChange={ ( k ) => setPrincipal( String( k ) ) }
				/>
			</div>

			{ !! principal && (
				<AccessList
					key={ `${ kind }:${ principal }` }
					kind={ kind }
					principal={ principal }
				/>
			) }
		</div>
	);
}
