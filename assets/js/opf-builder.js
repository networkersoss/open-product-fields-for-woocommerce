/**
 * OPF field builder. Dependency-free vanilla JS — no jQuery, no build step.
 * Edits a JSON model and persists through the REST API.
 */
( function () {
	'use strict';

	// wp-i18n is an enqueued dependency in wp-admin; the English fallback keeps
	// the file runnable in sandboxed tests where no wp global exists.
	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ )
		? window.wp.i18n.__
		: function ( text ) { return text; };
	var sprintf = ( window.wp && window.wp.i18n && window.wp.i18n.sprintf )
		? window.wp.i18n.sprintf
		: function ( format ) {
			var args = Array.prototype.slice.call( arguments, 1 );
			var auto = 0;
			return format.replace( /%(\d+)\$([sd])|%([sd])/g, function ( match, pos, type, autoType ) {
				var i = pos ? parseInt( pos, 10 ) - 1 : auto++;
				return String( args[ i ] );
			} );
		};

	var mount = document.getElementById( 'opf-builder-app' );
	if ( ! mount ) {
		return;
	}

	var productCats = [];
	try {
		productCats = JSON.parse( mount.dataset.productCats || '[]' ) || [];
	} catch ( e ) {
		productCats = [];
	}

	var postId = parseInt( mount.dataset.postId, 10 ) || 0;
	var model = JSON.parse( mount.dataset.model || '{}' );
	var nonce = mount.dataset.nonce;
	var restUrl = mount.dataset.rest;
	var previewRest = mount.dataset.previewRest;

	model.fields = model.fields || [];
	model.rule_groups = model.rule_groups || [];
	model.image_rules = model.image_rules || [];
	model.lookup_tables = model.lookup_tables || {};
	model.formula_variables = model.formula_variables || {};

	var TYPES = [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'upload', 'toggle', 'select', 'radio', 'checkbox', 'swatch', 'image_quantity', 'products', 'paragraph', 'content_image', 'section', 'section_end', 'calc' ];
	var PRICING = [ 'none', 'fixed', 'percent', 'formula' ];
	var REPEATABLE_TYPES = [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'select', 'radio', 'checkbox', 'swatch' ];
	// Accepted-type choices mirror WAPF Extended's `accept` option, which lists
	// the keys of WordPress `get_allowed_mime_types()` minus WAPF's executable
	// deny-list. Values are the same mime-group keys WAPF stores; the OPF schema
	// expands each `a|b` group into its individual extensions on save/render.
	var UPLOAD_TYPE_GROUPS = '3g2|3gp2 3gp|3gpp 7z aac asf|asx avi avif bmp class css csv dfxp divx doc docm docx dotm dotx flac flv gif gz|gzip heic heics heif heifs ico ics jpg|jpeg|jpe key mdb mid|midi mka mkv mov|qt mp3|m4a|m4b mp4|m4v mpeg|mpg|mpe mpp numbers odb odc odf odg odp ods odt ogg|oga ogv onetoc|onetoc2|onetmp|onepkg oxps pages pdf png potm potx pot|pps|ppt ppam ppsm ppsx pptm pptx psd rar ra|ram rtf rtx sldm sldx tar tiff|tif tsv txt|asc|c|cc|h|srt vtt wav|x-wav wax webm webp wm wma wmv wmx wp|wpd wri xcf xlam xla|xls|xlt|xlw xlsb xlsm xlsx xltm xltx xps zip'.split( ' ' );

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( 'button' === tag && ( ! attrs || undefined === attrs.type ) ) {
			node.type = 'button';
		}
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( 'class' === k ) {
					node.className = attrs[ k ];
				} else if ( 'text' === k ) {
					node.textContent = attrs[ k ];
				} else if ( 'html' === k ) {
					node.innerHTML = attrs[ k ];
				} else if ( 'value' === k ) {
					node.value = attrs[ k ];
				} else if ( 0 === k.indexOf( 'on' ) ) {
					node.addEventListener( k.slice( 2 ), attrs[ k ] );
				} else {
					node.setAttribute( k, attrs[ k ] );
				}
			} );
		}
		( children || [] ).forEach( function ( c ) {
			if ( c ) {
				node.appendChild( c );
			}
		} );
		return node;
	}

	function slugify( text ) {
		return String( text )
			.toLowerCase()
			.normalize( 'NFKD' )
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.slice( 0, 40 );
	}

	function uniqueId( base ) {
		var id = base || 'field';
		var n = 2;
		var taken = {};
		model.fields.forEach( function ( f ) {
			taken[ f.id ] = true;
		} );
		while ( taken[ id ] ) {
			id = base + '-' + n;
			n++;
		}
		return id;
	}

	function choiceRow( field, choice, index ) {
		var slugInput = el( 'input', { class: 'opf-b-input opf-b-slug', value: choice.slug, placeholder: __( 'slug', 'open-product-fields-for-woocommerce' ), oninput: function ( e ) {
			choice.slug = e.target.value;
		} } );
		var labelInput = el( 'input', { class: 'opf-b-input', value: choice.label, oninput: function ( e ) {
			choice.label = e.target.value;
		} } );
		var typeSelect = el( 'select', { class: 'opf-b-input' },
			PRICING.map( function ( t ) {
				var o = el( 'option', { value: t, text: t } );
				if ( t === ( choice.pricing.type || 'none' ) ) {
					o.selected = true;
				}
				return o;
			} )
		);
		typeSelect.addEventListener( 'change', function () {
			choice.pricing.type = typeSelect.value;
			if ( [ 'fixed', 'percent' ].indexOf( typeSelect.value ) !== -1 ) {
				choice.pricing.per_unit = 'fixed' !== typeSelect.value;
			} else {
				delete choice.pricing.per_unit;
			}
			rerender();
		} );
		var amountInput = el( 'input', { class: 'opf-b-input', type: 'text', value: choice.pricing.amount || '', placeholder: __( 'amount', 'open-product-fields-for-woocommerce' ) } );
		amountInput.addEventListener( 'input', function ( e ) {
			choice.pricing.amount = parseFloat( e.target.value ) || 0;
		} );
		var formulaInput = el( 'input', { class: 'opf-b-input', type: 'text', value: choice.pricing.formula || '', placeholder: '([price] + [addons]) * 0.2', title: __( 'Also supports acf(field_name) and acf_option(field_name) for numeric ACF fields.', 'open-product-fields-for-woocommerce' ) } );
		formulaInput.addEventListener( 'input', function ( e ) {
			choice.pricing.formula = e.target.value;
		} );
		// WAPF per-choice per-unit toggle for priced amounts.
		var pricingOptions = [];
		if ( [ 'fixed', 'percent' ].indexOf( choice.pricing.type ) !== -1 ) {
			var perUnit = el( 'input', { type: 'checkbox' } );
			perUnit.checked = undefined !== choice.pricing.per_unit ? !! choice.pricing.per_unit : 'fixed' !== choice.pricing.type;
			perUnit.addEventListener( 'change', function () {
				choice.pricing.per_unit = perUnit.checked;
			} );
			pricingOptions.push( el( 'label', { class: 'opf-b-pricing-unit' }, [ perUnit, document.createTextNode( ' ' + __( 'Multiply by product quantity', 'open-product-fields-for-woocommerce' ) ) ] ) );
		}
		var selected = el( 'input', { type: 'checkbox', title: __( 'Preselected', 'open-product-fields-for-woocommerce' ) } );
		selected.checked = !! choice.selected;
		selected.disabled = !! choice.disabled;
		selected.addEventListener( 'change', function () {
			choice.selected = selected.checked;
		} );
		var disabled = el( 'input', { type: 'checkbox', title: sprintf( /* translators: %s: choice label. */ __( 'Unavailable option: %s', 'open-product-fields-for-woocommerce' ), choice.label || '' ) } );
		disabled.checked = !! choice.disabled;
		var flags = el( 'div', { class: 'opf-b-choice-flags' }, [
			el( 'label', { class: 'opf-b-choice-flag' }, [ selected, el( 'span', { text: __( 'Default', 'open-product-fields-for-woocommerce' ) } ) ] ),
			el( 'label', { class: 'opf-b-choice-flag' }, [ disabled, el( 'span', { text: __( 'Unavailable', 'open-product-fields-for-woocommerce' ) } ) ] ),
		] );
		disabled.addEventListener( 'change', function () {
			choice.disabled = disabled.checked;
			selected.disabled = disabled.checked;
			if ( choice.disabled ) {
				choice.selected = false;
				selected.checked = false;
			}
		} );
		var remove = el( 'button', { type: 'button', class: 'button-link opf-b-remove', text: __( '×', 'open-product-fields-for-woocommerce' ), onclick: function () {
			field.choices.splice( index, 1 );
			rerender();
		} } );

		var row = el( 'div', { class: 'opf-b-choice' }, [
			flags, slugInput, labelInput, typeSelect,
			'formula' === choice.pricing.type ? formulaInput : amountInput,
			...pricingOptions,
			remove
		] );
		// WAPF radio-card layout: each card choice carries an optional image and
		// description, edited independently of the field's generic image input.
		if ( 'radio' === field.type && field.card_layout ) {
			var cardImageInput = el( 'input', { class: 'opf-b-input', type: 'url', value: choice.image || '', placeholder: __( 'Card image URL (optional)', 'open-product-fields-for-woocommerce' ) } );
			cardImageInput.addEventListener( 'input', function ( e ) { choice.image = e.target.value; } );
			var cardDescriptionInput = el( 'input', { class: 'opf-b-input', value: choice.description || '', placeholder: __( 'Card description (optional)', 'open-product-fields-for-woocommerce' ) } );
			cardDescriptionInput.addEventListener( 'input', function ( e ) { choice.description = e.target.value; } );
			row.appendChild( cardImageInput );
			row.appendChild( cardDescriptionInput );
		}
		if ( ! [ 'swatch', 'image_quantity' ].includes( field.type ) ) {
			return row;
		}
		var extras = [];
		if ( 'swatch' === field.type && 'color' === field.swatch_style ) {
			var colorInput = el( 'input', { class: 'opf-b-input', type: 'color', value: choice.color || '#ffffff', 'aria-label': __( 'Swatch color', 'open-product-fields-for-woocommerce' ) } );
			colorInput.addEventListener( 'input', function () { choice.color = colorInput.value.toUpperCase(); } );
			extras.push( colorInput );
		}

		var imageUrl = el( 'input', { class: 'opf-b-input opf-b-choice-image-url', type: 'url', value: choice.image || '', placeholder: __( 'Image URL (optional)', 'open-product-fields-for-woocommerce' ) } );
		imageUrl.addEventListener( 'input', function () {
			if ( imageUrl.value.trim() ) {
				choice.image = imageUrl.value.trim();
				if ( 'swatch' === field.type ) field.swatch_style = 'image';
			}
			else delete choice.image;
			delete choice.image_id;
		} );
		var chooseImage = el( 'button', { class: 'button', type: 'button', text: __( 'Choose image', 'open-product-fields-for-woocommerce' ), onclick: function () {
			if ( ! window.wp || ! window.wp.media ) {
				window.alert( __( 'The WordPress Media Library is unavailable on this screen.', 'open-product-fields-for-woocommerce' ) );
				return;
			}
			var frame = window.wp.media( {
				title: __( 'Choose swatch image', 'open-product-fields-for-woocommerce' ),
				button: { text: __( 'Use image', 'open-product-fields-for-woocommerce' ) },
				library: { type: 'image' },
				multiple: false,
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				if ( ! attachment || ! attachment.id || ! attachment.url ) return;
				choice.image_id = Number( attachment.id );
				choice.image = attachment.url;
				if ( 'swatch' === field.type ) field.swatch_style = 'image';
				imageUrl.value = attachment.url;
			} );
			frame.open();
		} } );
		var imageControls = el( 'div', { class: 'opf-b-choice-image' }, [ imageUrl, chooseImage ] );
		extras.push( imageControls );
		return el( 'div', { class: 'opf-b-choice-with-image' }, [ row ].concat( extras ) );
	}

	// WAPF bulk-import parity: one line per choice — `label`, or
	// `label<TAB>value<TAB>fixed price`. The import is atomic: any malformed
	// line aborts with a per-line error and no partial mutation. Color swatch
	// fields additionally accept `Label, #hex` in the label column.
	function bulkChoiceImport( field, list ) {
		var color = 'swatch' === field.type && 'color' === field.swatch_style;
		var bulkHelpId = 'opf-b-bulk-choice-help-' + String( field.id || '' ).replace( /[^a-zA-Z0-9_-]/g, '' );
		var status = el( 'span', { class: 'opf-b-status', 'data-opf-bulk-choice-status': '1', role: 'status', 'aria-live': 'polite' } );
		var input = el( 'textarea', {
			class: 'opf-b-input opf-b-bulk-choices',
			rows: '5',
			title: __( 'Bulk choice options', 'open-product-fields-for-woocommerce' ),
			'aria-label': __( 'Choices to import', 'open-product-fields-for-woocommerce' ),
			'aria-describedby': bulkHelpId,
			placeholder: color ? __( 'White, #ffffff\nRed, #ff0000', 'open-product-fields-for-woocommerce' ) : 'Small\tS\nLarge\tL\t2.50',
		} );
		var bulkReplace = el( 'input', { type: 'checkbox', title: __( 'Replace existing choices before import', 'open-product-fields-for-woocommerce' ) } );
		var button = el( 'button', { type: 'button', class: 'btn button', text: __( 'Import choices', 'open-product-fields-for-woocommerce' ), onclick: function () {
			var imported = [];
			var used = Object.create( null );
			if ( ! bulkReplace.checked ) {
				field.choices.forEach( function ( choice ) { used[ String( choice.slug || '' ) ] = true; } );
			}
			var lines = input.value.split( /\r?\n/ );
			for ( var lineIndex = 0; lineIndex < lines.length; lineIndex++ ) {
				var rawLine = lines[ lineIndex ].trim();
				if ( '' === rawLine ) continue;
				var columns = rawLine.split( '\t' ).map( function ( column ) { return column.trim(); } );
				if ( columns.length > 3 || '' === columns[ 0 ] || ( columns.length > 1 && '' === columns[ 1 ] ) ) {
					status.textContent = sprintf( /* translators: %d: line number. */ __( 'Line %d needs a label and, when provided, a value and numeric price.', 'open-product-fields-for-woocommerce' ), lineIndex + 1 );
					return;
				}
				var labelText = columns[ 0 ];
				var slug = columns.length > 1 ? columns[ 1 ] : slugify( labelText );
				if ( '' === slug ) slug = 'option-' + ( field.choices.length + imported.length + 1 );
				if ( used[ slug ] ) {
					if ( columns.length > 1 ) {
						status.textContent = sprintf( /* translators: %d: line number. */ __( 'Line %d duplicates an existing choice value.', 'open-product-fields-for-woocommerce' ), lineIndex + 1 );
						return;
					}
					var baseSlug = slug;
					var suffix = 2;
					while ( used[ slug ] ) slug = baseSlug + '-' + suffix++;
				}
				used[ slug ] = true;
				var pricing = { type: 'none', amount: 0, formula: '' };
				if ( columns.length === 3 ) {
					var amount = Number( columns[ 2 ] );
					if ( ! /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/.test( columns[ 2 ] ) || ! Number.isFinite( amount ) ) {
						status.textContent = sprintf( /* translators: %d: line number. */ __( 'Line %d price must be a finite number.', 'open-product-fields-for-woocommerce' ), lineIndex + 1 );
						return;
					}
					pricing = { type: 'fixed', amount: amount, formula: '' };
				}
				var choice = { slug: slug, label: labelText, selected: false, disabled: false, pricing: pricing };
				if ( 'image_quantity' === field.type ) choice.quantity = { default: 0, min: 0, max: 999999 };
				if ( color ) {
					var colorParts = labelText.split( ',' );
					if ( colorParts.length === 2 && /^#[0-9a-f]{3}(?:[0-9a-f]{3}|[0-9a-f]{5})?$/i.test( colorParts[ 1 ].trim() ) ) {
						choice.label = colorParts[ 0 ].trim();
						choice.color = colorParts[ 1 ].trim().toUpperCase();
					}
				}
				imported.push( choice );
			}
			if ( ! imported.length ) {
				status.textContent = __( 'Enter at least one non-empty choice.', 'open-product-fields-for-woocommerce' );
				return;
			}
			field.choices = bulkReplace.checked ? imported : field.choices.concat( imported );
			rerender();
		} } );
		return el( 'details', { class: 'opf-b-bulk-import' }, [
			el( 'summary', { text: __( 'Bulk import choices', 'open-product-fields-for-woocommerce' ) } ),
			labeledControl( __( 'Choices to import', 'open-product-fields-for-woocommerce' ), input ),
			el( 'p', { id: bulkHelpId, class: 'description', text: __( 'Paste one choice per line: label, or label + Tab + value + optional Tab + fixed price. Blank lines are ignored; options append unless Replace is checked.', 'open-product-fields-for-woocommerce' ) + ( color ? __( ' For colors, use Label, #ffffff. Missing or invalid colors use white.', 'open-product-fields-for-woocommerce' ) : '' ) } ),
			el( 'label', { class: 'opf-b-bulk-replace' }, [ bulkReplace, document.createTextNode( ' ' + __( 'Replace existing choices', 'open-product-fields-for-woocommerce' ) ) ] ),
			button,
			status,
		] );
	}

	function labeledControl( label, control ) {
		return el( 'label', { class: 'opf-b-conditional-control' }, [
			document.createTextNode( label ),
			control,
		] );
	}

	// Upload field options mirror WAPF Extended's `file` field surface:
	// `multiple`, `accept` (allowed types) and `maxsize` (MB). OPF never exposes
	// upload pricing because the schema rejects it.
	function uploadEditor( field ) {
		var editor = el( 'div', { class: 'opf-b-upload-settings' } );
		var multiple = el( 'input', { type: 'checkbox', 'data-opf-upload-setting': 'multiple' } );
		multiple.checked = !! field.multiple;
		multiple.addEventListener( 'change', function () { field.multiple = multiple.checked; } );
		editor.appendChild( labeledControl( __( 'Allow multiple files', 'open-product-fields-for-woocommerce' ), multiple ) );

		var selectedTypes = Array.isArray( field.accepted_types )
			? field.accepted_types.slice()
			: ( field.accepted_types ? String( field.accepted_types ).split( /[\s,]+/ ) : [] );
		var selected = {};
		selectedTypes.forEach( function ( entry ) {
			String( entry ).split( '|' ).forEach( function ( extension ) {
				if ( extension ) selected[ extension.toLowerCase().replace( /^\./, '' ) ] = true;
			} );
		} );
		var types = el( 'select', { class: 'opf-b-input opf-b-upload-types', multiple: 'multiple', size: '8', 'data-opf-upload-setting': 'accepted_types', 'aria-label': __( 'Accepted file types', 'open-product-fields-for-woocommerce' ) } );
		UPLOAD_TYPE_GROUPS.forEach( function ( group ) {
			var option = el( 'option', { value: group, text: group } );
			option.selected = group.split( '|' ).every( function ( extension ) { return selected[ extension ]; } );
			types.appendChild( option );
		} );
		types.addEventListener( 'change', function () {
			field.accepted_types = Array.prototype.slice.call( types.options )
				.filter( function ( option ) { return option.selected; } )
				.map( function ( option ) { return option.value; } );
		} );
		editor.appendChild( labeledControl( __( 'Accepted file types', 'open-product-fields-for-woocommerce' ), types ) );
		editor.appendChild( el( 'p', { class: 'description', text: __( 'Leave unselected to allow every WordPress-permitted file type.', 'open-product-fields-for-woocommerce' ) } ) );

		var size = el( 'input', { class: 'opf-b-input', type: 'number', min: '0', step: '0.1', value: null === field.max_size || undefined === field.max_size ? '' : field.max_size, 'data-opf-upload-setting': 'max_size', 'aria-label': __( 'Maximum file size (MB)', 'open-product-fields-for-woocommerce' ) } );
		size.addEventListener( 'input', function () {
			if ( '' !== size.value ) field.max_size = Math.max( 0, Number( size.value ) || 0 );
			else delete field.max_size;
		} );
		editor.appendChild( labeledControl( __( 'Maximum file size (MB)', 'open-product-fields-for-woocommerce' ), size ) );
		editor.appendChild( el( 'p', { class: 'description', text: __( 'WAPF default is 1 MB. PHP and WordPress limits are always enforced server-side.', 'open-product-fields-for-woocommerce' ) } ) );

		// WAPF upload parity: file-count bounds, minimum size/dimensions, the
		// automatic resize flag, the pre-upload image editor, and the free-form
		// MIME allow-list. Values are stored verbatim (strings) — the schema
		// casts and clamps on save.
		function uploadConstraint( key, label, step ) {
			var input = el( 'input', { class: 'opf-b-input', type: 'number', value: field[ key ] === undefined ? '' : field[ key ], placeholder: label } );
			if ( step ) input.setAttribute( 'step', step );
			input.addEventListener( 'input', function ( e ) {
				if ( '' === e.target.value ) {
					delete field[ key ];
				} else {
					field[ key ] = e.target.value;
				}
			} );
			return labeledControl( label, input );
		}
		var constraintBox = el( 'div', { class: 'opf-b-constraints' } );
		constraintBox.appendChild( uploadConstraint( 'min_files', __( 'Minimum files', 'open-product-fields-for-woocommerce' ) ) );
		constraintBox.appendChild( uploadConstraint( 'max_files', __( 'Maximum files (-1 for unlimited)', 'open-product-fields-for-woocommerce' ) ) );
		constraintBox.appendChild( uploadConstraint( 'min_size_mb', __( 'Minimum file size (MB)', 'open-product-fields-for-woocommerce' ), '0.01' ) );
		constraintBox.appendChild( uploadConstraint( 'min_width', __( 'Minimum image width (pixels)', 'open-product-fields-for-woocommerce' ) ) );
		constraintBox.appendChild( uploadConstraint( 'min_height', __( 'Minimum image height (pixels)', 'open-product-fields-for-woocommerce' ) ) );
		var resizeInput = el( 'input', { type: 'checkbox', 'data-opf-auto-resize': '1' } );
		resizeInput.checked = true === field.auto_resize;
		resizeInput.addEventListener( 'change', function () {
			if ( resizeInput.checked ) field.auto_resize = true;
			else delete field.auto_resize;
		} );
		constraintBox.appendChild( el( 'label', { class: 'opf-b-upload-resize' }, [ resizeInput, document.createTextNode( ' ' + __( 'Automatically resize images', 'open-product-fields-for-woocommerce' ) ) ] ) );
		constraintBox.appendChild( uploadConstraint( 'max_width', __( 'Maximum image width (pixels)', 'open-product-fields-for-woocommerce' ) ) );
		constraintBox.appendChild( uploadConstraint( 'max_height', __( 'Maximum image height (pixels)', 'open-product-fields-for-woocommerce' ) ) );
		var editorMode = el( 'select', { class: 'opf-b-input', 'data-opf-image-editor-mode': '1' }, [
			el( 'option', { value: '', text: __( 'Image editor disabled', 'open-product-fields-for-woocommerce' ) } ),
			el( 'option', { value: 'optional', text: __( 'Optional: edit button', 'open-product-fields-for-woocommerce' ) } ),
			el( 'option', { value: 'forced', text: __( 'Required: edit before upload', 'open-product-fields-for-woocommerce' ) } ),
		] );
		editorMode.value = field.image_editor_mode || '';
		editorMode.addEventListener( 'change', function () {
			if ( editorMode.value ) {
				field.image_editor_mode = editorMode.value;
				if ( undefined === field.image_editor_crop ) field.image_editor_crop = true;
				if ( undefined === field.image_editor_rotate ) field.image_editor_rotate = true;
				if ( undefined === field.image_editor_flip ) field.image_editor_flip = true;
				if ( undefined === field.image_editor_resize ) field.image_editor_resize = true;
				if ( undefined === field.image_editor_aspect_ratio ) field.image_editor_aspect_ratio = 'free';
			} else {
				[ 'image_editor_mode', 'image_editor_crop', 'image_editor_rotate', 'image_editor_flip', 'image_editor_resize', 'image_editor_aspect_ratio' ].forEach( function ( key ) { delete field[ key ]; } );
			}
		} );
		constraintBox.appendChild( labeledControl( __( 'Image editor', 'open-product-fields-for-woocommerce' ), editorMode ) );
		var editorControls = [];
		[ [ 'image_editor_crop', __( 'Allow crop', 'open-product-fields-for-woocommerce' ) ], [ 'image_editor_resize', __( 'Allow zoom/resize', 'open-product-fields-for-woocommerce' ) ], [ 'image_editor_rotate', __( 'Allow rotate', 'open-product-fields-for-woocommerce' ) ], [ 'image_editor_flip', __( 'Allow flip', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
			var checkbox = el( 'input', { type: 'checkbox' } );
			checkbox.checked = undefined === field[ setting[ 0 ] ] ? true : !! field[ setting[ 0 ] ];
			checkbox.addEventListener( 'change', function () { field[ setting[ 0 ] ] = checkbox.checked; } );
			editorControls.push( el( 'label', { class: 'opf-b-upload-editor-option' }, [ checkbox, document.createTextNode( ' ' + setting[ 1 ] ) ] ) );
		} );
		constraintBox.appendChild( el( 'div', { class: 'opf-b-upload-editor-options' }, editorControls ) );
		var aspectRatio = el( 'select', { class: 'opf-b-input', 'data-opf-image-editor-aspect': '1' }, [
			el( 'option', { value: 'free', text: __( 'Free crop', 'open-product-fields-for-woocommerce' ) } ),
			el( 'option', { value: '1:1', text: __( 'Square 1:1', 'open-product-fields-for-woocommerce' ) } ),
			el( 'option', { value: '4:3', text: '4:3' } ),
			el( 'option', { value: '3:2', text: '3:2' } ),
			el( 'option', { value: '16:9', text: '16:9' } ),
			el( 'option', { value: '2:3', text: '2:3' } ),
			el( 'option', { value: '9:16', text: '9:16' } ),
		] );
		aspectRatio.value = field.image_editor_aspect_ratio || 'free';
		aspectRatio.addEventListener( 'change', function () { field.image_editor_aspect_ratio = aspectRatio.value; } );
		constraintBox.appendChild( labeledControl( __( 'Editor aspect ratio', 'open-product-fields-for-woocommerce' ), aspectRatio ) );
		var typesInput = el( 'input', {
			class: 'opf-b-input',
			type: 'text',
			value: Array.isArray( field.allowed_types ) ? field.allowed_types.join( ', ' ) : '',
			placeholder: __( 'Allowed types (png, jpg, application/pdf)', 'open-product-fields-for-woocommerce' ),
		} );
		typesInput.addEventListener( 'input', function ( e ) {
			var types = e.target.value.split( ',' ).map( function ( t ) { return t.trim(); } ).filter( Boolean );
			if ( types.length ) field.allowed_types = types;
			else delete field.allowed_types;
		} );
		constraintBox.appendChild( labeledControl( __( 'Allowed types', 'open-product-fields-for-woocommerce' ), typesInput ) );
		editor.appendChild( constraintBox );
		return editor;
	}

	var PRODUCT_SUBTYPES = [ 'checkbox', 'radio', 'dropdown', 'image', 'card', 'vcard', 'card-qty', 'vcard-qty' ];
	var PRODUCT_QTY_SUBTYPES = [ 'card-qty', 'vcard-qty' ];
	var PRODUCT_CARD_SUBTYPES = [ 'card', 'vcard', 'card-qty', 'vcard-qty' ];

	function isProductsQtySubtype( field ) {
		return PRODUCT_QTY_SUBTYPES.indexOf( field.subtype ) !== -1;
	}

	function isProductsCardSubtype( field ) {
		return PRODUCT_CARD_SUBTYPES.indexOf( field.subtype ) !== -1;
	}

	// WAPF Extended 3.1.5 registers only `empty` (no quantity) and `!empty`
	// (any quantity) as conditional rules whose subject is a quantity-enabled
	// child-product card. The 3.1.6 changelog adds options without publishing
	// their keys; until those are known the WAPF 3.1.5 surface is offered.
	function isProductsQtyRuleSource( candidate ) {
		return !! candidate && 'products' === candidate.type && isProductsQtySubtype( candidate );
	}

	function productChoiceDefaults( productId, label ) {
		return {
			product_id: productId,
			slug: 'p' + productId,
			label: label || '',
			selected: false,
			disabled: false,
			pricing: { type: 'none', amount: 0, formula: '' },
			pricing_type: 'fixed',
			quantity: { default: 0, min: 0, max: 999999 },
		};
	}

	function productChoiceRow( field, choice, index ) {
		var qtySubtype = isProductsQtySubtype( field );
		var nameEl = el( 'span', { class: 'opf-b-product-name', text: ( choice.label || '#' + choice.product_id ) + ' — #' + choice.product_id } );
		var pricing = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Price', 'open-product-fields-for-woocommerce' ) }, [
			el( 'option', { value: 'fixed', text: __( 'Product price', 'open-product-fields-for-woocommerce' ) } ),
			el( 'option', { value: 'none', text: __( 'Free', 'open-product-fields-for-woocommerce' ) } ),
		] );
		pricing.value = choice.pricing_type || 'fixed';
		pricing.addEventListener( 'change', function () { choice.pricing_type = pricing.value; } );
		var cells = [ nameEl, pricing ];
		var selected = null;
		if ( ! qtySubtype ) {
			selected = el( 'input', { type: 'checkbox', title: __( 'Preselected', 'open-product-fields-for-woocommerce' ) } );
			selected.checked = !! choice.selected;
			selected.disabled = !! choice.disabled;
			selected.addEventListener( 'change', function () { choice.selected = selected.checked; } );
			cells.push( selected );
		}
		var disabled = el( 'input', { type: 'checkbox', title: __( 'Unavailable', 'open-product-fields-for-woocommerce' ) } );
		disabled.checked = !! choice.disabled;
		disabled.addEventListener( 'change', function () {
			choice.disabled = disabled.checked;
			if ( selected ) {
				selected.disabled = disabled.checked;
				if ( choice.disabled ) {
					choice.selected = false;
					selected.checked = false;
				}
			}
		} );
		cells.push( disabled );
		if ( qtySubtype ) {
			choice.quantity = choice.quantity || { default: 0, min: 0, max: 999999 };
			[ [ 'default', __( 'Default qty', 'open-product-fields-for-woocommerce' ) ], [ 'min', __( 'Min qty', 'open-product-fields-for-woocommerce' ) ], [ 'max', __( 'Max qty', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
				var input = el( 'input', { class: 'opf-b-input opf-b-product-qty', type: 'number', min: '0', max: '999999', value: choice.quantity[ setting[ 0 ] ], title: setting[ 1 ], placeholder: setting[ 1 ] } );
				input.addEventListener( 'input', function () {
					choice.quantity[ setting[ 0 ] ] = Math.max( 0, Math.min( 999999, Number( input.value ) || 0 ) );
				} );
				cells.push( input );
			} );
		}
		var remove = el( 'button', { type: 'button', class: 'button-link opf-b-remove', text: __( '×', 'open-product-fields-for-woocommerce' ), onclick: function () {
			field.choices.splice( index, 1 );
			rerender();
		} } );
		cells.push( remove );
		return el( 'div', { class: 'opf-b-product-choice' }, cells );
	}

	function productsEditor( field ) {
		if ( PRODUCT_SUBTYPES.indexOf( field.subtype ) === -1 ) {
			field.subtype = 'checkbox';
		}
		var wrap = el( 'div', { class: 'opf-b-products-settings' } );

		var subtype = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Display', 'open-product-fields-for-woocommerce' ) }, PRODUCT_SUBTYPES.map( function ( t ) {
			var option = el( 'option', { value: t, text: t } );
			option.selected = t === field.subtype;
			return option;
		} ) );
		subtype.addEventListener( 'change', function () { field.subtype = subtype.value; rerender(); } );
		wrap.appendChild( labeledControl( __( 'Display', 'open-product-fields-for-woocommerce' ), subtype ) );

		var selection = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Product selection', 'open-product-fields-for-woocommerce' ) }, [
			el( 'option', { value: 'manual', text: __( 'Pick products manually', 'open-product-fields-for-woocommerce' ) } ),
			el( 'option', { value: 'category', text: __( 'Products from a category', 'open-product-fields-for-woocommerce' ) } ),
		] );
		selection.value = field.product_selection || 'manual';
		selection.addEventListener( 'change', function () { field.product_selection = selection.value; rerender(); } );
		wrap.appendChild( labeledControl( __( 'Product selection', 'open-product-fields-for-woocommerce' ), selection ) );

		if ( 'category' === selection.value ) {
			field.product_query = field.product_query || { query_id: 0, query_label: '', limit: 10, sort: 'date_desc', pricing_type: 'fixed' };
			var cat = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Product category', 'open-product-fields-for-woocommerce' ) }, [ el( 'option', { value: '0', text: __( 'Choose a category…', 'open-product-fields-for-woocommerce' ) } ) ].concat(
				productCats.map( function ( term ) {
					var option = el( 'option', { value: String( term.id ), text: term.name } );
					return option;
				} )
			) );
			cat.value = String( field.product_query.query_id || 0 );
			cat.addEventListener( 'change', function () {
				field.product_query.query_id = parseInt( cat.value, 10 ) || 0;
				var picked = productCats.filter( function ( term ) { return String( term.id ) === cat.value; } );
				field.product_query.query_label = picked.length ? picked[ 0 ].name : '';
			} );
			wrap.appendChild( labeledControl( __( 'Category', 'open-product-fields-for-woocommerce' ), cat ) );

			var limit = el( 'input', { class: 'opf-b-input', type: 'number', min: '1', max: '50', value: field.product_query.limit || 10, 'aria-label': __( 'Maximum products', 'open-product-fields-for-woocommerce' ) } );
			limit.addEventListener( 'input', function () {
				field.product_query.limit = Math.max( 1, Math.min( 50, Number( limit.value ) || 10 ) );
			} );
			wrap.appendChild( labeledControl( __( 'Maximum products (1–50)', 'open-product-fields-for-woocommerce' ), limit ) );

			var sort = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Sorting', 'open-product-fields-for-woocommerce' ) }, [
				el( 'option', { value: 'date_desc', text: __( 'Creation date (newest first)', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'date_asc', text: __( 'Creation date (oldest first)', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'name_asc', text: __( 'Title (A–Z)', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'name_desc', text: __( 'Title (Z–A)', 'open-product-fields-for-woocommerce' ) } ),
			] );
			sort.value = field.product_query.sort || 'date_desc';
			sort.addEventListener( 'change', function () { field.product_query.sort = sort.value; } );
			wrap.appendChild( labeledControl( __( 'Sorting', 'open-product-fields-for-woocommerce' ), sort ) );

			var queryPricing = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Price', 'open-product-fields-for-woocommerce' ) }, [
				el( 'option', { value: 'fixed', text: __( 'Product price', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'none', text: __( 'Free', 'open-product-fields-for-woocommerce' ) } ),
			] );
			queryPricing.value = field.product_query.pricing_type || 'fixed';
			queryPricing.addEventListener( 'change', function () { field.product_query.pricing_type = queryPricing.value; } );
			wrap.appendChild( labeledControl( __( 'Price', 'open-product-fields-for-woocommerce' ), queryPricing ) );
		} else {
			field.choices = Array.isArray( field.choices ) ? field.choices : [];
			var search = el( 'select', { class: 'wc-product-search opf-b-product-search', multiple: 'multiple', style: 'width:100%', 'data-action': 'woocommerce_json_search_products', 'data-placeholder': __( 'Search for a product…', 'open-product-fields-for-woocommerce' ), 'data-allow_clear': 'true' } );
			field.choices.forEach( function ( choice ) {
				var option = el( 'option', { value: String( choice.product_id ), text: choice.label || '#' + choice.product_id } );
				option.selected = true;
				search.appendChild( option );
			} );
			var updateProductChoices = function () {
				var previous = {};
				field.choices.forEach( function ( choice ) { previous[ choice.product_id ] = choice; } );
				var next = [];
				Array.prototype.forEach.call( search.selectedOptions, function ( option ) {
					var id = parseInt( option.value, 10 ) || 0;
					if ( ! id ) return;
					var kept = previous[ id ] || productChoiceDefaults( id, option.text );
					kept.label = option.text;
					next.push( kept );
				} );
				field.choices = next;
				rerender();
			};
			// WooCommerce SelectWoo emits a jQuery change event. Listen through
			// jQuery when available so selecting a real product updates the model.
			if ( window.jQuery ) {
				window.jQuery( search ).on( 'change', updateProductChoices );
			} else {
				search.addEventListener( 'change', updateProductChoices );
			}
			wrap.appendChild( el( 'div', { class: 'opf-b-product-picker' }, [ search ] ) );
			var header = el( 'div', { class: 'opf-b-choices-header', html: '<strong>' + __( 'Linked products', 'open-product-fields-for-woocommerce' ) + '</strong> <em>(' + ( isProductsQtySubtype( field ) ? __( 'product · price · default · unavailable · qty bounds', 'open-product-fields-for-woocommerce' ) : __( 'product · price · default · unavailable', 'open-product-fields-for-woocommerce' ) ) + ')</em>' } );
			var list = el( 'div', { class: 'opf-b-choices' }, field.choices.map( function ( choice, i ) {
				return productChoiceRow( field, choice, i );
			} ) );
			wrap.appendChild( header );
			wrap.appendChild( list );
		}

		if ( ! isProductsQtySubtype( field ) ) {
			var qtyMethod = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Child quantity', 'open-product-fields-for-woocommerce' ) }, [
				el( 'option', { value: 'one', text: __( 'Always add 1 to the cart', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'parent', text: __( 'Match the parent product quantity', 'open-product-fields-for-woocommerce' ) } ),
			] );
			qtyMethod.value = field.qty_method || 'one';
			qtyMethod.addEventListener( 'change', function () { field.qty_method = qtyMethod.value; } );
			wrap.appendChild( labeledControl( __( 'Child quantity', 'open-product-fields-for-woocommerce' ), qtyMethod ) );
		} else {
			var display = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Quantity controls', 'open-product-fields-for-woocommerce' ) }, [
				el( 'option', { value: 'default', text: __( 'Number input', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'plus_min', text: __( '+/− buttons', 'open-product-fields-for-woocommerce' ) } ),
			] );
			display.value = field.display || 'default';
			display.addEventListener( 'change', function () { field.display = display.value; } );
			wrap.appendChild( labeledControl( __( 'Quantity controls', 'open-product-fields-for-woocommerce' ), display ) );
			[ [ 'min_choices', __( 'Minimum total quantity', 'open-product-fields-for-woocommerce' ) ], [ 'max_choices', __( 'Maximum total quantity', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
				var bound = el( 'input', { class: 'opf-b-input', type: 'number', min: '0', max: '999999', value: null === field[ setting[ 0 ] ] || undefined === field[ setting[ 0 ] ] ? '' : field[ setting[ 0 ] ], 'aria-label': setting[ 1 ] } );
				bound.addEventListener( 'input', function () {
					if ( '' !== bound.value ) field[ setting[ 0 ] ] = Math.max( 0, Math.min( 999999, Number( bound.value ) || 0 ) );
					else delete field[ setting[ 0 ] ];
				} );
				wrap.appendChild( labeledControl( setting[ 1 ], bound ) );
			} );
		}

		if ( isProductsCardSubtype( field ) ) {
			var cardBox = el( 'div', { class: 'opf-b-products-card-settings' } );
			[ [ 'items_per_row', __( 'Desktop columns (1–4)', 'open-product-fields-for-woocommerce' ), 2 ], [ 'items_per_row_tablet', __( 'Tablet columns (1–4)', 'open-product-fields-for-woocommerce' ), 1 ], [ 'items_per_row_mobile', __( 'Mobile columns (1–4)', 'open-product-fields-for-woocommerce' ), 1 ] ].forEach( function ( setting ) {
				var input = el( 'input', { class: 'opf-b-input', type: 'number', min: '1', max: '4', value: field[ setting[ 0 ] ] || setting[ 2 ], 'aria-label': setting[ 1 ] } );
				input.addEventListener( 'input', function () {
					if ( input.value ) field[ setting[ 0 ] ] = Math.max( 1, Math.min( 4, Number( input.value ) || setting[ 2 ] ) );
					else delete field[ setting[ 0 ] ];
				} );
				cardBox.appendChild( labeledControl( setting[ 1 ], input ) );
			} );
			[ [ 'incl_img', __( 'Show product image', 'open-product-fields-for-woocommerce' ) ], [ 'incl_desc', __( 'Show product description', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
				var toggle = el( 'input', { type: 'checkbox' } );
				toggle.checked = false !== field[ setting[ 0 ] ];
				toggle.addEventListener( 'change', function () { field[ setting[ 0 ] ] = toggle.checked; } );
				cardBox.appendChild( labeledControl( setting[ 1 ], toggle ) );
			} );
			[ 'slot_1', 'slot_2', 'slot_3' ].forEach( function ( slotKey, slotIndex ) {
				var slot = el( 'select', { class: 'opf-b-input', 'aria-label': sprintf( /* translators: %d: card slot number. */ __( 'Card slot %d', 'open-product-fields-for-woocommerce' ), slotIndex + 1 ) }, [
					el( 'option', { value: 'none', text: __( 'Nothing', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: 'price', text: __( 'Price', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: 'stock', text: __( 'Stock availability', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: 'link', text: __( 'Product link', 'open-product-fields-for-woocommerce' ) } ),
				] );
				slot.value = field[ slotKey ] || 'none';
				slot.addEventListener( 'change', function () { field[ slotKey ] = slot.value; } );
				cardBox.appendChild( labeledControl( sprintf( /* translators: %d: card slot number. */ __( 'Card slot %d', 'open-product-fields-for-woocommerce' ), slotIndex + 1 ), slot ) );
			} );
			if ( 'vcard' === field.subtype || 'vcard-qty' === field.subtype ) {
				var fit = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Image fit', 'open-product-fields-for-woocommerce' ) }, [
					el( 'option', { value: 'cover', text: __( 'Cover', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: 'contain', text: __( 'Contain', 'open-product-fields-for-woocommerce' ) } ),
				] );
				fit.value = field.img_fit || 'cover';
				fit.addEventListener( 'change', function () { field.img_fit = fit.value; } );
				cardBox.appendChild( labeledControl( __( 'Image fit', 'open-product-fields-for-woocommerce' ), fit ) );
			}
			wrap.appendChild( cardBox );
		}

		if ( 'image' === field.subtype ) {
			var labelPos = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Label position', 'open-product-fields-for-woocommerce' ) }, [
				el( 'option', { value: 'default', text: __( 'Below image, inside choice', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'out', text: __( 'Below image, outside choice', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'hide', text: __( 'Hide label visually', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'tooltip', text: __( 'Show label on hover/focus', 'open-product-fields-for-woocommerce' ) } ),
			] );
			labelPos.value = field.label_pos || 'tooltip';
			labelPos.addEventListener( 'change', function () { field.label_pos = labelPos.value; } );
			wrap.appendChild( labeledControl( __( 'Image label position', 'open-product-fields-for-woocommerce' ), labelPos ) );
			var itemWidth = el( 'input', { class: 'opf-b-input', type: 'number', min: '30', max: '300', value: field.item_width || 60, 'aria-label': __( 'Image width', 'open-product-fields-for-woocommerce' ) } );
			itemWidth.addEventListener( 'input', function () {
				if ( itemWidth.value ) field.item_width = Math.max( 30, Math.min( 300, Number( itemWidth.value ) || 60 ) );
				else delete field.item_width;
			} );
			wrap.appendChild( labeledControl( __( 'Image width (30–300 px)', 'open-product-fields-for-woocommerce' ), itemWidth ) );
			var largeImage = el( 'input', { type: 'checkbox', 'data-opf-products-image-setting': 'large_image' } );
			largeImage.checked = !! field.large_image;
			largeImage.addEventListener( 'change', function () { field.large_image = largeImage.checked; } );
			wrap.appendChild( labeledControl( __( 'Enlarge child image on hover and keyboard focus', 'open-product-fields-for-woocommerce' ), largeImage ) );
		}

		var imageZoom = el( 'input', { type: 'checkbox' } );
		imageZoom.checked = !! field.image_zoom;
		imageZoom.addEventListener( 'change', function () { field.image_zoom = imageZoom.checked; } );
		wrap.appendChild( labeledControl( __( 'Swap/zoom the main product image on selection', 'open-product-fields-for-woocommerce' ), imageZoom ) );

		[ [ 'hide_cart', __( 'Hide children in the cart', 'open-product-fields-for-woocommerce' ) ], [ 'hide_checkout', __( 'Hide children at checkout', 'open-product-fields-for-woocommerce' ) ], [ 'hide_order', __( 'Hide children on orders/emails', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
			var toggle = el( 'input', { type: 'checkbox' } );
			toggle.checked = !! field[ setting[ 0 ] ];
			toggle.addEventListener( 'change', function () { field[ setting[ 0 ] ] = toggle.checked; } );
			wrap.appendChild( labeledControl( setting[ 1 ], toggle ) );
		} );

		return wrap;
	}

	function conditionalRuleRow( field, conditional, rule ) {
		var sources = model.fields.filter( function ( candidate ) { return candidate.id !== field.id; } );
		var fieldOptions = sources.map( function ( candidate ) {
			return el( 'option', { value: candidate.id, text: ( candidate.label || candidate.id ) + ' (' + candidate.id + ')' } );
		} );
		if ( rule.field && ! sources.some( function ( candidate ) { return candidate.id === rule.field; } ) ) {
			fieldOptions.unshift( el( 'option', { value: rule.field, text: sprintf( /* translators: %s: field id. */ __( 'Unavailable field: %s', 'open-product-fields-for-woocommerce' ), rule.field ) } ) );
		}
		if ( ! fieldOptions.length ) {
			fieldOptions.push( el( 'option', { value: '', text: __( 'Add another field first', 'open-product-fields-for-woocommerce' ) } ) );
		}
		var fieldSelect = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Condition field', 'open-product-fields-for-woocommerce' ) }, fieldOptions );
		fieldSelect.value = rule.field || '';
		fieldSelect.disabled = ! sources.length;
		fieldSelect.addEventListener( 'change', function () {
			rule.field = fieldSelect.value;
			rule.value = '';
			rerender();
		} );

		var source = sources.find( function ( candidate ) { return candidate.id === rule.field; } );
		var qtySource = isProductsQtyRuleSource( source );
		var operatorLabels = qtySource
			? [
				[ 'empty', __( 'No quantity', 'open-product-fields-for-woocommerce' ) ],
				[ 'not_empty', __( 'Any quantity', 'open-product-fields-for-woocommerce' ) ],
			]
			: [
				[ 'is', __( 'Is', 'open-product-fields-for-woocommerce' ) ], [ 'is_not', __( 'Is not', 'open-product-fields-for-woocommerce' ) ], [ 'contains', __( 'Contains', 'open-product-fields-for-woocommerce' ) ], [ 'not_contains', __( 'Does not contain', 'open-product-fields-for-woocommerce' ) ],
				[ 'greater', __( 'Is greater than', 'open-product-fields-for-woocommerce' ) ], [ 'less', __( 'Is less than', 'open-product-fields-for-woocommerce' ) ], [ 'empty', __( 'Is empty', 'open-product-fields-for-woocommerce' ) ], [ 'not_empty', __( 'Is not empty', 'open-product-fields-for-woocommerce' ) ],
			];
		if ( qtySource && in_array( rule.operator, [ 'empty', 'not_empty' ], true ) === false ) {
			rule.operator = 'empty';
			rule.value = '';
		}
		var operatorSelect = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Condition operator', 'open-product-fields-for-woocommerce' ) }, operatorLabels.map( function ( item ) {
			var option = el( 'option', { value: item[ 0 ], text: item[ 1 ] } );
			option.selected = item[ 0 ] === rule.operator;
			return option;
		} ) );
		operatorSelect.addEventListener( 'change', function () {
			rule.operator = operatorSelect.value;
			if ( in_array( rule.operator, [ 'empty', 'not_empty' ], true ) ) rule.value = '';
			rerender();
		} );

		var valueControl = null;
		if ( ! in_array( rule.operator, [ 'empty', 'not_empty' ], true ) ) {
			if ( source && in_array( source.type, [ 'select', 'radio', 'swatch', 'checkbox' ], true ) && source.choices.length ) {
				var choiceOptions = source.choices.map( function ( choice ) {
					return el( 'option', { value: choice.slug, text: choice.label + ' (' + choice.slug + ')' } );
				} );
				if ( rule.value && ! source.choices.some( function ( choice ) { return choice.slug === rule.value; } ) ) {
					choiceOptions.unshift( el( 'option', { value: rule.value, text: sprintf( /* translators: %s: stored condition value. */ __( 'Current value: %s', 'open-product-fields-for-woocommerce' ), rule.value ) } ) );
				}
				valueControl = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Condition value', 'open-product-fields-for-woocommerce' ) }, choiceOptions );
				valueControl.value = rule.value;
			} else if ( source && 'toggle' === source.type ) {
				valueControl = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Condition value', 'open-product-fields-for-woocommerce' ) }, [
					el( 'option', { value: '1', text: __( 'Checked', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: '0', text: __( 'Not checked', 'open-product-fields-for-woocommerce' ) } ),
				] );
				valueControl.value = rule.value;
			} else {
				var inputType = source && 'number' === source.type ? 'number' : ( source && 'date' === source.type ? 'date' : 'text' );
				valueControl = el( 'input', { class: 'opf-b-input', type: inputType, value: rule.value || '', 'aria-label': __( 'Condition value', 'open-product-fields-for-woocommerce' ) } );
			}
			valueControl.addEventListener( 'input', function () { rule.value = valueControl.value; } );
			valueControl.addEventListener( 'change', function () { rule.value = valueControl.value; } );
		}

		var remove = el( 'button', { type: 'button', class: 'button button-link-delete', text: __( 'Remove rule', 'open-product-fields-for-woocommerce' ), onclick: function () {
			var index = Array.isArray( conditional.rules ) ? conditional.rules.indexOf( rule ) : -1;
			if ( index !== -1 ) conditional.rules.splice( index, 1 );
			rerender();
		} } );
		return el( 'div', { class: 'opf-b-conditional-rule' }, [
			labeledControl( __( 'Field', 'open-product-fields-for-woocommerce' ), fieldSelect ),
			labeledControl( __( 'Operator', 'open-product-fields-for-woocommerce' ), operatorSelect ),
			valueControl ? labeledControl( __( 'Value', 'open-product-fields-for-woocommerce' ), valueControl ) : el( 'span', { class: 'description', text: __( 'No value needed', 'open-product-fields-for-woocommerce' ) } ),
			remove,
		] );
	}

	function conditionalEditor( field ) {
		// Read-only at render: only assign `field.conditionals` when the user
		// actually adds or removes a group so untouched fields stay canonical.
		var ensureConditionals = function () {
			if ( ! Array.isArray( field.conditionals ) ) field.conditionals = [];
			return field.conditionals;
		};
		var sourceFields = model.fields.filter( function ( candidate ) { return candidate.id !== field.id; } );
		var groups = ( Array.isArray( field.conditionals ) ? field.conditionals : [] ).map( function ( conditional, groupIndex ) {
			var conditionalRules = Array.isArray( conditional.rules ) ? conditional.rules : [];
			var ensureRules = function () {
				if ( ! Array.isArray( conditional.rules ) ) conditional.rules = [];
				return conditional.rules;
			};
			var action = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Visibility action', 'open-product-fields-for-woocommerce' ) }, [
				el( 'option', { value: 'show', text: __( 'Show this field if', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'hide', text: __( 'Hide this field if', 'open-product-fields-for-woocommerce' ) } ),
			] );
			action.value = conditional.action || 'show';
			action.addEventListener( 'change', function () { conditional.action = action.value; } );
			var logic = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'How to combine rules', 'open-product-fields-for-woocommerce' ) }, [
				el( 'option', { value: 'all', text: __( 'All rules match', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'any', text: __( 'Any rule matches', 'open-product-fields-for-woocommerce' ) } ),
			] );
			logic.value = conditional.logic || 'all';
			logic.addEventListener( 'change', function () { conditional.logic = logic.value; } );
			var addRule = el( 'button', { type: 'button', class: 'button', text: __( '+ Add rule', 'open-product-fields-for-woocommerce' ), onclick: function () {
				ensureRules().push( { field: sourceFields[ 0 ].id, operator: 'is', value: '' } );
				rerender();
			} } );
			addRule.disabled = ! sourceFields.length;
			var removeGroup = el( 'button', { type: 'button', class: 'button button-link-delete', text: __( 'Remove condition', 'open-product-fields-for-woocommerce' ), onclick: function () {
				ensureConditionals().splice( groupIndex, 1 );
				rerender();
			} } );
			var rules = conditionalRules.map( function ( rule ) { return conditionalRuleRow( field, conditional, rule ); } );
			if ( ! rules.length ) {
				rules.push( el( 'p', { class: 'description', text: __( 'Add at least one rule for this condition to take effect.', 'open-product-fields-for-woocommerce' ) } ) );
			}
			return el( 'div', { class: 'opf-b-conditional-group' }, [
				el( 'div', { class: 'opf-b-conditional-settings' }, [ labeledControl( __( 'Action', 'open-product-fields-for-woocommerce' ), action ), labeledControl( __( 'Rule matching', 'open-product-fields-for-woocommerce' ), logic ) ] ),
			el( 'div', { class: 'opf-b-conditional-rules' }, rules ),
			el( 'div', { class: 'opf-b-conditional-actions' }, [ addRule, removeGroup ] ),
			] );
		} );
		var addCondition = el( 'button', { type: 'button', class: 'button', text: __( '+ Add condition', 'open-product-fields-for-woocommerce' ), onclick: function () {
			ensureConditionals().push( {
				action: 'show', logic: 'all',
				rules: sourceFields.length ? [ { field: sourceFields[ 0 ].id, operator: 'is', value: '' } ] : [],
			} );
			rerender();
		} } );
		addCondition.disabled = ! sourceFields.length;
		return el( 'div', { class: 'opf-b-conditional-editor' }, [
			el( 'strong', { text: __( 'Visibility conditions', 'open-product-fields-for-woocommerce' ) } ),
			el( 'p', { class: 'description', text: __( 'Condition groups are combined as alternatives; rules inside each group use the selected matching rule.', 'open-product-fields-for-woocommerce' ) } ),
			el( 'div', { class: 'opf-b-conditional-groups' }, groups ),
			addCondition,
		] );
	}

	// WAPF Extended 3.1.5 custom-variable builder parity (views/admin/
	// variable-builder.php): name (var_ prepended), standard value, and
	// field/qty rules whose first passing rule supplies the value/formula.
	// Shapes persist verbatim in the group model (`variables[]`), which the
	// engine (Calculator / CartIntegration) and WapfMapper already consume.
	var VARIABLE_RULE_TYPES = [ [ 'field', __( 'Field value changes', 'open-product-fields-for-woocommerce' ) ], [ 'qty', __( 'Product quantity changes', 'open-product-fields-for-woocommerce' ) ] ];
	var VARIABLE_FIELD_CONDITIONS = [
		[ '==', __( 'is equal to', 'open-product-fields-for-woocommerce' ) ],
		[ '!=', __( 'is not equal to', 'open-product-fields-for-woocommerce' ) ],
		[ 'empty', __( 'is empty', 'open-product-fields-for-woocommerce' ) ],
		[ '!empty', __( 'is not empty', 'open-product-fields-for-woocommerce' ) ],
		[ '==contains', __( 'contains', 'open-product-fields-for-woocommerce' ) ],
		[ '!=contains', __( 'does not contain', 'open-product-fields-for-woocommerce' ) ],
		[ 'gt', __( 'is greater than', 'open-product-fields-for-woocommerce' ) ],
		[ 'lt', __( 'is lesser than', 'open-product-fields-for-woocommerce' ) ],
	];
	var VARIABLE_QTY_CONDITIONS = [
		[ '==', __( 'is equal to', 'open-product-fields-for-woocommerce' ) ],
		[ '!=', __( 'is not equal to', 'open-product-fields-for-woocommerce' ) ],
		[ 'gt', __( 'is greater than', 'open-product-fields-for-woocommerce' ) ],
		[ 'lt', __( 'is lesser than', 'open-product-fields-for-woocommerce' ) ],
	];
	var VARIABLE_NO_VALUE_CONDITIONS = [ 'empty', '!empty' ];

	function conditionNeedValue( condition ) {
		return VARIABLE_NO_VALUE_CONDITIONS.indexOf( condition ) === -1;
	}

	function variableRuleRow( variable, rule, index ) {
		var isQty = 'qty' === ( rule.field === 'qty' || rule.type === 'qty' ? 'qty' : 'field' );
		if ( isQty ) {
			rule.type = 'qty';
			rule.field = 'qty';
		} else {
			rule.type = 'field';
		}

		var typeSelect = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'When the variable changes', 'open-product-fields-for-woocommerce' ) }, VARIABLE_RULE_TYPES.map( function ( entry ) {
			var option = el( 'option', { value: entry[ 0 ], text: entry[ 1 ] } );
			option.selected = entry[ 0 ] === rule.type;
			return option;
		} ) );
		typeSelect.addEventListener( 'change', function () {
			rule.type = typeSelect.value;
			if ( 'qty' === rule.type ) {
				rule.field = 'qty';
				if ( VARIABLE_QTY_CONDITIONS.every( function ( entry ) { return entry[ 0 ] !== rule.condition; } ) ) rule.condition = '==';
			} else {
				delete rule.field;
				if ( VARIABLE_FIELD_CONDITIONS.every( function ( entry ) { return entry[ 0 ] !== rule.condition; } ) ) rule.condition = '==';
			}
			rerender();
		} );

		var cells = [ labeledControl( __( 'If this happens', 'open-product-fields-for-woocommerce' ), typeSelect ) ];

		if ( isQty ) {
			var qtyCondition = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Quantity condition', 'open-product-fields-for-woocommerce' ) }, VARIABLE_QTY_CONDITIONS.map( function ( entry ) {
				var option = el( 'option', { value: entry[ 0 ], text: entry[ 1 ] } );
				option.selected = entry[ 0 ] === rule.condition;
				return option;
			} ) );
			qtyCondition.addEventListener( 'change', function () { rule.condition = qtyCondition.value; } );
			var qtyValue = el( 'input', { class: 'opf-b-input', type: 'number', step: 'any', min: '1', value: rule.value == null ? '' : rule.value, 'aria-label': __( 'Quantity', 'open-product-fields-for-woocommerce' ) } );
			qtyValue.addEventListener( 'input', function () { rule.value = qtyValue.value; } );
			cells.push( labeledControl( __( 'Quantity', 'open-product-fields-for-woocommerce' ), qtyCondition ) );
			cells.push( labeledControl( __( 'Value', 'open-product-fields-for-woocommerce' ), qtyValue ) );
			cells.push( el( 'span', { class: 'description', text: '' } ) );
		} else {
			var fieldOptions = model.fields.filter( function ( candidate ) { return ! [ 'section', 'section_end' ].includes( candidate.type ); } ).map( function ( candidate ) {
				return el( 'option', { value: candidate.id, text: ( candidate.label || candidate.id ) + ' (' + candidate.id + ')' } );
			} );
			if ( rule.field && ! model.fields.some( function ( candidate ) { return candidate.id === rule.field; } ) ) {
				fieldOptions.unshift( el( 'option', { value: rule.field, text: sprintf( /* translators: %s: field id. */ __( 'Unavailable field: %s', 'open-product-fields-for-woocommerce' ), rule.field ) } ) );
			}
			if ( ! fieldOptions.length ) fieldOptions.push( el( 'option', { value: '', text: __( 'Add a field first', 'open-product-fields-for-woocommerce' ) } ) );
			var fieldSelect = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Field', 'open-product-fields-for-woocommerce' ) }, fieldOptions );
			fieldSelect.value = rule.field || '';
			fieldSelect.addEventListener( 'change', function () { rule.field = fieldSelect.value; rule.value = ''; rerender(); } );
			cells.push( labeledControl( __( 'This field changes', 'open-product-fields-for-woocommerce' ), fieldSelect ) );

			var fieldCondition = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Field condition', 'open-product-fields-for-woocommerce' ) }, VARIABLE_FIELD_CONDITIONS.map( function ( entry ) {
				var option = el( 'option', { value: entry[ 0 ], text: entry[ 1 ] } );
				option.selected = entry[ 0 ] === rule.condition;
				return option;
			} ) );
			fieldCondition.addEventListener( 'change', function () {
				rule.condition = fieldCondition.value;
				if ( ! conditionNeedValue( rule.condition ) ) rule.value = '';
				rerender();
			} );
			cells.push( labeledControl( __( 'Condition', 'open-product-fields-for-woocommerce' ), fieldCondition ) );

			if ( ! conditionNeedValue( rule.condition ) ) {
				cells.push( el( 'span', { class: 'description', text: __( 'No value needed', 'open-product-fields-for-woocommerce' ) } ) );
			} else {
				var source = model.fields.find( function ( candidate ) { return candidate.id === rule.field; } );
				var valueControl;
				if ( source && [ 'select', 'radio', 'swatch', 'checkbox' ].includes( source.type ) && Array.isArray( source.choices ) && source.choices.length ) {
					var choiceOptions = source.choices.map( function ( choice ) {
						return el( 'option', { value: choice.slug, text: choice.label + ' (' + choice.slug + ')' } );
					} );
					if ( rule.value && ! source.choices.some( function ( choice ) { return choice.slug === rule.value; } ) ) {
						choiceOptions.unshift( el( 'option', { value: rule.value, text: sprintf( /* translators: %s: stored condition value. */ __( 'Current value: %s', 'open-product-fields-for-woocommerce' ), rule.value ) } ) );
					}
					valueControl = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Value', 'open-product-fields-for-woocommerce' ) }, choiceOptions );
					valueControl.value = rule.value || '';
				} else if ( source && 'toggle' === source.type ) {
					valueControl = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Value', 'open-product-fields-for-woocommerce' ) }, [
						el( 'option', { value: '1', text: __( 'Checked', 'open-product-fields-for-woocommerce' ) } ),
						el( 'option', { value: '0', text: __( 'Not checked', 'open-product-fields-for-woocommerce' ) } ),
					] );
					valueControl.value = rule.value || '';
				} else {
					valueControl = el( 'input', { class: 'opf-b-input', type: source && 'number' === source.type ? 'number' : 'text', value: rule.value == null ? '' : rule.value, 'aria-label': __( 'Value', 'open-product-fields-for-woocommerce' ) } );
				}
				valueControl.addEventListener( 'input', function () { rule.value = valueControl.value; } );
				valueControl.addEventListener( 'change', function () { rule.value = valueControl.value; } );
				cells.push( labeledControl( __( 'Value', 'open-product-fields-for-woocommerce' ), valueControl ) );
			}
		}

		var body = el( 'input', { class: 'opf-b-input', value: rule.variable || '', placeholder: __( 'number or formula', 'open-product-fields-for-woocommerce' ), 'aria-label': __( 'Variable value', 'open-product-fields-for-woocommerce' ) } );
		body.addEventListener( 'input', function () { rule.variable = body.value; } );
		cells.push( labeledControl( __( 'Variable value is', 'open-product-fields-for-woocommerce' ), body ) );

		var remove = el( 'button', { type: 'button', class: 'button-link opf-b-remove', text: __( '×', 'open-product-fields-for-woocommerce' ), onclick: function () {
			variable.rules.splice( index, 1 );
			rerender();
		} } );
		cells.push( remove );

		return el( 'div', { class: 'opf-b-variable-rule' }, cells );
	}

	function variablesEditor() {
		model.variables = Array.isArray( model.variables ) ? model.variables : [];
		var wrap = el( 'div', { class: 'opf-b-variables-editor' } );
		wrap.appendChild( el( 'strong', { text: __( 'Custom variables', 'open-product-fields-for-woocommerce' ) } ) );
		wrap.appendChild( el( 'p', { class: 'description', text: __( 'Create dynamic variables to use with formula-based pricing. Reference them as [var_name].', 'open-product-fields-for-woocommerce' ) } ) );
		wrap.appendChild( el( 'p', { class: 'description', text: __( 'A variable uses its standard value unless one of its rules matches; rules are checked in order and the first matching rule wins.', 'open-product-fields-for-woocommerce' ) } ) );

		var list = el( 'div', { class: 'opf-b-variable-list' } );
		model.variables.forEach( function ( variable, index ) {
			variable.rules = Array.isArray( variable.rules ) ? variable.rules : [];
			var header = el( 'div', { class: 'opf-b-variable-head' }, [
				el( 'strong', { text: 'var_' + ( variable.name || '' ) } ),
				el( 'button', { type: 'button', class: 'button', text: __( 'Duplicate', 'open-product-fields-for-woocommerce' ), onclick: function () {
					var copy = JSON.parse( JSON.stringify( variable ) );
					copy.name = uniqueVariableName( ( variable.name || 'variable' ) + '_copy' );
					model.variables.splice( index + 1, 0, copy );
					rerender();
				} } ),
				el( 'button', { type: 'button', class: 'button button-link-delete', text: __( 'Delete', 'open-product-fields-for-woocommerce' ), onclick: function () {
					model.variables.splice( index, 1 );
					rerender();
				} } ),
			] );

			var nameInput = el( 'input', { class: 'opf-b-input', value: variable.name || '', placeholder: __( 'name', 'open-product-fields-for-woocommerce' ), 'aria-label': __( 'Variable name', 'open-product-fields-for-woocommerce' ) } );
			nameInput.addEventListener( 'input', function () {
				variable.name = nameInput.value.replace( /[^A-Za-z0-9_]/g, '' );
				header.children[ 0 ].textContent = 'var_' + variable.name;
			} );
			var nameWrap = el( 'div', { class: 'opf-b-input-with-prepend' }, [ el( 'span', { class: 'opf-b-input-prepend', text: 'var_' } ), nameInput ] );

			var defaultInput = el( 'input', { class: 'opf-b-input', value: variable.default == null ? '' : variable.default, placeholder: __( 'number or formula', 'open-product-fields-for-woocommerce' ), 'aria-label': __( 'Standard value', 'open-product-fields-for-woocommerce' ) } );
			defaultInput.addEventListener( 'input', function () { variable.default = defaultInput.value; } );

			var rulesWrap = el( 'div', { class: 'opf-b-variable-rules' }, variable.rules.map( function ( rule, ruleIndex ) {
				return variableRuleRow( variable, rule, ruleIndex );
			} ) );
			var addRule = el( 'button', { type: 'button', class: 'button', text: __( '+ Add new rule', 'open-product-fields-for-woocommerce' ), onclick: function () {
				var firstField = model.fields.filter( function ( candidate ) { return ! [ 'section', 'section_end' ].includes( candidate.type ); } )[ 0 ];
				variable.rules.push( { type: 'field', field: firstField ? firstField.id : '', condition: '==', value: '', variable: '' } );
				rerender();
			} } );

			list.appendChild( el( 'div', { class: 'opf-b-variable', 'data-variable-id': variable.name || '' }, [
				header,
				el( 'div', { class: 'opf-b-variable-settings' }, [
					labeledControl( __( 'Variable name', 'open-product-fields-for-woocommerce' ), nameWrap ),
					labeledControl( __( 'Standard value', 'open-product-fields-for-woocommerce' ), defaultInput ),
				] ),
				el( 'div', { class: 'opf-b-variable-rules-label', text: __( 'Value changes', 'open-product-fields-for-woocommerce' ) } ),
				rulesWrap,
				addRule,
			] ) );
		} );
		wrap.appendChild( list );

		var addVariable = el( 'button', { type: 'button', class: 'button button-primary', text: __( 'Add new variable', 'open-product-fields-for-woocommerce' ), onclick: function () {
			model.variables.push( { name: uniqueVariableName( 'variable' ), default: '', rules: [] } );
			rerender();
		} } );
		wrap.appendChild( addVariable );
		return wrap;
	}

	function uniqueVariableName( base ) {
		var taken = {};
		( model.variables || [] ).forEach( function ( variable ) { taken[ variable.name ] = true; } );
		var slug = String( base ).replace( /[^A-Za-z0-9_]/g, '_' ).replace( /^_+/, '' ) || 'variable';
		var name = slug;
		var n = 2;
		while ( taken[ name ] ) { name = slug + '_' + n++; }
		return name;
	}

	function fieldCard( field, index ) {
		if ( [ 'paragraph', 'content_image', 'section', 'section_end', 'calc' ].includes( field.type ) ) {
			field.required = false;
			field.choices = [];
			field.pricing = { type: 'none', amount: 0, formula: '' };
		}
		if ( 'paragraph' === field.type ) {
			field.content = field.content || '';
		}
		var label = el( 'input', { class: 'opf-b-input opf-b-label', value: field.label, placeholder: __( 'Field label', 'open-product-fields-for-woocommerce' ) } );
		label.addEventListener( 'input', function ( e ) {
			field.label = e.target.value;
		} );

		var typeSel = el( 'select', { class: 'opf-b-input', title: __( 'Field type', 'open-product-fields-for-woocommerce' ) }, TYPES.map( function ( t ) {
			var o = el( 'option', { value: t, text: t } );
			if ( t === field.type ) {
				o.selected = true;
			}
			return o;
		} ) );
		typeSel.addEventListener( 'change', function () {
			field.type = typeSel.value;
			if ( 'number' === field.type && undefined === field.number_mode ) {
				field.number_mode = 'integer';
			}
			if ( 'image_quantity' === field.type ) field.required = false;
			if ( 'products' === field.type ) {
				field.subtype = field.subtype || 'checkbox';
				field.product_selection = field.product_selection || 'manual';
				field.qty_method = field.qty_method || 'one';
				delete field.repeat;
			}
			if ( [ 'paragraph', 'content_image', 'section', 'section_end', 'calc' ].includes( field.type ) ) {
				field.required = false;
				field.choices = [];
				field.pricing = { type: 'none', amount: 0, formula: '' };
			}
			if ( 'upload' === field.type ) {
				field.choices = [];
				field.pricing = { type: 'none', amount: 0, formula: '' };
				field.multiple = !! field.multiple;
				if ( null === field.max_size || undefined === field.max_size ) field.max_size = 1;
				if ( ! Array.isArray( field.accepted_types ) ) field.accepted_types = field.accepted_types ? [ field.accepted_types ] : [];
			}
			if ( 'calc' === field.type ) {
				field.required = false;
				field.choices = [];
				field.pricing = { type: 'none', amount: 0, formula: '' };
				field.calc_type = field.calc_type || 'default';
				field.formula = field.formula || '';
				field.result_format = field.result_format || 'number';
				field.result_text = field.result_text || '{result}';
			}
			if ( in_array( field.type, [ 'swatch', 'image_quantity', 'select', 'radio', 'checkbox' ] ) && ! field.choices.length ) {
				field.choices = [ { slug: 'option-1', label: sprintf( /* translators: %d: choice number. */ __( 'Option %d', 'open-product-fields-for-woocommerce' ), 1 ), selected: false, disabled: false, quantity: { default: 0, min: 0, max: 999999 }, pricing: { type: 'none', amount: 0, formula: '' } } ];
			}
			rerender();
		} );

		var req = el( 'input', { type: 'checkbox', title: __( 'Required', 'open-product-fields-for-woocommerce' ) } );
		req.checked = ! [ 'image_quantity', 'calc', 'paragraph', 'content_image', 'section', 'section_end' ].includes( field.type ) && !! field.required;
		req.disabled = [ 'image_quantity', 'calc', 'paragraph', 'content_image', 'section', 'section_end' ].includes( field.type );
		req.addEventListener( 'change', function () {
			field.required = req.checked;
		} );

		var desc = el( 'input', { class: 'opf-b-input opf-b-description', value: field.description || '', placeholder: __( 'Description (optional)', 'open-product-fields-for-woocommerce' ) } );
		desc.addEventListener( 'input', function ( e ) {
			field.description = e.target.value;
		} );

		var duplicate = el( 'button', { type: 'button', class: 'button opf-b-duplicate-field', text: __( 'Duplicate field', 'open-product-fields-for-woocommerce' ), onclick: function () {
			var copy = JSON.parse( JSON.stringify( field ) );
			copy.id = uniqueId( slugify( field.id || 'field' ) + '-copy' );
			model.fields.splice( index + 1, 0, copy );
			rerender();
		} } );

		var remove = el( 'button', { type: 'button', class: 'button button-link-delete', text: __( 'Delete field', 'open-product-fields-for-woocommerce' ), onclick: function () {
			model.fields.splice( index, 1 );
			rerender();
		} } );

		// WAPF presentation parity: instruction placement, defaults, URL
		// prefill, weight formula, per-surface value visibility, card layout,
		// choice-image switching, switch styling, and swatch zoom controls all
		// live on the field head for every non-static field type.
		var staticTypes = [ 'paragraph', 'content_image', 'section', 'section_end', 'calc' ];
		var headParts = [ label, typeSel, desc ];
		if ( staticTypes.indexOf( field.type ) === -1 ) {
			var descriptionPresentation = el( 'select', { class: 'opf-b-input', title: __( 'Instruction presentation', 'open-product-fields-for-woocommerce' ) }, [
				el( 'option', { value: 'inline', text: __( 'Instruction: inline', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'tooltip', text: __( 'Instruction: tooltip', 'open-product-fields-for-woocommerce' ) } ),
			] );
			descriptionPresentation.value = field.description_presentation || 'inline';
			descriptionPresentation.addEventListener( 'change', function () {
				if ( 'tooltip' === descriptionPresentation.value ) {
					field.description_presentation = 'tooltip';
				} else {
					delete field.description_presentation;
				}
				rerender();
			} );
			headParts.push( descriptionPresentation );
			if ( [ 'text', 'url' ].indexOf( field.type ) === -1 ) {
				var defaultInput = el( 'input', { class: 'opf-b-input', value: Array.isArray( field.default ) ? field.default.join( ', ' ) : ( field.default || '' ), placeholder: __( 'Default value (optional)', 'open-product-fields-for-woocommerce' ) } );
				defaultInput.addEventListener( 'input', function ( e ) {
					if ( '' === e.target.value ) {
						delete field.default;
					} else if ( 'checkbox' === field.type || ( 'swatch' === field.type && field.multiple ) || 'image_quantity' === field.type ) {
						field.default = e.target.value.split( ',' ).map( function ( value ) { return value.trim(); } ).filter( Boolean );
					} else {
						field.default = e.target.value;
					}
				} );
				headParts.push( defaultInput );
			}
			var prefillInput = el( 'input', { class: 'opf-b-input', value: field.prefill_param || '', placeholder: __( 'URL query key (optional)', 'open-product-fields-for-woocommerce' ) } );
			prefillInput.addEventListener( 'input', function ( e ) {
				if ( '' === e.target.value ) {
					delete field.prefill_param;
				} else {
					field.prefill_param = e.target.value;
				}
			} );
			headParts.push( prefillInput );
			headParts.push( req );
			var weightFormula = el( 'input', { class: 'opf-b-input', value: field.weight_formula || '', title: __( 'Extra product weight formula', 'open-product-fields-for-woocommerce' ), placeholder: __( 'Extra weight formula, e.g. [field.weight] * 1', 'open-product-fields-for-woocommerce' ) } );
			weightFormula.addEventListener( 'input', function ( e ) {
				if ( '' === e.target.value.trim() ) delete field.weight_formula;
				else field.weight_formula = e.target.value;
			} );
			headParts.push( weightFormula );
			if ( 'products' !== field.type ) {
				[ [ 'hide_cart', __( 'Hide in cart', 'open-product-fields-for-woocommerce' ) ], [ 'hide_checkout', __( 'Hide in checkout', 'open-product-fields-for-woocommerce' ) ], [ 'hide_order', __( 'Hide on order received and emails', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
					var visibility = el( 'input', { type: 'checkbox', title: setting[ 1 ], 'data-opf-visibility': setting[ 0 ] } );
					visibility.checked = !! field[ setting[ 0 ] ];
					visibility.addEventListener( 'change', function () {
						if ( visibility.checked ) field[ setting[ 0 ] ] = true;
						else delete field[ setting[ 0 ] ];
					} );
					headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ visibility, document.createTextNode( ' ' + setting[ 1 ] ) ] ) );
				} );
			}
			if ( 'radio' === field.type ) {
				var cardLayout = el( 'select', { class: 'opf-b-input', 'data-opf-card-layout': '1', title: __( 'Radio card layout', 'open-product-fields-for-woocommerce' ) }, [
					el( 'option', { value: '', text: __( 'Radio list', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: 'horizontal', text: __( 'Horizontal cards', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: 'vertical', text: __( 'Vertical cards', 'open-product-fields-for-woocommerce' ) } ),
				] );
				cardLayout.value = field.card_layout || '';
				cardLayout.addEventListener( 'change', function () {
					if ( cardLayout.value ) field.card_layout = cardLayout.value;
					else delete field.card_layout;
					rerender();
				} );
				headParts.push( cardLayout );
			}
			if ( 'swatch' === field.type ) {
				var imageQuantities = el( 'input', { type: 'checkbox', title: __( 'Quantity for each image choice', 'open-product-fields-for-woocommerce' ) } );
				imageQuantities.checked = !! field.image_quantities;
				imageQuantities.addEventListener( 'change', function () {
					if ( imageQuantities.checked && field.choices.some( function ( choice ) { return ! choice.image; } ) ) {
						imageQuantities.checked = false;
						if ( window.alert ) window.alert( __( 'Add an image to every choice before enabling image quantities.', 'open-product-fields-for-woocommerce' ) );
						return;
					}
					field.image_quantities = imageQuantities.checked;
					if ( field.image_quantities ) {
						delete field.multiple;
						field.min = field.min || 1;
						field.step = field.step || 1;
					}
					rerender();
				} );
				headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ imageQuantities, document.createTextNode( ' ' + __( 'Quantity per image choice', 'open-product-fields-for-woocommerce' ) ) ] ) );
			}
			if ( [ 'swatch', 'select', 'radio', 'checkbox' ].indexOf( field.type ) !== -1 ) {
				var changeProductImage = el( 'input', { type: 'checkbox', title: __( 'Change the main product image when this choice is selected', 'open-product-fields-for-woocommerce' ) } );
				changeProductImage.checked = !! field.change_product_image;
				changeProductImage.addEventListener( 'change', function () {
					field.change_product_image = changeProductImage.checked;
					rerender();
				} );
				headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ changeProductImage, document.createTextNode( ' ' + __( 'Change main product image from selected image choice', 'open-product-fields-for-woocommerce' ) ) ] ) );
			}
			if ( [ 'toggle', 'checkbox' ].indexOf( field.type ) !== -1 ) {
				var switchControl = el( 'input', { type: 'checkbox', title: __( 'Display as switches', 'open-product-fields-for-woocommerce' ) } );
				switchControl.checked = !! field.switch_control;
				switchControl.addEventListener( 'change', function () {
					if ( switchControl.checked ) field.switch_control = true;
					else delete field.switch_control;
					rerender();
				} );
				headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ switchControl, document.createTextNode( ' ' + __( 'Display as switches', 'open-product-fields-for-woocommerce' ) ) ] ) );
			}
		} else {
			headParts.push( req );
		}
		headParts.push( duplicate );
		headParts.push( remove );
		var head = el( 'div', { class: 'opf-b-field-head' }, headParts );
		var card = el( 'div', { class: 'opf-b-field' }, [ head ] );
		if ( 'toggle' === field.type ) {
			var message = el( 'input', { class: 'opf-b-input', type: 'text', value: field.message || '', 'aria-label': __( 'Checkbox message', 'open-product-fields-for-woocommerce' ) } );
			message.addEventListener( 'input', function () { field.message = message.value; } );
			var toggleDefault = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Default value', 'open-product-fields-for-woocommerce' ) }, [
				el( 'option', { value: '0', text: __( 'Unchecked', 'open-product-fields-for-woocommerce' ) } ), el( 'option', { value: '1', text: __( 'Checked', 'open-product-fields-for-woocommerce' ) } ),
			] );
			toggleDefault.value = field.default === '1' ? '1' : '0';
			toggleDefault.addEventListener( 'change', function () { field.default = toggleDefault.value; } );
			card.appendChild( el( 'div', { class: 'opf-b-constraints' }, [ labeledControl( __( 'Checkbox message', 'open-product-fields-for-woocommerce' ), message ), labeledControl( __( 'Default value', 'open-product-fields-for-woocommerce' ), toggleDefault ) ] ) );
		}
		if ( [ 'text', 'url' ].indexOf( field.type ) !== -1 ) {
			var textSettings = el( 'div', { class: 'opf-b-constraints' } );
			[ [ 'placeholder', __( 'Placeholder', 'open-product-fields-for-woocommerce' ) ], [ 'default', __( 'Default value', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
				var input = el( 'input', { class: 'opf-b-input', type: 'text', value: field[ setting[ 0 ] ] == null ? '' : String( field[ setting[ 0 ] ] ), 'aria-label': setting[ 1 ] } );
				input.addEventListener( 'input', function () {
					if ( input.value ) field[ setting[ 0 ] ] = input.value;
					else delete field[ setting[ 0 ] ];
				} );
				textSettings.appendChild( labeledControl( setting[ 1 ], input ) );
			} );
			card.appendChild( textSettings );
		}
		if ( [ 'text', 'textarea' ].indexOf( field.type ) !== -1 ) {
			var validationSettings = el( 'div', { class: 'opf-b-constraints' } );
			[ [ 'minlength', __( 'Minimum length', 'open-product-fields-for-woocommerce' ) ], [ 'maxlength', __( 'Maximum length', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
				var input = el( 'input', { class: 'opf-b-input', type: 'number', min: '1', step: '1', value: field[ setting[ 0 ] ] == null ? '' : String( field[ setting[ 0 ] ] ), 'aria-label': setting[ 1 ] } );
				input.addEventListener( 'input', function () {
					if ( input.value ) field[ setting[ 0 ] ] = Number( input.value );
					else delete field[ setting[ 0 ] ];
				} );
				validationSettings.appendChild( labeledControl( setting[ 1 ], input ) );
			} );
			if ( 'text' === field.type ) {
				var patternInput = el( 'input', { class: 'opf-b-input', type: 'text', value: field.pattern || '', placeholder: '[a-z]+', 'aria-label': __( 'HTML5 validation regex', 'open-product-fields-for-woocommerce' ) } );
				patternInput.addEventListener( 'input', function () {
					if ( patternInput.value ) field.pattern = patternInput.value;
					else delete field.pattern;
				} );
				validationSettings.appendChild( labeledControl( __( 'HTML5 validation regex', 'open-product-fields-for-woocommerce' ), patternInput ) );
			}
			card.appendChild( validationSettings );
		}
		// WAPF number-field parity: whole/decimal mode plus min/max/step bounds.
		if ( 'number' === field.type ) {
			var numberMode = el( 'select', { class: 'opf-b-input', title: __( 'Number mode', 'open-product-fields-for-woocommerce' ), 'data-opf-number-mode': '1' }, [
				el( 'option', { value: 'integer', text: __( 'Whole numbers', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'decimal', text: __( 'Integers and decimals', 'open-product-fields-for-woocommerce' ) } ),
			] );
			numberMode.value = field.number_mode || 'decimal';
			numberMode.addEventListener( 'change', function () {
				field.number_mode = numberMode.value;
			} );
			var numberSettings = el( 'div', { class: 'opf-b-constraints' }, [ labeledControl( __( 'Number type', 'open-product-fields-for-woocommerce' ), numberMode ) ] );
			[ [ 'min', __( 'Minimum', 'open-product-fields-for-woocommerce' ) ], [ 'max', __( 'Maximum', 'open-product-fields-for-woocommerce' ) ], [ 'step', __( 'Step', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
				var input = el( 'input', { class: 'opf-b-input', type: 'number', value: field[ setting[ 0 ] ] === undefined ? '' : field[ setting[ 0 ] ], placeholder: setting[ 1 ] } );
				input.addEventListener( 'input', function ( e ) {
					if ( '' === e.target.value ) delete field[ setting[ 0 ] ];
					else field[ setting[ 0 ] ] = e.target.value;
				} );
				numberSettings.appendChild( labeledControl( setting[ 1 ], input ) );
			} );
			card.appendChild( numberSettings );
		}
		if ( 'products' === field.type ) {
			card.appendChild( productsEditor( field ) );
		}
		if ( 'upload' === field.type ) {
			card.appendChild( uploadEditor( field ) );
		}
		card.appendChild( conditionalEditor( field ) );
		if ( REPEATABLE_TYPES.indexOf( field.type ) !== -1 || 'section' === field.type ) {
			var isSection = 'section' === field.type;
			var repeatSettings = el( 'div', { class: 'opf-b-repeat-settings' } );
			// WAPF parity: repetition is incompatible with priced/weighted
			// choices, image-quantity selection, and weight formulas.
			var hasChoicePricing = ( field.choices || [] ).some( function ( choice ) {
				return choice.pricing && choice.pricing.type && choice.pricing.type !== 'none';
			} );
			var hasChoiceWeight = ( field.choices || [] ).some( function ( choice ) { return Number( choice.weight || 0 ) !== 0; } );
			var hasWeightFormula = !! ( field.weight_formula && String( field.weight_formula ).trim() );
			var repeatBlocked = ! isSection && ( !! field.image_quantities || hasChoiceWeight || hasWeightFormula || hasChoicePricing || ( field.pricing && field.pricing.type && field.pricing.type !== 'none' ) );
			var repeatEnabled = el( 'input', { type: 'checkbox', title: __( 'Allow repeated rows', 'open-product-fields-for-woocommerce' ), 'data-opf-repeat-enabled': field.id } );
			repeatEnabled.checked = !! ( field.repeat && field.repeat.enabled );
			repeatEnabled.disabled = !! repeatBlocked;
			repeatEnabled.addEventListener( 'change', function () {
				if ( repeatEnabled.checked ) {
					field.repeat = field.repeat && field.repeat.enabled
						? field.repeat
						: { enabled: true, mode: 'button', max: 10000 };
				} else {
					delete field.repeat;
				}
				rerender();
			} );
			repeatSettings.appendChild( labeledControl( isSection ? __( 'Repeat this section', 'open-product-fields-for-woocommerce' ) : __( 'Allow customers to add repeated rows', 'open-product-fields-for-woocommerce' ), repeatEnabled ) );
			if ( field.repeat && field.repeat.enabled ) {
				var repeatMode = el( 'select', { class: 'opf-b-input', 'data-opf-repeat-mode': '1' }, [
					el( 'option', { value: 'button', text: __( 'Customer adds rows with a button', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: 'quantity', text: __( 'Match product quantity', 'open-product-fields-for-woocommerce' ) } ),
				] );
				repeatMode.value = field.repeat.mode || 'button';
				repeatMode.addEventListener( 'change', function () {
					field.repeat.mode = repeatMode.value;
					if ( 'quantity' === repeatMode.value ) {
						delete field.repeat.max;
						delete field.repeat.add;
						delete field.repeat.del;
					} else if ( ! field.repeat.max ) {
						field.repeat.max = 10000;
					}
					rerender();
				} );
				repeatSettings.appendChild( labeledControl( __( 'Repeat mode', 'open-product-fields-for-woocommerce' ), repeatMode ) );
				if ( 'button' === ( field.repeat.mode || 'button' ) ) {
					var repeatMax = el( 'input', { class: 'opf-b-input', type: 'number', min: '1', step: '1', value: field.repeat.max || '', placeholder: '10000', 'data-opf-repeat-max': field.id, 'aria-label': __( 'Maximum repeated rows', 'open-product-fields-for-woocommerce' ) } );
					repeatMax.addEventListener( 'input', function () {
						if ( repeatMax.value ) field.repeat.max = Number( repeatMax.value );
						else delete field.repeat.max;
					} );
					repeatSettings.appendChild( labeledControl( __( 'Maximum rows (blank uses 10000)', 'open-product-fields-for-woocommerce' ), repeatMax ) );
					[ [ 'add', __( 'Add button text', 'open-product-fields-for-woocommerce' ) ], [ 'del', __( 'Remove button text', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
						var labelInput = el( 'input', { class: 'opf-b-input', type: 'text', maxlength: '200', value: field.repeat[ setting[ 0 ] ] || '', 'data-opf-repeat-label': field.id + ':' + setting[ 0 ] } );
						labelInput.addEventListener( 'input', function () {
							if ( labelInput.value.trim() ) field.repeat[ setting[ 0 ] ] = labelInput.value.trim();
							else delete field.repeat[ setting[ 0 ] ];
						} );
						repeatSettings.appendChild( labeledControl( setting[ 1 ], labelInput ) );
					} );
				}
				var duplicateLabel = el( 'input', { class: 'opf-b-input', type: 'text', maxlength: '200', value: field.repeat.label || '', 'data-opf-repeat-label': field.id + ':label' } );
				duplicateLabel.addEventListener( 'input', function () {
					if ( duplicateLabel.value.trim() ) field.repeat.label = duplicateLabel.value.trim();
					else delete field.repeat.label;
				} );
				repeatSettings.appendChild( labeledControl( isSection ? __( 'Section instance label ({n} is the section number)', 'open-product-fields-for-woocommerce' ) : __( 'Duplicate row label ({n} is the row number)', 'open-product-fields-for-woocommerce' ), duplicateLabel ) );
			}
			card.appendChild( repeatSettings );
		} else if ( field.repeat && field.repeat.enabled ) {
			card.appendChild( el( 'p', { class: 'description opf-b-repeat-notice', text: __( 'Repeat settings are preserved, but this field type cannot repeat yet.', 'open-product-fields-for-woocommerce' ) } ) );
		}
		if ( 'paragraph' === field.type ) {
			var contentFormat = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Paragraph format', 'open-product-fields-for-woocommerce' ) }, [
				el( 'option', { value: 'plain', text: __( 'Plain text', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'html', text: __( 'Basic HTML', 'open-product-fields-for-woocommerce' ) } ),
			] );
			contentFormat.value = field.content_format || 'plain';
			contentFormat.addEventListener( 'change', function () {
				field.content_format = contentFormat.value;
				if ( 'plain' === contentFormat.value ) field.process_shortcodes = false;
				rerender();
			} );
			card.appendChild( labeledControl( __( 'Paragraph format', 'open-product-fields-for-woocommerce' ), contentFormat ) );
			var content = el( 'textarea', { class: 'opf-b-input opf-b-paragraph-content', rows: 4, 'aria-label': __( 'Paragraph content', 'open-product-fields-for-woocommerce' ) } );
			content.value = field.content || '';
			content.addEventListener( 'input', function () { field.content = content.value; } );
			card.appendChild( el( 'label', { class: 'opf-b-paragraph-label', text: __( 'Paragraph content', 'open-product-fields-for-woocommerce' ) }, [ content ] ) );
			if ( 'html' === field.content_format ) {
				var processShortcodes = el( 'input', { type: 'checkbox' } );
				processShortcodes.checked = !! field.process_shortcodes;
				processShortcodes.addEventListener( 'change', function () { field.process_shortcodes = processShortcodes.checked; } );
				card.appendChild( labeledControl( __( 'Process WordPress shortcodes', 'open-product-fields-for-woocommerce' ), processShortcodes ) );
			}
		}
		if ( 'content_image' === field.type ) {
			var contentImageUrl = el( 'input', { class: 'opf-b-input', type: 'url', value: field.image_url || '', placeholder: __( 'Image URL (https or local path)', 'open-product-fields-for-woocommerce' ), 'aria-label': __( 'Content image URL', 'open-product-fields-for-woocommerce' ) } );
			contentImageUrl.addEventListener( 'input', function () {
				field.image_url = contentImageUrl.value;
				delete field.image_id;
			} );
			var chooseContentImage = el( 'button', { class: 'button', type: 'button', text: __( 'Choose image', 'open-product-fields-for-woocommerce' ), onclick: function () {
				if ( ! window.wp || ! window.wp.media ) {
					window.alert( __( 'The WordPress Media Library is unavailable on this screen.', 'open-product-fields-for-woocommerce' ) );
					return;
				}
				var frame = window.wp.media( {
					title: __( 'Choose informative image', 'open-product-fields-for-woocommerce' ),
					button: { text: __( 'Use image', 'open-product-fields-for-woocommerce' ) },
					library: { type: 'image' },
					multiple: false,
				} );
				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					if ( ! attachment || ! attachment.id || ! attachment.url ) return;
					field.image_id = Number( attachment.id );
					field.image_url = attachment.url;
					contentImageUrl.value = attachment.url;
				} );
				frame.open();
			} } );
			card.appendChild( labeledControl( __( 'Informative image URL', 'open-product-fields-for-woocommerce' ), contentImageUrl ) );
			card.appendChild( chooseContentImage );
		}

		if ( 'calc' === field.type ) {
			field.calc_type = field.calc_type || 'default';
			field.formula = field.formula || '';
			field.result_format = field.result_format || 'number';
			field.result_text = field.result_text || '{result}';
			var calcSettings = el( 'div', { class: 'opf-b-calc-settings' } );
			var calcType = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Calculation type', 'open-product-fields-for-woocommerce' ) }, [
				el( 'option', { value: 'default', text: __( 'Informational calculation', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'cost', text: __( 'Cost calculation (adjusts price)', 'open-product-fields-for-woocommerce' ) } ),
			] );
			calcType.value = field.calc_type;
			calcType.addEventListener( 'change', function () { field.calc_type = calcType.value; rerender(); } );
			var calcFormula = el( 'input', { class: 'opf-b-input', type: 'text', value: field.formula || '', placeholder: '([field.price] + [field.qty]) * 0.2', 'aria-label': __( 'Formula', 'open-product-fields-for-woocommerce' ) } );
			calcFormula.addEventListener( 'input', function () { field.formula = calcFormula.value; } );
			calcSettings.appendChild( labeledControl( __( 'Calculation type', 'open-product-fields-for-woocommerce' ), calcType ) );
			calcSettings.appendChild( labeledControl( __( 'Formula', 'open-product-fields-for-woocommerce' ), calcFormula ) );
			if ( 'default' === field.calc_type ) {
				var resultFormat = el( 'select', { class: 'opf-b-input', 'aria-label': __( 'Result format', 'open-product-fields-for-woocommerce' ) }, [
					el( 'option', { value: 'number', text: __( 'Format as number', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: 'none', text: __( "Don't apply formatting", 'open-product-fields-for-woocommerce' ) } ),
				] );
				resultFormat.value = field.result_format;
				resultFormat.addEventListener( 'change', function () { field.result_format = resultFormat.value; } );
				calcSettings.appendChild( labeledControl( __( 'Result format', 'open-product-fields-for-woocommerce' ), resultFormat ) );
			}
			var resultText = el( 'input', { class: 'opf-b-input', type: 'text', value: field.result_text || '', placeholder: '{result}', 'aria-label': __( 'Result text', 'open-product-fields-for-woocommerce' ) } );
			resultText.addEventListener( 'input', function () {
				field.result_text = resultText.value.trim() ? resultText.value : '{result}';
			} );
			calcSettings.appendChild( labeledControl( __( 'Result text (use {result})', 'open-product-fields-for-woocommerce' ), resultText ) );
			card.appendChild( calcSettings );
		}
		if ( 'products' !== field.type && ( field.choices.length || in_array( field.type, [ 'swatch', 'image_quantity', 'select', 'radio', 'checkbox' ], true ) ) ) {
			var addChoice = el( 'button', { type: 'button', class: 'button', text: __( '+ Add choice', 'open-product-fields-for-woocommerce' ), onclick: function () {
				var n = field.choices.length + 1;
				var choice = { slug: 'option-' + n, label: sprintf( /* translators: %d: choice number. */ __( 'Option %d', 'open-product-fields-for-woocommerce' ), n ), selected: false, disabled: false, quantity: { default: 0, min: 0, max: 999999 }, pricing: { type: 'none', amount: 0, formula: '' } };
				if ( 'color' === field.swatch_style ) choice.color = '#FFFFFF';
				field.choices.push( choice );
				rerender();
			} } );
			var header = el( 'div', { class: 'opf-b-choices-header', html: '<strong>' + __( 'Choices', 'open-product-fields-for-woocommerce' ) + '</strong> <em>(' + __( 'slug · label · pricing', 'open-product-fields-for-woocommerce' ) + ')</em>' } );
			var list = el( 'div', { class: 'opf-b-choices' }, field.choices.map( function ( c, i ) {
				return choiceRow( field, c, i );
			} ) );
			card.appendChild( header );
			card.appendChild( list );
			card.appendChild( addChoice );
			card.appendChild( bulkChoiceImport( field, list ) );
		}
		if ( 'checkbox' === field.type ) {
			var checkboxLimits = el( 'div', { class: 'opf-b-constraints' } );
			var columns = el( 'input', { class: 'opf-b-input', type: 'number', min: '1', max: '12', step: '1', value: field.columns == null ? '1' : String( field.columns ), title: __( 'Checkbox columns', 'open-product-fields-for-woocommerce' ), 'aria-label': __( 'Checkbox columns', 'open-product-fields-for-woocommerce' ) } );
			columns.addEventListener( 'input', function () {
				if ( columns.value ) field.columns = Math.max( 1, Math.min( 12, Math.trunc( Number( columns.value ) || 1 ) ) );
				else delete field.columns;
			} );
			checkboxLimits.appendChild( labeledControl( __( 'Checkbox columns', 'open-product-fields-for-woocommerce' ), columns ) );
			[ [ 'min_choices', __( 'Minimum choices', 'open-product-fields-for-woocommerce' ) ], [ 'max_choices', __( 'Maximum choices', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
				var input = el( 'input', { class: 'opf-b-input', type: 'number', min: '1', max: '10000', step: '1', value: field[ setting[ 0 ] ] == null ? '' : String( field[ setting[ 0 ] ] ), 'aria-label': setting[ 1 ] } );
				input.addEventListener( 'input', function () {
					if ( input.value ) field[ setting[ 0 ] ] = Number( input.value );
					else delete field[ setting[ 0 ] ];
				} );
				checkboxLimits.appendChild( labeledControl( setting[ 1 ], input ) );
			} );
			card.appendChild( checkboxLimits );
		}
		if ( 'image_quantity' === field.type ) {
			[ [ 'min_choices', __( 'Minimum total quantity', 'open-product-fields-for-woocommerce' ) ], [ 'max_choices', __( 'Maximum total quantity', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
				var limit = el( 'input', { class: 'opf-b-input', type: 'number', min: '0', max: '999999', value: null === field[ setting[ 0 ] ] || undefined === field[ setting[ 0 ] ] ? '' : field[ setting[ 0 ] ] } );
				limit.addEventListener( 'input', function () {
					if ( '' !== limit.value ) field[ setting[ 0 ] ] = Math.max( 0, Math.min( 999999, Number( limit.value ) || 0 ) );
					else delete field[ setting[ 0 ] ];
				} );
				card.appendChild( labeledControl( setting[ 1 ], limit ) );
			} );
			field.choices.forEach( function ( choice ) {
				choice.quantity = choice.quantity || { default: 0, min: 0, max: 999999 };
				[ [ 'default', __( 'Default quantity', 'open-product-fields-for-woocommerce' ) ], [ 'min', __( 'Minimum quantity', 'open-product-fields-for-woocommerce' ) ], [ 'max', __( 'Maximum quantity', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
					var input = el( 'input', { class: 'opf-b-input', type: 'number', min: '0', max: '999999', value: choice.quantity[ setting[ 0 ] ] } );
					input.addEventListener( 'input', function () { choice.quantity[ setting[ 0 ] ] = Math.max( 0, Math.min( 999999, Number( input.value ) || 0 ) ); } );
					card.appendChild( labeledControl( choice.label + ' — ' + setting[ 1 ], input ) );
				} );
			} );
			var iqZoom = el( 'input', { type: 'checkbox', 'data-opf-image-setting': 'image_zoom' } );
			iqZoom.checked = !! field.image_zoom;
			iqZoom.addEventListener( 'change', function () { field.image_zoom = iqZoom.checked; } );
			card.appendChild( labeledControl( __( 'Enlarge image on hover and keyboard focus', 'open-product-fields-for-woocommerce' ), iqZoom ) );
		}
		if ( 'swatch' === field.type ) {
			var imageSettings = el( 'div', { class: 'opf-b-image-swatch-settings' } );
			var swatchStyle = el( 'select', { class: 'opf-b-input' }, [
				el( 'option', { value: 'text', text: __( 'Text swatches', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'image', text: __( 'Image swatches', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'color', text: __( 'Color swatches', 'open-product-fields-for-woocommerce' ) } ),
			] );
			swatchStyle.value = field.swatch_style || 'text';
			swatchStyle.addEventListener( 'change', function () {
				field.swatch_style = swatchStyle.value;
				if ( 'color' === swatchStyle.value ) field.choices.forEach( function ( choice ) { if ( ! choice.color ) choice.color = '#FFFFFF'; } );
				rerender();
			} );
			imageSettings.appendChild( labeledControl( __( 'Swatch appearance', 'open-product-fields-for-woocommerce' ), swatchStyle ) );

			var multiple = el( 'input', { type: 'checkbox' } );
			multiple.checked = !! field.multiple;
			multiple.addEventListener( 'change', function () { field.multiple = multiple.checked; rerender(); } );
			imageSettings.appendChild( labeledControl( __( 'Allow multiple selections', 'open-product-fields-for-woocommerce' ), multiple ) );
			if ( field.multiple ) {
				[ [ 'min_choices', __( 'Minimum choices', 'open-product-fields-for-woocommerce' ) ], [ 'max_choices', __( 'Maximum choices', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
					var input = el( 'input', { class: 'opf-b-input', type: 'number', min: '1', max: '10000', value: field[ setting[ 0 ] ] || '' } );
					input.addEventListener( 'input', function () {
						if ( input.value ) field[ setting[ 0 ] ] = Number( input.value );
						else delete field[ setting[ 0 ] ];
					} );
					imageSettings.appendChild( labeledControl( setting[ 1 ], input ) );
				} );
			}
			if ( 'color' === field.swatch_style ) {
				var colorLayout = el( 'select', { class: 'opf-b-input' }, [
					el( 'option', { value: 'square', text: __( 'Square', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: 'rounded', text: __( 'Rounded corners', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: 'circle', text: __( 'Circle', 'open-product-fields-for-woocommerce' ) } ),
				] );
				colorLayout.value = field.color_layout || 'circle';
				colorLayout.addEventListener( 'change', function () { field.color_layout = colorLayout.value; } );
				imageSettings.appendChild( labeledControl( __( 'Color shape', 'open-product-fields-for-woocommerce' ), colorLayout ) );
				var colorSize = el( 'input', { class: 'opf-b-input', type: 'number', min: '5', max: '500', value: field.color_size || 30 } );
				colorSize.addEventListener( 'input', function () { field.color_size = Number( colorSize.value ) || 30; } );
				imageSettings.appendChild( labeledControl( __( 'Color size (px)', 'open-product-fields-for-woocommerce' ), colorSize ) );
				var colorLabel = el( 'select', { class: 'opf-b-input' }, [
					el( 'option', { value: 'default', text: __( 'Show below', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: 'hide', text: __( 'Hide visually', 'open-product-fields-for-woocommerce' ) } ),
					el( 'option', { value: 'tooltip', text: __( 'Show on hover/focus', 'open-product-fields-for-woocommerce' ) } ),
				] );
				colorLabel.value = field.color_label_pos || 'tooltip';
				colorLabel.addEventListener( 'change', function () { field.color_label_pos = colorLabel.value; } );
				imageSettings.appendChild( labeledControl( __( 'Color label position', 'open-product-fields-for-woocommerce' ), colorLabel ) );
			}
			// WAPF zoom parity: image-quantity swatches have their own zoom
			// flag (`image_quantity_zoom`); regular image swatches use
			// `image_zoom`. Both require every choice to carry an image.
			var swatchHasImages = field.choices.some( function ( choice ) { return !! choice.image; } );
			var swatchAllImages = field.choices.length && field.choices.every( function ( choice ) { return !! choice.image; } );
			if ( field.image_quantities ) {
				if ( swatchAllImages ) {
					var imageQuantityZoom = el( 'input', { type: 'checkbox', title: __( 'Enlarge image+quantity choices on hover or keyboard focus', 'open-product-fields-for-woocommerce' ), 'data-opf-image-setting': 'image_quantity_zoom' } );
					imageQuantityZoom.checked = !! field.image_quantity_zoom;
					imageQuantityZoom.addEventListener( 'change', function () { field.image_quantity_zoom = imageQuantityZoom.checked; } );
					imageSettings.appendChild( labeledControl( __( 'Enlarge image+quantity choices on hover or keyboard focus', 'open-product-fields-for-woocommerce' ), imageQuantityZoom ) );
				}
			} else if ( swatchHasImages ) {
				var swatchZoom = el( 'input', { type: 'checkbox', title: __( 'Enlarge image swatches on hover or keyboard focus', 'open-product-fields-for-woocommerce' ), 'data-opf-image-setting': 'image_zoom' } );
				swatchZoom.checked = !! field.image_zoom;
				swatchZoom.addEventListener( 'change', function () { field.image_zoom = swatchZoom.checked; } );
				imageSettings.appendChild( labeledControl( __( 'Enlarge image swatches on hover or keyboard focus', 'open-product-fields-for-woocommerce' ), swatchZoom ) );
			}
			if ( 'image' !== field.swatch_style ) {
				card.appendChild( imageSettings );
				return card;
			}
			var imageZoom = el( 'input', { type: 'checkbox', title: __( 'Enlarge image swatches on hover or keyboard focus', 'open-product-fields-for-woocommerce' ), 'data-opf-image-setting': 'image_zoom' } );
			imageZoom.checked = !! field.image_zoom;
			imageZoom.addEventListener( 'change', function () { field.image_zoom = imageZoom.checked; } );
			if ( ! field.image_quantities && ! swatchHasImages ) {
				imageSettings.appendChild( labeledControl( __( 'Enlarge image on hover and keyboard focus', 'open-product-fields-for-woocommerce' ), imageZoom ) );
			}

			var labelPosition = el( 'select', { class: 'opf-b-input', 'data-opf-image-setting': 'label_pos' }, [
				el( 'option', { value: 'default', text: __( 'Below image, inside choice', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'out', text: __( 'Below image, outside choice', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'hide', text: __( 'Hide label visually', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'tooltip', text: __( 'Show label on hover/focus', 'open-product-fields-for-woocommerce' ) } ),
			] );
			labelPosition.value = field.label_pos || 'out';
			labelPosition.addEventListener( 'change', function () { field.label_pos = labelPosition.value; } );
			imageSettings.appendChild( labeledControl( __( 'Image label position', 'open-product-fields-for-woocommerce' ), labelPosition ) );

			var gridLayout = el( 'select', { class: 'opf-b-input', 'data-opf-image-setting': 'grid_layout' }, [
				el( 'option', { value: 'fixed', text: __( 'Fixed image width', 'open-product-fields-for-woocommerce' ) } ),
				el( 'option', { value: 'flexible', text: __( 'Responsive columns', 'open-product-fields-for-woocommerce' ) } ),
			] );
			gridLayout.value = field.grid_layout || 'fixed';
			gridLayout.addEventListener( 'change', function () { field.grid_layout = gridLayout.value; rerender(); } );
			imageSettings.appendChild( labeledControl( __( 'Image grid layout', 'open-product-fields-for-woocommerce' ), gridLayout ) );

			[ [ 'item_width', __( 'Image width (20–300 px)', 'open-product-fields-for-woocommerce' ), 20, 300, 68 ], [ 'items_per_row', __( 'Desktop columns (1–15)', 'open-product-fields-for-woocommerce' ), 1, 15, 3 ], [ 'items_per_row_tablet', __( 'Tablet columns (1–10)', 'open-product-fields-for-woocommerce' ), 1, 10, 3 ], [ 'items_per_row_mobile', __( 'Mobile columns (1–10)', 'open-product-fields-for-woocommerce' ), 1, 10, 3 ] ].forEach( function ( setting ) {
				var input = el( 'input', { class: 'opf-b-input', type: 'number', min: String( setting[ 2 ] ), max: String( setting[ 3 ] ), value: field[ setting[ 0 ] ] || setting[ 4 ], 'data-opf-image-setting': setting[ 0 ] } );
				input.addEventListener( 'input', function () {
					if ( input.value ) field[ setting[ 0 ] ] = Number( input.value );
					else delete field[ setting[ 0 ] ];
				} );
				imageSettings.appendChild( labeledControl( setting[ 1 ], input ) );
			} );
			card.appendChild( imageSettings );
		}
		if ( 'date' === field.type ) {
			var dateBounds = el( 'div', { class: 'opf-b-constraints' } );
			[ [ 'allow_past', __( 'Allow past dates', 'open-product-fields-for-woocommerce' ) ], [ 'allow_future', __( 'Allow future dates', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
				var checkbox = el( 'input', { type: 'checkbox', 'data-opf-date-policy': setting[ 0 ] } );
				checkbox.checked = field[ setting[ 0 ] ] !== false;
				checkbox.addEventListener( 'change', function () { field[ setting[ 0 ] ] = checkbox.checked; } );
				var label = el( 'label', { class: 'opf-b-date-policy' }, [ checkbox, document.createTextNode( setting[ 1 ] ) ] );
				dateBounds.appendChild( label );
			} );
			[ [ 'min_date', __( 'Minimum date (2026-12-31 or 7d)', 'open-product-fields-for-woocommerce' ) ], [ 'max_date', __( 'Maximum date (2026-12-31 or 1y 2m)', 'open-product-fields-for-woocommerce' ) ] ].forEach( function ( setting ) {
				var input = el( 'input', { class: 'opf-b-input', type: 'text', value: field[ setting[ 0 ] ] || '', placeholder: setting[ 1 ] } );
				input.addEventListener( 'input', function () {
					if ( input.value.trim() ) field[ setting[ 0 ] ] = input.value.trim();
					else delete field[ setting[ 0 ] ];
				} );
				dateBounds.appendChild( input );
			} );
			var cutoffInput = el( 'input', { class: 'opf-b-input', type: 'time', value: field.cutoff_time || '', placeholder: __( 'Disable today after', 'open-product-fields-for-woocommerce' ) } );
			cutoffInput.addEventListener( 'input', function () {
				if ( cutoffInput.value ) field.cutoff_time = cutoffInput.value;
				else delete field.cutoff_time;
			} );
			dateBounds.appendChild( cutoffInput );
			var disabledWeekdays = el( 'input', { class: 'opf-b-input', type: 'text', value: Array.isArray( field.disabled_weekdays ) ? field.disabled_weekdays.join( ', ' ) : '', placeholder: __( 'Disabled weekdays (0=Sun … 6=Sat)', 'open-product-fields-for-woocommerce' ) } );
			disabledWeekdays.addEventListener( 'input', function () {
				var days = disabledWeekdays.value.split( ',' ).map( function ( value ) { return value.trim(); } ).filter( Boolean );
				if ( days.length && days.every( function ( day ) { return /^[0-6]$/.test( day ); } ) ) field.disabled_weekdays = days.map( Number );
				else if ( ! days.length ) delete field.disabled_weekdays;
			} );
			var disabledDates = el( 'input', { class: 'opf-b-input', type: 'text', value: Array.isArray( field.disabled_dates ) ? field.disabled_dates.join( ', ' ) : '', placeholder: __( 'Disabled dates/ranges (YYYY-MM-DD, MM-DD, or start end)', 'open-product-fields-for-woocommerce' ) } );
			disabledDates.addEventListener( 'input', function () {
				var rules = disabledDates.value.split( ',' ).map( function ( value ) { return value.trim(); } ).filter( Boolean );
				if ( rules.length ) field.disabled_dates = rules;
				else delete field.disabled_dates;
			} );
			dateBounds.appendChild( disabledWeekdays );
			dateBounds.appendChild( disabledDates );
			card.appendChild( dateBounds );
		}

		return card;
	}

	function in_array( needle, haystack ) {
		return haystack.indexOf( needle ) !== -1;
	}

	// Clearing `children` alongside `innerHTML` keeps simplified DOM mocks (no
	// innerHTML parser) from accumulating duplicate cards across rerenders.
	function clearChildren( node ) {
		node.innerHTML = '';
		if ( node.children && Array.isArray( node.children ) ) node.children.length = 0;
	}

	function rerender() {
		var app = document.getElementById( 'opf-builder-fields' );
		clearChildren( app );
		model.fields.forEach( function ( field, i ) {
			app.appendChild( fieldCard( field, i ) );
		} );
		rerenderImageRules();
		rerenderVariables();
		// (Re)initialise WooCommerce product pickers on products fields.
		if ( typeof app.querySelector === 'function' && app.querySelector( '.opf-b-product-search' ) && window.jQuery ) {
			window.jQuery( document.body ).trigger( 'wc-enhanced-select-init' );
		}
	}

	function rerenderVariables() {
		var container = document.getElementById( 'opf-builder-variables' );
		if ( ! container ) return;
		clearChildren( container );
		container.appendChild( variablesEditor() );
	}

	// Image rules live outside `opf-builder-fields` (that container holds
	// field cards only) but refresh alongside it so field/choice changes
	// re-render condition selects.
	function rerenderImageRules() {
		var container = document.getElementById( 'opf-builder-image-rules' );
		if ( ! container ) return;
		clearChildren( container );
		container.appendChild( imageRulesEditor() );
	}

	// WAPF conditional product-image rules: each rule matches ALL of its
	// conditions; a value of "Any" ignores that field; the last matching rule
	// wins (the frontend scans the list in reverse, WAPF 3.1.5 parity); the
	// target may be a gallery URL or an external image.
	function imageRulesEditor() {
		var section = el( 'section', { class: 'opf-b-image-rules' }, [
			el( 'h3', { text: __( 'Conditional product images', 'open-product-fields-for-woocommerce' ) } ),
			el( 'p', { text: __( 'Each rule matches all its conditions. Use “Any” to ignore a field. When several rules match, the last one in the list wins. Paste a product-gallery image URL to switch to that slide, or another image URL to show an external image.', 'open-product-fields-for-woocommerce' ) } ),
		] );
		var choiceFields = model.fields.filter( function ( field ) {
			return [ 'select', 'radio', 'checkbox', 'swatch' ].indexOf( field.type ) !== -1 && ( field.choices || [] ).length;
		} );
		model.image_rules.forEach( function ( rule, ruleIndex ) {
			rule.conditions = rule.conditions || [];
			var target = el( 'input', { class: 'opf-b-input', type: 'url', value: rule.target_url || '', placeholder: __( 'Gallery or external image URL', 'open-product-fields-for-woocommerce' ) } );
			target.addEventListener( 'input', function () { rule.target_url = target.value; } );
			var chooseImage = el( 'button', { class: 'button', text: __( 'Choose from Media Library', 'open-product-fields-for-woocommerce' ), onclick: function () {
				if ( ! window.wp || ! window.wp.media ) return;
				var frame = window.wp.media( { title: __( 'Choose product image', 'open-product-fields-for-woocommerce' ), button: { text: __( 'Use this image', 'open-product-fields-for-woocommerce' ) }, multiple: false, library: { type: 'image' } } );
				frame.on( 'select', function () {
					var selected = frame.state().get( 'selection' ).first();
					var attachment = selected && selected.toJSON ? selected.toJSON() : null;
					if ( attachment && attachment.url ) {
						rule.target_url = attachment.url;
						target.value = attachment.url;
					}
				} );
				frame.open();
			} } );
			var rows = [ target, chooseImage ];
			rule.conditions.forEach( function ( condition, conditionIndex ) {
				var selectedField = choiceFields.find( function ( field ) { return field.id === condition.field; } ) || choiceFields[ 0 ];
				var fieldSelect = el( 'select', { class: 'opf-b-input' }, choiceFields.map( function ( field ) {
					var option = el( 'option', { value: field.id, text: field.label || field.id } );
					option.selected = selectedField && field.id === selectedField.id;
					return option;
				} ) );
				fieldSelect.addEventListener( 'change', function () {
					condition.field = fieldSelect.value;
					condition.value = '*';
					rerender();
				} );
				var valueSelect = el( 'select', { class: 'opf-b-input' }, [
					el( 'option', { value: '*', text: __( 'Any', 'open-product-fields-for-woocommerce' ) } ),
				].concat( selectedField ? ( selectedField.choices || [] ).map( function ( choice ) { return el( 'option', { value: choice.slug, text: choice.label || choice.slug } ); } ) : [] ) );
				valueSelect.value = condition.value || '*';
				valueSelect.addEventListener( 'change', function () { condition.value = valueSelect.value; } );
				var removeCondition = el( 'button', { class: 'button-link', text: __( 'Remove condition', 'open-product-fields-for-woocommerce' ), onclick: function () {
					rule.conditions.splice( conditionIndex, 1 );
					rerender();
				} } );
				rows.push( el( 'div', { class: 'opf-b-image-rule-condition' }, [ fieldSelect, valueSelect, removeCondition ] ) );
			} );
			var addCondition = el( 'button', { class: 'button', text: __( '+ Add condition', 'open-product-fields-for-woocommerce' ), onclick: function () {
				if ( choiceFields.length ) rule.conditions.push( { field: choiceFields[ 0 ].id, value: '*' } );
				rerender();
			} } );
			var removeRule = el( 'button', { class: 'button-link-delete', text: __( 'Remove rule', 'open-product-fields-for-woocommerce' ), onclick: function () {
				model.image_rules.splice( ruleIndex, 1 );
				rerender();
			} } );
			rows.push( addCondition, removeRule );
			section.appendChild( el( 'div', { class: 'opf-b-image-rule' }, rows ) );
		} );
		section.appendChild( el( 'button', { class: 'button', text: __( '+ Add image rule', 'open-product-fields-for-woocommerce' ), onclick: function () {
			if ( choiceFields.length ) model.image_rules.push( { target_url: '', conditions: [ { field: choiceFields[ 0 ].id, value: '*' } ] } );
			rerender();
		} } ) );
		if ( ! choiceFields.length ) section.appendChild( el( 'p', { text: __( 'Add at least one select, radio, checkbox, or swatch choice field to configure image rules.', 'open-product-fields-for-woocommerce' ) } ) );
		return section;
	}

	function save() {
		// JSON-authored lookup tables and formula variables are validated
		// before any field data is committed so a syntax error never produces
		// a partially updated group.
		var lookupInput = document.getElementById( 'opf-b-lookup-tables' );
		try {
			var parsedLookupTables = JSON.parse( lookupInput && lookupInput.value ? lookupInput.value : '{}' );
			if ( ! parsedLookupTables || typeof parsedLookupTables !== 'object' || Array.isArray( parsedLookupTables ) ) {
				throw new Error( 'Lookup tables must be a JSON object.' );
			}
			model.lookup_tables = parsedLookupTables;
		} catch ( error ) {
			var lookupStatus = document.getElementById( 'opf-b-lookup-status' );
			if ( lookupStatus ) lookupStatus.textContent = __( 'Fix lookup table JSON before saving.', 'open-product-fields-for-woocommerce' );
			return;
		}
		var variableInput = document.getElementById( 'opf-b-formula-variables' );
		try {
			var parsedVariables = JSON.parse( variableInput && variableInput.value ? variableInput.value : '{}' );
			if ( ! parsedVariables || typeof parsedVariables !== 'object' || Array.isArray( parsedVariables ) ) {
				throw new Error( 'Formula variables must be a JSON object.' );
			}
			model.formula_variables = parsedVariables;
		} catch ( error ) {
			var variableStatus = document.getElementById( 'opf-b-formula-variable-status' );
			if ( variableStatus ) variableStatus.textContent = __( 'Fix formula variable JSON before saving.', 'open-product-fields-for-woocommerce' );
			return;
		}
		var invalidProductIds = Array.prototype.slice.call( document.querySelectorAll( '#opf-placement-products, #opf-placement-excluded-products, #opf-placement-variations, #opf-placement-excluded-variations' ) ).find( function ( input ) {
			return ! input.checkValidity();
		} );
		if ( invalidProductIds ) {
			invalidProductIds.reportValidity();
			return;
		}
		var invalidRepeatMax = Array.prototype.slice.call( document.querySelectorAll( '[data-opf-repeat-max]' ) ).find( function ( input ) {
			return ! input.checkValidity();
		} );
		if ( invalidRepeatMax ) {
			invalidRepeatMax.reportValidity();
			return;
		}
		var placementAtSave = placementSelection();
		function changed( key ) {
			return JSON.stringify( placementAtSave[ key ] ) !== JSON.stringify( initialPlacementSelection[ key ] );
		}
		// WAPF parity: when every stored rule is a compact-UI inclusion
		// (category/tag/attribute `in`), the selects are authoritative — a
		// UI selection always wins over stored terms even without an edit.
		// Legacy `product_attribute` rules (composite `pa_x:term` terms)
		// round-trip in that dialect; OPF-authored `pa_*` rules keep theirs.
		var placementIsEditable = ( model.rule_groups || [] ).length <= 1
			&& ( model.rule_groups || [] ).every( function ( group ) {
				return ( group.rules || [] ).every( function ( rule ) {
					if ( 'product_attribute' === rule.subject ) return 'in' === rule.operator;
					return in_array( rule.operator, [ 'in', 'not_in' ] )
						&& ( in_array( rule.subject, [ 'product_cat', 'product_tag' ] ) || 0 === rule.subject.indexOf( 'pa_' ) );
				} );
			} );
		var legacyAttributeSubject = ( model.rule_groups || [] ).some( function ( group ) {
			return ( group.rules || [] ).some( function ( rule ) { return rule.subject === 'product_attribute'; } );
		} );
		var changedSubjects = {
			product: changed( 'products' ) || changed( 'excludedProducts' ),
			product_var: changed( 'variations' ) || changed( 'excludedVariations' ),
			var_att: changed( 'variationAttributes' ) || changed( 'excludedVariationAttributes' ),
			product_cat: placementIsEditable || changed( 'cats' ) || changed( 'excludedCats' ),
			product_tag: placementIsEditable || changed( 'tags' ) || changed( 'excludedTags' ),
			product_type: changed( 'types' ) || changed( 'excludedTypes' ),
			user_auth: changed( 'auth' ),
			user_role: changed( 'roles' ) || changed( 'excludedRoles' ),
			user_language: changed( 'language' ) || changed( 'languageOperator' ),
			pa_: placementIsEditable || changed( 'attributes' ) || changed( 'excludedAttributes' ),
		};
		var rules = [];
		if ( changedSubjects.product ) {
			if ( placementAtSave.products.length ) rules.push( { subject: 'product', operator: 'in', terms: placementAtSave.products } );
			if ( placementAtSave.excludedProducts.length ) rules.push( { subject: 'product', operator: 'not_in', terms: placementAtSave.excludedProducts } );
		}
		if ( changedSubjects.product_var ) {
			if ( placementAtSave.variations.length ) rules.push( { subject: 'product_var', operator: 'in', terms: placementAtSave.variations } );
			if ( placementAtSave.excludedVariations.length ) rules.push( { subject: 'product_var', operator: 'not_in', terms: placementAtSave.excludedVariations } );
		}
		if ( changedSubjects.var_att ) {
			if ( placementAtSave.variationAttributes.length ) rules.push( { subject: 'var_att', operator: 'in', terms: placementAtSave.variationAttributes } );
			if ( placementAtSave.excludedVariationAttributes.length ) rules.push( { subject: 'var_att', operator: 'not_in', terms: placementAtSave.excludedVariationAttributes } );
		}
		if ( changedSubjects.product_cat ) {
			if ( placementAtSave.cats.length ) rules.push( { subject: 'product_cat', operator: 'in', terms: placementAtSave.cats } );
			if ( placementAtSave.excludedCats.length ) rules.push( { subject: 'product_cat', operator: 'not_in', terms: placementAtSave.excludedCats } );
		}
		if ( changedSubjects.product_tag ) {
			if ( placementAtSave.tags.length ) rules.push( { subject: 'product_tag', operator: 'in', terms: placementAtSave.tags } );
			if ( placementAtSave.excludedTags.length ) rules.push( { subject: 'product_tag', operator: 'not_in', terms: placementAtSave.excludedTags } );
		}
		if ( changedSubjects.product_type ) {
			if ( placementAtSave.types.length ) rules.push( { subject: 'product_type', operator: 'in', terms: placementAtSave.types } );
			if ( placementAtSave.excludedTypes.length ) rules.push( { subject: 'product_type', operator: 'not_in', terms: placementAtSave.excludedTypes } );
		}
		if ( changedSubjects.pa_ ) {
			var attributesNot = {};
			if ( legacyAttributeSubject ) {
				// WAPF `product_attribute` rules carry composite `pa_x:term`
				// terms; round-trip that dialect for groups authored in it.
				if ( placementAtSave.attributes.length ) rules.push( { subject: 'product_attribute', operator: 'in', terms: placementAtSave.attributes.slice() } );
			} else {
				var attributesIn = {};
				placementAtSave.attributes.forEach( function ( value ) {
					var parts = value.split( ':' );
					( attributesIn[ parts[0] ] = attributesIn[ parts[0] ] || [] ).push( parts[1] );
				} );
				Object.keys( attributesIn ).forEach( function ( taxonomy ) { rules.push( { subject: taxonomy, operator: 'in', terms: attributesIn[ taxonomy ] } ); } );
			}
			placementAtSave.excludedAttributes.forEach( function ( value ) {
				var parts = value.split( ':' );
				( attributesNot[ parts[0] ] = attributesNot[ parts[0] ] || [] ).push( parts[1] );
			} );
			Object.keys( attributesNot ).forEach( function ( taxonomy ) { rules.push( { subject: taxonomy, operator: 'not_in', terms: attributesNot[ taxonomy ] } ); } );
		}
		if ( changedSubjects.user_auth && placementAtSave.auth ) {
			rules.push( { subject: 'user_auth', operator: 'logged_out' === placementAtSave.auth ? 'not_in' : 'in', terms: [ 'logged_in' ] } );
		}
		if ( changedSubjects.user_role ) {
			placementAtSave.roles.forEach( function ( role ) { rules.push( { subject: 'user_role', operator: 'in', terms: [ role ] } ); } );
			placementAtSave.excludedRoles.forEach( function ( role ) { rules.push( { subject: 'user_role', operator: 'not_in', terms: [ role ] } ); } );
		}
		if ( changedSubjects.user_language && placementAtSave.language ) {
			rules.push( { subject: 'user_language', operator: placementAtSave.languageOperator || 'in', terms: [ placementAtSave.language ] } );
		}

		if ( Object.keys( changedSubjects ).some( function ( subject ) { return changedSubjects[ subject ]; } ) ) {
			var groups = ( model.rule_groups || [] ).map( function ( group ) {
				return { rules: ( group.rules || [] ).filter( function ( rule ) {
					if ( changedSubjects[ rule.subject ] ) return false;
					if ( changedSubjects.pa_ && ( 0 === rule.subject.indexOf( 'pa_' ) || 'product_attribute' === rule.subject ) ) return false;
					return true;
				} ) };
			} );
			if ( groups.length ) {
				groups.forEach( function ( group ) { group.rules = group.rules.concat( rules ); } );
				model.rule_groups = groups.filter( function ( group ) { return group.rules.length > 0; } );
			} else {
				model.rule_groups = rules.length ? [ { rules: rules } ] : [];
			}
		}

		var status = document.getElementById( 'opf-b-status' );
		status.textContent = __( 'Saving…', 'open-product-fields-for-woocommerce' );
		window.fetch( restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			body: JSON.stringify( { id: postId, title: document.getElementById( 'title' ) ? document.getElementById( 'title' ).value : '', data: model } )
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( j.id ) {
				initialPlacementSelection = placementAtSave;
				postId = j.id;
				if ( ! parseInt( mount.dataset.postId, 10 ) ) {
					mount.dataset.postId = j.id;
				}
				status.textContent = __( 'Saved.', 'open-product-fields-for-woocommerce' );
			} else {
				status.textContent = sprintf( /* translators: %s: error message. */ __( 'Save failed: %s', 'open-product-fields-for-woocommerce' ), j.message || __( 'unknown error', 'open-product-fields-for-woocommerce' ) );
			}
		} ).catch( function ( e ) {
			status.textContent = sprintf( /* translators: %s: error message. */ __( 'Save failed: %s', 'open-product-fields-for-woocommerce' ), e );
		} );
	}

	function preview() {
		var frame = document.getElementById( 'opf-b-preview' );
		var status = document.getElementById( 'opf-b-status' );
		status.textContent = __( 'Loading preview…', 'open-product-fields-for-woocommerce' );
		window.fetch( previewRest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			body: JSON.stringify( { data: model, product_id: 0 } )
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			frame.innerHTML = j.html || ( j.message || __( 'No preview available.', 'open-product-fields-for-woocommerce' ) );
			status.textContent = '';
		} ).catch( function ( e ) {
			status.textContent = sprintf( /* translators: %s: error message. */ __( 'Preview failed: %s', 'open-product-fields-for-woocommerce' ), e );
		} );
	}

	function placementSelection() {
		function values( selector ) {
			if ( typeof document.querySelectorAll !== 'function' ) return [];
			return Array.prototype.slice.call( document.querySelectorAll( selector ) ).map( function ( option ) { return option.value; } );
		}
		function value( selector ) {
			var control = typeof document.querySelector === 'function' ? document.querySelector( selector ) : null;
			return control ? control.value : '';
		}
		function productIds( selector ) {
			return value( selector ).split( ',' ).map( function ( id ) { return id.trim().replace( /^0+/, '' ); } ).filter( function ( id, index, ids ) {
				return id && ids.indexOf( id ) === index;
			} );
		}
		return {
			products: productIds( '#opf-placement-products' ),
			excludedProducts: productIds( '#opf-placement-excluded-products' ),
			variations: productIds( '#opf-placement-variations' ),
			excludedVariations: productIds( '#opf-placement-excluded-variations' ),
			variationAttributes: values( '#opf-placement-variation-attributes option:checked' ),
			excludedVariationAttributes: values( '#opf-placement-excluded-variation-attributes option:checked' ),
			cats: values( '#opf-placement-cats option:checked' ),
			excludedCats: values( '#opf-placement-excluded-cats option:checked' ),
			tags: values( '#opf-placement-tags option:checked' ),
			excludedTags: values( '#opf-placement-excluded-tags option:checked' ),
			types: values( '#opf-placement-types option:checked' ),
			excludedTypes: values( '#opf-placement-excluded-types option:checked' ),
			attributes: values( '#opf-placement-attributes option:checked' ),
			excludedAttributes: values( '#opf-placement-excluded-attributes option:checked' ),
			auth: value( '#opf-placement-auth' ),
			roles: values( '#opf-placement-roles option:checked' ),
			excludedRoles: values( '#opf-placement-excluded-roles option:checked' ),
			language: value( '#opf-placement-language' ),
			languageOperator: value( '#opf-placement-language-operator' ),
		};
	}

	var initialPlacementSelection = null;
	// Reuse WooCommerce's authenticated product search, retaining optional ID entry.
	if ( window.jQuery ) {
		[ 'products', 'excluded-products', 'variations', 'excluded-variations' ].forEach( function ( name ) {
			var input = document.getElementById( 'opf-placement-' + name );
			var picker = document.getElementById( 'opf-placement-' + name + '-picker' );
			if ( ! input || ! picker ) return;
			window.jQuery( picker ).on( 'change', function () {
				input.value = ( window.jQuery( picker ).val() || [] ).join( ', ' );
			} );
			input.addEventListener( 'input', function () {
				if ( ! input.checkValidity() ) return;
				var ids = input.value.split( ',' ).map( function ( id ) { return id.trim(); } ).filter( function ( id ) { return id; } );
				ids.forEach( function ( id ) {
					if ( ! Array.prototype.some.call( picker.options, function ( option ) { return option.value === id; } ) ) picker.add( new Option( '#' + id, id ) );
				} );
				window.jQuery( picker ).val( ids ).trigger( 'change.select2' );
			} );
		} );
	}

	var toolbar = el( 'div', { class: 'opf-b-toolbar' }, [
		el( 'button', { type: 'button', class: 'button button-primary', text: __( '+ Add field', 'open-product-fields-for-woocommerce' ), onclick: function () {
			model.fields.push( { id: uniqueId( 'field' ), label: '', description: '', type: 'text', required: false, width: 100, choices: [], pricing: { type: 'none', amount: 0, formula: '' }, conditionals: [] } );
			rerender();
		} } ),
		el( 'button', { type: 'button', class: 'button', text: __( 'Save', 'open-product-fields-for-woocommerce' ), onclick: save } ),
		el( 'button', { type: 'button', class: 'button', text: __( 'Refresh preview', 'open-product-fields-for-woocommerce' ), onclick: preview } ),
		el( 'span', { id: 'opf-b-status', class: 'opf-b-status' } )
	] );

	var app = el( 'div', { id: 'opf-builder-fields', class: 'opf-b-fields' } );
	var imageRulesMount = el( 'div', { id: 'opf-builder-image-rules', class: 'opf-b-image-rules-mount' } );
	var variablesMount = el( 'div', { id: 'opf-builder-variables', class: 'opf-b-variables' } );
	var frame = el( 'div', { id: 'opf-b-preview', class: 'opf-b-preview' } );

	// WAPF JSON editors for lookup tables (lookuptable(name; dim; …)) and
	// named formula variables ([var_name]); both persist verbatim on the
	// group model and are validated again inside save().
	var lookupTables = el( 'div', { class: 'opf-b-lookup' }, [
		el( 'h4', { text: __( 'Lookup tables', 'open-product-fields-for-woocommerce' ) } ),
		el( 'p', { class: 'description', text: __( 'Add named tables as rows: each row has one cell per field dimension followed by the price. Use lookuptable(table_name; field_id; …) in a formula. For reusable site-wide tables, register rows with the opf_lookup_tables filter.', 'open-product-fields-for-woocommerce' ) } ),
		el( 'textarea', { id: 'opf-b-lookup-tables', class: 'opf-b-input', rows: '8', value: JSON.stringify( model.lookup_tables, null, 2 ) } ),
		el( 'span', { id: 'opf-b-lookup-status', class: 'opf-b-status' } ),
	] );
	lookupTables.children[ 2 ].addEventListener( 'input', function ( event ) {
		try {
			var parsedTables = JSON.parse( event.target.value );
			if ( ! parsedTables || typeof parsedTables !== 'object' || Array.isArray( parsedTables ) ) throw new Error( 'Expected an object.' );
			model.lookup_tables = parsedTables;
			var lookupStatus = document.getElementById( 'opf-b-lookup-status' );
			if ( lookupStatus ) lookupStatus.textContent = '';
		} catch ( error ) {
			var lookupStatusErr = document.getElementById( 'opf-b-lookup-status' );
			if ( lookupStatusErr ) lookupStatusErr.textContent = __( 'Invalid JSON; save is disabled until corrected.', 'open-product-fields-for-woocommerce' );
		}
	} );
	var formulaVariables = el( 'div', { class: 'opf-b-lookup' }, [
		el( 'h4', { text: __( 'Formula variables', 'open-product-fields-for-woocommerce' ) } ),
		el( 'p', { class: 'description', text: __( 'Define numeric defaults and ordered conditional changes. Use [var_name] in formulas. The first matching change wins; site-wide reusable variables can be registered with the opf_formula_variables filter, and a group definition with the same name overrides it.', 'open-product-fields-for-woocommerce' ) } ),
		el( 'textarea', { id: 'opf-b-formula-variables', class: 'opf-b-input', rows: '8', value: JSON.stringify( model.formula_variables, null, 2 ) } ),
		el( 'span', { id: 'opf-b-formula-variable-status', class: 'opf-b-status' } ),
	] );
	formulaVariables.children[ 2 ].addEventListener( 'input', function ( event ) {
		try {
			var parsedVars = JSON.parse( event.target.value );
			if ( ! parsedVars || typeof parsedVars !== 'object' || Array.isArray( parsedVars ) ) throw new Error( 'Expected an object.' );
			model.formula_variables = parsedVars;
			var variableStatus = document.getElementById( 'opf-b-formula-variable-status' );
			if ( variableStatus ) variableStatus.textContent = '';
		} catch ( error ) {
			var variableStatusErr = document.getElementById( 'opf-b-formula-variable-status' );
			if ( variableStatusErr ) variableStatusErr.textContent = __( 'Invalid JSON; save is disabled until corrected.', 'open-product-fields-for-woocommerce' );
		}
	} );

	mount.appendChild( toolbar );
	mount.appendChild( app );
	mount.appendChild( imageRulesMount );
	mount.appendChild( el( 'h4', { text: __( 'Custom variables', 'open-product-fields-for-woocommerce' ) } ) );
	mount.appendChild( variablesMount );
	mount.appendChild( lookupTables );
	mount.appendChild( formulaVariables );
	mount.appendChild( el( 'h4', { text: __( 'Preview', 'open-product-fields-for-woocommerce' ) } ) );
	mount.appendChild( frame );

	rerender();
	initialPlacementSelection = placementSelection();
} )();
