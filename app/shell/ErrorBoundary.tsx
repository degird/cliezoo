/**
 * A small error boundary, so one broken part of a page cannot take the
 * whole screen down with it.
 */
import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
	children: ReactNode;
	fallback: ReactNode;
	/** Called with the error, for logging or reporting. */
	onError?: ( error: Error, info: ErrorInfo ) => void;
}

export class ErrorBoundary extends Component< Props, { failed: boolean } > {
	state = { failed: false };

	static getDerivedStateFromError() {
		return { failed: true };
	}

	componentDidCatch( error: Error, info: ErrorInfo ) {
		this.props.onError?.( error, info );
	}

	render() {
		return this.state.failed ? this.props.fallback : this.props.children;
	}
}
