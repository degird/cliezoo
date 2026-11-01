/**
 * Home card: what is left to set up. Every line is derived from the
 * workspace itself, so it cannot claim something is done when it is not,
 * and the whole card goes away once there is nothing left (or when it is
 * put away).
 */
import { Link } from '@tanstack/react-router';
import { __, sprintf } from '@wordpress/i18n';
import { Check, Circle } from 'lucide-react';
import { api, useQuery, useQueryClient } from '@cliezoo/data';
import { Button, Skeleton, toast } from '@cliezoo/ui';

interface Step {
	id: string;
	label: string;
	done: boolean;
	to: string;
}

interface SetupState {
	steps: Step[];
	remaining: number;
	dismissed: boolean;
}

export default function SetupChecklist() {
	const client = useQueryClient();
	const setup = useQuery( {
		queryKey: [ 'setup' ],
		queryFn: async () => ( await api.get< SetupState >( '/setup' ) ).data,
	} );

	const dismiss = async () => {
		try {
			await api.post( '/setup/finish', { dismiss: true } );
			await client.invalidateQueries( { queryKey: [ 'setup' ] } );
			toast.success(
				__( 'Put away. Settings has everything.', 'cliezoo' )
			);
		} catch ( error ) {
			toast.error( ( error as Error ).message );
		}
	};

	if ( setup.isPending ) {
		return <Skeleton className="h-24" />;
	}
	// A checklist that could not be read stays out of the way rather than
	// shouting on the home screen; the Setup screen itself reports it.
	if ( setup.isError ) {
		return null;
	}
	const data = setup.data;
	if ( ! data || data.dismissed || 0 === data.remaining ) {
		return null;
	}

	return (
		<div className="space-y-3">
			<p className="text-sm text-muted">
				{ sprintf(
					/* translators: 1: how many are done, 2: how many there are. */
					__( '%1$d of %2$d done', 'cliezoo' ),
					data.steps.length - data.remaining,
					data.steps.length
				) }
			</p>
			<ul className="space-y-1.5">
				{ data.steps.map( ( step ) => (
					<li key={ step.id } className="flex items-center gap-2.5">
						{ step.done ? (
							<Check
								size={ 16 }
								className="shrink-0 text-success"
								aria-hidden="true"
							/>
						) : (
							<Circle
								size={ 16 }
								className="shrink-0 text-subtle"
								aria-hidden="true"
							/>
						) }
						{ step.done ? (
							<span className="text-sm text-muted line-through">
								{ step.label }
							</span>
						) : (
							<Link
								to={ step.to }
								className="text-sm text-fg hover:underline"
							>
								{ step.label }
							</Link>
						) }
					</li>
				) ) }
			</ul>
			<Button variant="ghost" size="sm" onPress={ dismiss }>
				{ __( 'Put this away', 'cliezoo' ) }
			</Button>
		</div>
	);
}
