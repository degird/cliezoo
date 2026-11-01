/**
 * Settings → Templates: every template in the workspace, whatever it makes.
 *
 * Templates are kept beside the things they make — a doc template with
 * docs, a board's list template with boards — which is right for the
 * person making one and no use at all to the person who has to look after
 * them. This is that person's screen: one list, narrowed by kind or by a
 * word, each row saying where it lives so it can be opened and changed
 * where it was made. Nothing is created or deleted here, because the
 * modules own that, along with the rules about who may.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link } from '@tanstack/react-router';
import { api, formatDate, useQuery } from '@cliezoo/data';
import {
	Alert,
	Badge,
	EmptyState,
	ErrorState,
	SearchField,
	Select,
	Skeleton,
} from '@cliezoo/ui';
import { Slot } from '../../../packages/app/slot';
import { useDebounced } from '../../../packages/app/useDebounced';

interface Template {
	kind: string;
	kind_label: string;
	uid: string;
	name: string;
	description: string;
	uses: number;
	updated_at: string | null;
	/** The screen that looks after this kind, inside the app. */
	where: string;
}

interface Answer {
	kinds: Array< { id: string; label: string } >;
	templates: Template[];
	more: boolean;
}

export default function Templates() {
	const [ kind, setKind ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const asked = useDebounced( query, 300 );
	const all = useQuery( {
		queryKey: [ 'templates', 'all', kind, asked ],
		staleTime: 15_000,
		queryFn: async () =>
			(
				await api.get< Answer >( '/templates/all', {
					kind: kind || undefined,
					q: asked || undefined,
				} )
			).data,
	} );

	if ( all.isError ) {
		return (
			<ErrorState error={ all.error } onRetry={ () => all.refetch() } />
		);
	}
	const rows = all.data?.templates ?? [];

	return (
		<div className="space-y-4">
			<div className="flex flex-wrap items-end gap-3">
				<Select
					label={ __( 'Kind', 'cliezoo' ) }
					className="w-52"
					items={ [
						{ id: 'all', label: __( 'Every kind', 'cliezoo' ) },
						...( all.data?.kinds ?? [] ),
					] }
					selectedKey={ kind || 'all' }
					onSelectionChange={ ( key ) =>
						setKind( 'all' === key ? '' : String( key ) )
					}
				/>
				<div className="min-w-0 flex-1 sm:max-w-xs">
					<SearchField
						label={ __( 'Find a template', 'cliezoo' ) }
						value={ query }
						onChange={ setQuery }
					/>
				</div>
				{ /* Where an add-on's template tools go. */ }
				<Slot name="templates.toolbar" variant="chip" />
			</div>

			{ all.isPending && <Skeleton className="h-64" /> }

			{ all.isSuccess && ! rows.length && (
				<EmptyState title={ __( 'No templates yet', 'cliezoo' ) }>
					{ __(
						'A template is made where it is used — save a project, a task, a board’s lists, a doc, a folder or a reply as one, and it shows up here.',
						'cliezoo'
					) }
				</EmptyState>
			) }

			{ !! rows.length && (
				<>
					<p className="text-sm text-muted">
						{ sprintf(
							/* translators: %d: how many templates there are. */
							_n(
								'%d template.',
								'%d templates.',
								rows.length,
								'cliezoo'
							),
							rows.length
						) }
					</p>
					<ul className="divide-y divide-line rounded-lg border border-line bg-bg">
						{ rows.map( ( row ) => (
							<li
								key={ `${ row.kind }:${ row.uid }` }
								className="flex flex-wrap items-center gap-2 px-3 py-2.5"
							>
								<span className="min-w-0 flex-1">
									<span className="block truncate text-sm font-medium text-fg">
										{ row.name }
									</span>
									{ !! row.description && (
										<span className="block truncate text-xs text-muted">
											{ row.description }
										</span>
									) }
								</span>
								<Badge tone="neutral">{ row.kind_label }</Badge>
								{ row.uses > 0 && (
									<span className="shrink-0 text-xs text-muted">
										{ sprintf(
											/* translators: %d: how many times a template has been used. */
											_n(
												'used %d time',
												'used %d times',
												row.uses,
												'cliezoo'
											),
											row.uses
										) }
									</span>
								) }
								{ !! row.updated_at && (
									<span className="hidden shrink-0 text-xs text-muted sm:block">
										{ formatDate( row.updated_at ) }
									</span>
								) }
								{ !! row.where && (
									<Link
										to={ `/${ row.where }` }
										className="shrink-0 text-sm text-accent underline-offset-2 hover:underline"
									>
										{ __( 'Where it lives', 'cliezoo' ) }
									</Link>
								) }
							</li>
						) ) }
					</ul>
				</>
			) }

			{ !! all.data?.more && (
				<Alert tone="warning">
					{ __(
						'Showing the first 200 of a kind. Search to find the rest.',
						'cliezoo'
					) }
				</Alert>
			) }
		</div>
	);
}
