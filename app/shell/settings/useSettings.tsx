/**
 * Settings form state: load a settings module, edit a draft, save the
 * changed keys, show field errors, warn about unsaved changes.
 */
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { api, useQuery, useQueryClient, type ApiError } from '@cliezoo/data';
import { Alert, Button, Skeleton, toast } from '@cliezoo/ui';

type Values = Record< string, unknown >;

export function useSettings( module: string ) {
	const client = useQueryClient();
	const query = useQuery( {
		queryKey: [ 'settings', module ],
		queryFn: async () =>
			(
				await api.get< {
					values: Values;
					can_edit: boolean;
					info?: Record< string, string >;
				} >( `/settings/${ module }` )
			).data,
	} );
	const [ draft, setDraft ] = useState< Values >( {} );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		if ( query.data ) {
			setDraft( query.data.values );
		}
	}, [ query.data ] );

	const changed = useMemo( () => {
		const base = query.data?.values ?? {};
		return Object.fromEntries(
			Object.entries( draft ).filter(
				( [ key, value ] ) =>
					JSON.stringify( value ) !== JSON.stringify( base[ key ] )
			)
		);
	}, [ draft, query.data ] );
	const dirty = Object.keys( changed ).length > 0;

	// Unsaved changes warning when leaving the page.
	useEffect( () => {
		if ( ! dirty ) {
			return;
		}
		const warn = ( event: BeforeUnloadEvent ) => {
			event.preventDefault();
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	const set = ( key: string, value: unknown ) => {
		setDraft( ( current ) => ( { ...current, [ key ]: value } ) );
		setErrors( ( current ) => {
			const next = { ...current };
			delete next[ key ];
			return next;
		} );
	};

	/**
	 * Writes the changed keys.
	 *
	 * @param announce Say so when it worked. A screen that saves two modules
	 *                 at once says it once itself, rather than showing the
	 *                 same words twice for one press.
	 * @return Whether it was written.
	 */
	const save = async ( announce = true ): Promise< boolean > => {
		setSaving( true );
		setErrors( {} );
		try {
			const result = (
				await api.patch< { values: Values } >(
					`/settings/${ module }`,
					{ values: changed }
				)
			).data;
			client.setQueryData( [ 'settings', module ], {
				...query.data,
				values: result.values,
				can_edit: true,
			} );
			// Whatever reads a module's own state (the AI buttons read
			// ['ai', 'tasks']) learns about the change now.
			await client.invalidateQueries( { queryKey: [ module ] } );
			if ( announce ) {
				toast.success( __( 'Settings saved.', 'cliezoo' ) );
			}
			return true;
		} catch ( error ) {
			const failure = error as ApiError;
			setErrors( failure.fields );
			// Always said, and per module: which one refused is the useful part.
			toast.error( failure.message );
			return false;
		} finally {
			setSaving( false );
		}
	};

	return {
		loading: query.isPending,
		error: query.error as ApiError | null,
		canEdit: !! query.data?.can_edit,
		/** Facts shown but not set, such as the workspace ID. */
		info: query.data?.info ?? {},
		values: draft,
		errors,
		dirty,
		saving,
		set,
		save,
		reset: () => setDraft( query.data?.values ?? {} ),
	};
}

export type SettingsState = ReturnType< typeof useSettings >;

/**
 * Wraps a settings form: loading, read-only notice and the sticky save bar.
 * @param root0
 * @param root0.state
 * @param root0.children
 * @param root0.canSave
 * @param root0.also
 */
export function SettingsForm( {
	state,
	children,
	canSave = true,
	also = [],
}: {
	state: SettingsState;
	children: ReactNode;
	/** A section can block saving while its own rules are not met. */
	canSave?: boolean;
	/**
	 * Other settings modules this one screen also edits.
	 *
	 * Sales is about leads and deals, which are two modules and one subject:
	 * nobody thinks of "how a lead is scored" and "when a deal is stale" as
	 * two places to go. A form inside a form is not valid markup and a second
	 * sticky save bar would sit on top of the first, so the screen is one
	 * form with one save that writes both.
	 */
	also?: SettingsState[];
} ) {
	const all = [ state, ...also ];
	if ( all.some( ( one ) => one.loading ) ) {
		return (
			<div className="space-y-3" aria-busy="true">
				<Skeleton className="h-10" />
				<Skeleton className="h-10" />
				<Skeleton className="h-10" />
			</div>
		);
	}
	const failed = all.find( ( one ) => one.error );
	if ( failed?.error ) {
		return <Alert tone="error">{ failed.error.message }</Alert>;
	}
	// Read-only if any of them is: a half-saveable screen is worse than one
	// that says plainly it cannot be changed.
	const canEdit = all.every( ( one ) => one.canEdit );
	const dirty = all.some( ( one ) => one.dirty );
	const saving = all.some( ( one ) => one.saving );
	return (
		<form
			onSubmit={ async ( event ) => {
				event.preventDefault();
				// Only the ones with something to write, so saving one module
				// does not touch another's values.
				const writing = all.filter( ( one ) => one.dirty );
				const done = await Promise.all(
					writing.map( ( one ) => one.save( 1 === writing.length ) )
				);
				// One press, one "saved" — and nothing said at all if any of
				// them refused, because each refusal has said so already.
				if ( writing.length > 1 && done.every( Boolean ) ) {
					toast.success( __( 'Settings saved.', 'cliezoo' ) );
				}
			} }
			className="space-y-6"
		>
			{ ! canEdit && (
				<Alert tone="info">
					{ __(
						'You can view these settings but not change them.',
						'cliezoo'
					) }
				</Alert>
			) }
			<fieldset disabled={ ! canEdit } className="space-y-6">
				{ children }
			</fieldset>
			{ canEdit && (
				<div className="sticky bottom-16 z-10 flex items-center justify-end gap-2 rounded-lg border border-line bg-raised/95 p-3 shadow-md backdrop-blur lg:bottom-4">
					{ dirty && (
						<span className="me-auto text-sm text-muted">
							{ __( 'You have unsaved changes.', 'cliezoo' ) }
						</span>
					) }
					<Button
						variant="ghost"
						onPress={ () => all.forEach( ( one ) => one.reset() ) }
						isDisabled={ ! dirty }
					>
						{ __( 'Discard', 'cliezoo' ) }
					</Button>
					<Button
						type="submit"
						variant="primary"
						isPending={ saving }
						isDisabled={ ! dirty || ! canSave }
					>
						{ __( 'Save changes', 'cliezoo' ) }
					</Button>
				</div>
			) }
		</form>
	);
}

/**
 * A titled group of fields.
 * @param root0
 * @param root0.title
 * @param root0.description
 * @param root0.children
 */
export function Fieldset( {
	title,
	description,
	children,
}: {
	title: string;
	description?: string;
	children: ReactNode;
} ) {
	return (
		<section className="rounded-lg border border-line bg-raised p-5">
			<h2 className="text-base font-semibold">{ title }</h2>
			{ description && (
				<p className="mt-1 text-sm text-muted">{ description }</p>
			) }
			<div className="mt-4 grid gap-4 sm:grid-cols-2">{ children }</div>
		</section>
	);
}
