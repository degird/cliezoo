/**
 * One editable field, from its metadata.
 *
 * Every form in Cliezoo is built from the same entity metadata the list
 * uses, so a field added on the server appears in the form without anyone
 * writing it twice, and it is labelled, validated and formatted the same
 * way wherever it shows up.
 */
import { lazy, Suspense, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { api, getBoot, useQuery, type ApiError } from '@cliezoo/data';
import {
	Button,
	ErrorState,
	SearchField,
	Select,
	Skeleton,
	Switch,
	TextField,
	toast,
} from '@cliezoo/ui';
import { humanise } from '../list/cells';
import { useEntityMeta, type FieldMeta } from '../list/meta';
import { AiForText, type AiMenuProps } from '../ai/AiMenu';
import { MarkdownField } from './MarkdownField';
import { RegisteredInput, registeredType } from './RegisteredField';
const PhoneInput = lazy( () =>
	import( /* webpackChunkName: "phone-input" */ './PhoneInput' ).then(
		( m ) => ( { default: m.PhoneInput } )
	)
);
import { currencyDecimals } from './customField';
import { customControl } from './CustomInputs';
import { fromLocalInput, toLocalInput } from './datetime';

/** How many options a picker loads before it admits it is showing a subset. */
const PICKER_LIMIT = 100;

export interface FieldInputProps {
	field: FieldMeta;
	value: unknown;
	onChange: ( value: unknown ) => void;
	error?: string;
	/** Renders without a visible label (the caller labels the group). */
	hideLabel?: boolean;
	/** The whole record being edited, for fields that depend on another. */
	draft?: Record< string, unknown >;
	/**
	 * Extra query for a reference picker, when only some of the records
	 * make sense here — the lists of this board rather than every list in
	 * the workspace. Without it a picker offers choices the server will
	 * refuse, or worse, accept.
	 */
	refQuery?: Record< string, unknown >;
	/** AI writing help for a long text: where, and on which record. */
	ai?: Pick< AiMenuProps, 'place' | 'record' | 'extras' | 'about' >;
	/** A long text written with formatting (markdown), with a toolbar. */
	rich?: boolean;
	/**
	 * Hands a chosen reference back as a chip ({ uid, label }) rather
	 * than its uid alone, for a caller that shows what was chosen
	 * before the record is saved.
	 */
	refChip?: boolean;
}

/**
 * The values of an editable list, for `listed` fields.
 * @param key
 */
function useListValues( key: string ) {
	return useQuery( {
		queryKey: [ 'lists' ],
		staleTime: 5 * 60_000,
		enabled: !! key,
		queryFn: async () =>
			(
				await api.get<
					Record<
						string,
						{ values: Array< { value: string; label: string } > }
					>
				>( '/lists' )
			).data,
		select: ( lists ) => lists[ key ]?.values ?? [],
	} );
}

/**
 * Records of another entity, for `ref` fields.
 * @param entity
 * @param query
 */
function useRefOptions( entity: string, query?: Record< string, unknown > ) {
	return useQuery( {
		queryKey: [ entity, 'picker', query ?? {} ],
		staleTime: 60_000,
		enabled: !! entity,
		queryFn: async () =>
			(
				await api.get< Array< Record< string, unknown > > >(
					`/${ entity }`,
					{ limit: PICKER_LIMIT, ...( query ?? {} ) }
				)
			).data,
	} );
}

const NUMERIC = [ 'int', 'bigint', 'decimal', 'number', 'money' ];

/**
 * What a typed value means: nothing when it is empty, a number when the
 * field holds one, the text otherwise.
 *
 * @param field The field.
 * @param next  What was typed.
 */
function parse( field: FieldMeta, next: string ): unknown {
	if ( '' === next ) {
		return null;
	}
	return NUMERIC.includes( field.type ) ? Number( next ) : next;
}

/** The currencies this browser knows, with the workspace's own first. */
function currencies(): string[] {
	let codes: string[];
	try {
		codes = (
			Intl as unknown as { supportedValuesOf: ( k: string ) => string[] }
		 ).supportedValuesOf( 'currency' );
	} catch {
		codes = [ 'USD', 'EUR', 'GBP' ];
	}
	const own = getBoot().workspace.currency;
	return [ own, ...codes.filter( ( code ) => code !== own ) ];
}

/**
 * Which control a field gets.
 *
 * It is a plain function rather than a chain of `if`s inside the component
 * because the order matters and is easy to get wrong: a field backed by one
 * of the workspace's editable lists arrives as an enum *with a list name*,
 * and its values live in the list rather than in the schema, so asking
 * "is it an enum?" first would render an empty dropdown.
 *
 * @param field The field.
 */
export function controlFor( field: FieldMeta ): string {
	if ( 'bool' === field.type ) {
		return 'switch';
	}
	if ( 'listed' === field.type || field.list ) {
		return 'list';
	}
	if ( 'enum' === field.type ) {
		return 'enum';
	}
	if ( 'ref' === field.type || 'user' === field.type ) {
		return 'ref';
	}
	if ( 'currency' === field.type ) {
		return 'currency';
	}
	if ( 'money' === field.type ) {
		return 'money';
	}
	if ( 'longtext' === field.type || 'html' === field.type ) {
		return 'multiline';
	}
	// A record's tags are a list of words, kept as JSON. Every record that
	// has them — clients, projects, conversations — had no way to set them.
	if ( 'json' === field.type && 'tags' === field.key ) {
		return 'tags';
	}
	// Other email addresses are a list too, written the same way.
	if ( 'json' === field.type && 'emails' === field.key ) {
		return 'emails';
	}
	return 'text';
}

/**
 * Renders the right control for a field.
 *
 * @param root0
 * @param root0.field
 * @param root0.value
 * @param root0.onChange
 * @param root0.error
 * @param root0.hideLabel
 * @param root0.draft
 * @param root0.refQuery
 * @param root0.ai
 * @param root0.rich
 * @param root0.refChip
 */
export function FieldInput( {
	field,
	value,
	onChange,
	error,
	hideLabel,
	draft,
	refQuery,
	ai,
	rich = false,
	refChip = false,
}: FieldInputProps ) {
	// A type an add-on registered (registerFieldType) is edited its way.
	const registered = registeredType( field );
	if ( registered?.edit ) {
		return (
			<RegisteredInput
				def={ registered }
				{ ...{ field, value, onChange, error, hideLabel } }
			/>
		);
	}
	const label = field.label;
	const common = {
		label: hideLabel ? undefined : label,
		'aria-label': hideLabel ? label : undefined,
		description: field.help,
		errorMessage: error,
		isRequired: field.required,
		isDisabled: field.readonly,
	};
	const text = ( v: unknown ) =>
		v === null || v === undefined ? '' : String( v );
	const control = controlFor( field );

	// A custom field's type names its own control (a list with its
	// choices, money in the field's currency, a number with its format),
	// so it is asked first: its options are an object, not a list of
	// values, and its type ids overlap the built-in ones.
	if ( field.key.startsWith( 'custom.' ) ) {
		const own = customControl( field, value, onChange, common );
		if ( own ) {
			return own;
		}
		if ( 'person' === field.type ) {
			return (
				<RefInput
					field={ { ...field, type: 'user' } }
					{ ...{ value, onChange, common } }
				/>
			);
		}
	}

	if ( 'phone' === field.type ) {
		return (
			<Suspense fallback={ <Skeleton className="h-9" /> }>
				<PhoneInput
					{ ...common }
					value={
						null === value || undefined === value
							? null
							: text( value )
					}
					onChange={ onChange }
				/>
			</Suspense>
		);
	}

	if ( 'switch' === control ) {
		return (
			<div className="flex items-center">
				<Switch
					isSelected={ !! value }
					onChange={ onChange }
					isDisabled={ field.readonly }
				>
					{ label }
				</Switch>
			</div>
		);
	}

	if ( 'list' === control ) {
		return <ListedInput { ...{ field, value, onChange, common } } />;
	}

	if ( 'enum' === control ) {
		return (
			<Select
				{ ...common }
				items={ ( field.options ?? [] ).map( ( option ) => ( {
					id: option,
					label: humanise( option ),
				} ) ) }
				selectedKey={ text( value ) || null }
				onSelectionChange={ ( key ) =>
					onChange( null === key ? null : String( key ) )
				}
			/>
		);
	}

	if ( 'ref' === control ) {
		return (
			<RefInput
				{ ...{ field, value, onChange, common, refQuery, refChip } }
			/>
		);
	}

	if ( 'currency' === control ) {
		return (
			<Select
				{ ...common }
				items={ currencies().map( ( code ) => ( {
					id: code,
					label: code,
				} ) ) }
				selectedKey={ text( value ) || null }
				placeholder={ getBoot().workspace.currency }
				onSelectionChange={ ( key ) =>
					onChange( null === key ? null : String( key ) )
				}
			/>
		);
	}

	if ( 'money' === control ) {
		return (
			<MoneyInput
				{ ...{ field, value, onChange, draft } }
				common={ common as Record< string, unknown > }
			/>
		);
	}

	if ( 'tags' === control || 'emails' === control ) {
		return (
			<TagsInput
				{ ...{ value, onChange, common } }
				longest={ 'emails' === control ? 190 : 40 }
			/>
		);
	}

	if ( 'multiline' === control ) {
		const box = rich ? (
			<MarkdownField
				label={ common.label }
				description={ common.description }
				errorMessage={ common.errorMessage }
				isDisabled={ common.isDisabled }
				value={ text( value ) }
				onChange={ ( next: string ) =>
					onChange( '' === next ? null : next )
				}
			/>
		) : (
			<TextField
				{ ...common }
				multiline
				rows={ 4 }
				value={ text( value ) }
				onChange={ ( next: string ) =>
					onChange( '' === next ? null : next )
				}
			/>
		);
		if ( ! ai ) {
			return box;
		}
		return (
			<div className="relative">
				{ box }
				<div className="absolute end-0 top-0">
					<AiForText
						{ ...ai }
						html={ 'html' === field.type }
						value={ text( value ) }
						onChange={ ( next ) => onChange( next || null ) }
					/>
				</div>
			</div>
		);
	}

	// A moment: a date-and-time box in the reader's own time, saved as UTC.
	// Drawn as a plain text box before, so a meeting's start could only be
	// typed in a format nobody was told.
	if ( 'datetime' === field.type ) {
		return (
			<TextField
				{ ...common }
				type="datetime-local"
				value={ toLocalInput( value ) }
				onChange={ ( next: string ) =>
					onChange( fromLocalInput( next ) )
				}
			/>
		);
	}

	const type =
		{
			email: 'email',
			url: 'url',
			phone: 'tel',
			date: 'date',
			color: 'color',
		}[ field.type ] ??
		( NUMERIC.includes( field.type ) ? 'number' : 'text' );

	return (
		<TextField
			{ ...common }
			type={ type }
			inputMode={ NUMERIC.includes( field.type ) ? 'decimal' : undefined }
			value={ text( value ) }
			onChange={ ( next: string ) => onChange( parse( field, next ) ) }
		/>
	);
}

/**
 * Tags, written as words separated by commas.
 *
 * What is typed is kept as it is typed, so a comma at the end is not taken
 * away mid-word; the list saved is the words, each once.
 *
 * @param root0          Props.
 * @param root0.value    The tags.
 * @param root0.onChange Called with the new list.
 * @param root0.common   Label, help and state.
 * @param root0.longest  The longest one entry may be.
 */
function TagsInput( {
	value,
	onChange,
	common,
	longest = 40,
}: {
	value: unknown;
	onChange: ( next: unknown ) => void;
	common: Record< string, unknown >;
	longest?: number;
} ) {
	const [ typed, setTyped ] = useState( () =>
		Array.isArray( value ) ? value.map( String ).join( ', ' ) : ''
	);
	return (
		<TextField
			{ ...common }
			description={
				( common.description as string | undefined ) ??
				__( 'Separate them with commas.', 'cliezoo' )
			}
			value={ typed }
			onChange={ ( next: string ) => {
				setTyped( next );
				onChange( tagsFrom( next, longest ) );
			} }
		/>
	);
}

/**
 * The tags in what was typed: trimmed, each once, at most twenty.
 *
 * @param typed   What was typed.
 * @param longest
 */
export function tagsFrom( typed: string, longest = 40 ): string[] {
	const out: string[] = [];
	for ( const part of typed.split( ',' ) ) {
		const tag = part.trim().slice( 0, longest );
		if ( tag && ! out.includes( tag ) ) {
			out.push( tag );
		}
	}
	return out.slice( 0, 20 );
}

/**
 * A value from one of the workspace's editable lists.
 * @param root0
 * @param root0.field
 * @param root0.value
 * @param root0.onChange
 * @param root0.common
 */
/**
 * An amount of money, shown and typed the way people write it.
 *
 * The server keeps minor units (cents), which is right for arithmetic and
 * wrong for a text box, so this is the one field that converts on the way
 * in and on the way out.
 *
 * @param root0
 * @param root0.field
 * @param root0.value
 * @param root0.onChange
 * @param root0.common
 * @param root0.draft
 */
function MoneyInput( {
	field,
	value,
	onChange,
	common,
	draft,
}: {
	field: FieldMeta;
	value: unknown;
	onChange: ( value: unknown ) => void;
	common: Record< string, unknown >;
	draft?: Record< string, unknown >;
} ) {
	const money = ( value ?? {} ) as { amount?: number; currency?: string };
	// field.currency names the field this amount is counted in, so the code
	// itself comes from the record being edited — and, while that is still
	// empty, from the workspace.
	const currency =
		money.currency ||
		( field.currency ? String( draft?.[ field.currency ] ?? '' ) : '' ) ||
		getBoot().workspace.currency;
	const decimals = currencyDecimals( currency );
	const major =
		'number' === typeof money.amount
			? String( money.amount / 10 ** decimals )
			: '';
	return (
		<TextField
			{ ...common }
			type="number"
			inputMode="decimal"
			value={ major }
			description={
				( common.description as string | undefined ) ?? currency
			}
			onChange={ ( next: string ) =>
				onChange(
					'' === next
						? null
						: {
								amount: Math.round(
									Number( next ) * 10 ** decimals
								),
								currency,
							}
				)
			}
		/>
	);
}

function ListedInput( {
	field,
	value,
	onChange,
	common,
}: {
	field: FieldMeta;
	value: unknown;
	onChange: ( value: unknown ) => void;
	common: Record< string, unknown >;
} ) {
	const values = useListValues( field.list ?? '' );
	if ( values.isError ) {
		return (
			<ErrorState
				error={ values.error }
				title={ __( 'The options could not be loaded.', 'cliezoo' ) }
				onRetry={ () => values.refetch() }
			/>
		);
	}
	return (
		<Select
			{ ...common }
			items={ ( values.data ?? [] ).map( ( option ) => ( {
				id: option.value,
				label: option.label,
			} ) ) }
			selectedKey={ value ? String( value ) : null }
			onSelectionChange={ ( key ) =>
				onChange( null === key ? null : String( key ) )
			}
		/>
	);
}

/**
 * Another record: a person, a client, a project.
 * @param root0
 * @param root0.field
 * @param root0.value
 * @param root0.onChange
 * @param root0.common
 */
/**
 * The key a reference picker selects by: the uid inside the chip the server
 * sent, or the plain value when something set it by hand.
 *
 * @param value The field value.
 */
function refKey( value: unknown ): string | null {
	const chip = value as { uid?: string } | null;
	if ( chip && 'object' === typeof chip && chip.uid ) {
		return chip.uid;
	}
	return value ? String( value ) : null;
}

function RefInput( {
	field,
	value,
	onChange,
	common,
	refQuery,
	refChip,
}: {
	field: FieldMeta;
	value: unknown;
	onChange: ( value: unknown ) => void;
	common: Record< string, unknown >;
	refQuery?: Record< string, unknown >;
	refChip?: boolean;
} ) {
	const entity = 'user' === field.type ? 'members' : ( field.ref ?? '' );
	// Past the first page of records, the picker is searched: a task among
	// thousands has to be findable, not just the first hundred.
	const [ q, setQ ] = useState( '' );
	// Somebody who has come to make a new one, in a workspace small enough
	// that the picker would not otherwise offer a search to type it into.
	const [ making, setMaking ] = useState( false );
	// The one just made here, offered until the list has caught up with it:
	// a picker that cannot find what it has been told to choose chooses
	// nothing, and the record would be saved without it.
	const [ made, setMade ] = useState< { id: string; label: string } | null >(
		null
	);
	const rows = useRefOptions(
		entity,
		'' === q.trim() ? refQuery : { ...( refQuery ?? {} ), q: q.trim() }
	);
	// A reference arrives as a chip ({ uid, label }); the picker selects by
	// the uid inside it.
	const selected = refKey( value );
	if ( rows.isError ) {
		return (
			<ErrorState
				error={ rows.error }
				title={ __(
					'The list of records could not be loaded.',
					'cliezoo'
				) }
				onRetry={ () => rows.refetch() }
			/>
		);
	}
	const items = ( rows.data ?? [] ).map( ( row ) => ( {
		id: String( row.uid ),
		label: String( row.name ?? row.title ?? row.label ?? row.uid ),
	} ) );
	// What is chosen stays offered while somebody searches for something
	// else, or the picker would show nothing chosen.
	const chip = value as { uid?: string; label?: string } | null;
	if (
		selected &&
		! items.some( ( one ) => one.id === selected ) &&
		chip &&
		'object' === typeof chip &&
		chip.label
	) {
		items.unshift( { id: selected, label: chip.label } );
	}
	if ( made && ! items.some( ( one ) => one.id === made.id ) ) {
		items.unshift( made );
	}
	const searchable = items.length >= PICKER_LIMIT || '' !== q || making;
	// Nothing found for what was typed: rather than sending somebody away
	// to make it and come back, the picker makes it here and chooses it.
	const wanted = q.trim();
	const missing =
		'' !== wanted &&
		! rows.isFetching &&
		! items.some(
			( one ) => one.label.toLowerCase() === wanted.toLowerCase()
		);
	const select = (
		<Select
			{ ...common }
			description={
				items.length >= PICKER_LIMIT
					? sprintf(
							/* translators: %d: how many are listed. */
							__( 'Showing the first %d, by name.', 'cliezoo' ),
							items.length
						)
					: ( common.description as string | undefined )
			}
			items={ items }
			selectedKey={ selected }
			onSelectionChange={ ( key ) => {
				if ( null === key ) {
					onChange( null );
					return;
				}
				const uid = String( key );
				if ( ! refChip ) {
					onChange( uid );
					return;
				}
				// The caller shows what was chosen straight away, so it
				// is handed the name along with the uid.
				const found = items.find( ( one ) => one.id === uid );
				onChange( { uid, label: found?.label ?? uid } );
			} }
		/>
	);
	if ( ! searchable ) {
		// A client is the one record a form is most often stopped by — a
		// deal for somebody new, a project for a client not yet written
		// down — so its picker says it can make one here, where a small
		// workspace would otherwise never show the search that does.
		return INLINE.includes( entity ) && ! field.readonly ? (
			<div className="space-y-1.5">
				{ select }
				<StartMaking
					entity={ entity }
					onStart={ () => setMaking( true ) }
				/>
			</div>
		) : (
			select
		);
	}
	return (
		<div className="space-y-1.5">
			<SearchField
				label={ sprintf(
					/* translators: %s: what is being chosen. */
					__( 'Find: %s', 'cliezoo' ),
					field.label
				) }
				value={ q }
				onChange={ setQ }
				// Enter in a text box submits the form it is in. Here that
				// saved the record while its client was still being looked
				// for — a new client typed and Enter pressed made the deal
				// with no client at all.
				onKeyDown={ ( event: {
					key: string;
					preventDefault: () => void;
				} ) => {
					if ( 'Enter' === event.key ) {
						event.preventDefault();
					}
				} }
				// eslint-disable-next-line jsx-a11y/no-autofocus -- Opened by pressing "New client…", so the next thing is typing its name.
				autoFocus={ making }
			/>
			{ select }
			{ missing && ! field.readonly && (
				<MakeOne
					entity={ entity }
					name={ wanted }
					onMade={ ( one ) => {
						setMade( { id: one.uid, label: one.label } );
						setQ( '' );
						onChange(
							refChip
								? { uid: one.uid, label: one.label }
								: one.uid
						);
						rows.refetch();
					} }
				/>
			) }
		</div>
	);
}

/** The records a picker offers to make on the spot even when it is short. */
const INLINE = [ 'clients' ];

/**
 * "New client…" under a short picker: opens the search, where typing the
 * name offers to add it.
 *
 * @param root0         Props.
 * @param root0.entity  What would be made.
 * @param root0.onStart Called when somebody means to make one.
 */
function StartMaking( {
	entity,
	onStart,
}: {
	entity: string;
	onStart: () => void;
} ) {
	const meta = useEntityMeta( entity );
	if ( ! meta.data?.can?.create ) {
		return null;
	}
	return (
		<Button size="sm" variant="ghost" onPress={ onStart }>
			{ sprintf(
				/* translators: %s: what would be made, e.g. Client. */
				__( 'New %s…', 'cliezoo' ),
				String( meta.data?.label ?? entity ).toLowerCase()
			) }
		</Button>
	);
}

/**
 * Making the record that is not there yet, from the picker asking for it.
 *
 * Only a name: anything more belongs on the record's own form, which is
 * where somebody goes when they mean to fill it in. This is for the
 * moment where a missing client is the only thing standing between
 * somebody and the project they are making.
 *
 * @param root0        Props.
 * @param root0.entity What is being made.
 * @param root0.name   What to call it.
 * @param root0.onMade Called with the new record.
 */
function MakeOne( {
	entity,
	name,
	onMade,
}: {
	entity: string;
	name: string;
	onMade: ( made: { uid: string; label: string } ) => void;
} ) {
	const meta = useEntityMeta( entity );
	const [ busy, setBusy ] = useState( false );
	const title = meta.data?.title_field ?? 'name';
	if ( ! meta.data?.can?.create ) {
		return null;
	}

	return (
		<Button
			size="sm"
			variant="ghost"
			isPending={ busy }
			onPress={ async () => {
				setBusy( true );
				try {
					const made = (
						await api.post< Record< string, unknown > >(
							`/${ entity }`,
							{ [ title ]: name }
						)
					).data;
					onMade( {
						uid: String( made.uid ),
						label: String( made[ title ] ?? name ),
					} );
				} catch ( error ) {
					toast.error( ( error as ApiError ).message );
				} finally {
					setBusy( false );
				}
			} }
		>
			{ sprintf(
				/* translators: 1: what is being made, e.g. Client, 2: its name. */
				__( 'Add %1$s “%2$s”', 'cliezoo' ),
				meta.data?.label ?? entity,
				name
			) }
		</Button>
	);
}
