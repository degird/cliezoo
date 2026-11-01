/**
 * The handful of icons a record may be given, and the picker for them.
 *
 * "Customize icon" is on every menu the competitors have, and the version
 * that lets somebody type a name is worse than nothing: they type one, it
 * is stored, and no screen ever draws it. So the choice is a short list of
 * icons this app already carries, which means what is picked is always
 * something that can be shown.
 *
 * Short on purpose. Every icon here is one more in the bundle, and a
 * hundred to choose from is a decision nobody wants to make about a phase
 * of a project.
 */
import { __ } from '@wordpress/i18n';
import {
	Award,
	Calendar,
	CheckCircle2,
	Flag,
	Gift,
	Handshake,
	Megaphone,
	MessageSquare,
	Package,
	Rocket,
	Sparkles,
	Target,
	Truck,
	type LucideIcon,
} from 'lucide-react';
import { Select } from '@cliezoo/ui';

const ICONS: Record< string, LucideIcon > = {
	flag: Flag,
	rocket: Rocket,
	target: Target,
	calendar: Calendar,
	package: Package,
	truck: Truck,
	megaphone: Megaphone,
	handshake: Handshake,
	award: Award,
	gift: Gift,
	sparkles: Sparkles,
	check: CheckCircle2,
	message: MessageSquare,
};

/**
 * What each one is called, for the picker.
 */
const NAMES = (): Record< string, string > => ( {
	flag: __( 'Flag', 'cliezoo' ),
	rocket: __( 'Launch', 'cliezoo' ),
	target: __( 'Target', 'cliezoo' ),
	calendar: __( 'Date', 'cliezoo' ),
	package: __( 'Delivery', 'cliezoo' ),
	truck: __( 'Shipped', 'cliezoo' ),
	megaphone: __( 'Announcement', 'cliezoo' ),
	handshake: __( 'Hand-over', 'cliezoo' ),
	award: __( 'Award', 'cliezoo' ),
	gift: __( 'Gift', 'cliezoo' ),
	sparkles: __( 'Something new', 'cliezoo' ),
	check: __( 'Signed off', 'cliezoo' ),
	message: __( 'Conversation', 'cliezoo' ),
} );

export function RecordIcon( {
	name,
	fallback = 'flag',
	size = 15,
	color,
	className,
}: {
	/** What the record has stored, which may be nothing or nonsense. */
	name?: string | null;
	/** What to draw when it has nothing. */
	fallback?: string;
	size?: number;
	color?: string | null;
	className?: string;
} ) {
	const Glyph = ICONS[ String( name ?? '' ) ] ?? ICONS[ fallback ] ?? Flag;
	return (
		<Glyph
			size={ size }
			aria-hidden="true"
			className={ className }
			style={ color ? { color } : undefined }
		/>
	);
}

/**
 * Choosing one.
 *
 * @param root0          Props.
 * @param root0.value    What is chosen now.
 * @param root0.onChange Called with the new choice.
 * @param root0.label    What the field is called.
 */
export function RecordIconPicker( {
	value,
	onChange,
	label,
}: {
	value?: string | null;
	onChange: ( name: string ) => void;
	label?: string;
} ) {
	const names = NAMES();
	return (
		<Select
			label={ label ?? __( 'Icon', 'cliezoo' ) }
			items={ Object.keys( ICONS ).map( ( id ) => ( {
				id,
				label: names[ id ] ?? id,
			} ) ) }
			selectedKey={ String( value ?? '' ) }
			onSelectionChange={ ( key ) => onChange( String( key ) ) }
		/>
	);
}
