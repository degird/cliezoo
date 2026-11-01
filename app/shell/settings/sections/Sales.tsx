/**
 * Settings → Sales: how a lead is judged, and when one has gone quiet.
 *
 * The score is the reason this screen has to exist. A lead's score is shown
 * everywhere and asked for nowhere: it was worked out from weights written
 * down in the code, with a comment saying a studio "knows better than any
 * default what a good lead looks like" — which was true, and there was no
 * screen on which to say so. A number somebody is asked to trust and cannot
 * change is a number they stop reading.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Switch, TextField } from '@cliezoo/ui';
import { Fieldset, SettingsForm, useSettings } from '../useSettings';
import { LeadIntakePanel } from './LeadIntakePanel';

/** What each point of the score is for, in the order it is asked about. */
const WEIGHTS = (): Array< { id: string; label: string; help: string } > => [
	{
		id: 'has_email',
		label: __( 'They left an email address', 'cliezoo' ),
		help: __( 'Somebody you can answer.', 'cliezoo' ),
	},
	{
		id: 'has_phone',
		label: __( 'They left a phone number', 'cliezoo' ),
		help: __( 'Usually somebody in a hurry.', 'cliezoo' ),
	},
	{
		id: 'has_business',
		label: __( 'They named a company', 'cliezoo' ),
		help: __( 'A business rather than a passer-by.', 'cliezoo' ),
	},
	{
		id: 'has_website',
		label: __( 'They have a website', 'cliezoo' ),
		help: __( 'Something to look at before you call.', 'cliezoo' ),
	},
	{
		id: 'has_budget',
		label: __( 'They mentioned a budget', 'cliezoo' ),
		help: __(
			'The strongest signal by default, because somebody who says a number has already decided to spend one.',
			'cliezoo'
		),
	},
	{
		id: 'rating',
		label: __( 'Your own rating of them', 'cliezoo' ),
		help: __( 'Per star, so five stars is five times this.', 'cliezoo' ),
	},
	{
		id: 'source',
		label: __( 'They came from a source you count as strong', 'cliezoo' ),
		help: __( 'Which sources those are is below.', 'cliezoo' ),
	},
	{
		id: 'touched',
		label: __( 'Somebody has spoken to them', 'cliezoo' ),
		help: __(
			'A lead that has been answered is further along than one that has not.',
			'cliezoo'
		),
	},
];

export default function Sales() {
	const s = useSettings( 'leads' );
	const v = s.values;
	const weights = ( v.score_weights ?? {} ) as Record< string, number >;
	const sources = ( v.strong_sources ?? [] ) as string[];

	const weigh = ( id: string, to: number ) =>
		s.set( 'score_weights', { ...weights, [ id ]: to } );

	return (
		<>
			<SettingsForm state={ s }>
				<Fieldset
					title={ __( 'New leads', 'cliezoo' ) }
					description={ __(
						'What happens when an enquiry arrives, whether it came from a form on your site or somebody wrote it down after a phone call.',
						'cliezoo'
					) }
				>
					<TextField
						label={ __( 'The status a lead starts in', 'cliezoo' ) }
						description={ __(
							'One of your own lead statuses. Left as it is, a lead starts at “new”.',
							'cliezoo'
						) }
						value={ String( v.default_status ?? '' ) }
						onChange={ ( x: string ) =>
							s.set( 'default_status', x )
						}
					/>
					<Switch
						isSelected={ !! v.refuse_duplicates }
						onChange={ ( x: boolean ) =>
							s.set( 'refuse_duplicates', x )
						}
					>
						{ __(
							'Refuse a second lead from an address you already have',
							'cliezoo'
						) }
					</Switch>
					<p className="-mt-2 text-sm text-muted">
						{ __(
							'Two rows for one conversation is how somebody gets chased twice by two people. Turned off, the second one is written down with a warning instead.',
							'cliezoo'
						) }
					</p>
					<TextField
						label={ __( 'Gone quiet after', 'cliezoo' ) }
						type="number"
						description={ __(
							'Days without a word before a lead is counted as gone quiet and named on the list. Nought turns it off.',
							'cliezoo'
						) }
						value={ String(
							( v.quiet_after_days as number ) ?? 0
						) }
						onChange={ ( x: string ) =>
							s.set( 'quiet_after_days', Number( x ) || 0 )
						}
					/>
				</Fieldset>

				<Fieldset
					title={ __( 'What makes a lead worth chasing', 'cliezoo' ) }
					description={ __(
						'Each of these adds its points to a lead’s score. Nought means it counts for nothing. Nobody else can know which of these matters to you, which is why they are here rather than in the code.',
						'cliezoo'
					) }
				>
					{ WEIGHTS().map( ( one ) => (
						<TextField
							key={ one.id }
							label={ one.label }
							type="number"
							description={ one.help }
							value={ String( weights[ one.id ] ?? 0 ) }
							onChange={ ( x: string ) =>
								weigh( one.id, Number( x ) || 0 )
							}
						/>
					) ) }
					<TextField
						multiline
						rows={ 4 }
						label={ __( 'Sources you count as strong', 'cliezoo' ) }
						description={ sprintf(
							/* translators: %d: how many points the "strong source" rule is worth. */
							__(
								'One per line. A lead from one of these gets the %d points above. Empty means none of them do, which is the honest default.',
								'cliezoo'
							),
							Number( weights.source ?? 0 )
						) }
						value={ sources.join( '\n' ) }
						onChange={ ( x: string ) =>
							s.set(
								'strong_sources',
								x
									.split( '\n' )
									.map( ( one ) => one.trim() )
									.filter( Boolean )
									.slice( 0, 50 )
							)
						}
					/>
				</Fieldset>

			</SettingsForm>
			<LeadIntakePanel />
		</>
	);
}
