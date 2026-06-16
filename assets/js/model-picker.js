/**
 * Settings > WebLLM model picker.
 *
 * A searchable (type-to-filter) model selector built with the WordPress
 * ComboboxControl. The model list is read live from WebLLM's own
 * `prebuiltAppConfig.model_list` (via dynamic import of the package), so nothing
 * about the available models is hardcoded in PHP. The chosen `model_id` is mirrored
 * into a hidden input so the standard Settings API form saves it.
 *
 * Buildless on purpose: uses the registered `wp-element` / `wp-components` globals
 * (no JSX / webpack). web-llm is pulled with a dynamic import (unpinned = latest);
 * the same package will power inference, so this also primes its cache.
 */
( function ( wp ) {
	if ( ! wp || ! wp.element || ! wp.components || ! wp.components.ComboboxControl ) {
		return;
	}

	const { createElement: el, useState, useEffect, createRoot, render } = wp.element;
	const { ComboboxControl } = wp.components;
	const { __, sprintf } = wp.i18n;

	const mount = document.getElementById( 'ai-provider-webllm-model-picker' );
	const input = document.getElementById( 'ai_provider_webllm_model_input' );

	if ( ! mount || ! input ) {
		return;
	}

	const initial = mount.dataset.selected || '';

	/**
	 * Maps a WebLLM model_list entry to a ComboboxControl option.
	 *
	 * @param {Object} model A `prebuiltAppConfig.model_list` entry.
	 * @return {{value: string, label: string}} The option.
	 */
	function toOption( model ) {
		let label = model.id;
		if ( model.vram > 0 ) {
			label +=
				' — ' +
				sprintf(
					/* translators: %s: approximate model size in gigabytes. */
					__( '≈%s GB', 'ai-provider-for-webllm' ),
					( model.vram / 1024 ).toFixed( 1 )
				);
		}
		if ( model.low ) {
			label += ' · ' + __( 'runs on low-end devices', 'ai-provider-for-webllm' );
		}
		return { value: model.id, label };
	}

	function ModelPicker() {
		const [ value, setValue ] = useState( initial );
		const [ options, setOptions ] = useState(
			initial ? [ { value: initial, label: initial } ] : []
		);
		const [ loading, setLoading ] = useState( true );

		// Load WebLLM's real catalogue once.
		useEffect( () => {
			let active = true;

			import( 'https://esm.run/@mlc-ai/web-llm' )
				.then( ( mod ) => {
					if ( ! active ) {
						return;
					}

					const list = ( ( mod.prebuiltAppConfig && mod.prebuiltAppConfig.model_list ) || [] )
						// Drop embedding models — this is a text-generation provider.
						.filter( ( m ) => m.model_id && ! /embed/i.test( m.model_id ) )
						.map( ( m ) => ( {
							id: m.model_id,
							vram: Number( m.vram_required_MB ) || 0,
							low: !! m.low_resource_required,
						} ) )
						.sort( ( a, b ) => a.vram - b.vram )
						.map( toOption );

					// Keep the saved model selectable even if it left the catalogue.
					if ( initial && ! list.some( ( o ) => o.value === initial ) ) {
						list.unshift( {
							value: initial,
							label: initial + ' ' + __( '(saved)', 'ai-provider-for-webllm' ),
						} );
					}

					setOptions( list.length ? list : ( initial ? [ { value: initial, label: initial } ] : [] ) );
					setLoading( false );
				} )
				.catch( ( error ) => {
					if ( ! active ) {
						return;
					}
					// eslint-disable-next-line no-console
					console.error( 'WebLLM: could not load the model catalogue.', error );
					setLoading( false );
				} );

			return () => {
				active = false;
			};
		}, [] );

		// Mirror the selection into the hidden field the settings form submits.
		useEffect( () => {
			input.value = value || '';
		}, [ value ] );

		return el( ComboboxControl, {
			label: __( 'Active model', 'ai-provider-for-webllm' ),
			hideLabelFromVision: true,
			value,
			options,
			onChange: ( next ) => setValue( next || '' ),
			help: loading
				? __( 'Loading models from WebLLM…', 'ai-provider-for-webllm' )
				: undefined,
			allowReset: false,
			// ComboboxControl forwards `className` (not `style`) to its root, so the
			// white input background is applied via CSS on this class — see model-picker.css.
			className: 'ai-provider-webllm-combobox',
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
		} );
	}

	const element = el( ModelPicker );

	if ( typeof createRoot === 'function' ) {
		createRoot( mount ).render( element );
	} else {
		render( element, mount );
	}
} )( window.wp );
