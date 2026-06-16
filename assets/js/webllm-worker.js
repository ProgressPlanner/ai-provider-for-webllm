/**
 * WebLLM browser worker (runs on admin pages).
 *
 * Spins up the WebLLM engine in a dedicated Web Worker, then runs a loop against
 * the PHP bridge: long-poll `/poll` for a job, run it with
 * `engine.chat.completions.create()`, post the completion back to `/result`.
 * This is what makes PHP-initiated generation work.
 *
 * Config is provided by wp_localize_script as `window.aiProviderWebllmWorker`.
 */
( function () {
	const cfg = window.aiProviderWebllmWorker || {};

	if ( ! cfg.enabled || ! cfg.model ) {
		return;
	}

	// WebGPU requires a secure context (HTTPS or localhost).
	if ( ! window.isSecureContext ) {
		setStatus(
			'unavailable — needs a secure context (HTTPS or localhost). This site is served over ' +
				'http://, so the browser disables WebGPU.'
		);
		return;
	}

	if ( ! ( 'gpu' in navigator ) ) {
		setStatus( 'unavailable — WebGPU not available (update the browser, or enable it in flags)' );
		return;
	}

	const headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce };

	main().catch( ( error ) => setStatus( 'error: ' + message( error ) ) );

	async function main() {
		const webllm = await import( 'https://esm.run/@mlc-ai/web-llm' );

		setStatus( 'loading model…' );
		const worker = new Worker( cfg.workerUrl, { type: 'module' } );
		const engine = await webllm.CreateWebWorkerMLCEngine( worker, cfg.model, {
			initProgressCallback: ( report ) => {
				const pct = Math.round( ( report.progress || 0 ) * 100 );
				setStatus( 'loading model… ' + pct + '%' );
			},
		} );

		setStatus( 'ready' );
		await pollLoop( engine );
	}

	async function pollLoop( engine ) {
		// eslint-disable-next-line no-constant-condition
		while ( true ) {
			let job;
			try {
				const res = await fetch( cfg.restUrl + '/poll', {
					method: 'POST',
					headers,
					body: JSON.stringify( { ready: true, model: cfg.model } ),
				} );
				job = ( await res.json() ).job;
			} catch ( error ) {
				await sleep( 2000 );
				continue;
			}

			if ( ! job ) {
				continue;
			}

			try {
				// The engine is already bound to a model; drop the redundant `model` field.
				const { model, ...params } = job.payload || {};
				const completion = await engine.chat.completions.create( params );
				await report( job.id, { result: completion } );
			} catch ( error ) {
				await report( job.id, { error: message( error ) } );
			}
		}
	}

	function report( id, extra ) {
		return fetch( cfg.restUrl + '/result', {
			method: 'POST',
			headers,
			body: JSON.stringify( Object.assign( { id }, extra ) ),
		} );
	}

	function sleep( ms ) {
		return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
	}

	function message( error ) {
		return ( error && error.message ) || String( error );
	}

	function setStatus( text ) {
		const el = document.getElementById( 'ai-provider-webllm-worker-status' );
		if ( el ) {
			el.textContent = 'WebLLM worker: ' + text;
		}
		// eslint-disable-next-line no-console
		if ( window.console ) {
			console.log( '[WebLLM worker]', text );
		}
	}
} )();
