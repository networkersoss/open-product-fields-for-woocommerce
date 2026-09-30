/**
 * OPF field builder. Dependency-free vanilla JS — no jQuery, no build step.
 * Edits a JSON model and persists through the REST API.
 */
( function () {
	'use strict';

	var mount = document.getElementById( 'opf-builder-app' );
	if ( ! mount ) {
		return;
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

	var TYPES = [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'upload', 'toggle', 'select', 'radio', 'checkbox', 'swatch', 'child_products', 'paragraph', 'html', 'shortcode', 'content_image', 'section', 'calculation' ];
	var CHOICE_PRICING = [ 'none', 'fixed', 'percent', 'formula' ];

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
		var slugInput = el( 'input', { class: 'opf-b-input opf-b-slug', value: choice.slug, placeholder: 'slug', oninput: function ( e ) {
			choice.slug = e.target.value;
		} } );
		var labelInput = el( 'input', { class: 'opf-b-input', value: choice.label, oninput: function ( e ) {
			choice.label = e.target.value;
		} } );
		var typeSelect = el( 'select', { class: 'opf-b-input' },
			CHOICE_PRICING.map( function ( t ) {
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
		var amountInput = el( 'input', { class: 'opf-b-input', type: 'text', value: choice.pricing.amount || '', placeholder: 'amount' } );
		amountInput.addEventListener( 'input', function ( e ) {
			choice.pricing.amount = parseFloat( e.target.value ) || 0;
		} );
		var formulaInput = el( 'input', { class: 'opf-b-input', type: 'text', value: choice.pricing.formula || '', placeholder: '([price] + [addons]) * 0.2', title: 'Also supports acf(field_name) and acf_option(field_name) for numeric ACF fields.' } );
		formulaInput.addEventListener( 'input', function ( e ) {
			choice.pricing.formula = e.target.value;
		} );
		var pricingOptions = [];
		if ( [ 'fixed', 'percent' ].indexOf( choice.pricing.type ) !== -1 ) {
			var perUnit = el( 'input', { type: 'checkbox' } );
			perUnit.checked = undefined !== choice.pricing.per_unit ? !! choice.pricing.per_unit : 'fixed' !== choice.pricing.type;
			perUnit.addEventListener( 'change', function () {
				choice.pricing.per_unit = perUnit.checked;
			} );
			pricingOptions.push( el( 'label', { class: 'opf-b-pricing-unit' }, [ perUnit, document.createTextNode( ' Multiply by product quantity' ) ] ) );
		}
		var selected = el( 'input', { type: 'checkbox', title: 'Preselected' } );
		selected.checked = !! choice.selected;
		selected.addEventListener( 'change', function () {
			choice.selected = selected.checked;
		} );
		var disabled = el( 'input', { type: 'checkbox', title: 'Unavailable option: ' + choice.label } );
		disabled.checked = !! choice.disabled;
		disabled.addEventListener( 'change', function () {
			choice.disabled = disabled.checked;
		} );
		var disabledLabel = el( 'label', { class: 'opf-b-choice-disabled' }, [ disabled, document.createTextNode( ' Unavailable' ) ] );
		var remove = el( 'button', { class: 'button-link opf-b-remove', text: '×', onclick: function () {
			field.choices.splice( index, 1 );
			rerender();
		} } );

		var row = el( 'div', { class: 'opf-b-choice' }, [
			selected, slugInput, labelInput, disabledLabel, typeSelect,
			'formula' === choice.pricing.type ? formulaInput : amountInput,
			...pricingOptions,
			remove
		] );
		if ( [ 'swatch', 'select', 'radio', 'checkbox' ].indexOf( field.type ) !== -1 ) {
			var colorInput = el( 'input', { class: 'opf-b-input', type: 'text', value: choice.color || '', placeholder: '#RRGGBB' } );
			var imageInput = el( 'input', { class: 'opf-b-input', type: 'url', value: choice.image || '', placeholder: 'Image URL (optional)' } );
			imageInput.addEventListener( 'input', function ( e ) { choice.image = e.target.value; } );
			if ( 'swatch' === field.type ) {
				var weightInput = el( 'input', { class: 'opf-b-input', type: 'number', value: choice.weight === undefined ? '' : choice.weight, placeholder: 'Weight delta' } );
				colorInput.addEventListener( 'input', function ( e ) { choice.color = e.target.value; } );
				weightInput.addEventListener( 'input', function ( e ) { choice.weight = e.target.value; } );
				row.appendChild( colorInput );
				row.appendChild( weightInput );
			}
			row.appendChild( imageInput );
		}
		if ( 'radio' === field.type && field.card_layout ) {
			var cardImageInput = el( 'input', { class: 'opf-b-input', type: 'url', value: choice.image || '', placeholder: 'Card image URL (optional)' } );
			cardImageInput.addEventListener( 'input', function ( e ) { choice.image = e.target.value; } );
			var cardDescriptionInput = el( 'input', { class: 'opf-b-input', value: choice.description || '', placeholder: 'Card description (optional)' } );
			cardDescriptionInput.addEventListener( 'input', function ( e ) { choice.description = e.target.value; } );
			row.appendChild( cardImageInput );
			row.appendChild( cardDescriptionInput );
		}
		return row;
	}

	function fieldCard( field, index ) {
		field.choices = Array.isArray( field.choices ) ? field.choices : [];
		var label = el( 'input', { class: 'opf-b-input opf-b-label', value: field.label, placeholder: 'Field label' } );
		label.addEventListener( 'input', function ( e ) {
			field.label = e.target.value;
		} );

		var typeSel = el( 'select', { class: 'opf-b-input', title: 'Field type' }, TYPES.map( function ( t ) {
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
			if ( 'swatch' !== field.type ) {
				delete field.multiple;
				delete field.image_quantities;
			}
			if ( [ 'paragraph', 'html', 'shortcode', 'content_image', 'section', 'calculation' ].indexOf( field.type ) !== -1 ) {
				field.required = false;
				field.choices = [];
				field.pricing = { type: 'none', amount: 0, formula: '' };
				if ( 'calculation' === field.type ) {
					field.formula = field.formula || '';
					field.result_text = field.result_text || '{result}';
				} else if ( 'content_image' === field.type ) {
					field.image_url = field.image_url || '';
					field.alt = field.alt || '';
				} else {
					field.content = field.content || '';
				}
			}
			if ( in_array( field.type, [ 'swatch', 'select', 'radio', 'checkbox' ] ) && ! field.choices.length ) {
				field.choices = [ { slug: 'option-1', label: 'Option 1', selected: false, disabled: false, pricing: { type: 'none', amount: 0, formula: '' } } ];
			}
			rerender();
		} );

		var req = el( 'input', { type: 'checkbox', title: 'Required' } );
		req.checked = !! field.required;
		req.addEventListener( 'change', function () {
			field.required = req.checked;
		} );

		var multiple = el( 'input', { type: 'checkbox', title: 'Allow multiple selections' } );
		multiple.checked = 'swatch' === field.type && !! field.multiple && ! field.image_quantities;
		multiple.addEventListener( 'change', function () {
			field.multiple = multiple.checked;
			if ( field.multiple ) delete field.image_quantities;
			if ( ! field.multiple ) {
				delete field.min_selections;
				delete field.max_selections;
			}
			rerender();
		} );
		var imageQuantities = el( 'input', { type: 'checkbox', title: 'Quantity for each image choice' } );
		imageQuantities.checked = 'swatch' === field.type && !! field.image_quantities;
		imageQuantities.addEventListener( 'change', function () {
			if ( imageQuantities.checked && field.choices.some( function ( choice ) { return ! choice.image; } ) ) {
				imageQuantities.checked = false;
				window.alert( 'Add an image to every choice before enabling image quantities.' );
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
		var imageZoom = el( 'input', { type: 'checkbox', title: 'Enlarge image swatches on hover or keyboard focus' } );
		imageZoom.checked = 'swatch' === field.type && ! field.image_quantities && !! field.image_zoom;
		imageZoom.addEventListener( 'change', function () {
			field.image_zoom = imageZoom.checked;
			rerender();
		} );
		var imageQuantityZoom = el( 'input', { type: 'checkbox', title: 'Enlarge image+quantity choices on hover or keyboard focus' } );
		imageQuantityZoom.checked = 'swatch' === field.type && !! field.image_quantities && !! field.image_quantity_zoom;
		imageQuantityZoom.addEventListener( 'change', function () {
			field.image_quantity_zoom = imageQuantityZoom.checked;
			rerender();
		} );
		var changeProductImage = el( 'input', { type: 'checkbox', title: 'Change the main product image when this choice is selected' } );
		changeProductImage.checked = [ 'swatch', 'select', 'radio', 'checkbox' ].indexOf( field.type ) !== -1 && !! field.change_product_image;
		changeProductImage.addEventListener( 'change', function () {
			field.change_product_image = changeProductImage.checked;
			rerender();
		} );

		var desc = el( 'input', { class: 'opf-b-input', value: field.description || '', placeholder: 'Description (optional)' } );
		desc.addEventListener( 'input', function ( e ) {
			field.description = e.target.value;
		} );
		var descriptionPresentation = el( 'select', { class: 'opf-b-input', title: 'Instruction presentation' }, [
			el( 'option', { value: 'inline', text: 'Instruction: inline' } ),
			el( 'option', { value: 'tooltip', text: 'Instruction: tooltip' } ),
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
		var prefillInput = el( 'input', { class: 'opf-b-input', value: field.prefill_param || '', placeholder: 'URL query key (optional)' } );
		prefillInput.addEventListener( 'input', function ( e ) {
			if ( '' === e.target.value ) {
				delete field.prefill_param;
			} else {
				field.prefill_param = e.target.value;
			}
		} );
		var defaultInput = el( 'input', { class: 'opf-b-input', value: Array.isArray( field.default ) ? field.default.join( ', ' ) : ( field.default || '' ), placeholder: 'Default value (optional)' } );
		defaultInput.addEventListener( 'input', function ( e ) {
			if ( '' === e.target.value ) {
				delete field.default;
			} else if ( 'checkbox' === field.type || ( 'swatch' === field.type && field.multiple ) ) {
				field.default = e.target.value.split( ',' ).map( function ( value ) { return value.trim(); } ).filter( Boolean );
			} else {
				field.default = e.target.value;
			}
		} );

		var remove = el( 'button', { class: 'button button-link-delete', text: 'Delete field', onclick: function () {
			model.fields.splice( index, 1 );
			rerender();
		} } );

		var head;
		if ( 'content_image' === field.type ) {
			var imageUrl = el( 'input', { class: 'opf-b-input', type: 'url', value: field.image_url || '', placeholder: 'Image URL (https or local path)' } );
			imageUrl.addEventListener( 'input', function ( e ) { field.image_url = e.target.value; } );
			var imageAlt = el( 'input', { class: 'opf-b-input', value: field.alt || '', placeholder: 'Alternative text' } );
			imageAlt.addEventListener( 'input', function ( e ) { field.alt = e.target.value; } );
			head = el( 'div', { class: 'opf-b-field-head' }, [ typeSel, imageUrl, imageAlt, remove ] );
		} else if ( 'calculation' === field.type ) {
			var calculationType = el( 'select', { class: 'opf-b-input' }, [
				el( 'option', { value: 'informational', text: 'Informational result' } ),
				el( 'option', { value: 'price', text: 'Adjust product price' } ),
			] );
			calculationType.value = field.calculation_type || 'informational';
			calculationType.addEventListener( 'change', function ( e ) { field.calculation_type = e.target.value; } );
			var calculationFormula = el( 'input', { class: 'opf-b-input', value: field.formula || '', placeholder: '[field.width] * [field.height]' } );
			calculationFormula.addEventListener( 'input', function ( e ) { field.formula = e.target.value; } );
			var calculationText = el( 'input', { class: 'opf-b-input', value: field.result_text || '{result}', placeholder: '{result} units' } );
			calculationText.addEventListener( 'input', function ( e ) { field.result_text = e.target.value; } );
			head = el( 'div', { class: 'opf-b-field-head' }, [ label, typeSel, calculationType, calculationFormula, calculationText, remove ] );
		} else if ( 'paragraph' === field.type || 'html' === field.type || 'shortcode' === field.type || 'section' === field.type ) {
			var content = el( 'textarea', { class: 'opf-b-input', value: field.content || '', placeholder: 'html' === field.type ? 'Safe HTML content' : ( 'shortcode' === field.type ? 'Shortcode or third-party content' : 'Paragraph content' ) } );
			if ( 'section' === field.type ) {
				var heading = el( 'input', { class: 'opf-b-input', value: field.heading || '', placeholder: 'Section heading' } );
				heading.addEventListener( 'input', function ( e ) { field.heading = e.target.value; } );
				head = el( 'div', { class: 'opf-b-field-head' }, [ typeSel, heading, content, remove ] );
			} else {
				head = el( 'div', { class: 'opf-b-field-head' }, [ typeSel, content, remove ] );
			}
			content.addEventListener( 'input', function ( e ) {
				field.content = e.target.value;
			} );
		} else {
			var weightFormula = el( 'input', { class: 'opf-b-input', value: field.weight_formula || '', title: 'Extra product weight formula', placeholder: 'Extra weight formula, e.g. [field.weight] * 1' } );
			weightFormula.addEventListener( 'input', function ( e ) {
				if ( '' === e.target.value.trim() ) delete field.weight_formula;
				else field.weight_formula = e.target.value;
			} );
			var headParts = [ label, typeSel, desc, descriptionPresentation, defaultInput, prefillInput, req, weightFormula ];
			[ [ 'hide_cart', 'Hide in cart' ], [ 'hide_checkout', 'Hide in checkout' ], [ 'hide_order', 'Hide on order received and emails' ] ].forEach( function ( setting ) {
				var visibility = el( 'input', { type: 'checkbox', title: setting[1], 'data-opf-visibility': setting[0] } );
				visibility.checked = !! field[ setting[0] ];
				visibility.addEventListener( 'change', function () {
					if ( visibility.checked ) field[ setting[0] ] = true;
					else delete field[ setting[0] ];
				} );
				headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ visibility, document.createTextNode( ' ' + setting[1] ) ] ) );
			} );
			if ( 'child_products' === field.type ) {
				field.product_ids = Array.isArray( field.product_ids ) ? field.product_ids : [];
				field.category_ids = Array.isArray( field.category_ids ) ? field.category_ids : [];
				var childSource = el( 'select', { class: 'opf-b-input', title: 'Product source' }, [ el( 'option', { value: 'specific', text: 'Specific products' } ), el( 'option', { value: 'categories', text: 'Product categories' } ) ] );
				childSource.value = field.product_source || 'specific';
				childSource.addEventListener( 'change', function () { field.product_source = childSource.value; rerender(); } );
				var childDisplay = el( 'select', { class: 'opf-b-input', title: 'Product display' }, [ 'checkboxes', 'radio', 'select', 'cards', 'images' ].map( function ( value ) { return el( 'option', { value: value, text: value } ); } ) );
				childDisplay.value = field.product_display || 'checkboxes';
				childDisplay.addEventListener( 'change', function () { field.product_display = childDisplay.value; rerender(); } );
				var childMultiple = el( 'input', { type: 'checkbox', title: 'Allow multiple child products' } );
				childMultiple.checked = undefined === field.multiple ? [ 'checkboxes', 'images' ].indexOf( childDisplay.value ) !== -1 : !! field.multiple;
				childMultiple.addEventListener( 'change', function () { field.multiple = childMultiple.checked; } );
				var childQuantity = el( 'input', { type: 'checkbox', title: 'Show quantity per child product' } );
				childQuantity.checked = !! field.quantity_input;
				childQuantity.addEventListener( 'change', function () { field.quantity_input = childQuantity.checked; rerender(); } );
				var childZoom = el( 'input', { type: 'checkbox', title: 'Enlarge product images on hover or keyboard focus' } );
				childZoom.checked = !! field.image_zoom;
				childZoom.addEventListener( 'change', function () { field.image_zoom = childZoom.checked; } );
				var childMode = el( 'select', { class: 'opf-b-input', title: 'Child quantity behavior' }, [ el( 'option', { value: 'per_parent', text: 'Quantity per parent item' } ), el( 'option', { value: 'multiply_parent', text: 'Multiply by parent quantity' } ) ] );
				childMode.value = field.quantity_mode || 'per_parent';
				childMode.addEventListener( 'change', function () { field.quantity_mode = childMode.value; } );
				var childSearch = el( 'input', { class: 'opf-b-input', type: 'search', placeholder: 'Search products or categories' } );
				var childResults = el( 'select', { class: 'opf-b-input', size: '5', 'aria-label': 'Search results' } );
				childSearch.addEventListener( 'input', function () {
					var url = restUrl.replace( /\/groups\/?$/, '/child-products' ) + '?source=' + encodeURIComponent( childSource.value ) + '&search=' + encodeURIComponent( childSearch.value );
					window.fetch( url, { headers: { 'X-WP-Nonce': nonce } } ).then( function ( response ) { return response.json(); } ).then( function ( items ) {
						childResults.textContent = '';
						(items || []).forEach( function ( item ) { childResults.appendChild( el( 'option', { value: item.id, text: item.name + ' (#' + item.id + ')' } ) ); } );
					} ).catch( function () { childResults.textContent = ''; } );
				} );
				childResults.addEventListener( 'change', function () {
					var list = 'categories' === childSource.value ? field.category_ids : field.product_ids;
					var id = parseInt( childResults.value, 10 );
					if ( id > 0 && list.indexOf( id ) === -1 && list.length < ( 'categories' === childSource.value ? 100 : 500 ) ) list.push( id );
					rerender();
				} );
				var idsInput = el( 'input', { class: 'opf-b-input', value: ( 'categories' === childSource.value ? field.category_ids : field.product_ids ).join( ', ' ), placeholder: 'Selected IDs, comma separated' } );
				idsInput.addEventListener( 'input', function () {
					var ids = idsInput.value.split( ',' ).map( function ( value ) { return parseInt( value.trim(), 10 ); } ).filter( function ( value, index, list ) { return value > 0 && list.indexOf( value ) === index; } );
					if ( 'categories' === childSource.value ) field.category_ids = ids.slice( 0, 100 ); else field.product_ids = ids.slice( 0, 500 );
				} );
				var childBounds = el( 'div', { class: 'opf-b-child-bounds' }, [
					el( 'input', { class: 'opf-b-input', type: 'number', min: '0', value: undefined === field.min_selections ? '' : field.min_selections, placeholder: 'Minimum selections' } ),
					el( 'input', { class: 'opf-b-input', type: 'number', min: '0', value: undefined === field.max_selections ? '' : field.max_selections, placeholder: 'Maximum selections' } )
				] );
				childBounds.children[0].addEventListener( 'input', function ( e ) { if ( '' === e.target.value ) delete field.min_selections; else field.min_selections = parseInt( e.target.value, 10 ); } );
				childBounds.children[1].addEventListener( 'input', function ( e ) { if ( '' === e.target.value ) delete field.max_selections; else field.max_selections = parseInt( e.target.value, 10 ); } );
				headParts.push( childSource, childSearch, childResults, idsInput, childDisplay, childMode, childBounds );
				headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ childMultiple, document.createTextNode( ' Allow multiple selections' ) ] ) );
				headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ childQuantity, document.createTextNode( ' Show child quantity inputs' ) ] ) );
				headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ childZoom, document.createTextNode( ' Enlarge images on hover or focus' ) ] ) );
			}
			if ( 'radio' === field.type ) {
				var cardLayout = el( 'select', { class: 'opf-b-input', 'data-opf-card-layout': '1', title: 'Radio card layout' }, [
					el( 'option', { value: '', text: 'Radio list' } ),
					el( 'option', { value: 'horizontal', text: 'Horizontal cards' } ),
					el( 'option', { value: 'vertical', text: 'Vertical cards' } ),
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
				headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ multiple, document.createTextNode( ' Allow multiple selections' ) ] ) );
				headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ imageQuantities, document.createTextNode( ' Quantity per image choice' ) ] ) );
				if ( field.choices.some( function ( choice ) { return !! choice.image; } ) ) {
					if ( field.image_quantities ) {
						if ( field.choices.every( function ( choice ) { return !! choice.image; } ) ) {
							headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ imageQuantityZoom, document.createTextNode( ' Enlarge image+quantity choices on hover or keyboard focus' ) ] ) );
						}
					} else {
						headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ imageZoom, document.createTextNode( ' Enlarge image swatches on hover or keyboard focus' ) ] ) );
					}
				}
			}
			if ( [ 'swatch', 'select', 'radio', 'checkbox' ].indexOf( field.type ) !== -1 ) {
				headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ changeProductImage, document.createTextNode( ' Change main product image from selected image choice' ) ] ) );
			}
			if ( [ 'toggle', 'checkbox' ].indexOf( field.type ) !== -1 ) {
				var switchControl = el( 'input', { type: 'checkbox', title: 'Display as switches' } );
				switchControl.checked = !! field.switch_control;
				switchControl.addEventListener( 'change', function () {
					if ( switchControl.checked ) field.switch_control = true;
					else delete field.switch_control;
					rerender();
				} );
				headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ switchControl, document.createTextNode( ' Display as switches' ) ] ) );
			}
			if ( 'checkbox' === field.type ) {
				var checkboxColumns = el( 'input', { class: 'opf-b-input', type: 'number', min: '1', max: '12', step: '1', value: field.columns === undefined ? '1' : String( field.columns ), title: 'Checkbox columns' } );
				checkboxColumns.addEventListener( 'input', function ( event ) {
					if ( '' === event.target.value ) delete field.columns;
					else field.columns = Math.max( 1, Math.min( 12, parseInt( event.target.value || '1', 10 ) || 1 ) );
				} );
				headParts.push( el( 'label', { class: 'opf-b-multiple' }, [ checkboxColumns, document.createTextNode( ' Checkbox columns (1–12)' ) ] ) );
			}
			headParts.push( remove );
			head = el( 'div', { class: 'opf-b-field-head' }, headParts );
		}
		var card = el( 'div', { class: 'opf-b-field' }, [ head ] );
		if ( [ 'text', 'textarea', 'number' ].indexOf( field.type ) !== -1 ) {
			field.pricing = field.pricing || { type: 'none', amount: 0, formula: '' };
			var fieldPricingTypes = 'number' === field.type
				? [ 'none', 'fixed', 'percent', 'quantity', 'value', 'formula' ]
				: [ 'none', 'fixed', 'percent', 'quantity', 'characters', 'formula' ];
			var fieldPricingSelect = el( 'select', { class: 'opf-b-input' }, fieldPricingTypes.map( function ( type ) {
				var option = el( 'option', { value: type, text: type } );
				if ( type === ( field.pricing.type || 'none' ) ) {
					option.selected = true;
				}
				return option;
			} ) );
			fieldPricingSelect.addEventListener( 'change', function () {
				field.pricing.type = fieldPricingSelect.value;
				if ( [ 'fixed', 'percent', 'characters', 'value' ].indexOf( field.pricing.type ) !== -1 ) {
					field.pricing.per_unit = 'fixed' !== field.pricing.type;
				} else {
					delete field.pricing.per_unit;
				}
				rerender();
			} );
			var fieldPricingValue = el( 'input', {
				class: 'opf-b-input', type: 'text',
				value: 'formula' === field.pricing.type ? ( field.pricing.formula || '' ) : ( field.pricing.amount || '' ),
				placeholder: 'formula' === field.pricing.type ? '([price] + [addons]) * 0.2' : 'amount',
				title: 'formula' === field.pricing.type ? 'Also supports acf(field_name) and acf_option(field_name) for numeric ACF fields.' : ''
			} );
			fieldPricingValue.addEventListener( 'input', function ( e ) {
				if ( 'formula' === field.pricing.type ) {
					field.pricing.formula = e.target.value;
				} else {
					field.pricing.amount = parseFloat( e.target.value ) || 0;
				}
			} );
			var pricingParts = [ fieldPricingSelect, fieldPricingValue ];
			if ( [ 'fixed', 'percent', 'characters', 'value' ].indexOf( field.pricing.type ) !== -1 ) {
				var fieldPerUnit = el( 'input', { type: 'checkbox' } );
				fieldPerUnit.checked = undefined !== field.pricing.per_unit ? !! field.pricing.per_unit : 'fixed' !== field.pricing.type;
				fieldPerUnit.addEventListener( 'change', function () {
					field.pricing.per_unit = fieldPerUnit.checked;
				} );
				pricingParts.push( el( 'label', { class: 'opf-b-pricing-unit' }, [ fieldPerUnit, document.createTextNode( ' Multiply by product quantity' ) ] ) );
			}
			card.appendChild( el( 'div', { class: 'opf-b-constraints opf-b-pricing' }, pricingParts ) );
		}
		var constrainedTypes = [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'upload' ];
		if ( constrainedTypes.indexOf( field.type ) !== -1 || ( 'swatch' === field.type && field.image_quantities ) ) {
			var constraintFields = [];
			function constraintInput( key, label, type ) {
				var input = el( 'input', { class: 'opf-b-input', type: type || 'text', value: field[ key ] === undefined ? '' : field[ key ], placeholder: label } );
				input.addEventListener( 'input', function ( e ) {
					if ( '' === e.target.value ) {
						delete field[ key ];
					} else {
						field[ key ] = e.target.value;
					}
				} );
				return input;
			}
			if ( 'number' === field.type || ( 'swatch' === field.type && field.image_quantities ) ) {
				if ( 'number' === field.type ) {
					var numberMode = el( 'select', { class: 'opf-b-input', title: 'Number mode', 'data-opf-number-mode': '1' }, [
						el( 'option', { value: 'integer', text: 'Whole numbers' } ),
						el( 'option', { value: 'decimal', text: 'Integers and decimals' } ),
					] );
					numberMode.value = field.number_mode || 'decimal';
					numberMode.addEventListener( 'change', function () {
						field.number_mode = numberMode.value;
					} );
					constraintFields.push( el( 'label', { class: 'opf-b-number-mode', text: 'Number type ' }, [ numberMode ] ) );
				}
				constraintFields.push( constraintInput( 'min', 'Minimum', 'number' ), constraintInput( 'max', 'Maximum', 'number' ), constraintInput( 'step', 'Step', 'number' ) );
				if ( 'swatch' === field.type && field.image_quantities ) {
					constraintFields.push(
						constraintInput( 'min_selections', 'Minimum images selected', 'number' ),
						constraintInput( 'max_selections', 'Maximum images selected', 'number' ),
						constraintInput( 'min_total_quantity', 'Minimum total quantity', 'number' ),
						constraintInput( 'max_total_quantity', 'Maximum total quantity', 'number' )
					);
				}
			} else if ( 'date' === field.type ) {
				[ [ 'allow_past', 'Allow past dates' ], [ 'allow_future', 'Allow future dates' ] ].forEach( function ( setting ) {
					var checkbox = el( 'input', { type: 'checkbox', 'data-opf-date-policy': setting[ 0 ] } );
					checkbox.checked = field[ setting[ 0 ] ] !== false;
					checkbox.addEventListener( 'change', function () {
						field[ setting[ 0 ] ] = checkbox.checked;
					} );
					constraintFields.push( el( 'label', { class: 'opf-b-date-policy' }, [ checkbox, document.createTextNode( setting[ 1 ] ) ] ) );
				} );
				constraintFields.push( constraintInput( 'min_date', 'Minimum date (YYYY-MM-DD or 7d)' ), constraintInput( 'max_date', 'Maximum date (YYYY-MM-DD or 2m)' ), constraintInput( 'cutoff_time', 'Disable today at', 'time' ) );
				var weekdaysInput = el( 'input', { class: 'opf-b-input', type: 'text', value: Array.isArray( field.disabled_weekdays ) ? field.disabled_weekdays.join( ', ' ) : '', placeholder: 'Disabled weekdays (0=Sun … 6=Sat)' } );
				weekdaysInput.addEventListener( 'input', function () {
					var values = weekdaysInput.value.split( ',' ).map( function ( value ) { return value.trim(); } ).filter( Boolean );
					if ( values.length && values.every( function ( value ) { return /^[0-6]$/.test( value ); } ) ) field.disabled_weekdays = values.map( Number );
					else if ( ! values.length ) delete field.disabled_weekdays;
				} );
				var disabledDatesInput = el( 'input', { class: 'opf-b-input', type: 'text', value: Array.isArray( field.disabled_dates ) ? field.disabled_dates.join( ', ' ) : '', placeholder: 'Disabled dates (YYYY-MM-DD or recurring MM-DD)' } );
				disabledDatesInput.addEventListener( 'input', function () {
					var values = disabledDatesInput.value.split( ',' ).map( function ( value ) { return value.trim(); } ).filter( Boolean );
					if ( values.length ) field.disabled_dates = values;
					else delete field.disabled_dates;
				} );
				constraintFields.push( weekdaysInput, disabledDatesInput );
			} else if ( 'checkbox' === field.type || ( 'swatch' === field.type && ( field.multiple || field.image_quantities ) ) ) {
				constraintFields.push( constraintInput( 'min_selections', 'Minimum selections', 'number' ), constraintInput( 'max_selections', 'Maximum selections', 'number' ) );
			} else if ( 'upload' === field.type ) {
				var resizeInput = el( 'input', { type: 'checkbox', 'data-opf-auto-resize': '1' } );
				resizeInput.checked = true === field.auto_resize;
				resizeInput.addEventListener( 'change', function () {
					if ( resizeInput.checked ) field.auto_resize = true;
					else delete field.auto_resize;
				} );
				var editorMode = el( 'select', { class: 'opf-b-input', 'data-opf-image-editor-mode': '1' }, [
					el( 'option', { value: '', text: 'Image editor disabled' } ),
					el( 'option', { value: 'optional', text: 'Optional: edit button' } ),
					el( 'option', { value: 'forced', text: 'Required: edit before upload' } ),
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
				var editorControls = [];
				[ [ 'image_editor_crop', 'Allow crop' ], [ 'image_editor_resize', 'Allow zoom/resize' ], [ 'image_editor_rotate', 'Allow rotate' ], [ 'image_editor_flip', 'Allow flip' ] ].forEach( function ( setting ) {
					var checkbox = el( 'input', { type: 'checkbox' } );
					checkbox.checked = undefined === field[ setting[ 0 ] ] ? true : !! field[ setting[ 0 ] ];
					checkbox.addEventListener( 'change', function () { field[ setting[ 0 ] ] = checkbox.checked; } );
					editorControls.push( el( 'label', { class: 'opf-b-upload-editor-option' }, [ checkbox, document.createTextNode( ' ' + setting[ 1 ] ) ] ) );
				} );
				var aspectRatio = el( 'select', { class: 'opf-b-input', 'data-opf-image-editor-aspect': '1' }, [
					el( 'option', { value: 'free', text: 'Free crop' } ),
					el( 'option', { value: '1:1', text: 'Square 1:1' } ),
					el( 'option', { value: '4:3', text: '4:3' } ),
					el( 'option', { value: '3:2', text: '3:2' } ),
					el( 'option', { value: '16:9', text: '16:9' } ),
					el( 'option', { value: '2:3', text: '2:3' } ),
					el( 'option', { value: '9:16', text: '9:16' } ),
				] );
				aspectRatio.value = field.image_editor_aspect_ratio || 'free';
				aspectRatio.addEventListener( 'change', function () { field.image_editor_aspect_ratio = aspectRatio.value; } );
				var typesInput = el( 'input', {
					class: 'opf-b-input',
					type: 'text',
					value: Array.isArray( field.allowed_types ) ? field.allowed_types.join( ', ' ) : '',
					placeholder: 'Allowed types (png, jpg, application/pdf)'
				} );
				typesInput.addEventListener( 'input', function ( e ) {
					var types = e.target.value.split( ',' ).map( function ( t ) {
						return t.trim();
					} ).filter( Boolean );
					if ( types.length ) {
						field.allowed_types = types;
					} else {
						delete field.allowed_types;
					}
				} );
				constraintFields.push(
					constraintInput( 'min_files', 'Minimum files', 'number' ),
					constraintInput( 'max_files', 'Maximum files (-1 for unlimited)', 'number' ),
					constraintInput( 'max_size', 'Max size in bytes', 'number' ),
					constraintInput( 'min_size_mb', 'Minimum file size (MB)', 'number', '0.01' ),
					constraintInput( 'min_width', 'Minimum image width (pixels)', 'number' ),
					constraintInput( 'min_height', 'Minimum image height (pixels)', 'number' ),
					el( 'label', { class: 'opf-b-upload-resize' }, [ resizeInput, document.createTextNode( ' Automatically resize images' ) ] ),
					constraintInput( 'max_width', 'Maximum image width (pixels)', 'number' ),
					constraintInput( 'max_height', 'Maximum image height (pixels)', 'number' ),
					editorMode,
					el( 'div', { class: 'opf-b-upload-editor-options' }, editorControls ),
					aspectRatio,
					typesInput
				);
			} else {
				constraintFields.push( constraintInput( 'minlength', 'Min length', 'number' ), constraintInput( 'maxlength', 'Max length', 'number' ), constraintInput( 'pattern', 'Pattern' ) );
			}
			card.appendChild( el( 'div', { class: 'opf-b-constraints' }, constraintFields ) );
		}

		if ( [ 'select', 'radio', 'checkbox', 'swatch' ].indexOf( field.type ) !== -1 ) {
			var addChoice = el( 'button', { class: 'button', text: '+ Add choice', onclick: function () {
				var n = field.choices.length + 1;
				field.choices.push( { slug: 'option-' + n, label: 'Option ' + n, selected: false, disabled: false, pricing: { type: 'none', amount: 0, formula: '' } } );
				rerender();
			} } );
			var header = el( 'div', { class: 'opf-b-choices-header', html: '<strong>Choices</strong> <em>(slug · label · pricing)</em>' } );
			var list = el( 'div', { class: 'opf-b-choices' }, field.choices.map( function ( c, i ) {
				return choiceRow( field, c, i );
			} ) );
			card.appendChild( header );
			card.appendChild( list );
			card.appendChild( addChoice );
		}
		if ( [ 'select', 'radio', 'checkbox', 'swatch' ].indexOf( field.type ) !== -1 ) {
			var bulkHelpId = 'opf-b-bulk-choice-help-' + String( field.id || index ).replace( /[^a-zA-Z0-9_-]/g, '' );
			var bulkStatus = el( 'span', { class: 'opf-b-status', 'data-opf-bulk-choice-status': '1', 'aria-live': 'polite' } );
			var bulkOptions = el( 'textarea', {
				class: 'opf-b-input opf-b-bulk-choices',
				rows: '5',
				title: 'Bulk choice options',
				'aria-describedby': bulkHelpId,
				placeholder: 'Small\tS\nLarge\tL\t2.50',
			} );
			var bulkReplace = el( 'input', { type: 'checkbox', title: 'Replace existing choices before import' } );
			var bulkImport = el( 'button', { class: 'button', text: 'Import choices' } );
			bulkImport.addEventListener( 'click', function () {
				var imported = [];
				var used = Object.create( null );
				if ( ! bulkReplace.checked ) {
					field.choices.forEach( function ( choice ) { used[ String( choice.slug || '' ) ] = true; } );
				}
				var lines = bulkOptions.value.split( /\r?\n/ );
				for ( var lineIndex = 0; lineIndex < lines.length; lineIndex++ ) {
					var rawLine = lines[ lineIndex ].trim();
					if ( '' === rawLine ) continue;
					var columns = rawLine.split( '\t' ).map( function ( column ) { return column.trim(); } );
					if ( columns.length > 3 || '' === columns[0] || ( columns.length > 1 && '' === columns[1] ) ) {
						bulkStatus.textContent = 'Line ' + ( lineIndex + 1 ) + ' needs a label and, when provided, a value and numeric price.';
						return;
					}
					var labelText = columns[0];
					var slug = columns.length > 1 ? columns[1] : slugify( labelText );
					if ( '' === slug ) slug = 'option-' + ( field.choices.length + imported.length + 1 );
					if ( used[ slug ] ) {
						if ( columns.length > 1 ) {
							bulkStatus.textContent = 'Line ' + ( lineIndex + 1 ) + ' duplicates an existing choice value.';
							return;
						}
						var baseSlug = slug;
						var suffix = 2;
						while ( used[ slug ] ) slug = baseSlug + '-' + suffix++;
					}
					used[ slug ] = true;
					var pricing = { type: 'none', amount: 0, formula: '' };
					if ( columns.length === 3 ) {
						var amount = Number( columns[2] );
						if ( ! /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/.test( columns[2] ) || ! Number.isFinite( amount ) ) {
							bulkStatus.textContent = 'Line ' + ( lineIndex + 1 ) + ' price must be a finite number.';
							return;
						}
						pricing = { type: 'fixed', amount: amount, formula: '' };
					}
					imported.push( { slug: slug, label: labelText, selected: false, disabled: false, pricing: pricing } );
				}
				if ( ! imported.length ) {
					bulkStatus.textContent = 'Enter at least one non-empty choice.';
					return;
				}
			field.choices = bulkReplace.checked ? imported : field.choices.concat( imported );
			rerender();
			} );
			card.appendChild( el( 'div', { class: 'opf-b-bulk-choices' }, [
				el( 'label', { text: 'Bulk add choices' }, [ bulkOptions ] ),
				el( 'p', { id: bulkHelpId, class: 'description', text: 'Paste one choice per line: label, or label + Tab + value + optional Tab + fixed price. Blank lines are ignored; options append unless Replace is checked.' } ),
				el( 'label', { class: 'opf-b-bulk-replace' }, [ bulkReplace, document.createTextNode( ' Replace existing choices' ) ] ),
				bulkImport,
				bulkStatus,
			] ) );
		}

		var repeatableTypes = [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'select', 'radio', 'checkbox', 'swatch' ];
		if ( repeatableTypes.indexOf( field.type ) !== -1 ) {
			var hasChoicePricing = ( field.choices || [] ).some( function ( choice ) {
				return choice.pricing && choice.pricing.type && choice.pricing.type !== 'none';
			} );
			var hasChoiceWeight = ( field.choices || [] ).some( function ( choice ) { return Number( choice.weight || 0 ) !== 0; } );
			var hasWeightFormula = !! ( field.weight_formula && field.weight_formula.trim() );
			var repeatToggle = el( 'input', { type: 'checkbox', title: 'Allow repeated rows' } );
			repeatToggle.checked = !! ( field.repeat && field.repeat.enabled );
			repeatToggle.disabled = !! field.image_quantities || hasChoiceWeight || hasWeightFormula || hasChoicePricing || ( field.pricing && field.pricing.type && field.pricing.type !== 'none' );
			repeatToggle.addEventListener( 'change', function () {
				if ( repeatToggle.checked ) {
					field.repeat = { enabled: true, mode: 'button', max: ( field.repeat && field.repeat.max ) || 5 };
				} else {
					delete field.repeat;
				}
				rerender();
			} );
			var repeatControls = [ el( 'label', { class: 'opf-b-repeat-toggle', text: 'Allow repeated rows ' }, [ repeatToggle ] ) ];
			if ( field.repeat && field.repeat.enabled ) {
				var repeatMode = el( 'select', { class: 'opf-b-input', 'data-opf-repeat-mode': '1' }, [
					el( 'option', { value: 'button', text: 'Repeat by button' } ),
					el( 'option', { value: 'quantity', text: 'Repeat by product quantity' } ),
				] );
				repeatMode.value = field.repeat.mode || 'button';
				repeatMode.addEventListener( 'change', function () {
					field.repeat = 'quantity' === repeatMode.value
						? { enabled: true, mode: 'quantity' }
						: { enabled: true, mode: 'button', max: ( field.repeat && field.repeat.max ) || 5 };
					rerender();
				} );
				repeatControls.push( el( 'label', { class: 'opf-b-repeat-mode', text: 'Repeat mode ' }, [ repeatMode ] ) );
				if ( 'button' === ( field.repeat.mode || 'button' ) ) {
					var repeatMax = el( 'input', { type: 'number', class: 'opf-b-input', min: '1', max: '20', value: field.repeat.max || 5, title: 'Maximum repeated rows' } );
					repeatMax.addEventListener( 'input', function () {
						field.repeat.max = Math.max( 1, Math.min( 20, parseInt( repeatMax.value || '5', 10 ) || 5 ) );
					} );
					repeatControls.push( el( 'label', { class: 'opf-b-repeat-max', text: 'Maximum rows ' }, [ repeatMax ] ) );
				} else {
					repeatControls.push( el( 'small', { text: 'One input is shown for each product unit. The server may limit quantity based on its form-input capacity; over-limit submissions are rejected.' } ) );
				}
			}
			if ( repeatToggle.disabled ) {
				repeatControls.push( el( 'small', { text: 'This field cannot repeat with its current pricing, weight, or quantity settings.' } ) );
			}
			card.appendChild( el( 'div', { class: 'opf-b-repeat' }, repeatControls ) );
		}

		return card;
	}

	function in_array( needle, haystack ) {
		return haystack.indexOf( needle ) !== -1;
	}

	function rerender() {
		var app = document.getElementById( 'opf-builder-fields' );
		app.innerHTML = '';
		model.fields.forEach( function ( field, i ) {
			app.appendChild( fieldCard( field, i ) );
		} );
		app.appendChild( imageRulesEditor() );
	}

	function imageRulesEditor() {
		var section = el( 'section', { class: 'opf-b-image-rules' }, [
			el( 'h3', { text: 'Conditional product images' } ),
			el( 'p', { text: 'Each rule matches all its conditions. Use “Any” to ignore a field. First matching rule wins. Paste a product-gallery image URL to switch to that slide, or another image URL to show an external image.' } ),
		] );
		var choiceFields = model.fields.filter( function ( field ) {
			return [ 'select', 'radio', 'checkbox', 'swatch' ].indexOf( field.type ) !== -1 && ( field.choices || [] ).length;
		} );
		model.image_rules.forEach( function ( rule, ruleIndex ) {
			rule.conditions = rule.conditions || [];
			var target = el( 'input', { class: 'opf-b-input', type: 'url', value: rule.target_url || '', placeholder: 'Gallery or external image URL' } );
			target.addEventListener( 'input', function () { rule.target_url = target.value; } );
			var chooseImage = el( 'button', { class: 'button', text: 'Choose from Media Library', onclick: function () {
				if ( ! window.wp || ! window.wp.media ) return;
				var frame = window.wp.media( { title: 'Choose product image', button: { text: 'Use this image' }, multiple: false, library: { type: 'image' } } );
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
					el( 'option', { value: '*', text: 'Any' } ),
					...( selectedField ? ( selectedField.choices || [] ).map( function ( choice ) { return el( 'option', { value: choice.slug, text: choice.label || choice.slug } ); } ) : [] ),
				] );
				valueSelect.value = condition.value || '*';
				valueSelect.addEventListener( 'change', function () { condition.value = valueSelect.value; } );
				var removeCondition = el( 'button', { class: 'button-link', text: 'Remove condition', onclick: function () {
					rule.conditions.splice( conditionIndex, 1 );
					rerender();
				} } );
				rows.push( el( 'div', { class: 'opf-b-image-rule-condition' }, [ fieldSelect, valueSelect, removeCondition ] ) );
			} );
			var addCondition = el( 'button', { class: 'button', text: '+ Add condition', onclick: function () {
				if ( choiceFields.length ) rule.conditions.push( { field: choiceFields[ 0 ].id, value: '*' } );
				rerender();
			} } );
			var removeRule = el( 'button', { class: 'button-link-delete', text: 'Remove rule', onclick: function () {
				model.image_rules.splice( ruleIndex, 1 );
				rerender();
			} } );
			rows.push( addCondition, removeRule );
			section.appendChild( el( 'div', { class: 'opf-b-image-rule' }, rows ) );
		} );
		section.appendChild( el( 'button', { class: 'button', text: '+ Add image rule', onclick: function () {
			if ( choiceFields.length ) model.image_rules.push( { target_url: '', conditions: [ { field: choiceFields[ 0 ].id, value: '*' } ] } );
			rerender();
		} } ) );
		if ( ! choiceFields.length ) section.appendChild( el( 'p', { text: 'Add at least one select, radio, checkbox, or swatch choice field to configure image rules.' } ) );
		return section;
	}

	function save() {
		var lookupInput = document.getElementById( 'opf-b-lookup-tables' );
		try {
			var parsedLookupTables = JSON.parse( lookupInput ? lookupInput.value : '{}' );
			if ( ! parsedLookupTables || typeof parsedLookupTables !== 'object' || Array.isArray( parsedLookupTables ) ) {
				throw new Error( 'Lookup tables must be a JSON object.' );
			}
			model.lookup_tables = parsedLookupTables;
		} catch ( error ) {
			var lookupStatus = document.getElementById( 'opf-b-lookup-status' );
			if ( lookupStatus ) lookupStatus.textContent = 'Fix lookup table JSON before saving.';
			return;
		}
		var variableInput = document.getElementById( 'opf-b-formula-variables' );
		try {
			var parsedVariables = JSON.parse( variableInput ? variableInput.value : '{}' );
			if ( ! parsedVariables || typeof parsedVariables !== 'object' || Array.isArray( parsedVariables ) ) {
				throw new Error( 'Formula variables must be a JSON object.' );
			}
			model.formula_variables = parsedVariables;
		} catch ( error ) {
			var variableStatus = document.getElementById( 'opf-b-formula-variable-status' );
			if ( variableStatus ) variableStatus.textContent = 'Fix formula variable JSON before saving.';
			return;
		}
		// The compact placement UI edits only one AND-group of category/tag/attribute
		// inclusion rules. Preserve product-specific, exclusion, and multi-group
		// rules verbatim so editing a migrated group cannot broaden it globally.
		var placementIsEditable = ( model.rule_groups || [] ).length <= 1
			&& ( model.rule_groups || [] ).every( function ( group ) {
				return ( group.rules || [] ).every( function ( rule ) {
					return rule.operator === 'in' && ( rule.subject === 'product_cat' || rule.subject === 'product_tag' || rule.subject === 'product_attribute' );
				} );
			} );

		// Fold placement selects into the model when their UI fully represents it.
		var cats = Array.prototype.slice.call( document.querySelectorAll( '#opf-placement-cats option:checked' ) ).map( function ( o ) {
			return o.value;
		} );
		var tags = Array.prototype.slice.call( document.querySelectorAll( '#opf-placement-tags option:checked' ) ).map( function ( o ) {
			return o.value;
		} );
		var attributes = Array.prototype.slice.call( document.querySelectorAll( '#opf-placement-attributes option:checked' ) ).map( function ( o ) {
			return o.value;
		} );
		var rules = [];
		if ( cats.length ) {
			rules.push( { subject: 'product_cat', operator: 'in', terms: cats } );
		}
		if ( tags.length ) {
			rules.push( { subject: 'product_tag', operator: 'in', terms: tags } );
		}
		if ( attributes.length ) {
			rules.push( { subject: 'product_attribute', operator: 'in', terms: attributes } );
		}
		if ( placementIsEditable ) {
			model.rule_groups = rules.length ? [ { rules: rules } ] : [];
		}

		var status = document.getElementById( 'opf-b-status' );
		status.textContent = 'Saving…';
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
				postId = j.id;
				if ( ! parseInt( mount.dataset.postId, 10 ) ) {
					mount.dataset.postId = j.id;
				}
				status.textContent = 'Saved.';
			} else {
				status.textContent = 'Save failed: ' + ( j.message || 'unknown error' );
			}
		} ).catch( function ( e ) {
			status.textContent = 'Save failed: ' + e;
		} );
	}

	function preview() {
		var frame = document.getElementById( 'opf-b-preview' );
		var status = document.getElementById( 'opf-b-status' );
		status.textContent = 'Loading preview…';
		window.fetch( previewRest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			body: JSON.stringify( { data: model, product_id: 0 } )
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			frame.innerHTML = j.html || ( j.message || 'No preview available.' );
			status.textContent = '';
		} ).catch( function ( e ) {
			status.textContent = 'Preview failed: ' + e;
		} );
	}

	var toolbar = el( 'div', { class: 'opf-b-toolbar' }, [
		el( 'button', { class: 'button button-primary', text: '+ Add field', onclick: function () {
			model.fields.push( { id: uniqueId( 'field' ), label: '', description: '', type: 'text', required: false, width: 100, choices: [], pricing: { type: 'none', amount: 0, formula: '' }, conditionals: [] } );
			rerender();
		} } ),
		el( 'button', { class: 'button', text: 'Save', onclick: save } ),
		el( 'button', { class: 'button', text: 'Refresh preview', onclick: preview } ),
		el( 'span', { id: 'opf-b-status', class: 'opf-b-status' } )
	] );

	var app = el( 'div', { id: 'opf-builder-fields', class: 'opf-b-fields' } );
	var lookupTables = el( 'div', { class: 'opf-b-lookup' }, [
		el( 'h4', { text: 'Lookup tables' } ),
		el( 'p', { text: 'Add named tables as rows: each row has one cell per field dimension followed by the price. Use lookuptable(table_name; field_id; …) in a formula. For reusable site-wide tables, register rows with the opf_lookup_tables filter.' } ),
		el( 'textarea', { id: 'opf-b-lookup-tables', class: 'opf-b-input', rows: '8', value: JSON.stringify( model.lookup_tables, null, 2 ) } ),
		el( 'span', { id: 'opf-b-lookup-status', class: 'opf-b-status' } ),
	] );
	lookupTables.children[2].addEventListener( 'input', function ( event ) {
		try {
			var parsed = JSON.parse( event.target.value );
			if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) ) throw new Error( 'Expected an object.' );
			model.lookup_tables = parsed;
			document.getElementById( 'opf-b-lookup-status' ).textContent = '';
		} catch ( error ) {
			document.getElementById( 'opf-b-lookup-status' ).textContent = 'Invalid JSON; save is disabled until corrected.';
		}
	} );
	var formulaVariables = el( 'div', { class: 'opf-b-lookup' }, [
		el( 'h4', { text: 'Formula variables' } ),
		el( 'p', { text: 'Define numeric defaults and ordered conditional changes. Use [var_name] in formulas. The first matching change wins; site-wide reusable variables can be registered with the opf_formula_variables filter, and a group definition with the same name overrides it.' } ),
		el( 'textarea', { id: 'opf-b-formula-variables', class: 'opf-b-input', rows: '8', value: JSON.stringify( model.formula_variables, null, 2 ) } ),
		el( 'span', { id: 'opf-b-formula-variable-status', class: 'opf-b-status' } ),
	] );
	formulaVariables.children[2].addEventListener( 'input', function ( event ) {
		try {
			var parsed = JSON.parse( event.target.value );
			if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) ) throw new Error( 'Expected an object.' );
			model.formula_variables = parsed;
			document.getElementById( 'opf-b-formula-variable-status' ).textContent = '';
		} catch ( error ) {
			document.getElementById( 'opf-b-formula-variable-status' ).textContent = 'Invalid JSON; save is disabled until corrected.';
		}
	} );
	var frame = el( 'div', { id: 'opf-b-preview', class: 'opf-b-preview' } );

	mount.appendChild( toolbar );
	mount.appendChild( lookupTables );
	mount.appendChild( formulaVariables );
	mount.appendChild( app );
	mount.appendChild( el( 'h4', { text: 'Preview' } ) );
	mount.appendChild( frame );

	rerender();
} )();
