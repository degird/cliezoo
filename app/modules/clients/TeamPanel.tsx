/**
 * The people on your team who look after a client, on its page.
 */
import { __ } from '@wordpress/i18n';
import { X } from 'lucide-react';
import { api, useQueryClient, type ApiError } from '@cliezoo/data';
import { Avatar, IconButton, toast } from '@cliezoo/ui';
import { FieldInput } from '../../packages/app/form/FieldInput';

interface Person {
	uid: string;
	label: string;
	title?: string;
}

export function TeamPanel( {
	uid,
	team,
	canEdit,
}: {
	uid: string;
	team: Person[];
	canEdit: boolean;
} ) {
	const queries = useQueryClient();
	const save = async ( next: string[] ) => {
		try {
			await api.patch( `/clients/${ uid }`, { team: next } );
			await queries.invalidateQueries( { queryKey: [ 'clients', uid ] } );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};
	const uids = team.map( ( one ) => one.uid );

	return (
		<section
			aria-labelledby="client-team"
			className="rounded-lg border border-line bg-raised p-4"
		>
			<h2 id="client-team" className="mb-3 text-sm font-semibold text-fg">
				{ __( 'Assigned team', 'cliezoo' ) }
			</h2>
			{ ! team.length && (
				<p className="text-sm text-muted">
					{ __( 'Nobody is assigned yet.', 'cliezoo' ) }
				</p>
			) }
			<ul className="space-y-2">
				{ team.map( ( person ) => (
					<li key={ person.uid } className="flex items-center gap-2">
						<Avatar name={ person.label } size="sm" />
						<span className="min-w-0 flex-1">
							<span className="block truncate text-sm text-fg">
								{ person.label }
							</span>
							{ !! person.title && (
								<span className="block truncate text-xs text-muted">
									{ person.title }
								</span>
							) }
						</span>
						{ canEdit && (
							<IconButton
								size="sm"
								variant="ghost"
								label={ __( 'Take off the team', 'cliezoo' ) }
								icon={ <X size={ 14 } aria-hidden="true" /> }
								onPress={ () =>
									save(
										uids.filter(
											( one ) => one !== person.uid
										)
									)
								}
							/>
						) }
					</li>
				) ) }
			</ul>
			{ canEdit && (
				<div className="mt-3">
					<FieldInput
						key={ uids.join( ',' ) }
						field={ {
							key: 'team_add',
							label: __( 'Add somebody', 'cliezoo' ),
							type: 'ref',
							ref: 'members',
						} }
						value={ null }
						refQuery={ {
							filter: JSON.stringify( {
								and: [
									{ field: 'kind', op: 'eq', value: 'staff' },
								],
							} ),
						} }
						onChange={ ( picked: unknown ) => {
							const one =
								picked && 'object' === typeof picked
									? String(
											( picked as { uid?: string } )
												.uid ?? ''
										)
									: String( picked ?? '' );
							if ( one && ! uids.includes( one ) ) {
								save( [ ...uids, one ] );
							}
						} }
					/>
				</div>
			) }
		</section>
	);
}
