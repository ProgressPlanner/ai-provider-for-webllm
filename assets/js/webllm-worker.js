/**
 * WebLLM browser worker (runs on admin pages).
 *
 * Spins up the WebLLM engine in a dedicated Web Worker, then runs a loop against
 * the PHP bridge: long-poll `/poll` for a job, run it with
 * `engine.chat.completions.create()`, post the completion back to `/result`.
 * A separate timer posts `/heartbeat` so the worker's liveness is tracked even
 * while it is busy running an inference (and therefore not polling). This is what
 * makes PHP-initiated generation work.
 *
 * Config is provided by wp_localize_script as `window.aiProviderWebllmWorker`.
 */
/* global Worker */
( function () {
	const cfg = window.aiProviderWebllmWorker || {};
	const i18n = ( window.wp && window.wp.i18n ) || {
		__: ( s ) => s,
		sprintf: ( f ) => f,
	};
	const { __, sprintf } = i18n;
	const HEARTBEAT_MS = 10000;

	if ( ! cfg.enabled || ! cfg.model ) {
		return;
	}

	// WebGPU requires a secure context (HTTPS or localhost).
	if ( ! window.isSecureContext ) {
		setStatus(
			__(
				'unavailable — needs a secure context (HTTPS or localhost) so the browser enables WebGPU',
				'ai-provider-for-webllm'
			)
		);
		return;
	}

	if ( ! ( 'gpu' in navigator ) ) {
		setStatus(
			__(
				'unavailable — WebGPU is not available in this browser',
				'ai-provider-for-webllm'
			)
		);
		return;
	}

	const headers = {
		'Content-Type': 'application/json',
		'X-WP-Nonce': cfg.nonce,
	};

	main().catch( ( error ) =>
		setStatus(
			sprintf(
				/* translators: %s: WebLLM worker error message. */
				__( 'error: %s', 'ai-provider-for-webllm' ),
				message( error )
			)
		)
	);

	async function main() {
		// eslint-disable-next-line import/no-unresolved
		const webllm = await import( 'https://esm.run/@mlc-ai/web-llm@0.2.84' );

		setStatus( __( 'loading model…', 'ai-provider-for-webllm' ) );
		const worker = new Worker( cfg.workerUrl, { type: 'module' } );
		const engine = await webllm.CreateWebWorkerMLCEngine(
			worker,
			cfg.model,
			{
				initProgressCallback: ( progressReport ) => {
					const pct = Math.round(
						( progressReport.progress || 0 ) * 100
					);
					setStatus(
						sprintf(
							/* translators: %d: WebLLM model loading progress percentage. */
							__(
								'loading model… %d%%',
								'ai-provider-for-webllm'
							),
							pct
						)
					);
				},
			}
		);

		setStatus( __( 'ready', 'ai-provider-for-webllm' ) );
		startHeartbeat();
		await pollLoop( engine );
	}

	// Keep reporting liveness independently of the job loop, so a long inference
	// (during which we are not polling) does not look like a disconnected worker.
	function startHeartbeat() {
		setInterval( () => {
			fetch( cfg.restUrl + '/heartbeat', {
				method: 'POST',
				headers,
				body: JSON.stringify( { ready: true, model: cfg.model } ),
			} ).catch( () => {} );
		}, HEARTBEAT_MS );
	}

	async function pollLoop( engine ) {
		while ( true ) {
			let job;
			try {
				const res = await fetch( cfg.restUrl + '/poll', {
					method: 'POST',
					headers,
					body: JSON.stringify( { ready: true, model: cfg.model } ),
				} );
				job = ( await res.json() ).job;
			} catch {
				await sleep( 2000 );
				continue;
			}

			if ( ! job ) {
				continue;
			}

			try {
				// The engine is already bound to a model; drop the redundant `model` field.
				const { model, ...params } = job.payload || {};
				const completion =
					await engine.chat.completions.create( params );
				await report( job.id, job.claim_token, { result: completion } );
			} catch ( error ) {
				await report( job.id, job.claim_token, {
					error: message( error ),
				} );
			}
		}
	}

	function report( id, claimToken, extra ) {
		return fetch( cfg.restUrl + '/result', {
			method: 'POST',
			headers,
			body: JSON.stringify(
				Object.assign( { id, claim_token: claimToken || '' }, extra )
			),
		} );
	}

	function sleep( ms ) {
		return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
	}

	function message( error ) {
		return ( error && error.message ) || String( error );
	}

	function setStatus( text ) {
		const el = document.getElementById(
			'ai-provider-webllm-worker-status'
		);
		if ( el ) {
			el.textContent = sprintf(
				/* translators: %s: WebLLM worker status. */
				__( 'WebLLM worker: %s', 'ai-provider-for-webllm' ),
				text
			);
		}
	}
} )();
