/**
 * Form fields: every field has a visible label, a description slot and an
 * error linked with aria-describedby (React Aria wires the ids).
 */
import type { ReactNode } from 'react';
import { Check, ChevronDown, Search, X } from 'lucide-react';
import {
	Button as AriaButton,
	Checkbox as AriaCheckbox,
	FieldError,
	Input,
	Label,
	ListBox,
	ListBoxItem,
	SearchField as AriaSearchField,
	Select as AriaSelect,
	SelectValue,
	Switch as AriaSwitch,
	Text,
	TextArea as AriaTextArea,
	TextField as AriaTextField,
	type CheckboxProps,
	type ListBoxItemProps,
	type SearchFieldProps as AriaSearchFieldProps,
	type SelectProps as AriaSelectProps,
	type SwitchProps,
	type TextFieldProps as AriaTextFieldProps,
} from 'react-aria-components';
import { Popover } from './overlays';
import { cx, focusRing, inputClass, withClass } from './utils';

const labelClass = 'text-sm font-medium text-fg';
const helpClass = 'text-xs text-muted';
const errorClass = 'text-xs text-danger';

interface FieldBits {
	label?: ReactNode;
	description?: ReactNode;
	errorMessage?: string;
}

export interface TextFieldProps extends AriaTextFieldProps, FieldBits {
	placeholder?: string;
	multiline?: boolean;
	rows?: number;
}

export function TextField( {
	label,
	description,
	errorMessage,
	placeholder,
	multiline,
	rows = 4,
	className,
	...props
}: TextFieldProps ) {
	return (
		<AriaTextField
			{ ...props }
			isInvalid={ props.isInvalid || !! errorMessage }
			className={ withClass(
				className as string,
				'flex flex-col gap-1.5'
			) }
		>
			{ label && <Label className={ labelClass }>{ label }</Label> }
			{ multiline ? (
				<AriaTextArea
					rows={ rows }
					placeholder={ placeholder }
					className={ inputClass }
				/>
			) : (
				<Input placeholder={ placeholder } className={ inputClass } />
			) }
			{ description && (
				<Text slot="description" className={ helpClass }>
					{ description }
				</Text>
			) }
			<FieldError className={ errorClass }>{ errorMessage }</FieldError>
		</AriaTextField>
	);
}

export interface SearchFieldProps extends AriaSearchFieldProps {
	label: string;
	placeholder?: string;
	shortcut?: ReactNode;
}

/**
 * Search input with a visually hidden label and a clear button.
 * @param root0
 * @param root0.label
 * @param root0.placeholder
 * @param root0.shortcut
 * @param root0.className
 */
export function SearchField( {
	label,
	placeholder,
	shortcut,
	className,
	...props
}: SearchFieldProps ) {
	return (
		<AriaSearchField
			{ ...props }
			className={ withClass(
				className as string,
				'group relative flex items-center'
			) }
		>
			<Label className="sr-only">{ label }</Label>
			<Search
				size={ 16 }
				className="pointer-events-none absolute start-2.5 text-subtle"
				aria-hidden="true"
			/>
			<Input
				placeholder={ placeholder }
				className={ cx(
					inputClass,
					'ps-8 pe-8 [&::-webkit-search-cancel-button]:hidden'
				) }
			/>
			{ shortcut && (
				<span className="pointer-events-none absolute end-2 group-data-[empty=false]:hidden">
					{ shortcut }
				</span>
			) }
			<AriaButton
				className={ cx(
					'absolute end-1.5 rounded p-1 text-muted hover:text-fg group-data-[empty]:hidden',
					focusRing
				) }
			>
				<X size={ 14 } aria-hidden="true" />
			</AriaButton>
		</AriaSearchField>
	);
}

export interface SelectOption {
	id: string;
	label: string;
	description?: string;
}

export interface SelectProps< T extends SelectOption >
	extends Omit< AriaSelectProps< T >, 'children' >, FieldBits {
	items: T[];
	placeholder?: string;
}

export function Select< T extends SelectOption >( {
	label,
	description,
	errorMessage,
	items,
	placeholder,
	className,
	...props
}: SelectProps< T > ) {
	return (
		<AriaSelect
			{ ...props }
			placeholder={ placeholder }
			isInvalid={ props.isInvalid || !! errorMessage }
			className={ withClass(
				className as string,
				'flex flex-col gap-1.5'
			) }
		>
			{ label && <Label className={ labelClass }>{ label }</Label> }
			<AriaButton
				className={ cx(
					inputClass,
					'flex items-center justify-between gap-2 text-start data-[focus-visible]:border-focus data-[focus-visible]:ring-2 data-[focus-visible]:ring-focus/25'
				) }
			>
				<SelectValue className="truncate data-[placeholder]:text-subtle" />
				<ChevronDown
					size={ 16 }
					className="shrink-0 text-muted"
					aria-hidden="true"
				/>
			</AriaButton>
			{ description && (
				<Text slot="description" className={ helpClass }>
					{ description }
				</Text>
			) }
			<FieldError className={ errorClass }>{ errorMessage }</FieldError>
			<Popover
				placement="bottom start"
				className="min-w-[--trigger-width]"
			>
				<ListBox
					items={ items }
					className="max-h-72 overflow-auto p-1 outline-none"
				>
					{ ( item ) => (
						<Option
							id={ item.id }
							textValue={ item.label }
							description={ item.description }
						>
							{ item.label }
						</Option>
					) }
				</ListBox>
			</Popover>
		</AriaSelect>
	);
}

export function Option( {
	children,
	description,
	...props
}: ListBoxItemProps & { children: ReactNode; description?: string } ) {
	return (
		<ListBoxItem
			{ ...props }
			className="group flex cursor-default items-center gap-2 rounded-md px-2.5 py-1.5 text-sm text-fg outline-none data-[focused]:bg-sunken data-[disabled]:opacity-50"
		>
			<span className="flex min-w-0 flex-1 flex-col">
				<span className="truncate">{ children }</span>
				{ description && (
					<span className="truncate text-xs text-muted">
						{ description }
					</span>
				) }
			</span>
			<Check
				size={ 16 }
				className="invisible text-accent-fg group-data-[selected]:visible"
				aria-hidden="true"
			/>
		</ListBoxItem>
	);
}

export function Checkbox( {
	children,
	className,
	...props
}: CheckboxProps & { children?: ReactNode } ) {
	return (
		<AriaCheckbox
			{ ...props }
			className={ withClass(
				className as string,
				'group flex items-center gap-2 text-sm text-fg data-[disabled]:opacity-50'
			) }
		>
			<span
				aria-hidden="true"
				className="flex size-4 shrink-0 items-center justify-center rounded border border-line-strong bg-bg group-data-[selected]:border-accent group-data-[selected]:bg-accent group-data-[indeterminate]:border-accent group-data-[indeterminate]:bg-accent group-data-[focus-visible]:outline-2 group-data-[focus-visible]:outline-offset-2 group-data-[focus-visible]:outline-focus"
			>
				<Check
					size={ 12 }
					strokeWidth={ 3 }
					className="invisible text-accent-contrast group-data-[selected]:visible"
				/>
			</span>
			{ children }
		</AriaCheckbox>
	);
}

export function Switch( {
	children,
	className,
	...props
}: SwitchProps & { children?: ReactNode } ) {
	return (
		<AriaSwitch
			{ ...props }
			className={ withClass(
				className as string,
				'group flex items-center gap-2.5 text-sm text-fg data-[disabled]:opacity-50'
			) }
		>
			<span
				aria-hidden="true"
				className="flex h-5 w-9 shrink-0 items-center rounded-full bg-line-strong p-0.5 transition-colors group-data-[selected]:bg-accent group-data-[focus-visible]:outline-2 group-data-[focus-visible]:outline-offset-2 group-data-[focus-visible]:outline-focus"
			>
				<span className="size-4 rounded-full bg-white shadow-sm transition-transform group-data-[selected]:translate-x-4 rtl:group-data-[selected]:-translate-x-4" />
			</span>
			{ children }
		</AriaSwitch>
	);
}
