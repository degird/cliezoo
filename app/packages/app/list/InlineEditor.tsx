/**
 * Inline cell editing for simple field types (Enter saves, Escape cancels).
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Check, X } from 'lucide-react';
import { IconButton, Select, Switch, TextField } from '@cliezoo/ui';
import { humanise } from './cells';
import type { FieldMeta } from './meta';

const TEXT_TYPES = [
	'text',
	'email',
	'phone',
	'url',
	'int',
	'bigint',
	'decimal',
	'number',
	'date',
];

/**
 * Whether a field can be edited in the list.
 *
 * @param field Field.
 */
export function canInlineEdit( field: FieldMeta ): boolean {
	if ( field.readonly || field.system ) {
		return false;
	}
	if ( field.type === 'enum' || field.type === 'select' ) {
		return !! field.options?.length;
	}
	return (
		TEXT_TYPES.includes( field.type ) ||
		field.type === 'bool' ||
		field.type === 'checkbox'
	);
}

export function InlineEditor( {
	field,
	value,
	onSave,
	onCancel,
}: {
	field: FieldMeta;
	value: unknown;
	onSave: ( value: unknown ) => void;
	onCancel: () => void;
} ) {
	const [ draft, setDraft ] = useState< unknown >( value ?? '' );
	const numeric = [ 'int', 'bigint', 'decimal', 'number' ].includes(
		field.type
	);
	const commit = () => {
		if ( numeric ) {
			onSave( draft === '' || draft === null ? null : Number( draft ) );
			return;
		}
		onSave( draft === '' ? null : draft );
	};
	const keys = ( event: React.KeyboardEvent ) => {
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			onCancel();
		} else if ( event.key === 'Enter' ) {
			event.preventDefault();
			commit();
		}
	};

	let input;
	if ( field.type === 'enum' || field.type === 'select' ) {
		input = (
			<Select
				aria-label={ field.label }
				items={ ( field.options ?? [] ).map( ( option ) => ( {
					id: option,
					label: humanise( option ),
				} ) ) }
				selectedKey={ ( draft as string ) || null }
				onSelectionChange={ ( key ) => onSave( String( key ) ) }
			/>
		);
	} else if ( field.type === 'bool' || field.type === 'checkbox' ) {
		input = (
			<Switch isSelected={ !! draft } onChange={ ( on ) => onSave( on ) }>
				<span className="sr-only">{ field.label }</span>
			</Switch>
		);
	} else {
		input = (
			<TextField
				aria-label={ field.label }
				type={ field.type === 'date' ? 'date' : 'text' }
				inputMode={ numeric ? 'decimal' : undefined }
				value={
					draft === null || draft === undefined ? '' : String( draft )
				}
				onChange={ setDraft }
				onKeyDown={ keys }
				// eslint-disable-next-line jsx-a11y/no-autofocus -- Editing starts where the person asked for it.
				autoFocus
			/>
		);
	}

	return (
		<div
			className="flex min-w-40 items-center gap-1"
			role="group"
			aria-label={ field.label }
		>
			<div className="min-w-0 flex-1">{ input }</div>
			{ field.type !== 'enum' &&
				field.type !== 'select' &&
				field.type !== 'bool' &&
				field.type !== 'checkbox' && (
					<IconButton
						size="sm"
						label={ __( 'Save', 'cliezoo' ) }
						tooltip={ false }
						icon={ <Check size={ 14 } aria-hidden="true" /> }
						onPress={ commit }
					/>
				) }
			<IconButton
				size="sm"
				label={ __( 'Cancel', 'cliezoo' ) }
				tooltip={ false }
				icon={ <X size={ 14 } aria-hidden="true" /> }
				onPress={ onCancel }
			/>
		</div>
	);
}
