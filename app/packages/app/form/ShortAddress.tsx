/**
 * A short address for a page meant for anybody — a form, a booking page —
 * found by its name the way a website is: /cz/f/work-with-us. Off unless
 * set; the page's own link keeps working either way.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { api, getBoot, type ApiError } from '@cliezoo/data';
import { Button, TextField, toast } from '@cliezoo/ui';

export function ShortAddress( {
	saveTo,
	prefix,
	value,
	onSaved,
}: {
	/** The record's REST path, e.g. /forms/{uid}. */
	saveTo: string;
	/** The public prefix: f for a form, book for a booking page. */
	prefix: string;
	value: string;
	onSaved: () => void;
} ) {
	const [ slug, setSlug ] = useState( value );
	const [ busy, setBusy ] = useState( false );
	const clean = slug.trim().toLowerCase();

	const save = async ( next: string ) => {
		setBusy( true );
		try {
			await api.patch( saveTo, { slug: next } );
			toast.success( __( 'Saved.', 'cliezoo' ) );
			onSaved();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<section
			aria-label={ __( 'Short address', 'cliezoo' ) }
			className="rounded-lg border border-line bg-surface p-4"
		>
			<h2 className="mb-1 text-sm font-semibold text-fg">
				{ __( 'Short address', 'cliezoo' ) }
			</h2>
			<p className="mb-3 text-xs text-muted">
				{ __(
					'Optional: a name people can type. Anybody who guesses it finds the page, so keep it for pages meant for anybody. The same robot checks stand behind it.',
					'cliezoo'
				) }
			</p>
			<TextField
				label={ __( 'Address', 'cliezoo' ) }
				value={ slug }
				onChange={ setSlug }
				description={
					clean
						? `${ getBoot().routes.publicUrl }${ prefix }/${ clean }`
						: __(
								'Letters, numbers and dashes, such as work-with-us.',
								'cliezoo'
							)
				}
			/>
			<div className="mt-3 flex flex-wrap gap-2">
				<Button
					size="sm"
					variant="primary"
					isPending={ busy }
					isDisabled={ clean === value || ! clean }
					onPress={ () => save( clean ) }
				>
					{ __( 'Save', 'cliezoo' ) }
				</Button>
				{ !! value && (
					<Button
						size="sm"
						isDisabled={ busy }
						onPress={ () => {
							setSlug( '' );
							save( '' );
						} }
					>
						{ __( 'Take it away', 'cliezoo' ) }
					</Button>
				) }
			</div>
		</section>
	);
}
