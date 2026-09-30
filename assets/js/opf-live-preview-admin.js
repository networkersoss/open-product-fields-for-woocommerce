( function () {
	'use strict';
	const editor = document.querySelector( '[data-opf-preview-editor]' );
	if ( ! editor ) return;
	const groups = JSON.parse( editor.dataset.groups || '[]' );
	const images = JSON.parse( editor.dataset.images || '[]' );
	const registeredFonts = JSON.parse( editor.dataset.fonts || '[]' );
	let config = JSON.parse( editor.dataset.config || '[]' );
	let activeIndex = 0;
	const groupSelect = editor.querySelector( '[data-opf-preview-group]' );
	const itemSelect = editor.querySelector( '[data-opf-preview-item]' );
	const addItem = editor.querySelector( '[data-opf-preview-add]' );
	const removeItem = editor.querySelector( '[data-opf-preview-remove]' );
	const fieldSelect = editor.querySelector( '[data-opf-preview-field]' );
	const imageSelect = editor.querySelector( '[data-opf-preview-image]' );
	const colorInput = editor.querySelector( '[data-opf-preview-color]' );
	const familyInput = editor.querySelector( '[data-opf-preview-font-family]' );
	const fontPicker = editor.querySelector( '[data-opf-preview-font-picker]' );
	const sizeInput = editor.querySelector( '[data-opf-preview-size]' );
	const mobileSizeInput = editor.querySelector( '[data-opf-preview-mobile-size]' );
	const weightSelect = editor.querySelector( '[data-opf-preview-weight]' );
	const fontStyleSelect = editor.querySelector( '[data-opf-preview-font-style]' );
	const alignmentSelect = editor.querySelector( '[data-opf-preview-alignment]' );
	const mobileAlignmentSelect = editor.querySelector( '[data-opf-preview-mobile-alignment]' );
	const widthInput = editor.querySelector( '[data-opf-preview-width]' );
	const heightInput = editor.querySelector( '[data-opf-preview-height]' );
	const shapeSelect = editor.querySelector( '[data-opf-preview-shape]' );
	const fitSelect = editor.querySelector( '[data-opf-preview-fit]' );
	const dynamicProperty = editor.querySelector( '[data-opf-dynamic-property]' );
	const dynamicField = editor.querySelector( '[data-opf-dynamic-field]' );
	const dynamicDefault = editor.querySelector( '[data-opf-dynamic-default]' );
	const dynamicValues = editor.querySelector( '[data-opf-dynamic-values]' );
	const canvas = editor.querySelector( '[data-opf-preview-canvas]' );
	const canvasImage = editor.querySelector( '[data-opf-preview-canvas-image]' );
	const box = editor.querySelector( '[data-opf-preview-box]' );
	const sample = editor.querySelector( '[data-opf-preview-sample]' );
	const output = editor.querySelector( '[data-opf-preview-json]' );
	const imageControl = imageSelect;
	let first = config[ 0 ] || {};

	const addOption = ( select, value, label ) => {
		const option = document.createElement( 'option' );
		option.value = String( value );
		option.textContent = label;
		select.appendChild( option );
	};
	const refreshItemSelect = () => {
		itemSelect.replaceChildren();
		config.forEach( ( item, index ) => {
			const group = groups.find( ( candidate ) => Number( candidate.id ) === Number( item.group_id ) );
			const field = group?.fields.find( ( candidate ) => candidate.id === item.field_id );
			addOption( itemSelect, index, field ? `${ field.label } — ${ index + 1 }` : `Overlay ${ index + 1 }` );
		} );
		itemSelect.value = String( activeIndex );
		removeItem.disabled = config.length < 2;
	};
	groups.forEach( ( group ) => addOption( groupSelect, group.id, group.title ) );
	images.forEach( ( image ) => addOption( imageSelect, image.id, image.label ) );
	registeredFonts.forEach( ( font ) => addOption( fontPicker, font.name, `${ font.name } (${ font.format.toUpperCase() })` ) );
	refreshItemSelect();
	if ( first.group_id ) groupSelect.value = String( first.group_id );
	if ( first.image_id ) imageSelect.value = String( first.image_id );
	const initialStyle = first.text || {};
	const initialImageStyle = first.image || {};
	let dynamicConfig = { ...( first.dynamic || {} ) };
	shapeSelect.value = initialImageStyle.shape || 'rectangle';
	fitSelect.value = initialImageStyle.fit || 'fill';
	colorInput.value = /^#[0-9a-f]{6}$/i.test( initialStyle.color || '' ) ? initialStyle.color : '#000000';
	familyInput.value = initialStyle.font_family || 'Arial, sans-serif';
	fontPicker.value = registeredFonts.some( ( font ) => font.name === familyInput.value ) ? familyInput.value : '';
	sizeInput.value = initialStyle.font_size || 24;
	mobileSizeInput.value = initialStyle.mobile_font_size || 18;
	weightSelect.value = initialStyle.font_weight || 'normal';
	fontStyleSelect.value = initialStyle.font_style || 'normal';
	alignmentSelect.value = initialStyle.alignment || 'center';
	mobileAlignmentSelect.value = initialStyle.mobile_alignment || 'center';
	let boxData = first.box || { x: 25, y: 40, width: 50, height: 20 };
	widthInput.value = boxData.width;
	heightInput.value = boxData.height;
	let dragging = false;
	let dragStart = null;

	const activeGroup = () => groups.find( ( group ) => String( group.id ) === groupSelect.value );
	const activeImage = () => images.find( ( image ) => String( image.id ) === imageSelect.value );
	const dynamicInput = ( property, value ) => {
		if ( 'font_family' === property ) {
			const select = document.createElement( 'select' );
			const families = [ 'Arial, sans-serif', 'Georgia, serif', 'Times New Roman, serif', 'Verdana, sans-serif', 'Courier New, monospace', ...registeredFonts.map( ( font ) => font.name ) ];
			[ ...new Set( families ) ].forEach( ( family ) => addOption( select, family, family ) );
			if ( value && ! families.includes( value ) ) addOption( select, value, value );
			select.value = value || 'Arial, sans-serif';
			return select;
		}
		if ( 'alignment' === property ) {
			const select = document.createElement( 'select' );
			[ 'left', 'center', 'right' ].forEach( ( item ) => addOption( select, item, item[ 0 ].toUpperCase() + item.slice( 1 ) ) );
			select.value = value || 'center';
			return select;
		}
		const input = document.createElement( 'input' );
		input.type = { color: 'color', font_size: 'number' }[ property ] || 'text';
		if ( 'font_size' === property ) { input.min = '1'; input.max = '256'; }
		input.value = value ?? ( 'color' === property ? '#000000' : '' );
		return input;
	};
	const renderDynamicValues = ( preferCurrentField = false ) => {
		const property = dynamicProperty.value;
		const group = activeGroup();
		const choices = group?.dynamic_fields || [];
		const previousFieldId = dynamicField.value;
		dynamicField.replaceChildren();
		addOption( dynamicField, '', 'Choose a field' );
		choices.forEach( ( candidate ) => addOption( dynamicField, candidate.id, candidate.label ) );
		const existing = property ? dynamicConfig[ property ] : null;
		const selectedFieldId = ( preferCurrentField ? previousFieldId : existing?.field_id ) || previousFieldId || '';
		if ( choices.some( ( candidate ) => candidate.id === selectedFieldId ) ) dynamicField.value = selectedFieldId;
		dynamicDefault.value = existing?.default ?? '';
		dynamicValues.replaceChildren();
		const selected = choices.find( ( candidate ) => candidate.id === dynamicField.value );
		( selected?.choices || [] ).forEach( ( choice ) => {
			const label = document.createElement( 'label' );
			label.className = 'opf-preview-editor__dynamic-row';
			const title = document.createElement( 'span' );
			title.textContent = choice.label;
			const value = dynamicInput( property, existing?.values?.[ choice.slug ] );
			value.dataset.opfDynamicChoice = choice.slug;
			label.append( title, value );
			dynamicValues.appendChild( label );
		} );
	};
	const saveDynamic = () => {
		const property = dynamicProperty.value;
		if ( ! property ) return;
		const sourceId = dynamicField.value;
		if ( ! sourceId ) { delete dynamicConfig[ property ]; saveConfig(); return; }
		const values = {};
		dynamicValues.querySelectorAll( '[data-opf-dynamic-choice]' ).forEach( ( control ) => { values[ control.dataset.opfDynamicChoice ] = control.value; } );
		dynamicConfig[ property ] = { field_id: sourceId, values, default: dynamicDefault.value || null };
		saveConfig();
	};
	const fillFields = () => {
		fieldSelect.replaceChildren();
		( activeGroup()?.fields || [] ).forEach( ( field ) => addOption( fieldSelect, field.id, field.label ) );
		if ( first.field_id && ( activeGroup()?.fields || [] ).some( ( field ) => field.id === first.field_id ) ) fieldSelect.value = first.field_id;
	};
	const paintBox = () => {
		boxData.width = Number( widthInput.value ) || 50;
		boxData.height = Number( heightInput.value ) || 20;
		boxData.x = Math.min( boxData.x, 100 - boxData.width );
		boxData.y = Math.min( boxData.y, 100 - boxData.height );
		box.style.left = boxData.x + '%';
		box.style.top = boxData.y + '%';
		box.style.width = boxData.width + '%';
		box.style.height = boxData.height + '%';
		box.querySelector( 'span' ).style.color = colorInput.value;
		box.querySelector( 'span' ).style.fontFamily = familyInput.value;
		box.querySelector( 'span' ).style.fontSize = sizeInput.value + 'px';
		box.querySelector( 'span' ).style.fontWeight = weightSelect.value;
		box.querySelector( 'span' ).style.fontStyle = fontStyleSelect.value;
		box.querySelector( 'span' ).style.textAlign = alignmentSelect.value;
	};
	const saveConfig = () => {
		const group = activeGroup();
		const field = ( group?.fields || [] ).find( ( item ) => item.id === fieldSelect.value );
		const image = activeImage();
		if ( ! group || ! field || ! image ) {
			output.value = '[]';
			return;
		}
		const isMain = Number( image.index ) === 0;
		const source = 'upload' === field.type ? 'upload' : 'text';
		sample.textContent = 'upload' === source ? 'Uploaded image' : 'Preview text';
		editor.querySelectorAll( '[data-opf-upload-style]' ).forEach( ( control ) => { control.hidden = 'upload' !== source; } );
		const item = {
			id: first.field_id === field.id ? first.id : 'preview_' + ( activeIndex + 1 ) + '_' + field.id.slice( 0, 40 ),
			group_id: Number( group.id ),
			field_id: field.id,
			source,
			target: isMain ? 'main' : 'gallery',
			image_id: isMain ? 0 : Number( image.id ),
			box: boxData,
			...( 'text' === source ? { text: {
				color: colorInput.value,
				font_family: familyInput.value,
				font_size: Number( sizeInput.value ) || 24,
				mobile_font_size: Number( mobileSizeInput.value ) || 18,
				font_weight: weightSelect.value,
				font_style: fontStyleSelect.value,
				alignment: alignmentSelect.value,
				mobile_alignment: mobileAlignmentSelect.value,
			} } : { image: { shape: shapeSelect.value, fit: fitSelect.value } } ),
			dynamic: 'text' === source ? dynamicConfig : {},
		};
		config[ activeIndex ] = item;
		first = item;
		output.value = JSON.stringify( config );
		refreshItemSelect();
	};
	const refresh = () => {
		const image = activeImage();
		canvasImage.src = image?.url || '';
		canvas.hidden = ! image;
		if ( image && canvasImage.complete && canvasImage.naturalWidth && canvasImage.naturalHeight ) {
			canvas.style.aspectRatio = canvasImage.naturalWidth + ' / ' + canvasImage.naturalHeight;
		}
		paintBox();
		saveConfig();
	};
	familyInput.addEventListener( 'input', () => {
		fontPicker.value = registeredFonts.some( ( font ) => font.name === familyInput.value ) ? familyInput.value : '';
		refresh();
	} );
	fontPicker.addEventListener( 'change', () => {
		if ( fontPicker.value ) familyInput.value = fontPicker.value;
		refresh();
	} );
	canvasImage.addEventListener( 'load', () => {
		if ( canvasImage.naturalWidth && canvasImage.naturalHeight ) canvas.style.aspectRatio = canvasImage.naturalWidth + ' / ' + canvasImage.naturalHeight;
	} );
	const point = ( event ) => {
		const rect = canvas.getBoundingClientRect();
		return { x: Math.max( 0, Math.min( 100, ( event.clientX - rect.left ) / rect.width * 100 ) ), y: Math.max( 0, Math.min( 100, ( event.clientY - rect.top ) / rect.height * 100 ) ) };
	};
	groupSelect.addEventListener( 'change', () => { first.field_id = ''; fillFields(); renderDynamicValues(); refresh(); } );
	itemSelect.addEventListener( 'change', () => loadItem( Number( itemSelect.value ) ) );
	addItem.addEventListener( 'click', () => {
		saveConfig();
		if ( ! config[ activeIndex ] || config.length >= 20 ) return;
		const source = config[ activeIndex ];
		const clone = JSON.parse( JSON.stringify( source ) );
		clone.id = `preview_${ activeIndex + 2 }_${ Date.now().toString( 36 ) }`;
		clone.box.x = Math.min( 100 - clone.box.width, clone.box.x + 5 );
		clone.box.y = Math.min( 100 - clone.box.height, clone.box.y + 5 );
		config.push( clone );
		activeIndex = config.length - 1;
		loadItem( activeIndex );
	} );
	removeItem.addEventListener( 'click', () => {
		if ( config.length < 2 ) return;
		config.splice( activeIndex, 1 );
		activeIndex = Math.min( activeIndex, config.length - 1 );
		loadItem( activeIndex );
	} );
	dynamicProperty.addEventListener( 'change', () => { renderDynamicValues(); saveDynamic(); } );
	dynamicField.addEventListener( 'change', () => { renderDynamicValues( true ); saveDynamic(); } );
	dynamicDefault.addEventListener( 'input', saveDynamic );
	dynamicValues.addEventListener( 'input', saveDynamic );
	dynamicValues.addEventListener( 'change', saveDynamic );
	[ fieldSelect, imageControl, colorInput, sizeInput, mobileSizeInput, weightSelect, fontStyleSelect, alignmentSelect, mobileAlignmentSelect, widthInput, heightInput, shapeSelect, fitSelect ].forEach( ( input ) => {
		input.addEventListener( 'input', refresh );
		input.addEventListener( 'change', refresh );
	} );
	box.addEventListener( 'pointerdown', ( event ) => {
		dragging = true;
		box.setPointerCapture( event.pointerId );
		dragStart = { point: point( event ), x: boxData.x, y: boxData.y };
	} );
	box.addEventListener( 'pointermove', ( event ) => {
		if ( ! dragging || ! dragStart ) return;
		const current = point( event );
		boxData.x = Math.max( 0, Math.min( 100 - boxData.width, dragStart.x + current.x - dragStart.point.x ) );
		boxData.y = Math.max( 0, Math.min( 100 - boxData.height, dragStart.y + current.y - dragStart.point.y ) );
		paintBox();
		saveConfig();
	} );
	box.addEventListener( 'pointerup', () => { dragging = false; dragStart = null; } );
	box.addEventListener( 'keydown', ( event ) => {
		const delta = event.shiftKey ? 5 : 1;
		if ( ! [ 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight' ].includes( event.key ) ) return;
		event.preventDefault();
		if ( 'ArrowLeft' === event.key ) boxData.x = Math.max( 0, boxData.x - delta );
		if ( 'ArrowRight' === event.key ) boxData.x = Math.min( 100 - boxData.width, boxData.x + delta );
		if ( 'ArrowUp' === event.key ) boxData.y = Math.max( 0, boxData.y - delta );
		if ( 'ArrowDown' === event.key ) boxData.y = Math.min( 100 - boxData.height, boxData.y + delta );
		paintBox();
		saveConfig();
	} );
	function loadItem( index ) {
		if ( ! config[ index ] ) return;
		activeIndex = index;
		first = config[ activeIndex ];
		if ( first.group_id ) groupSelect.value = String( first.group_id );
		if ( first.image_id ) imageSelect.value = String( first.image_id );
		else if ( images[0] ) imageSelect.value = String( images[0].id );
		const style = first.text || {};
		const imageStyle = first.image || {};
		colorInput.value = /^#[0-9a-f]{6}$/i.test( style.color || '' ) ? style.color : '#000000';
		familyInput.value = style.font_family || 'Arial, sans-serif';
		fontPicker.value = registeredFonts.some( ( font ) => font.name === familyInput.value ) ? familyInput.value : '';
		sizeInput.value = style.font_size || 24;
		mobileSizeInput.value = style.mobile_font_size || 18;
		weightSelect.value = style.font_weight || 'normal';
		fontStyleSelect.value = style.font_style || 'normal';
		alignmentSelect.value = style.alignment || 'center';
		mobileAlignmentSelect.value = style.mobile_alignment || 'center';
		shapeSelect.value = imageStyle.shape || 'rectangle';
		fitSelect.value = imageStyle.fit || 'fill';
		boxData = first.box || { x: 25, y: 40, width: 50, height: 20 };
		widthInput.value = boxData.width;
		heightInput.value = boxData.height;
		dynamicConfig = { ...( first.dynamic || {} ) };
		dynamicProperty.value = Object.keys( dynamicConfig )[ 0 ] || '';
		fillFields();
		renderDynamicValues();
		refreshItemSelect();
		refresh();
	}
	fillFields();
	if ( Object.keys( dynamicConfig ).length ) dynamicProperty.value = Object.keys( dynamicConfig )[ 0 ];
	renderDynamicValues();
	refresh();
} )();
