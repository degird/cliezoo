/**
 * Field types added through `registerFieldType()` (Blueprint §11.4), drawn
 * where every other field is: in forms (FieldInput) and in list cells.
 *
 * Add-ons register a type's `display` and `edit`
 * components; the app supplies the label, the help text and the error
 * around them, so a registered field is labelled and announced like a
 * built-in one without each add-on getting that right on its own.
 */
import { useId, type ReactNode } from 'react';
import { registry, type FieldTypeDef } from '../registry';
import type { FieldMeta } from '../list/meta';

/**
 * The registered definition for a field's type, if an add-on gave one.
 *
 * @param field      The field.
 * @param field.type Its type.
 */
export function registeredType( field: {
	type: string;
} ): FieldTypeDef | undefined {
	return registry.fieldTypes.get( field.type );
}

/**
 * A registered type's editor, labelled.
 *
 * @param root0           Props.
 * @param root0.def       The registered type.
 * @param root0.field     The field.
 * @param root0.value     Its value.
 * @param root0.onChange  Called with a new value.
 * @param root0.error     A validation message.
 * @param root0.hideLabel The caller labels the group itself.
 */
export function RegisteredInput( {
	def,
	field,
	value,
	onChange,
	error,
	hideLabel,
}: {
	def: FieldTypeDef;
	field: FieldMeta;
	value: unknown;
	onChange: ( value: unknown ) => void;
	error?: string;
	hideLabel?: boolean;
} ) {
	const id = useId();
	const Edit = def.edit;
	if ( ! Edit ) {
		return null;
	}
	return (
		<div
			role="group"
			aria-labelledby={ `${ id }-label` }
			aria-describedby={
				field.help || error ? `${ id }-help` : undefined
			}
			className="flex flex-col gap-1"
		>
			<span
				id={ `${ id }-label` }
				className={
					hideLabel ? 'sr-only' : 'text-sm font-medium text-fg'
				}
			>
				{ field.label }
			</span>
			<Edit
				value={ value }
				onChange={ onChange }
				field={ field as unknown as Record< string, unknown > }
			/>
			{ ( field.help || error ) && (
				<span id={ `${ id }-help` } className="text-xs">
					{ field.help && (
						<span className="block text-muted">{ field.help }</span>
					) }
					{ error && (
						<span className="block text-danger" role="alert">
							{ error }
						</span>
					) }
				</span>
			) }
		</div>
	);
}

/**
 * A registered type's cell, or undefined to let the list draw it.
 *
 * @param field The field.
 * @param value Its value (never empty: the list draws empty cells itself).
 */
export function registeredCell(
	field: FieldMeta,
	value: unknown
): ReactNode | undefined {
	const def = registeredType( field );
	if ( ! def ) {
		return undefined;
	}
	const Display = def.display;
	return (
		<Display
			value={ value }
			field={ field as unknown as Record< string, unknown > }
		/>
	);
}
