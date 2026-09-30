/**
 * OPF frontend. Small ES module — no jQuery, no framework, no build step.
 *
 * Responsibilities:
 *  - mirror server-side conditional visibility client-side for instant UX
 *    (the server re-validates everything on add-to-cart),
 *  - keep a values map per group in sync as the customer edits fields,
 *  - toggle hidden fields with the `hidden` attribute + aria-hidden.
 *
 * The server embeds the field metadata as window.OPF_FIELDS:
 *   { "<group_id>": { "<field_id>": { type, conditionals } } }
 */

const REGISTRY = window.OPF_FIELDS || {};

const isVisible = ( field, values ) => {
	if ( ! field.conditionals || ! field.conditionals.length ) {
		return true;
	}
	let hasShow = false;
	let showPass = false;
	let hidePass = false;

	const rulePasses = ( rule ) => {
		const value = values[ rule.field ];
		const actual = Array.isArray( value )
			? value.join( ', ' )
			: String( value ?? '' );
		const expect = String( rule.value ?? '' );
		switch ( rule.operator ) {
			case 'is':
				return Array.isArray( value )
					? value.includes( expect )
					: actual === expect;
			case 'is_not':
				return ! rulePasses( { ...rule, operator: 'is' } );
			case 'contains':
				return actual.toLowerCase().includes( expect.toLowerCase() );
			case 'greater':
				return actual !== '' && Number( actual ) > Number( expect );
			case 'less':
				return actual !== '' && Number( actual ) < Number( expect );
			case 'empty':
				return actual.trim() === '';
			case 'not_empty':
				return actual.trim() !== '';
			default:
				return false;
		}
	};

	field.conditionals.forEach( ( conditional ) => {
		const results = conditional.rules.map( rulePasses );
		const passed =
			'any' === conditional.logic
				? results.includes( true )
				: ! results.includes( false );
		if ( 'hide' === conditional.action ) {
			hidePass = hidePass || passed;
		} else {
			hasShow = true;
			showPass = showPass || passed;
		}
	} );

	if ( hidePass ) {
		return false;
	}
	return hasShow ? showPass : true;
};

const dateSiteClock = ( input ) => {
	const siteEpoch = Number( input.dataset.opfDateSiteEpoch );
	if ( ! Number.isFinite( siteEpoch ) ) return null;
	if ( ! input.dataset.opfDateClientEpoch ) input.dataset.opfDateClientEpoch = String( Date.now() );
	const now = new Date( siteEpoch * 1000 + Date.now() - Number( input.dataset.opfDateClientEpoch ) );
	const timeZone = input.dataset.opfDateTimezone || 'UTC';
	try {
		const parts = new Intl.DateTimeFormat( 'en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' } ).formatToParts( now );
		const part = ( type ) => ( parts.find( ( item ) => item.type === type ) || {} ).value || '';
		return { date: part( 'year' ) + '-' + part( 'month' ) + '-' + part( 'day' ), time: part( 'hour' ) + ':' + part( 'minute' ) };
	} catch ( error ) {
		const match = timeZone.match( /^([+-])(\d{2}):(\d{2})$/ );
		if ( ! match ) return null;
		const offset = ( Number( match[2] ) * 60 + Number( match[3] ) ) * ( '-' === match[1] ? -1 : 1 );
		const shifted = new Date( now.getTime() + offset * 60000 );
		return { date: shifted.toISOString().slice( 0, 10 ), time: shifted.toISOString().slice( 11, 16 ) };
	}
};

const formatIsoDate = ( isoDate, format ) => {
	const match = String( isoDate || '' ).match( /^(\d{4})-(\d{2})-(\d{2})$/ );
	if ( ! match ) return '';
	const values = { yyyy: match[1], yy: match[1].slice( -2 ), mm: match[2], m: String( Number( match[2] ) ), dd: match[3], d: String( Number( match[3] ) ) };
	const normalized = String( format || 'mm-dd-yyyy' ).toLowerCase();
	return normalized.replace( /yyyy|yy|mm|m|dd|d/g, ( token ) => values[ token ] );
};

const dateMatchesBlackout = ( isoDate, disabledDates ) => {
	const monthDay = isoDate.slice( 5 );
	return disabledDates.some( ( rawRule ) => {
		const rule = String( rawRule || '' ).trim();
		if ( rule === isoDate || rule === monthDay ) return true;
		const range = rule.split( /\s+/ );
		if ( 2 !== range.length ) return false;
		const [ start, end ] = range;
		if ( /^\d{4}-\d{2}-\d{2}$/.test( start ) && /^\d{4}-\d{2}-\d{2}$/.test( end ) ) {
			return isoDate >= start && isoDate <= end;
		}
		if ( /^\d{2}-\d{2}$/.test( start ) && /^\d{2}-\d{2}$/.test( end ) ) {
			return start <= end ? monthDay >= start && monthDay <= end : monthDay >= start || monthDay <= end;
		}
		const year = isoDate.slice( 0, 4 );
		const fixedStart = /^\d{2}-\d{2}$/.test( start ) ? year + '-' + start : start;
		const fixedEnd = /^\d{2}-\d{2}$/.test( end ) ? year + '-' + end : end;
		return /^\d{4}-\d{2}-\d{2}$/.test( fixedStart ) && /^\d{4}-\d{2}-\d{2}$/.test( fixedEnd ) && fixedStart <= isoDate && isoDate <= fixedEnd;
	} );
};

const dateSelectionAllowed = ( input, isoDate ) => {
	if ( input.min && isoDate < input.min ) return false;
	if ( input.max && isoDate > input.max ) return false;
	const date = new Date( isoDate + 'T00:00:00Z' );
	if ( Number.isNaN( date.getTime() ) ) return false;
	const weekdays = JSON.parse( input.dataset.opfDisabledWeekdays || '[]' );
	const disabledDates = JSON.parse( input.dataset.opfDisabledDates || '[]' );
	if ( weekdays.includes( date.getUTCDay() ) || dateMatchesBlackout( isoDate, disabledDates ) ) return false;
	const allowPast = input.dataset.opfAllowPast !== '0';
	const allowFuture = input.dataset.opfAllowFuture !== '0';
	const cutoff = input.dataset.opfDateCutoff;
	const clock = cutoff || ! allowPast || ! allowFuture ? dateSiteClock( input ) : null;
	if ( ( cutoff || ! allowPast || ! allowFuture ) && ! clock ) return false;
	if ( clock && ! allowPast && isoDate < clock.date ) return false;
	if ( clock && ! allowFuture && isoDate > clock.date ) return false;
	if ( cutoff && clock && isoDate === clock.date && clock.time >= cutoff ) return false;
	return true;
};

const initDatePicker = ( fieldEl, input ) => {
	if ( ! document.createElement || fieldEl.querySelector( '.opf-date-picker' ) ) return;
	const wrapper = document.createElement( 'div' );
	wrapper.className = 'opf-date-picker';
	const toggle = document.createElement( 'button' );
	toggle.type = 'button';
	toggle.className = 'opf-date-picker__toggle';
	toggle.textContent = 'Choose date';
	toggle.setAttribute( 'aria-haspopup', 'dialog' );
	toggle.setAttribute( 'aria-expanded', 'false' );
	toggle.setAttribute( 'aria-controls', input.id + '-calendar' );
	const panel = document.createElement( 'div' );
	panel.className = 'opf-date-picker__panel';
	panel.id = input.id + '-calendar';
	panel.setAttribute( 'role', 'dialog' );
	panel.setAttribute( 'aria-label', 'Choose a date' );
	panel.hidden = true;
	const header = document.createElement( 'div' );
	header.className = 'opf-date-picker__header';
	const previous = document.createElement( 'button' );
	previous.type = 'button';
	previous.textContent = '‹';
	previous.setAttribute( 'aria-label', 'Previous month' );
	const monthLabel = document.createElement( 'strong' );
	const next = document.createElement( 'button' );
	next.type = 'button';
	next.textContent = '›';
	next.setAttribute( 'aria-label', 'Next month' );
	header.appendChild( previous );
	header.appendChild( monthLabel );
	header.appendChild( next );
	const grid = document.createElement( 'div' );
	grid.className = 'opf-date-picker__grid';
	grid.setAttribute( 'role', 'grid' );
	grid.setAttribute( 'aria-label', 'Calendar dates' );
	const configuredWeekStart = Number.parseInt( input.dataset.opfWeekStart || '0', 10 );
	const weekStart = Number.isInteger( configuredWeekStart ) && configuredWeekStart >= 0 && configuredWeekStart <= 6 ? configuredWeekStart : 0;
	panel.appendChild( header );
	panel.appendChild( grid );
	wrapper.appendChild( toggle );
	wrapper.appendChild( panel );
	fieldEl.appendChild( wrapper );

	let visibleMonth = ( input.value && /^\d{4}-\d{2}-\d{2}$/.test( input.value ) ? input.value : ( dateSiteClock( input ) || {} ).date || new Date().toISOString().slice( 0, 10 ) ).slice( 0, 7 ) + '-01';
	let activeDate = input.value;
	const render = () => {
		const formattedValue = formatIsoDate( input.value, input.dataset.opfDateFormat );
		toggle.textContent = formattedValue || 'Choose date';
		toggle.setAttribute( 'aria-label', formattedValue ? 'Change date, ' + formattedValue : 'Choose date' );
		const month = new Date( visibleMonth + 'T00:00:00Z' );
		monthLabel.textContent = new Intl.DateTimeFormat( undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' } ).format( month );
		monthLabel.setAttribute( 'aria-live', 'polite' );
		grid.textContent = '';
		const weekdayRow = document.createElement( 'div' );
		weekdayRow.setAttribute( 'role', 'row' );
		const weekdayNames = [ 'Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa' ];
		weekdayNames.slice( weekStart ).concat( weekdayNames.slice( 0, weekStart ) ).forEach( ( day ) => {
			const heading = document.createElement( 'span' );
			heading.textContent = day;
			heading.setAttribute( 'role', 'columnheader' );
			weekdayRow.appendChild( heading );
		} );
		grid.appendChild( weekdayRow );
		const firstDay = new Date( month.getTime() ).getUTCDay();
		const firstDayOffset = ( firstDay - weekStart + 7 ) % 7;
		const days = new Date( Date.UTC( month.getUTCFullYear(), month.getUTCMonth() + 1, 0 ) ).getUTCDate();
		let row = document.createElement( 'div' );
		row.setAttribute( 'role', 'row' );
		for ( let blank = 0; blank < firstDayOffset; blank++ ) row.appendChild( document.createElement( 'span' ) );
		const clock = dateSiteClock( input );
		const preferred = [ activeDate, input.value, clock && clock.date ].find( ( candidate ) => candidate && candidate.slice( 0, 7 ) === visibleMonth.slice( 0, 7 ) && dateSelectionAllowed( input, candidate ) ) || '';
		let rovingAssigned = false;
		for ( let day = 1; day <= days; day++ ) {
			const isoDate = visibleMonth.slice( 0, 7 ) + '-' + String( day ).padStart( 2, '0' );
			const choice = document.createElement( 'button' );
			choice.type = 'button';
			choice.textContent = String( day );
			choice.dataset.opfDate = isoDate;
			choice.setAttribute( 'aria-label', new Intl.DateTimeFormat( undefined, { dateStyle: 'full', timeZone: 'UTC' } ).format( new Date( isoDate + 'T00:00:00Z' ) ) );
			choice.disabled = ! dateSelectionAllowed( input, isoDate );
			choice.tabIndex = -1;
			if ( isoDate === input.value ) choice.setAttribute( 'aria-pressed', 'true' );
			if ( isoDate === clock?.date ) choice.setAttribute( 'aria-current', 'date' );
			if ( ! choice.disabled && ( isoDate === preferred || ( ! preferred && ! rovingAssigned ) ) ) {
				choice.tabIndex = 0;
				activeDate = isoDate;
				rovingAssigned = true;
			}
			choice.addEventListener( 'click', () => {
				input.value = isoDate;
				const EventType = window.Event || Event;
				input.dispatchEvent( new EventType( 'input', { bubbles: true } ) );
				input.dispatchEvent( new EventType( 'change', { bubbles: true } ) );
				panel.hidden = true;
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.focus();
			} );
			row.appendChild( choice );
			if ( ( firstDayOffset + day ) % 7 === 0 ) {
				grid.appendChild( row );
				row = document.createElement( 'div' );
				row.setAttribute( 'role', 'row' );
			}
		}
		if ( row.children.length ) grid.appendChild( row );
		status.textContent = rovingAssigned ? '' : 'No selectable dates this month.';
	};
	const status = document.createElement( 'div' );
	status.className = 'opf-date-picker__status';
	status.setAttribute( 'role', 'status' );
	status.setAttribute( 'aria-live', 'polite' );
	panel.appendChild( status );
	input.opfRenderDateCalendar = render;
	const changeMonth = ( delta ) => {
		const month = new Date( visibleMonth + 'T00:00:00Z' );
		month.setUTCMonth( month.getUTCMonth() + delta );
		visibleMonth = month.toISOString().slice( 0, 7 ) + '-01';
		activeDate = '';
		render();
	};
	previous.addEventListener( 'click', () => {
		changeMonth( -1 );
	} );
	next.addEventListener( 'click', () => {
		changeMonth( 1 );
	} );
	toggle.addEventListener( 'click', () => {
		panel.hidden = ! panel.hidden;
		toggle.setAttribute( 'aria-expanded', String( ! panel.hidden ) );
		render();
		if ( ! panel.hidden ) {
			const selected = grid.querySelector( 'button[aria-pressed="true"]:not(:disabled)' ) || grid.querySelector( 'button[tabindex="0"]' ) || next;
			if ( selected ) selected.focus();
		}
	} );
	panel.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key ) {
			panel.hidden = true;
			toggle.setAttribute( 'aria-expanded', 'false' );
			toggle.focus();
			event.preventDefault();
		}
	} );
	grid.addEventListener( 'keydown', ( event ) => {
		const current = event.target.closest( 'button[data-opf-date]' );
		if ( ! current ) return;
		const selected = new Date( current.dataset.opfDate + 'T00:00:00Z' );
		let target = null;
		if ( 'ArrowLeft' === event.key ) target = -1;
		if ( 'ArrowRight' === event.key ) target = 1;
		if ( 'ArrowUp' === event.key ) target = -7;
		if ( 'ArrowDown' === event.key ) target = 7;
		const weekdayOffset = ( selected.getUTCDay() - weekStart + 7 ) % 7;
		if ( 'Home' === event.key ) target = -weekdayOffset;
		if ( 'End' === event.key ) target = 6 - weekdayOffset;
		if ( 'PageUp' === event.key || 'PageDown' === event.key ) {
			const month = new Date( selected.getTime() );
			month.setUTCMonth( month.getUTCMonth() + ( 'PageUp' === event.key ? -1 : 1 ) );
			visibleMonth = month.toISOString().slice( 0, 7 ) + '-01';
			activeDate = '';
			render();
			const sameDay = String( Math.min( selected.getUTCDate(), new Date( Date.UTC( month.getUTCFullYear(), month.getUTCMonth() + 1, 0 ) ).getUTCDate() ) ).padStart( 2, '0' );
			const wanted = grid.querySelector( 'button[data-opf-date="' + visibleMonth.slice( 0, 7 ) + '-' + sameDay + '"]:not(:disabled)' ) || grid.querySelector( 'button[tabindex="0"]' );
			if ( wanted ) {
				activeDate = wanted.dataset.opfDate;
				grid.querySelectorAll( 'button[data-opf-date]' ).forEach( ( button ) => { button.tabIndex = button === wanted ? 0 : -1; } );
				wanted.focus();
			}
			event.preventDefault();
			return;
		}
		if ( null === target ) return;
		let delta = target;
		for ( let tries = 0; tries < 42; tries++ ) {
			const candidate = new Date( selected.getTime() );
			candidate.setUTCDate( candidate.getUTCDate() + delta );
			const isoDate = candidate.toISOString().slice( 0, 10 );
			if ( dateSelectionAllowed( input, isoDate ) ) {
				activeDate = isoDate;
				if ( visibleMonth.slice( 0, 7 ) !== isoDate.slice( 0, 7 ) ) {
					visibleMonth = isoDate.slice( 0, 7 ) + '-01';
					render();
				}
				const wanted = grid.querySelector( 'button[data-opf-date="' + isoDate + '"]' );
				if ( wanted ) {
					grid.querySelectorAll( 'button[data-opf-date]' ).forEach( ( button ) => { button.tabIndex = button === wanted ? 0 : -1; } );
					wanted.focus();
				}
				break;
			}
			delta += [ 'ArrowUp', 'ArrowDown' ].includes( event.key ) ? target : ( 'End' === event.key ? -1 : 1 );
		}
		event.preventDefault();
	} );
	input.addEventListener( 'input', () => {
		if ( /^\d{4}-\d{2}-\d{2}$/.test( input.value ) ) {
			activeDate = input.value;
			visibleMonth = input.value.slice( 0, 7 ) + '-01';
		}
		render();
	} );
	render();
};

const updateNumberStepperButtons = ( fieldEl ) => {
	if ( ! fieldEl?.querySelector ) return;
	const wrapper = fieldEl.querySelector( '[data-opf-number-stepper]' );
	const input = wrapper?.querySelector( 'input[type="number"]' );
	if ( ! input ) return;
	const down = wrapper.querySelector( '[data-opf-number-step="down"]' );
	const up = wrapper.querySelector( '[data-opf-number-step="up"]' );
	const blocked = input.disabled || input.readOnly;
	const min = '' !== input.min && Number.isFinite( Number( input.min ) ) ? Number( input.min ) : null;
	const max = '' !== input.max && Number.isFinite( Number( input.max ) ) ? Number( input.max ) : null;
	const value = '' === input.value ? ( null !== min ? min : 0 ) : Number( input.value );
	if ( down ) down.disabled = blocked || ( null !== min && value <= min );
	if ( up ) up.disabled = blocked || ( null !== max && value >= max );
};

const init = ( root = document ) => {
	const groups = root.matches?.( '[data-opf-group]' ) ? [ root, ...root.querySelectorAll( '[data-opf-group]' ) ] : root.querySelectorAll( '[data-opf-group]' );
	groups.forEach( ( groupEl ) => {
		if ( groupEl.dataset?.opfInitialized ) return;
		if ( groupEl.dataset ) groupEl.dataset.opfInitialized = '1';
		groupEl.addEventListener( 'click', ( event ) => {
			const stepButton = event.target.closest( '[data-opf-number-step]' );
			if ( stepButton && groupEl.contains( stepButton ) ) {
				event.preventDefault();
				const fieldEl = stepButton.closest( '[data-opf-field]' );
				const input = fieldEl?.querySelector( '[data-opf-number-stepper] input[type="number"]' );
				if ( ! input || input.disabled || input.readOnly ) return;
				const step = 'any' === input.step ? 1 : Number( input.step || '1' );
				if ( ! Number.isFinite( step ) || step <= 0 ) return;
				const min = '' !== input.min && Number.isFinite( Number( input.min ) ) ? Number( input.min ) : null;
				const max = '' !== input.max && Number.isFinite( Number( input.max ) ) ? Number( input.max ) : null;
				const current = '' === input.value ? ( null !== min ? min : 0 ) : Number( input.value );
				const direction = 'up' === stepButton.dataset.opfNumberStep ? 1 : -1;
				const decimals = Math.min( 10, Math.max( String( step ).split( '.' )[1]?.length || 0, String( current ).split( '.' )[1]?.length || 0 ) );
				let next = Math.round( ( current + direction * step ) * ( 10 ** decimals ) ) / ( 10 ** decimals );
				if ( null !== min ) next = Math.max( min, next );
				if ( null !== max ) next = Math.min( max, next );
				input.value = String( next );
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				updateNumberStepperButtons( fieldEl );
				return;
			}
			const trigger = event.target.closest( '.opf-instruction-tooltip__trigger' );
			if ( ! trigger || ! groupEl.contains( trigger ) ) {
				groupEl.querySelectorAll( '.opf-instruction-tooltip.is-open' ).forEach( ( wrapper ) => {
					wrapper.classList.remove( 'is-open' );
					wrapper.querySelector( '.opf-instruction-tooltip__trigger' )?.setAttribute( 'aria-expanded', 'false' );
				} );
				return;
			}
			const wrapper = trigger.closest( '.opf-instruction-tooltip' );
			const open = 'true' !== trigger.getAttribute( 'aria-expanded' );
			trigger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			wrapper.classList.toggle( 'is-open', open );
		} );
		groupEl.addEventListener( 'focusout', ( event ) => {
			const wrapper = event.target.closest( '.opf-instruction-tooltip' );
			if ( ! wrapper || wrapper.contains( event.relatedTarget ) ) return;
			wrapper.classList.remove( 'is-open' );
			wrapper.querySelector( '.opf-instruction-tooltip__trigger' )?.setAttribute( 'aria-expanded', 'false' );
		} );
		groupEl.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' !== event.key ) return;
			const trigger = event.target.closest( '.opf-instruction-tooltip__trigger' );
			if ( ! trigger ) return;
			trigger.setAttribute( 'aria-expanded', 'false' );
			trigger.closest( '.opf-instruction-tooltip' ).classList.remove( 'is-open' );
			trigger.focus();
		} );
		const gid = groupEl.getAttribute( 'data-opf-group' );
		const registry = REGISTRY[ gid ] || {};
		const fields = groupEl.querySelectorAll( '[data-opf-field]' );

		const values = {};
		const fieldDefs = {};
		const readRepeatedRows = ( fieldEl, fieldType ) => Array.from( fieldEl.querySelectorAll( '[data-opf-repeat-row]' ) ).map( ( row ) => {
			const inputs = Array.from( row.querySelectorAll( 'input:not([type="hidden"]), textarea, select' ) );
			if ( 'toggle' === fieldType ) return inputs[ 0 ] && inputs[ 0 ].checked ? '1' : '0';
			const checkbox = inputs.filter( ( input ) => 'checkbox' === input.type && ! input.closest( '[data-opf-upload]' ) );
			if ( checkbox.length ) return checkbox.filter( ( input ) => input.checked ).map( ( input ) => input.value );
			const radioInput = inputs.find( ( input ) => 'radio' === input.type );
			if ( radioInput ) {
				const radio = inputs.find( ( input ) => 'radio' === input.type && input.checked );
				return radio ? radio.value : null;
			}
			const input = inputs[ 0 ];
			if ( ! input ) return null;
			if ( 'toggle' === input.dataset.opfType || ( input.type === 'checkbox' && ! checkbox.length ) ) return input.checked ? '1' : '0';
			return input.value || null;
		} );

		fields.forEach( ( fieldEl ) => {
			const fid = fieldEl.getAttribute( 'data-opf-field' );
			fieldDefs[ fid ] = registry[ fid ] || { type: 'text', conditionals: [] };
			if ( fieldDefs[ fid ].repeat && fieldDefs[ fid ].repeat.enabled ) {
				values[ fid ] = readRepeatedRows( fieldEl, fieldDefs[ fid ].type );
				return;
			}

			const input = fieldEl.querySelector( 'input:not([type="hidden"]), textarea, select' );
			if ( input ) {
				if ( fieldDefs[ fid ].type === 'child_products' ) {
					const quantityInputs = fieldEl.querySelectorAll( 'input[type="number"]' );
					const multiSelect = fieldEl.querySelector( 'select[multiple]' );
					const checkedInputs = fieldEl.querySelectorAll( 'input[type="checkbox"]:checked, input[type="radio"]:checked' );
					if ( fieldDefs[ fid ].child_quantity_input && quantityInputs.length ) {
						values[ fid ] = {};
						quantityInputs.forEach( ( quantityInput ) => {
							const match = quantityInput.name.match( /\[(\d+)\]$/ );
							if ( match && Number( quantityInput.value ) > 0 ) values[ fid ][ match[1] ] = quantityInput.value;
						} );
					} else if ( multiSelect ) {
						values[ fid ] = Array.from( multiSelect.selectedOptions ).map( ( option ) => option.value );
					} else if ( checkedInputs.length ) {
						values[ fid ] = Array.from( checkedInputs ).map( ( choice ) => choice.value );
					} else {
						values[ fid ] = input.value || null;
					}
				} else values[ fid ] = input.type === 'checkbox' && input.name.endsWith( '[]' )
					? Array.from(
							groupEl.querySelectorAll(
								'[data-opf-field="' + fid + '"] input:checked'
							)
						).map( ( c ) => c.value )
					: ( fieldDefs[ fid ].type === 'toggle' ? ( input.checked ? '1' : '0' ) : input.value );
			}
		} );
		fields.forEach( ( fieldEl ) => {
			const input = fieldEl.querySelector( 'input[type="date"]' );
			if ( input ) initDatePicker( fieldEl, input );
			updateNumberStepperButtons( fieldEl );
		} );

		const refresh = () => {
			const groupPrice = parseFloat( groupEl.getAttribute( 'data-opf-product-price' ) || '0' ) || 0;
			const rawValues = { ...values };
			const fileCounts = {};
			fields.forEach( ( fieldEl ) => {
				const fid = fieldEl.getAttribute( 'data-opf-field' );
				if ( fieldDefs[ fid ]?.type !== 'upload' ) return;
				const input = fieldEl.querySelector( 'input[type="file"]' );
				fileCounts[ fid.toLowerCase() ] = ( input && input.files ? input.files.length : 0 ) + fieldEl.querySelectorAll( '[data-opf-upload-token]' ).length;
			} );
		const formulaVariables = resolveGroupFormulaVariables( gid, rawValues );
			const lookupTables = ( window.OPF_LOOKUP_TABLES || {} )[ gid ] || {};
			const fieldPrices = resolveFieldPrices( fieldDefs, rawValues, groupPrice, 1, 0, lookupTables, formulaVariables );
			let resolvedValues = resolveCalculatedValues(
				fieldDefs,
				rawValues,
				groupPrice,
				1,
				0,
				lookupTables,
				formulaVariables,
				fileCounts,
				fieldPrices
			);
			fields.forEach( ( fieldEl ) => {
				const fid = fieldEl.getAttribute( 'data-opf-field' );
				if ( fieldDefs[ fid ]?.type === 'upload' && ! isVisible( fieldDefs[ fid ], resolvedValues ) ) fileCounts[ fid.toLowerCase() ] = 0;
			} );
			resolvedValues = resolveCalculatedValues( fieldDefs, rawValues, groupPrice, 1, 0, lookupTables, formulaVariables, fileCounts, fieldPrices );
			fields.forEach( ( fieldEl ) => {
				const fid = fieldEl.getAttribute( 'data-opf-field' );
				const def = fieldDefs[ fid ] || {};
				const visible = Object.prototype.hasOwnProperty.call( resolvedValues, fid ) || def.type !== 'calculation'
					? isVisible( def, resolvedValues )
					: false;
				fieldEl.classList.toggle( 'opf-field--hidden', ! visible );
				fieldEl.classList.toggle( 'opf-hide', ! visible );
				fieldEl.toggleAttribute( 'hidden', ! visible );

				// Accordion header: mostrar la elección actual
				const accValue = fieldEl.querySelector( '.acc-value' );
				const calculationOutput = fieldEl.querySelector( '[data-opf-calculation]' );
				if ( calculationOutput && Object.prototype.hasOwnProperty.call( resolvedValues, fid ) ) {
					calculationOutput.textContent = String( def.result_text || '{result}' ).replace( /\{result\}/g, String( resolvedValues[ fid ] ) );
				}
				if ( accValue ) {
					const v = resolvedValues[ fid ];
					const choices = def.choices || [];
					if ( choices.length ) {
						const slugs = Array.isArray( v ) ? v : [ v ];
						const chosen = choices.filter( ( c ) => slugs.includes( c.slug ) );
						if ( chosen.length ) accValue.textContent = chosen.map( ( c ) => c.label ).join( ', ' );
					} else if ( typeof v === 'string' && v.trim() ) {
						accValue.textContent = v;
					}
				}
			} );
		};

		const syncChecked = () => {
			// Legacy theme integration keys swatch styling off `opf-checked`
			// on the .opf-swatch wrapper, exactly as the legacy JS did.
			groupEl.querySelectorAll( '.opf-swatch' ).forEach( ( swatch ) => {
				const input = swatch.querySelector( 'input' );
				if ( ! input ) {
					return;
				}
				swatch.classList.toggle( 'opf-checked', !! input.checked );
			} );
		};

		const validateChoiceLimits = () => {
			fields.forEach( ( fieldEl ) => {
				const min = parseInt( fieldEl.dataset.opfMinSelections || '0', 10 );
				const max = parseInt( fieldEl.dataset.opfMaxSelections || '0', 10 );
				const repeatedRows = fieldEl.querySelectorAll( '[data-opf-repeat-row]' );
				if ( repeatedRows.length ) {
					repeatedRows.forEach( ( row ) => {
						const inputs = row.querySelectorAll( 'input[type="checkbox"]' );
						if ( ! inputs.length ) return;
						const count = row.querySelectorAll( 'input[type="checkbox"]:checked' ).length;
						const message = min && count < min ? 'Select at least ' + min + ' option(s).' : ( max && count > max ? 'Select at most ' + max + ' option(s).' : '' );
						inputs.forEach( ( input ) => input.setCustomValidity( message ) );
					} );
					return;
				}
				const quantityInputs = fieldEl.querySelectorAll( 'input[data-opf-quantity-choice]' );
				if ( quantityInputs.length ) {
					const quantities = Array.from( quantityInputs ).map( ( input ) => Math.max( 0, parseInt( input.value || '0', 10 ) || 0 ) );
					const selected = quantities.filter( ( quantity ) => quantity > 0 ).length;
					const total = quantities.reduce( ( sum, quantity ) => sum + quantity, 0 );
					const minTotal = parseInt( fieldEl.dataset.opfMinTotalQuantity || '0', 10 );
					const maxTotal = parseInt( fieldEl.dataset.opfMaxTotalQuantity || '0', 10 );
					let message = min && selected < min
						? 'Select at least ' + min + ' option(s).'
						: ( max && selected > max ? 'Select at most ' + max + ' option(s).' : '' );
					if ( ! message && minTotal && total < minTotal ) message = 'Enter a total quantity of at least ' + minTotal + '.';
					if ( ! message && maxTotal && total > maxTotal ) message = 'Enter a total quantity of at most ' + maxTotal + '.';
					quantityInputs.forEach( ( input ) => {
						const quantity = Math.max( 0, parseInt( input.value || '0', 10 ) || 0 );
						const minQuantity = parseInt( input.dataset.opfMinQuantity || '1', 10 );
						if ( ! message && quantity > 0 && quantity < minQuantity ) message = 'Enter at least ' + minQuantity + ' for each selected image.';
					} );
					quantityInputs.forEach( ( input ) => input.setCustomValidity( message ) );
					return;
				}
				if ( ! min && ! max ) {
					return;
				}
				const inputs = fieldEl.querySelectorAll( 'input[type="checkbox"]' );
				const count = fieldEl.querySelectorAll( 'input[type="checkbox"]:checked' ).length;
				const message = min && count < min
					? 'Select at least ' + min + ' option(s).'
					: ( max && count > max ? 'Select at most ' + max + ' option(s).' : '' );
				inputs.forEach( ( input ) => input.setCustomValidity( message ) );
			} );
		};

		const validateDateRestrictions = () => {
			fields.forEach( ( fieldEl ) => {
				const input = fieldEl.querySelector( 'input[type="date"]' );
				if ( ! input ) return;
				const value = input.value;
				let message = '';
				if ( value ) {
					const date = new Date( value + 'T00:00:00Z' );
					const weekdays = JSON.parse( input.dataset.opfDisabledWeekdays || '[]' );
					const disabledDates = JSON.parse( input.dataset.opfDisabledDates || '[]' );
					if ( weekdays.includes( date.getUTCDay() ) ) message = 'This weekday is unavailable.';
					if ( ! message && dateMatchesBlackout( value, disabledDates ) ) message = 'This date is unavailable.';
					const cutoff = input.dataset.opfDateCutoff;
					const allowPast = input.dataset.opfAllowPast !== '0';
					const allowFuture = input.dataset.opfAllowFuture !== '0';
					const clock = cutoff || ! allowPast || ! allowFuture ? dateSiteClock( input ) : null;
					if ( ! message && ( cutoff || ! allowPast || ! allowFuture ) && ! clock ) message = 'Date availability cannot be confirmed.';
					if ( ! message && clock && ! allowPast && value < clock.date ) message = 'Past dates are unavailable.';
					if ( ! message && clock && ! allowFuture && value > clock.date ) message = 'Future dates are unavailable.';
					if ( ! message && cutoff && clock && value === clock.date && clock.time >= cutoff ) message = 'Today is no longer available.';
				}
				input.setCustomValidity( message );
			} );
		};
		if ( window.setInterval && ! groupEl.opfDateCutoffTimer && Array.from( fields ).some( ( fieldEl ) => {
			const input = fieldEl.querySelector( 'input[type="date"]' );
			return input && ( input.dataset.opfDateCutoff || input.dataset.opfAllowPast === '0' || input.dataset.opfAllowFuture === '0' );
		} ) ) {
			groupEl.opfDateCutoffTimer = window.setInterval( () => {
				validateDateRestrictions();
				fields.forEach( ( fieldEl ) => {
					const input = fieldEl.querySelector( 'input[type="date"]' );
					const panel = fieldEl.querySelector( '.opf-date-picker__panel' );
					if ( input && panel && ! panel.hidden && input.opfRenderDateCalendar ) input.opfRenderDateCalendar();
				} );
			}, 30000 );
			if ( window.addEventListener && window.clearInterval ) {
				window.addEventListener( 'pagehide', () => window.clearInterval( groupEl.opfDateCutoffTimer ), { once: true } );
			}
		}

		groupEl.addEventListener( 'input', ( event ) => {
			const fieldEl = event.target.closest( '[data-opf-field]' );
			if ( ! fieldEl ) {
				return;
			}
			const fid = fieldEl.getAttribute( 'data-opf-field' );
			const input = event.target;
			if ( fieldDefs[ fid ]?.repeat?.enabled ) {
				values[ fid ] = readRepeatedRows( fieldEl, fieldDefs[ fid ].type );
			} else if ( fieldDefs[ fid ] && fieldDefs[ fid ].type === 'toggle' ) {
				values[ fid ] = input.checked ? '1' : '0';
			} else if ( input.type === 'checkbox' && input.name.endsWith( '[]' ) ) {
				values[ fid ] = Array.from(
					groupEl.querySelectorAll(
						'[data-opf-field="' + fid + '"] input:checked'
					)
				).map( ( c ) => c.value );
			} else {
				values[ fid ] = input.value;
			}
			if ( input.type === 'radio' || input.type === 'checkbox' ) {
				syncChecked();
			}
			validateChoiceLimits();
			validateDateRestrictions();
			updateNumberStepperButtons( fieldEl );
			refresh();
		} );

		const updateRepeatButtons = ( fieldEl ) => {
			const count = fieldEl.querySelectorAll( '[data-opf-repeat-row]' ).length;
			const max = parseInt( fieldEl.getAttribute( 'data-opf-repeat-max' ) || '1', 10 );
			const add = fieldEl.querySelector( '[data-opf-repeat-add]' );
			if ( add ) add.disabled = count >= max;
			fieldEl.querySelectorAll( '[data-opf-repeat-remove]' ).forEach( ( button ) => { button.disabled = count <= 1; } );
		};
		const reindexRepeatRows = ( fieldEl ) => {
			Array.from( fieldEl.querySelectorAll( '[data-opf-repeat-row]' ) ).forEach( ( row, index ) => {
				row.querySelectorAll( 'input, textarea, select' ).forEach( ( input ) => {
					input.name = input.name.replace( /\[\d+\](?=(?:\[\])?$)/, '[' + index + ']' );
				} );
				row.querySelectorAll( '[id], label[for]' ).forEach( ( node ) => {
					if ( node.id ) node.id = node.id.replace( /-repeat-\d+/, '-repeat-' + index );
					if ( node.htmlFor ) node.htmlFor = node.htmlFor.replace( /-repeat-\d+/, '-repeat-' + index );
				} );
			} );
		};
		const quantityRepeatStash = new Map();
		const clearRepeatRow = ( row, next ) => {
			row.querySelectorAll( 'input, textarea, select' ).forEach( ( input ) => {
				input.name = input.name.replace( /\[\d+\](?=(?:\[\])?$)/, '[' + next + ']' );
				input.value = input.type === 'hidden' ? '0' : '';
				if ( 'checked' in input ) input.checked = false;
				if ( input.setCustomValidity ) input.setCustomValidity( '' );
			} );
			row.querySelectorAll( '[id], label[for]' ).forEach( ( node ) => {
				if ( node.id ) node.id = node.id.replace( /-repeat-\d+/, '-repeat-' + next );
				if ( node.htmlFor ) node.htmlFor = node.htmlFor.replace( /-repeat-\d+/, '-repeat-' + next );
			} );
		};
		const quantityInput = document.querySelector ? document.querySelector( 'form.cart input[name="quantity"], form.cart input.qty' ) : null;
		const syncQuantityRepeats = () => {
			const requested = quantityInput ? parseInt( quantityInput.value || '1', 10 ) : 1;
			fields.forEach( ( fieldEl ) => {
				if ( fieldEl.getAttribute( 'data-opf-repeat-mode' ) !== 'quantity' ) return;
				const list = fieldEl.querySelector( '[data-opf-repeat-list]' );
				if ( ! list ) return;
				const max = Math.max( 0, parseInt( fieldEl.getAttribute( 'data-opf-repeat-max' ) || '0', 10 ) || 0 );
				if ( quantityInput && fieldEl.dataset.opfRepeatInvalid ) {
					quantityInput.setCustomValidity( '' );
					delete fieldEl.dataset.opfRepeatInvalid;
				}
				if ( requested > max ) {
					if ( quantityInput ) {
						quantityInput.setCustomValidity( 'This product quantity exceeds the maximum supported with these options (' + max + ').' );
						fieldEl.dataset.opfRepeatInvalid = '1';
					}
					return;
				}
				if ( quantityInput ) quantityInput.setCustomValidity( '' );
				const desired = Math.max( 1, Number.isFinite( requested ) ? requested : 1 );
				let rows = Array.from( list.querySelectorAll( '[data-opf-repeat-row]' ) );
				while ( rows.length < desired && rows.length < max && rows[0] ) {
					const fid = fieldEl.getAttribute( 'data-opf-field' );
					const index = rows.length;
					const stashed = quantityRepeatStash.get( fid );
					if ( stashed && stashed.has( index ) ) {
						list.appendChild( stashed.get( index ) );
						stashed.delete( index );
					} else {
						const clone = rows[0].cloneNode( true );
						clearRepeatRow( clone, index );
						list.appendChild( clone );
					}
					rows = Array.from( list.querySelectorAll( '[data-opf-repeat-row]' ) );
				}
				if ( rows.length > desired ) {
					const fid = fieldEl.getAttribute( 'data-opf-field' );
					if ( ! quantityRepeatStash.has( fid ) ) quantityRepeatStash.set( fid, new Map() );
					const stashed = quantityRepeatStash.get( fid );
					while ( rows.length > desired ) {
						const index = rows.length - 1;
						const row = rows.pop();
						stashed.set( index, row );
						row.remove();
					}
			}
			reindexRepeatRows( fieldEl );
			const fid = fieldEl.getAttribute( 'data-opf-field' );
			values[ fid ] = readRepeatedRows( fieldEl, fieldDefs[ fid ]?.type );
		} );
		validateChoiceLimits();
		validateDateRestrictions();
		refresh();
		};
		if ( quantityInput && typeof quantityInput.addEventListener === 'function' ) {
			quantityInput.addEventListener( 'input', syncQuantityRepeats );
			quantityInput.addEventListener( 'change', syncQuantityRepeats );
			const cartForm = quantityInput.closest ? quantityInput.closest( 'form.cart' ) : null;
			if ( cartForm ) cartForm.addEventListener( 'submit', ( event ) => {
				syncQuantityRepeats();
				if ( ! quantityInput.checkValidity() ) {
					event.preventDefault();
					quantityInput.reportValidity();
				}
			} );
		}
		groupEl.addEventListener( 'click', ( event ) => {
			const add = event.target.closest( '[data-opf-repeat-add]' );
			const remove = event.target.closest( '[data-opf-repeat-remove]' );
			if ( ! add && ! remove ) return;
			const fieldEl = event.target.closest( '[data-opf-field][data-opf-repeat="1"]' );
			const list = fieldEl && fieldEl.querySelector( '[data-opf-repeat-list]' );
			if ( ! list ) return;
			event.preventDefault();
			const rows = Array.from( list.querySelectorAll( '[data-opf-repeat-row]' ) );
			if ( add && rows.length < parseInt( fieldEl.getAttribute( 'data-opf-repeat-max' ) || '1', 10 ) ) {
				const next = rows.length;
				const clone = rows[ 0 ].cloneNode( true );
				clearRepeatRow( clone, next );
				list.appendChild( clone );
			} else if ( remove ) {
				const row = remove.closest( '[data-opf-repeat-row]' );
				if ( rows.length > 1 && row ) row.remove();
			}
			reindexRepeatRows( fieldEl );
			const repeatId = fieldEl.getAttribute( 'data-opf-field' );
			values[ repeatId ] = readRepeatedRows( fieldEl, fieldDefs[ repeatId ]?.type );
			updateRepeatButtons( fieldEl );
			validateChoiceLimits();
			refresh();
		} );
		fields.forEach( ( fieldEl ) => { if ( fieldDefs[ fieldEl.getAttribute( 'data-opf-field' ) ]?.repeat?.enabled ) updateRepeatButtons( fieldEl ); } );
		syncQuantityRepeats();

		refresh();
		syncChecked();
		validateChoiceLimits();
		validateDateRestrictions();
	} );
};

const editUploadImage = ( file, uploader, { allowOriginal = true } = {} ) => new Promise( ( resolve ) => {
	const mode = uploader.dataset.opfUploadEditor || '';
	if ( ! mode ) {
		resolve( file );
		return;
	}
	if ( file.type && ! file.type.startsWith( 'image/' ) ) {
		if ( 'forced' === mode ) {
			uploader.querySelector( '[data-opf-upload-status]' ).textContent = 'The image editor accepts image files only.';
			resolve( null );
		} else {
			resolve( file );
		}
		return;
	}
	const dialog = document.createElement( 'dialog' );
	dialog.className = 'opf-image-editor';
	dialog.setAttribute( 'aria-labelledby', 'opf-image-editor-title' );
	const heading = document.createElement( 'h2' );
	heading.id = 'opf-image-editor-title';
	heading.textContent = 'Edit image';
	const preview = document.createElement( 'canvas' );
	preview.className = 'opf-image-editor__preview';
	const tools = document.createElement( 'div' );
	tools.className = 'opf-image-editor__tools';
	const image = new Image();
	const objectUrl = URL.createObjectURL( file );
	let angle = 0;
	let flipX = false;
	let flipY = false;
	let zoom = 1;
	let horizontal = 0.5;
	let vertical = 0.5;
	let ratio = uploader.dataset.opfEditorAspect || 'free';
	const addButton = ( label, action ) => {
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.textContent = label;
		button.addEventListener( 'click', action );
		tools.appendChild( button );
		return button;
	};
	const addRange = ( label, min, max, step, initial, onChange ) => {
		const wrapper = document.createElement( 'label' );
		wrapper.className = 'opf-image-editor__range';
		const text = document.createElement( 'span' );
		text.textContent = label;
		const range = document.createElement( 'input' );
		range.type = 'range';
		range.min = String( min );
		range.max = String( max );
		range.step = String( step );
		range.value = String( initial );
		range.setAttribute( 'aria-label', label );
		range.addEventListener( 'input', () => onChange( Number( range.value ) ) );
		wrapper.append( text, range );
		tools.appendChild( wrapper );
	};
	const aspectValue = ( value ) => {
		if ( 'free' === value ) return null;
		const parts = value.split( ':' ).map( Number );
		return parts.length === 2 && parts[0] > 0 && parts[1] > 0 ? parts[0] / parts[1] : null;
	};
	const draw = () => {
		const quarterTurn = Math.abs( angle % 180 ) === 90;
		const width = image.naturalWidth;
		const height = image.naturalHeight;
		const transformedWidth = quarterTurn ? height : width;
		const transformedHeight = quarterTurn ? width : height;
		const transformed = document.createElement( 'canvas' );
		transformed.width = transformedWidth;
		transformed.height = transformedHeight;
		const transformContext = transformed.getContext( '2d' );
		transformContext.translate( transformedWidth / 2, transformedHeight / 2 );
		transformContext.rotate( angle * Math.PI / 180 );
		transformContext.scale( flipX ? -1 : 1, flipY ? -1 : 1 );
		transformContext.drawImage( image, -width / 2, -height / 2 );
		let cropWidth = transformedWidth / zoom;
		let cropHeight = transformedHeight / zoom;
		const wanted = uploader.dataset.opfEditorCrop === '1' ? aspectValue( ratio ) : null;
		if ( wanted ) {
			if ( cropWidth / cropHeight > wanted ) cropWidth = cropHeight * wanted;
			else cropHeight = cropWidth / wanted;
		}
		const left = ( transformedWidth - cropWidth ) * horizontal;
		const top = ( transformedHeight - cropHeight ) * vertical;
		const output = document.createElement( 'canvas' );
		output.width = Math.max( 1, Math.round( cropWidth ) );
		output.height = Math.max( 1, Math.round( cropHeight ) );
		output.getContext( '2d' ).drawImage( transformed, left, top, cropWidth, cropHeight, 0, 0, output.width, output.height );
		const previewScale = Math.min( 1, 720 / output.width, 480 / output.height );
		preview.width = Math.max( 1, Math.round( output.width * previewScale ) );
		preview.height = Math.max( 1, Math.round( output.height * previewScale ) );
		preview.getContext( '2d' ).drawImage( output, 0, 0, preview.width, preview.height );
		return output;
	};
	const close = ( result ) => {
		URL.revokeObjectURL( objectUrl );
		dialog.close();
		dialog.remove();
		resolve( result );
	};
	image.onload = () => {
		if ( uploader.dataset.opfEditorCrop === '1' ) {
			const aspect = document.createElement( 'select' );
			aspect.setAttribute( 'aria-label', 'Crop aspect ratio' );
			[ [ 'free', 'Free' ], [ '1:1', 'Square 1:1' ], [ '4:3', '4:3' ], [ '3:2', '3:2' ], [ '16:9', '16:9' ], [ '2:3', '2:3' ], [ '9:16', '9:16' ] ].forEach( ( option ) => {
				const item = document.createElement( 'option' );
				item.value = option[0];
				item.textContent = option[1];
				aspect.appendChild( item );
			} );
			aspect.value = ratio;
			aspect.addEventListener( 'change', () => { ratio = aspect.value; draw(); } );
			const label = document.createElement( 'label' );
			label.textContent = 'Crop ratio ';
			label.appendChild( aspect );
			tools.appendChild( label );
			addRange( 'Crop left to right', 0, 1, 0.01, horizontal, ( value ) => { horizontal = value; draw(); } );
			addRange( 'Crop top to bottom', 0, 1, 0.01, vertical, ( value ) => { vertical = value; draw(); } );
		}
		if ( uploader.dataset.opfEditorResize === '1' ) addRange( 'Zoom', 1, 3, 0.05, 1, ( value ) => { zoom = value; draw(); } );
		if ( uploader.dataset.opfEditorRotate === '1' ) {
			addButton( 'Rotate left', () => { angle = ( angle + 270 ) % 360; draw(); } );
			addButton( 'Rotate right', () => { angle = ( angle + 90 ) % 360; draw(); } );
		}
		if ( uploader.dataset.opfEditorFlip === '1' ) {
			addButton( 'Flip horizontally', () => { flipX = ! flipX; draw(); } );
			addButton( 'Flip vertically', () => { flipY = ! flipY; draw(); } );
		}
		const actions = document.createElement( 'div' );
		actions.className = 'opf-image-editor__actions';
		const save = document.createElement( 'button' );
		save.type = 'button';
		save.textContent = 'Use edited image';
		save.addEventListener( 'click', () => draw().toBlob( ( blob ) => {
			if ( ! blob ) { close( null ); return; }
			if ( file.type && blob.type !== file.type ) {
				const status = uploader.querySelector( '[data-opf-upload-status]' );
				if ( status ) status.textContent = 'This image format cannot be edited without changing its file type.';
				close( null );
				return;
			}
			const outputType = file.type || blob.type;
			const extension = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp' }[ outputType ];
			if ( ! extension ) { close( null ); return; }
			const outputName = file.type ? file.name : file.name.replace( /\.[^.]*$/, '' ) + '.' + extension;
			close( new File( [ blob ], outputName, { type: outputType, lastModified: file.lastModified } ) );
		}, file.type || 'image/png' ) );
		actions.appendChild( save );
		if ( mode === 'optional' && allowOriginal ) {
			const original = document.createElement( 'button' );
			original.type = 'button';
			original.textContent = 'Upload original';
			original.addEventListener( 'click', () => close( file ) );
			actions.appendChild( original );
		}
		const cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.textContent = 'Cancel';
		cancel.addEventListener( 'click', () => close( null ) );
		actions.appendChild( cancel );
		dialog.append( heading, preview, tools, actions );
		dialog.addEventListener( 'cancel', ( event ) => { event.preventDefault(); close( null ); } );
		document.body.appendChild( dialog );
		draw();
		if ( typeof dialog.showModal === 'function' ) dialog.showModal();
		else dialog.setAttribute( 'open', '' );
	};
	image.onerror = () => {
		URL.revokeObjectURL( objectUrl );
		if ( mode === 'optional' && allowOriginal ) {
			resolve( file );
			return;
		}
		const status = uploader.querySelector( '[data-opf-upload-status]' );
		if ( status ) status.textContent = 'This file is not a readable image and cannot be edited.';
		resolve( null );
	};
	image.src = objectUrl;
} );

const initUploaders = ( root = document ) => {
	const uploaders = root.matches?.( '[data-opf-upload]' ) ? [ root, ...root.querySelectorAll( '[data-opf-upload]' ) ] : root.querySelectorAll( '[data-opf-upload]' );
	uploaders.forEach( ( uploader ) => {
		if ( uploader.dataset?.opfUploaderInitialized ) return;
		const input = uploader.querySelector( '[data-opf-upload-input]' );
		const drop = uploader.querySelector( '[data-opf-upload-drop]' );
		const list = uploader.querySelector( '[data-opf-upload-list]' );
		const status = uploader.querySelector( '[data-opf-upload-status]' );
		if ( ! input || ! list || ! window.XMLHttpRequest ) return;
		if ( uploader.dataset ) uploader.dataset.opfUploaderInitialized = '1';
		const max = Number( uploader.dataset.opfUploadMax ) || 1;
		const min = Math.max( 0, Number( uploader.dataset.opfUploadMin ) || 0 );
		const form = input.closest( 'form' );
		let pending = 0;
		if ( form && ! form.dataset.opfUploadSubmitGuard ) {
			form.dataset.opfUploadSubmitGuard = '1';
			form.addEventListener( 'submit', ( event ) => {
				form.querySelectorAll( '[data-opf-upload]' ).forEach( ( uploadField ) => {
					if ( uploadField.closest( '.opf-hide' ) ) return;
					const uploadInput = uploadField.querySelector( '[data-opf-upload-input]' );
					const count = uploadField.querySelectorAll( '[data-opf-upload-token]' ).length + ( uploadInput && uploadInput.files ? uploadInput.files.length : 0 );
					const requiredCount = Math.max( 0, Number( uploadField.dataset.opfUploadMin ) || 0 );
					if ( count < requiredCount ) {
						event.preventDefault();
						const message = uploadField.querySelector( '[data-opf-upload-status]' );
						if ( message ) message.textContent = `Upload at least ${ requiredCount } file(s).`;
						uploadInput?.focus();
					}
				} );
				if ( Number( form.dataset.opfUploadsPending || 0 ) > 0 ) {
					event.preventDefault();
					const message = form.querySelector( '[data-opf-upload-status]' );
					if ( message ) message.textContent = 'Please wait for uploads to finish.';
				}
			} );
		}
		const ticketInputs = () => Array.from( uploader.querySelectorAll( '[data-opf-upload-token]' ) );
		const setStatus = ( message ) => { if ( status ) status.textContent = message; };
		const send = ( method, url, body, onProgress ) => new Promise( ( resolve, reject ) => {
			const xhr = new XMLHttpRequest();
			xhr.open( method, url, true );
			xhr.setRequestHeader( 'X-WP-Nonce', uploader.dataset.opfUploadNonce || '' );
			if ( onProgress && xhr.upload ) xhr.upload.onprogress = onProgress;
			xhr.onload = () => {
				let response = {};
				try { response = JSON.parse( xhr.responseText || '{}' ); } catch ( error ) {}
				if ( xhr.status >= 200 && xhr.status < 300 ) resolve( response );
				else reject( new Error( response.message || 'The file could not be uploaded.' ) );
			};
			xhr.onerror = () => reject( new Error( 'The file could not be uploaded. Check your connection and try again.' ) );
			xhr.send( body );
		} );
		const uploadFile = async ( file, replacement = null ) => {
			const existingTickets = ticketInputs().filter( ( ticket ) => ! replacement || ticket !== replacement.tokenInput );
			if ( max > 0 && existingTickets.length >= max ) { setStatus( `You can upload up to ${ max } file(s).` ); return; }
			pending++;
			if ( form ) form.dataset.opfUploadsPending = String( Number( form.dataset.opfUploadsPending || 0 ) + 1 );
			const row = document.createElement( 'li' );
			row.className = 'opf-upload__item';
			const title = document.createElement( 'span' );
			title.className = 'opf-upload__name';
			title.textContent = file.name;
			const progress = document.createElement( 'progress' );
			progress.max = 100;
			progress.value = 0;
			progress.setAttribute( 'aria-label', `Uploading ${ file.name }` );
			row.appendChild( title );
			let localPreviewUrl = '';
			if ( file.type && file.type.startsWith( 'image/' ) && window.URL && URL.createObjectURL ) {
				const image = document.createElement( 'img' );
				image.className = 'opf-upload__preview';
				image.alt = '';
				localPreviewUrl = URL.createObjectURL( file );
				image.src = localPreviewUrl;
				image.dataset.opfLocalPreviewUrl = localPreviewUrl;
				row.appendChild( image );
			}
			row.appendChild( progress );
			list.appendChild( row );
			const body = new FormData();
			body.append( 'file', file, file.name );
			body.append( 'product_id', uploader.dataset.opfUploadProduct || '0' );
			body.append( 'group_id', uploader.dataset.opfUploadGroup || '' );
			body.append( 'field_id', uploader.dataset.opfUploadField || '' );
			if ( replacement?.tokenInput?.value ) body.append( 'replace_token', replacement.tokenInput.value );
			try {
				const url = ( uploader.dataset.opfUploadUrl || '' ).replace( /\/?$/, '/' );
				const result = await send( 'POST', url, body, ( event ) => {
					if ( event.lengthComputable ) progress.value = Math.round( event.loaded / event.total * 100 );
				} );
				const previewKey = `${ uploader.dataset.opfUploadGroup }:${ uploader.dataset.opfUploadField }`;
				const previewMap = window.OPF_UPLOAD_PREVIEW_URLS || ( window.OPF_UPLOAD_PREVIEW_URLS = {} );
				const previewUrls = Array.isArray( previewMap[ previewKey ] ) ? previewMap[ previewKey ] : ( previewMap[ previewKey ] = [] );
				const token = replacement?.tokenInput || document.createElement( 'input' );
				token.type = 'hidden';
				token.name = `opf_upload_tokens[${ uploader.dataset.opfUploadGroup }][${ uploader.dataset.opfUploadField }][]`;
				token.value = result.token;
				token.dataset.opfUploadToken = '';
				if ( ! replacement ) uploader.appendChild( token );
				if ( replacement?.row ) {
					const oldPreview = replacement.row.querySelector( '.opf-upload__preview' );
					const oldUrl = oldPreview?.dataset.opfLocalPreviewUrl || '';
					if ( oldUrl ) {
						const oldIndex = previewUrls.indexOf( oldUrl );
						if ( oldIndex !== -1 ) previewUrls.splice( oldIndex, 1 );
						URL.revokeObjectURL( oldUrl );
					}
					replacement.row.remove();
				}
				if ( localPreviewUrl ) previewUrls.push( localPreviewUrl );
				previewMap[ previewKey ] = previewUrls;
				document.dispatchEvent( new CustomEvent( 'opf:upload-preview-change', { detail: { key: previewKey, url: previewUrls[ 0 ] || '' } } ) );
				progress.remove();
				const remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.className = 'opf-upload__remove';
				remove.textContent = 'Remove';
				remove.setAttribute( 'aria-label', `Remove ${ result.name || file.name }` );
				remove.addEventListener( 'click', async () => {
					remove.disabled = true;
					try {
						await send( 'DELETE', `${ url }${ encodeURIComponent( result.token ) }`, null );
						const previewUrl = row.querySelector( '.opf-upload__preview' )?.dataset.opfLocalPreviewUrl || '';
						if ( previewUrl ) {
							const previewIndex = previewUrls.indexOf( previewUrl );
							if ( previewIndex !== -1 ) previewUrls.splice( previewIndex, 1 );
							URL.revokeObjectURL( previewUrl );
							previewMap[ previewKey ] = previewUrls;
							document.dispatchEvent( new CustomEvent( 'opf:upload-preview-change', { detail: { key: previewKey, url: previewUrls[ 0 ] || '' } } ) );
						}
						token.remove();
						row.remove();
						dispatchUploadChange( input );
					} catch ( error ) { setStatus( error.message ); remove.disabled = false; }
				} );
				row.appendChild( remove );
				if ( uploader.dataset.opfUploadEditor === 'optional' && file.type?.startsWith( 'image/' ) ) {
					const edit = document.createElement( 'button' );
					edit.type = 'button';
					edit.className = 'opf-upload__edit';
					edit.textContent = 'Edit';
					edit.setAttribute( 'aria-label', `Edit ${ result.name || file.name }` );
					edit.addEventListener( 'click', async () => {
						edit.disabled = true;
						const prepared = await editUploadImage( file, uploader, { allowOriginal: false } );
						if ( prepared ) await uploadFile( prepared, { tokenInput: token, row } );
						if ( edit.isConnected ) edit.disabled = false;
					} );
					row.appendChild( edit );
				}
				setStatus( `${ result.name || file.name } uploaded.` );
				dispatchUploadChange( input );
			} catch ( error ) {
				if ( localPreviewUrl ) URL.revokeObjectURL( localPreviewUrl );
				row.remove();
				setStatus( error.message );
			} finally {
				pending--;
				if ( form ) form.dataset.opfUploadsPending = String( Math.max( 0, Number( form.dataset.opfUploadsPending || 1 ) - 1 ) );
			}
		};
		const queueFiles = async ( files ) => {
			for ( const file of Array.from( files || [] ) ) {
				const prepared = uploader.dataset.opfUploadEditor === 'forced' ? await editUploadImage( file, uploader ) : file;
				if ( prepared ) await uploadFile( prepared );
			}
		};
		input.addEventListener( 'change', async () => {
			const files = Array.from( input.files || [] );
			input.value = '';
			await queueFiles( files );
		} );
		if ( drop ) {
			drop.addEventListener( 'dragover', ( event ) => { event.preventDefault(); drop.classList.add( 'opf-upload__drop--active' ); } );
			[ 'dragleave', 'drop' ].forEach( ( type ) => drop.addEventListener( type, ( event ) => { event.preventDefault(); drop.classList.remove( 'opf-upload__drop--active' ); } ) );
			drop.addEventListener( 'drop', ( event ) => { if ( event.dataTransfer?.files ) queueFiles( event.dataTransfer.files ); } );
		}
	} );
};

const dispatchUploadChange = ( input ) => {
	const EventType = window.Event || Event;
	input.dispatchEvent( new EventType( 'input', { bubbles: true } ) );
	input.dispatchEvent( new EventType( 'change', { bubbles: true } ) );
};

// ---------------------------------------------------------------------------
// Totals block writer (replaces the legacy plugin's own totals JS).
// Reads the server-rendered data-product-price + the per-choice/field
// data-opf-price attributes and writes the three legacy totals spans, using
// the same display_options contract the theme's currency converter expects.

const fmtMoney = (amount) => {
  const o = (window.opf_config || {}).display_options || {};
  const symbol = o.symbol || '$';
  const decimals = typeof o.decimals === 'number' ? o.decimals : 2;
  const thousand = o.thousand || ',';
  const decimal = o.decimal || '.';
  const fixed = Math.abs(amount).toFixed(decimals);
  const [intPart, fracPart] = fixed.split('.');
  const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousand);
  const price = `${grouped}${decimals > 0 ? decimal + fracPart.slice(0, decimals) : ''}`;
  const format = String(o.price_format || 'symbolprice');
  const formatted = format.includes('symbol') && format.includes('price')
    ? format.replace('symbol', symbol).replace('price', price)
    : `${symbol}${price}`;
  return `${amount < 0 ? '-' : ''}${formatted}`;
};

const lookupTableValue = (rawArgs, fieldValues, lookupTables) => {
  const args = rawArgs.split(';').map((arg) => arg.trim());
  const name = args.shift();
  if (!/^[a-z0-9_]+$/i.test(name) || args.length < 1 || args.length > 64) return 0;
  const rows = lookupTables && Array.isArray(lookupTables[name]) ? lookupTables[name].filter((row) =>
    Array.isArray(row) && row.length === args.length + 1 && Number.isFinite(Number(row[row.length - 1]))
  ) : [];
  const input = args.map((id) => fieldValues[String(id).toLowerCase()]);
  if (!rows.length || input.some((value) => value == null || Array.isArray(value) || typeof value === 'object' || String(value).trim() === '')) return 0;
  const equal = (left, right) => Number.isFinite(Number(left)) && String(left).trim() !== '' && Number.isFinite(Number(right)) && String(right).trim() !== ''
    ? Number(left) === Number(right)
    : String(left) === String(right);
  const tupleEqual = (left, right) => left.length === right.length && left.every((value, index) => equal(value, right[index]));
  for (const row of rows) {
    if (tupleEqual(input, row.slice(0, -1))) return Number(row[row.length - 1]);
  }
  if (args.length > 2) return 0;
  const target = [];
  for (let dimension = 0; dimension < args.length; dimension++) {
    const coordinates = rows.map((row) => row[dimension]);
    const allNumeric = Number.isFinite(Number(input[dimension])) && String(input[dimension]).trim() !== '' && coordinates.every((coordinate) => Number.isFinite(Number(coordinate)) && String(coordinate).trim() !== '');
    if (allNumeric) {
      const candidates = coordinates.map(Number).filter((coordinate) => coordinate >= Number(input[dimension])).sort((a, b) => a - b);
      if (!candidates.length) return 0;
      target.push(candidates[0]);
    } else {
      const exact = coordinates.find((coordinate) => equal(input[dimension], coordinate));
      if (exact === undefined) return 0;
      target.push(exact);
    }
  }
  const match = rows.find((row) => tupleEqual(target, row.slice(0, -1)));
  return match ? Number(match[match.length - 1]) : 0;
};

const evalFormula = (formula, price, qty, addons, val, fieldValues = {}, siteToday = '', fileCounts = {}, lookupTables = {}, formulaVariables = {}, fieldPrices = {}) => {
  // Safe mirror of the server-side evaluator (per-unit formulas; the qty
  // factor was stripped at import and is re-applied by the caller).
  const todayDate = siteToday || globalThis.OPF_TODAY || new Date().toISOString().slice(0, 10);
  const dateFormat = String(
    globalThis.opf_config?.date_format
      || (typeof document !== 'undefined' ? document.querySelector('[data-opf-date-format]')?.dataset.opfDateFormat : '')
      || 'mm-dd-yyyy'
  ).toLowerCase();
  const resolveFormulaDate = (rawValue) => {
    let value = String(rawValue || '').trim();
    if ((value.startsWith("'") && value.endsWith("'")) || (value.startsWith('"') && value.endsWith('"'))) value = value.slice(1, -1);
    if (value === '__OPF_TODAY__') value = String(todayDate);
    const field = /^\[field\.([a-z0-9_-]+)\]$/i.exec(value);
    if (field) {
      const supplied = fieldValues[String(field[1]).toLowerCase()];
      value = supplied == null || Array.isArray(supplied) ? '' : String(supplied);
    }
    let year;
    let month;
    let day;
    const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (iso) {
      [, year, month, day] = iso.map((part) => Number(part));
    } else {
      const tokens = dateFormat.match(/yyyy|yy|mm|m|dd|d|[-\/., ]/g);
      if (!tokens || tokens.length !== 5) return null;
      const pattern = tokens.map((token) => {
        if (token === 'yyyy') return '(\\d{4})';
        if (token === 'yy' || token === 'mm' || token === 'dd') return '(\\d{2})';
        if (token === 'm' || token === 'd') return '(\\d{1,2})';
        return token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      }).join('');
      const match = new RegExp('^' + pattern + '$').exec(value);
      if (!match || match.length !== 4) return null;
      const parts = {};
      let capture = 1;
      tokens.forEach((token) => {
        if (/^(yyyy|yy|mm|m|dd|d)$/.test(token)) parts[token] = Number(match[capture++]);
      });
      year = parts.yyyy ?? parts.yy;
      if (parts.yy !== undefined) year += year < 70 ? 2000 : 1900;
      month = parts.mm ?? parts.m;
      day = parts.dd ?? parts.d;
    }
    if (![year, month, day].every(Number.isInteger)) return null;
    const date = new Date(0);
    date.setUTCHours(0, 0, 0, 0);
    date.setUTCFullYear(year, month - 1, day);
    if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return null;
    return { year, month, day, weekday: date.getUTCDay() };
  };
  const expr = String(formula)
    .replace(/lookuptable\s*\(([^()]*)\)/gi, (_, args) => String(lookupTableValue(args, fieldValues, lookupTables)))
    .replace(/\[price\.([a-z0-9_-]+)\]/gi, (_, id) => {
      const value = fieldPrices[String(id).toLowerCase()];
      return Number.isFinite(Number(value)) ? String(Number(value)) : '0';
    })
    .replace(/\[var_([a-z][a-z0-9_]{0,63})\]/gi, (_, name) => {
      const value = formulaVariables[String(name).toLowerCase()];
      return Number.isFinite(Number(value)) ? String(Number(value)) : '0';
    })
    .replace(/\bacf(_option)?\s*\(\s*([a-z][a-z0-9_-]{0,63})\s*\)/gi, (_, option, name) => {
      const source = option ? 'option' : 'field';
      const value = formulaVariables['opf_acf_' + source + '_' + String(name).toLowerCase()];
      return Number.isFinite(Number(value)) ? String(Number(value)) : '0';
    })
    .replace(/len\s*\(([^()]*)\)/gi, (_, rawArgs) => {
      const args = rawArgs.split(';', 2);
      let text = args[0].trim();
      const field = /^\[field\.([a-z0-9_-]+)\]$/i.exec(text);
      if (field) {
        const value = fieldValues[String(field[1]).toLowerCase()];
        text = value == null || Array.isArray(value) ? '' : String(value);
      }
      if (args.length > 1 && !['true', 'false'].includes(args[1].trim().toLowerCase())) return '0';
      if (args.length > 1 && args[1].trim().toLowerCase() === 'true') text = text.replace(/\s+/gu, '');
      return String(Array.from(text).length);
    })
    .replace(/\[field\.([a-z0-9_-]+)\]\s*(>=|<=|!=|=|>|<)\s*([a-z][^;,()]*)/gi, (full, id, operator, rawRight) => {
      const value = fieldValues[String(id).toLowerCase()];
      const right = rawRight.trim();
      if (value == null || Array.isArray(value) || Number.isFinite(Number(value)) && String(value).trim() !== '' || Number.isFinite(Number(right))) return full;
      const left = String(value);
      let passed = false;
      if (operator === '=') passed = left === right;
      else if (operator === '!=') passed = left !== right;
      else if (operator === '>') passed = left > right;
      else if (operator === '<') passed = left < right;
      else if (operator === '>=') passed = left >= right;
      else if (operator === '<=') passed = left <= right;
      return passed ? '1' : '0';
    })
    .replace(/files\s*\(\s*([a-z0-9_-]+)\s*\)/gi, (_, id) => String(Math.max(0, parseInt(fileCounts[String(id).toLowerCase()] || 0, 10) || 0)))
    .replace(/sumQty\s*\(\s*([a-z0-9_-]+)\s*\)/gi, (_, id) => {
      const value = fieldValues[String(id).toLowerCase()];
      if (!value || typeof value !== 'object' || Array.isArray(value)) return '0';
      return String(Object.values(value).reduce((sum, quantity) => {
        const parsed = /^\d+$/.test(String(quantity)) ? Number(quantity) : 0;
        return sum + parsed;
      }, 0));
    })
    .replace(/checked\s*\(\s*([a-z0-9_-]+)\s*\)/gi, (_, id) => {
      const value = fieldValues[String(id).toLowerCase()];
      return String(Array.isArray(value) ? value.filter((item) => (typeof item === 'string' || typeof item === 'number') && String(item) !== '').length : 0);
    })
    .replace(/today\s*\(\s*\)/gi, '__OPF_TODAY__')
    .replace(/\b(dow|month)\s*\(([^()]*)\)/gi, (_, fn, rawDate) => {
      const date = resolveFormulaDate(rawDate);
      if (!date) return '0';
      return String(fn.toLowerCase() === 'dow' ? date.weekday : date.month);
    })
    .replace(/datediff\s*\(([^()]*)\)/gi, (_, rawArgs) => {
      const args = rawArgs.split(';');
      if (args.length !== 2) return '0';
      const firstParts = resolveFormulaDate(args[0]);
      const secondParts = resolveFormulaDate(args[1]);
      const toDate = (parts) => parts && new Date(Date.UTC(parts.year, parts.month - 1, parts.day));
      const first = toDate(firstParts);
      const second = toDate(secondParts);
      if (!first || !second) return '0';
      return String((second.getTime() - first.getTime()) / 86400000);
    })
    .replace(/\[field\.([a-z0-9_-]+)\]/gi, (_, id) => {
      const value = fieldValues[String(id).toLowerCase()];
      return Number.isFinite(Number(value)) && String(value).trim() !== '' ? ` ${Number(value)} ` : ' 0 ';
    })
    .replace(/\[price\]/gi, ' P ')
    .replace(/\[qty\]/gi, ' Q ')
    .replace(/\[addons\]|\[options_total\]/gi, ' A ')
    .replace(/\[val\]/gi, ' V ');
  const vars = { P: price, Q: qty, A: addons, V: parseFloat(val) || 0 };
  let i = 0;
  const s = expr;
  const skipWs = () => { while (i < s.length && /\s/.test(s[i])) i++; };
  const parseExpr = () => {
    let v = parseTerm();
    while (true) {
      skipWs();
      if (s[i] === '+') { i++; v += parseTerm(); }
      else if (s[i] === '-') { i++; v -= parseTerm(); }
      else break;
    }
    return v;
  };
  const parseCondition = () => {
    const left = parseExpr();
    skipWs();
    const match = /^(>=|<=|!=|=|>|<)/.exec(s.slice(i));
    if (!match) return left;
    i += match[0].length;
    const right = parseExpr();
    switch (match[0]) {
      case '=': return left === right ? 1 : 0;
      case '!=': return left !== right ? 1 : 0;
      case '>': return left > right ? 1 : 0;
      case '<': return left < right ? 1 : 0;
      case '>=': return left >= right ? 1 : 0;
      case '<=': return left <= right ? 1 : 0;
      default: return NaN;
    }
  };
  const parseTerm = () => {
    let v = parseFactor();
    while (true) {
      skipWs();
      if (s[i] === '*') { i++; v *= parseFactor(); }
      else if (s[i] === '/') { i++; const r = parseFactor(); v = r === 0 ? NaN : v / r; }
      else break;
    }
    return v;
  };
  const parseFactor = () => {
    skipWs();
    if (s[i] === '(') { i++; const v = parseExpr(); skipWs(); if (s[i] === ')') i++; return v; }
    if (s[i] === '-') { i++; return -parseFactor(); }
    const fn = /^(min|max|abs|if|and|or|round|ceil|floor|pow|sqrt|sin|cos|tan)\s*\(/i.exec(s.slice(i));
    if (fn) {
      const name = fn[1].toLowerCase();
      i += fn[0].length;
      if (name === 'if') {
        const condition = parseCondition(); skipWs();
        if (s[i] !== ';' && s[i] !== ',') return NaN;
        i++;
        const whenTrue = parseExpr(); skipWs();
        if (s[i] !== ';' && s[i] !== ',') return NaN;
        i++;
        const whenFalse = parseExpr(); skipWs();
        if (s[i] !== ')') return NaN;
        i++;
        return condition !== 0 ? whenTrue : whenFalse;
      }
      if (name === 'and' || name === 'or') {
        const results = [];
        while (true) {
          results.push(parseCondition() !== 0);
          skipWs();
          if (s[i] !== ';' && s[i] !== ',') break;
          i++;
        }
        if (s[i] !== ')' || !results.length) return NaN;
        i++;
        return (name === 'and' ? results.every(Boolean) : results.some(Boolean)) ? 1 : 0;
      }
      const first = parseExpr(); skipWs();
      if (['abs', 'ceil', 'floor', 'sqrt', 'sin', 'cos', 'tan'].includes(name)) {
        if (s[i] !== ')') return NaN;
        i++;
        if (name === 'abs') return Math.abs(first);
        if (name === 'ceil') return Math.ceil(first);
        if (name === 'floor') return Math.floor(first);
        if (name === 'sqrt') return Math.sqrt(first);
        if (name === 'sin') return Math.sin(first);
        if (name === 'cos') return Math.cos(first);
        return Math.tan(first);
      }
      if (name === 'round') {
        let decimals = 0;
        if (s[i] === ';' || s[i] === ',') {
          i++;
          decimals = parseExpr();
          if (!Number.isInteger(decimals) || Math.abs(decimals) > 8) return NaN;
          skipWs();
        }
        if (s[i] !== ')') return NaN;
        i++;
        const factor = 10 ** decimals;
        return Math.sign(first) * Math.floor((Math.abs(first) + Number.EPSILON) * factor + 0.5) / factor;
      }
      if ((name === 'min' || name === 'max') && s[i] === ')') {
        i++;
        return first;
      }
      if (s[i] !== ';' && s[i] !== ',') return NaN;
      i++;
      const second = parseExpr(); skipWs();
      if (name === 'pow') {
        if (s[i] !== ')') return NaN;
        i++;
        return Math.pow(first, second);
      }
      const args = [first, second];
      while (s[i] === ';' || s[i] === ',') {
        i++;
        args.push(parseExpr());
        skipWs();
      }
      if (s[i] !== ')') return NaN;
      i++;
      return name === 'min' ? Math.min(...args) : Math.max(...args);
    }
    const m = /^\d+(?:\.\d+)?/.exec(s.slice(i));
    if (m) { i += m[0].length; return parseFloat(m[0]); }
    const variable = /^[PAQV]/.exec(s.slice(i));
    if (variable) { i += variable[0].length; return vars[variable[0]]; }
    i++; // force failure on unknown token
    return NaN;
  };
  skipWs();
  const out = parseExpr();
  skipWs();
  return isFinite(out) && i === s.length ? out : 0;
};

const perUnitAmount = (amount, pricing, qty) => {
  const configuredPerUnit = Object.prototype.hasOwnProperty.call(pricing, 'per_unit')
    ? !!pricing.per_unit
    : pricing.type !== 'fixed';
  return configuredPerUnit ? amount : amount / Math.max(1, qty);
};

const choiceOrFieldAddon = (def, value, base, qty, addons, val, fieldValues = {}, lookupTables = {}, formulaVariables = {}, fieldPrices = {}) => {
  if (def.type === 'swatch' || def.type === 'select' || def.type === 'radio' || def.type === 'checkbox') {
    const imageQuantities = def.type === 'swatch' && !!def.image_quantities && value && typeof value === 'object' && !Array.isArray(value);
    const slugs = imageQuantities ? Object.keys(value) : (Array.isArray(value) ? value : [value]);
    let sum = 0;
    (def.choices || []).forEach((c) => {
      if (!slugs.includes(c.slug) || c.disabled) return;
      const p = c.pricing || {};
      const choiceQuantity = imageQuantities ? Math.max(0, parseInt(value[c.slug], 10) || 0) : 1;
      if (p.type === 'fixed') {
        const amount = parseFloat(p.amount) || 0;
        sum += perUnitAmount(amount, p, qty) * choiceQuantity;
      }
      else if (p.type === 'percent') sum += perUnitAmount(base * ((parseFloat(p.amount) || 0) / 100), p, qty) * choiceQuantity;
      else if (p.type === 'formula') sum += evalFormula(p.formula, base, qty, addons, val, fieldValues, '', {}, lookupTables, formulaVariables, fieldPrices) / Math.max(1, qty) * choiceQuantity;
    });
    return sum;
  }
  const p = def.pricing || {};
  if (!String(value || '').trim()) return 0;
  if (p.type === 'fixed') {
    const amount = parseFloat(p.amount) || 0;
    return perUnitAmount(amount, p, qty);
  }
  if (p.type === 'percent') return perUnitAmount(base * ((parseFloat(p.amount) || 0) / 100), p, qty);
  if (p.type === 'characters') {
    const length = Array.from(String(value || '')).length;
    return perUnitAmount((parseFloat(p.amount) || 0) * length, p, qty);
  }
  if (p.type === 'quantity') return parseFloat(p.amount) || 0;
  if (p.type === 'value') {
    const numericValue = Number(value);
    return Number.isFinite(numericValue)
      ? perUnitAmount((parseFloat(p.amount) || 0) * numericValue, p, qty)
      : 0;
  }
  if (p.type === 'formula') return evalFormula(p.formula, base, qty, addons, val, fieldValues, '', {}, lookupTables, formulaVariables, fieldPrices) / Math.max(1, qty);
  return 0;
};

const formatPriceHint = (amount, pricingType) => {
	if ( ! Number.isFinite( amount ) || amount === 0 ) return '';
	const configured = window.OPF_PRICE_DISPLAY || ( window.opf_config || {} ).display_options || {};
	const decimals = Math.max( 0, Math.min( 8, Number.isInteger( configured.decimals ) ? configured.decimals : 2 ) );
	const [ rawInteger, rawFraction = '' ] = Math.abs( amount ).toFixed( decimals ).split( '.' );
	const integer = rawInteger.replace( /\B(?=(\d{3})+(?!\d))/g, configured.thousand || ',' );
	const fraction = rawFraction.replace( /0+$/, '' );
	const number = integer + ( fraction ? ( configured.decimal || '.' ) + fraction : '' );
	const symbol = configured.symbol || '$';
	const priceFormat = String( configured.price_format || 'symbolprice' );
	let money = priceFormat.replace( 'symbol', symbol ).replace( 'price', number );
	money = money.replace( /&nbsp;/gi, '\u00a0' );
	if ( amount < 0 ) money = '- ' + money;
	const settings = window.OPF_PRICE_HINTS || {};
	const keepPlus = pricingType === 'formula' || settings.plus !== false;
	const shown = amount > 0 && keepPlus ? '+ ' + money : money;
	return settings.brackets === false ? shown : '(' + shown + ')';
};

const updateChoicePriceHints = ( fieldEl, definitions, fid, def, values, base, qty, addons, lookupTables, formulaVariables, fieldPrices, isVisible = true ) => {
	const nodes = Array.from( fieldEl.querySelectorAll( '[data-opf-choice-hint]' ) );
	const settings = window.OPF_PRICE_HINTS || {};
	const enabled = settings.show !== false && isVisible;
	nodes.forEach( ( node ) => {
		const slug = node.dataset.opfChoiceHint || node.getAttribute( 'data-opf-choice-hint' ) || '';
		const choice = ( def.choices || [] ).find( ( item ) => item.slug === slug );
		let hint = '';
		if ( enabled && choice && ! choice.disabled ) {
			const candidateValue = def.image_quantities ? { [ slug ]: '1' } : slug;
			const candidateValues = { ...values, [ fid ]: candidateValue };
			const candidatePrices = resolveFieldPrices( definitions, candidateValues, base, qty, addons, lookupTables, formulaVariables );
			const candidateDef = { ...def, choices: [ choice ] };
			const amountPerUnit = choiceOrFieldAddon(
				candidateDef,
				candidateValue,
				base,
				qty,
				addons,
				slug,
				candidateValues,
				lookupTables,
				formulaVariables,
				candidatePrices
			);
			hint = formatPriceHint( amountPerUnit * qty, ( choice.pricing || {} ).type );
		}
		if ( String( node.tagName || '' ).toLowerCase() === 'option' ) {
			const baseLabel = node.dataset.opfBaseLabel || node.getAttribute( 'data-opf-base-label' ) || node.textContent;
			node.textContent = baseLabel + ( hint ? ' ' + hint : '' );
		} else {
			node.textContent = hint;
		}
	} );
	const fieldHint = fieldEl.querySelector ? fieldEl.querySelector( '[data-opf-field-hint]' ) : null;
	if ( fieldHint ) {
		let hint = '';
		if ( enabled ) {
			if ( def.type === 'calculation' && def.calculation_type === 'price' ) {
				const lineAmount = evalFormula( def.formula || '', base, qty, addons, '', values, '', {}, lookupTables, formulaVariables, fieldPrices );
				hint = formatPriceHint( lineAmount, 'formula' );
			} else if ( def.pricing && def.pricing.type !== 'none' ) {
				const currentValue = values[fid];
				const candidateValue = currentValue == null || currentValue === '' ? ( def.default || '1' ) : currentValue;
				const candidateValues = { ...values, [ fid ]: candidateValue };
				const candidatePrices = resolveFieldPrices( definitions, candidateValues, base, qty, addons, lookupTables, formulaVariables );
				const amountPerUnit = choiceOrFieldAddon( def, candidateValue, base, qty, addons, String( candidateValue ), candidateValues, lookupTables, formulaVariables, candidatePrices );
				hint = formatPriceHint( amountPerUnit * qty, def.pricing.type );
			}
		}
		fieldHint.textContent = hint;
	}
};

const resolveFieldPrices = (definitions, values, base, qty, startingAddons = 0, lookupTables = {}, formulaVariables = {}) => {
  const ids = Object.keys(definitions || {});
  const prices = {};
  const states = {};
  const stack = [];
  const cycles = new Set();
  const resolve = (rawId) => {
    const id = String(rawId).toLowerCase();
    if (Object.prototype.hasOwnProperty.call(prices, id)) return prices[id];
    const def = definitions && definitions[id];
    if (!def || !Object.prototype.hasOwnProperty.call(values || {}, id)) {
      prices[id] = 0;
      return 0;
    }
    if (states[id] === 'resolving') {
      const cycleStart = stack.indexOf(id);
      if (cycleStart >= 0) stack.slice(cycleStart).forEach((cycleId) => cycles.add(cycleId));
      return 0;
    }
    states[id] = 'resolving';
    stack.push(id);
    const value = values[id];
    const selected = value && typeof value === 'object' && !Array.isArray(value)
      ? Object.keys(value)
      : (Array.isArray(value) ? value.map(String) : [String(value ?? '')]);
    const formulas = [];
    if (['swatch', 'select', 'radio', 'checkbox'].includes(def.type)) {
      (def.choices || []).forEach((choice) => {
        if (selected.includes(String(choice.slug)) && !choice.disabled && choice.pricing?.type === 'formula') formulas.push(choice.pricing.formula || '');
      });
    } else if (def.pricing?.type === 'formula') {
      formulas.push(def.pricing.formula || '');
    }
    formulas.forEach((formula) => {
      for (const match of String(formula).matchAll(/\[price\.([a-z0-9_-]+)\]/gi)) resolve(match[1]);
    });
    const index = ids.indexOf(id);
    let addons = Number(startingAddons) || 0;
    for (let previous = 0; previous < index; previous++) {
      const previousId = String(ids[previous]).toLowerCase();
      if (states[previousId] !== 'resolving') addons += resolve(previousId);
    }
    const rawValue = values[id];
    const val = typeof rawValue === 'string' || typeof rawValue === 'number' ? String(rawValue) : '';
    const computed = choiceOrFieldAddon(def, rawValue, base, qty, addons, val, values, lookupTables, formulaVariables, prices);
    stack.pop();
    prices[id] = cycles.has(id) ? 0 : computed;
    states[id] = 'resolved';
    return prices[id];
  };
  ids.forEach(resolve);
  return prices;
};

const resolveCalculatedValues = (definitions, rawValues, base = 0, qty = 1, addons = 0, lookupTables = {}, formulaVariables = {}, fileCounts = {}, fieldPrices = {}) => {
  const defs = definitions || {};
  const raw = {};
  Object.keys(rawValues || {}).forEach((id) => { raw[String(id).toLowerCase()] = rawValues[id]; });
  const values = {};
  const states = {};
  const stack = [];
  const cycles = new Set();
  const resolve = (rawId) => {
    const id = String(rawId).toLowerCase();
    const def = defs[id];
    if (!def) return false;
    if (states[id] === 'resolved') return Object.prototype.hasOwnProperty.call(values, id);
    if (states[id] === 'resolving') {
      const cycleStart = stack.indexOf(id);
      if (cycleStart >= 0) stack.slice(cycleStart).forEach((cycleId) => cycles.add(cycleId));
      return false;
    }
    states[id] = 'resolving';
    stack.push(id);
    const dependencies = [];
    (def.conditionals || []).forEach((conditional) => (conditional.rules || []).forEach((rule) => {
      if (rule.field) dependencies.push(String(rule.field).toLowerCase());
    }));
    if (def.type === 'calculation') {
      String(def.formula || '').replace(/\[field\.([a-z0-9_-]+)\]|(?:checked|files|sumQty)\s*\(\s*([a-z0-9_-]+)\s*\)/gi, (_, fieldId, functionId) => {
        dependencies.push(String(fieldId || functionId).toLowerCase());
        return _;
      });
      String(def.formula || '').replace(/lookuptable\s*\(([^()]*)\)/gi, (_, rawArgs) => {
        rawArgs.split(';').slice(1).forEach((argument) => {
          const match = /^\s*(?:\[field\.([a-z0-9_-]+)\]|([a-z0-9_-]+))\s*$/i.exec(argument);
          if (match) dependencies.push(String(match[1] || match[2]).toLowerCase());
        });
        return _;
      });
    }
    let dependenciesAvailable = true;
    [...new Set(dependencies)].forEach((dependency) => {
      const available = resolve(dependency);
      if (!available && (cycles.has(dependency) || defs[dependency]?.type === 'calculation')) dependenciesAvailable = false;
    });
    stack.pop();
    if (cycles.has(id) || !dependenciesAvailable || !isVisible(def, values)) {
      states[id] = 'resolved';
      delete values[id];
      return false;
    }
    if (def.type === 'calculation') {
      const result = evalFormula(def.formula || '', base, qty, addons, '', values, window.OPF_TODAY || '', fileCounts, lookupTables, formulaVariables, fieldPrices);
      if (!Number.isFinite(result)) {
        states[id] = 'resolved';
        return false;
      }
      values[id] = result;
    } else if (Object.prototype.hasOwnProperty.call(raw, id)) {
      values[id] = raw[id];
    }
    states[id] = 'resolved';
    return Object.prototype.hasOwnProperty.call(values, id);
  };
  Object.keys(defs).forEach(resolve);
  return values;
};

const resolveFormulaVariables = (definitions, values) => {
  const resolved = {};
  const rulePasses = (rule) => {
    const value = values[rule.field];
    const actual = Array.isArray(value) ? value.map(String).join(', ') : (value == null ? '' : String(value));
    const expected = String(rule.value ?? '');
    switch (rule.operator) {
      case 'is': return Array.isArray(value) ? value.map(String).includes(expected) : actual === expected;
      case 'is_not': return Array.isArray(value) ? !value.map(String).includes(expected) : actual !== expected;
      case 'contains': return actual.toLowerCase().includes(expected.toLowerCase());
      case 'greater': return actual.trim() !== '' && expected.trim() !== '' && Number.isFinite(Number(actual)) && Number.isFinite(Number(expected)) && Number(actual) > Number(expected);
      case 'less': return actual.trim() !== '' && expected.trim() !== '' && Number.isFinite(Number(actual)) && Number.isFinite(Number(expected)) && Number(actual) < Number(expected);
      case 'empty': return actual.trim() === '';
      case 'not_empty': return actual.trim() !== '';
      default: return false;
    }
  };
  Object.keys(definitions || {}).forEach((name) => {
    const definition = definitions[name] || {};
    let value = Number.isFinite(Number(definition.default)) ? Number(definition.default) : 0;
    for (const change of (Array.isArray(definition.changes) ? definition.changes : [])) {
      const results = (Array.isArray(change.rules) ? change.rules : []).map(rulePasses);
      const matched = results.length > 0 && (change.logic === 'any' ? results.includes(true) : results.every(Boolean));
      if (matched && Number.isFinite(Number(change.value))) { value = Number(change.value); break; }
    }
    resolved[String(name).toLowerCase()] = value;
  });
  return resolved;
};

const resolveGroupFormulaVariables = (gid, values) => ({
  ...resolveFormulaVariables((window.OPF_FORMULA_VARIABLES || {})[gid] || {}, values),
  ...((window.OPF_ACF_VARIABLES || {})[gid] || {}),
});


const productImageAttributeNames = [ 'src', 'srcset', 'sizes', 'alt', 'data-large_image', 'data-large_image_width', 'data-large_image_height' ];
const productImageSnapshots = new WeakMap();

const resolveProductImageRule = ( rules, values, mode = 'rules', lastChangedField = null ) => {
	for ( let index = ( rules || [] ).length - 1; index >= 0; index-- ) {
		const rule = rules[ index ];
		if ( Array.isArray( rule.conditions ) && rule.conditions.length > 0 && rule.conditions.every( ( condition ) => {
			if ( condition.value === '*' ) return true;
			if ( 'last' === mode && condition.field !== lastChangedField ) return false;
			const current = values ? values[ condition.field ] : undefined;
			return Array.isArray( current )
				? current.map( String ).includes( String( condition.value ) )
				: current !== undefined && current !== null && String( current ) === String( condition.value );
		} ) ) return rule;
	}
	return null;
};

const lastImageFieldByGroup = new Map();

const initialImageField = ( groupEl, rules ) => {
	const fields = new Set();
	( rules || [] ).forEach( ( rule ) => ( rule.conditions || [] ).forEach( ( condition ) => {
		if ( condition.value !== '*' ) fields.add( String( condition.field ) );
	} ) );
	for ( const fieldEl of groupEl.querySelectorAll( '[data-opf-field]' ) ) {
		const fieldId = fieldEl.getAttribute( 'data-opf-field' );
		if ( fields.has( String( fieldId ) ) && ! fieldEl.closest( '.opf-hide, .opf-field--hidden' ) ) return String( fieldId );
	}
	return null;
};

const imageUrlMatches = ( image, target, baseUrl ) => {
	if ( ! image || ! target ) return false;
	const values = [ image.currentSrc, image.getAttribute( 'src' ), image.getAttribute( 'data-large_image' ) ];
	const link = typeof image.closest === 'function' ? image.closest( 'a' ) : null;
	if ( link ) values.push( link.getAttribute( 'href' ) );
	const normalize = ( value ) => {
		if ( ! value ) return '';
		try {
			const url = new URL( value, baseUrl || 'https://opf.invalid/' );
			return url.origin + url.pathname;
		} catch ( error ) { return String( value ); }
	};
	const expected = normalize( target );
	return !! expected && values.some( ( value ) => normalize( value ) === expected );
};

const updateProductImage = ( doc, evaluations, legacyValues = null ) => {
	if ( ! doc || typeof doc.querySelector !== 'function' ) return;
	// Keep the helper's earlier field-map signature for migrations and focused callers.
	if ( ! Array.isArray( evaluations ) ) evaluations = [ { definitions: evaluations || {}, values: legacyValues || {}, rules: [] } ];
	const gallery = doc.querySelector( '.woocommerce-product-gallery' );
	const slides = gallery && typeof gallery.querySelectorAll === 'function'
		? Array.from( gallery.querySelectorAll( '.woocommerce-product-gallery__image' ) ) : [];
	const baseUrl = doc.location && doc.location.href;
	let target = null;
	const hasRules = evaluations.some( ( item ) => ( item.rules || [] ).length );
	for ( const item of evaluations ) {
		const rule = resolveProductImageRule( item.rules, item.values, item.imageRuleMode, item.lastChangedField );
		if ( rule ) { target = { url: rule.target_url, alt: '' }; break; }
	}
	if ( ! hasRules && ! target ) {
		for ( const item of evaluations ) {
			Object.keys( item.definitions || {} ).some( ( fieldId ) => {
				const def = item.definitions[ fieldId ];
				if ( ! def || ! def.change_product_image ) return false;
				const raw = item.values ? item.values[ fieldId ] : '';
				const selected = Array.isArray( raw ) ? raw.map( String ) : ( raw === undefined || raw === null || raw === '' ? [] : [ String( raw ) ] );
				const choice = ( def.choices || [] ).find( ( candidate ) => selected.includes( String( candidate.slug ) ) && candidate.image );
				if ( choice ) target = { url: choice.image, alt: choice.label || '' };
				return !! choice;
			} );
			if ( target ) break;
		}
	}
	const activeSlide = gallery && typeof gallery.querySelector === 'function' ? gallery.querySelector( '.flex-active-slide' ) : null;
	const activeIndex = activeSlide ? slides.indexOf( activeSlide ) : 0;
	const currentImage = slides[ activeIndex ] && slides[ activeIndex ].querySelector( 'img' );
	const image = currentImage || doc.querySelector( '.woocommerce-product-gallery img.wp-post-image' ) || doc.querySelector( '.woocommerce-product-gallery img' );
	if ( ! image ) return;
	const stateKey = gallery || image;
	if ( ! productImageSnapshots.has( stateKey ) ) {
		const capture = ( img ) => {
			const link = typeof img.closest === 'function' ? img.closest( 'a' ) : null;
			const attrs = {};
			productImageAttributeNames.forEach( ( name ) => { attrs[ name ] = img.getAttribute( name ); } );
			return { image: img, attrs, href: link ? link.getAttribute( 'href' ) : null, link };
		};
		const originalImages = slides.map( ( slide ) => slide.querySelector( 'img' ) ).filter( Boolean );
		if ( ! originalImages.length ) originalImages.push( image );
		productImageSnapshots.set( stateKey, { gallery, slideIndex: activeIndex, images: originalImages.map( capture ) } );
	}
	const snapshot = productImageSnapshots.get( stateKey );
	const restoreImages = () => {
		snapshot.images.forEach( ( saved ) => {
			productImageAttributeNames.forEach( ( name ) => {
				if ( saved.attrs[ name ] === null ) saved.image.removeAttribute( name );
				else saved.image.setAttribute( name, saved.attrs[ name ] );
			} );
			if ( saved.link && saved.href !== null ) saved.link.setAttribute( 'href', saved.href );
			else if ( saved.link ) saved.link.removeAttribute( 'href' );
		} );
	};
	const navigate = ( index ) => {
		if ( ! gallery || index < 0 || typeof gallery.querySelectorAll !== 'function' ) return false;
		const view = doc.defaultView || ( typeof window !== 'undefined' ? window : null );
		const jq = view && view.jQuery;
		if ( jq ) {
			try {
				const control = jq( gallery );
				if ( control.data( 'flexslider' ) && typeof control.flexslider === 'function' ) { control.flexslider( index ); return true; }
			} catch ( error ) { /* theme supplied jQuery data may not be FlexSlider */ }
		}
		const thumbs = gallery.querySelectorAll( '.flex-control-nav a' );
		if ( thumbs[ index ] && typeof thumbs[ index ].click === 'function' ) { thumbs[ index ].click(); return true; }
		return false;
	};
	if ( ! target ) {
		restoreImages();
		navigate( snapshot.slideIndex );
		return;
	}
	const targetIndex = slides.findIndex( ( slide ) => imageUrlMatches( slide.querySelector( 'img' ), target.url, baseUrl ) );
	if ( targetIndex >= 0 ) {
		restoreImages();
		navigate( targetIndex );
		return;
	}
	restoreImages();
	const selectedIndex = activeSlide ? activeIndex : snapshot.slideIndex;
	const selectedImageState = snapshot.images[ selectedIndex ] || snapshot.images[ 0 ];
	const targetImage = selectedImageState.image;
	targetImage.setAttribute( 'src', target.url );
	targetImage.removeAttribute( 'srcset' );
	targetImage.removeAttribute( 'sizes' );
	targetImage.setAttribute( 'data-large_image', target.url );
	targetImage.removeAttribute( 'data-large_image_width' );
	targetImage.removeAttribute( 'data-large_image_height' );
	targetImage.setAttribute( 'alt', target.alt || '' );
	if ( selectedImageState.link ) selectedImageState.link.setAttribute( 'href', target.url );
};

let pricePreviewRequestId = 0;

const writeTotals = async () => {
  const totalsEl = document.querySelector('.opf-product-totals, .wapf-product-totals');
  const groupEls = Array.from(document.querySelectorAll('[data-opf-group]'));
  const baseNode = totalsEl || groupEls.find((groupEl) => groupEl.hasAttribute('data-opf-product-price'));
  if (!baseNode) return;
  const base = parseFloat(baseNode.getAttribute(totalsEl ? 'data-product-price' : 'data-opf-product-price'));
  if (!isFinite(base)) return;
  const qtyInput = document.querySelector('form.cart input[name="quantity"], form.cart .qty');
  const qty = Math.max(1, parseInt(qtyInput && qtyInput.value, 10) || 1);

  let optionsPerUnit = 0;
  const calculationDisplays = [];
  const productImageEvaluations = [];
	groupEls.forEach((groupEl) => {
    const gid = groupEl.getAttribute('data-opf-group');
    const rawValues = {};
    const fileCounts = {};
    const lookupTables = (window.OPF_LOOKUP_TABLES || {})[gid] || {};
    let formulaVariables = {};
    const fields = groupEl.querySelectorAll('[data-opf-field]');
    fields.forEach((fieldEl) => {
      const fid = fieldEl.getAttribute('data-opf-field');
      const def = (window.OPF_FIELDS || {})[gid]?.[fid];
      if (def?.type === 'upload') {
        const uploadInput = fieldEl.querySelector('input[type="file"]');
        fileCounts[fid.toLowerCase()] = (uploadInput && uploadInput.files ? uploadInput.files.length : 0) + fieldEl.querySelectorAll('[data-opf-upload-token]').length;
      }
      if (def?.type === 'swatch' && def.image_quantities) {
        const quantities = {};
        fieldEl.querySelectorAll('input[data-opf-quantity-choice]').forEach((input) => {
          const slug = input.getAttribute('data-opf-quantity-choice');
          quantities[slug] = input.value;
        });
        rawValues[fid] = quantities;
        return;
      }
      const checked = groupEl.querySelector(`[data-opf-field="${fid}"] input:checked`);
      const anyInput = fieldEl.querySelector('input:not([type=checkbox]):not([type=radio]):not([type=hidden]), textarea, select');
      if (checked && checked.type === 'checkbox') {
        rawValues[fid] = Array.from(
          groupEl.querySelectorAll(`[data-opf-field="${fid}"] input:checked`)
        ).map((c) => c.value);
      } else if (checked) {
        rawValues[fid] = checked.value;
      } else if (anyInput) {
        rawValues[fid] = anyInput.value;
      } else {
        rawValues[fid] = '';
      }
    });
    formulaVariables = resolveGroupFormulaVariables(gid, rawValues);
    const candidateFieldPrices = resolveFieldPrices((window.OPF_FIELDS || {})[gid] || {}, rawValues, base, qty, optionsPerUnit, lookupTables, formulaVariables);
    let values = resolveCalculatedValues(
      (window.OPF_FIELDS || {})[gid] || {}, rawValues, base, qty, optionsPerUnit,
      lookupTables, formulaVariables, fileCounts, candidateFieldPrices
    );
    fields.forEach((fieldEl) => {
      const fid = fieldEl.getAttribute('data-opf-field');
      const def = (window.OPF_FIELDS || {})[gid]?.[fid];
      if (def && !isVisible(def, values)) {
        delete values[fid];
        delete fileCounts[fid.toLowerCase()];
      }
    });
    formulaVariables = resolveGroupFormulaVariables(gid, values);
    const candidatePrices = resolveFieldPrices((window.OPF_FIELDS || {})[gid] || {}, values, base, qty, optionsPerUnit, lookupTables, formulaVariables);
    values = resolveCalculatedValues(
      (window.OPF_FIELDS || {})[gid] || {}, rawValues, base, qty, optionsPerUnit,
      lookupTables, formulaVariables, fileCounts, candidatePrices
    );
    const fieldPrices = resolveFieldPrices((window.OPF_FIELDS || {})[gid] || {}, values, base, qty, optionsPerUnit, lookupTables, formulaVariables);
	const imageRules = ( window.OPF_IMAGE_RULES || {} )[ gid ] || [];
	const imageRuleMode = ( window.OPF_IMAGE_RULE_MODES || {} )[ gid ] || 'rules';
	let lastChangedField = lastImageFieldByGroup.get( gid );
	if ( undefined === lastChangedField ) lastChangedField = initialImageField( groupEl, imageRules );
	productImageEvaluations.push({
		groupId: gid,
		definitions: ( window.OPF_FIELDS || {} )[ gid ] || {},
		values,
		rules: imageRules,
		imageRuleMode,
		lastChangedField,
    });
    fields.forEach((fieldEl) => {
      const fid = fieldEl.getAttribute('data-opf-field');
      const def = (window.OPF_FIELDS || {})[gid]?.[fid];
      if (!def) return;
      // conditional visibility: hidden fields contribute nothing
      const container = fieldEl;
      const hasHideClass = !! ( container.classList && typeof container.classList.contains === 'function' && container.classList.contains('opf-hide') );
      const isFieldVisible = ! hasHideClass && isVisible(def, values);
      updateChoicePriceHints(container, (window.OPF_FIELDS || {})[gid] || {}, fid, def, values, base, qty, optionsPerUnit, lookupTables, formulaVariables, fieldPrices, isFieldVisible);
      if (!isFieldVisible) return;
      if (def.type === 'calculation') {
        const output = container.querySelector('[data-opf-calculation]');
        if (output) calculationDisplays.push({ output, def, values, fileCounts, lookupTables, formulaVariables, fieldPrices });
        return;
      }
      const addon = choiceOrFieldAddon(def, values[fid], base, qty, optionsPerUnit, values[fid] && typeof values[fid] === 'string' ? values[fid] : '', values, lookupTables, formulaVariables, fieldPrices);
      optionsPerUnit += addon;
    });
  });

  updateProductImage(document, productImageEvaluations);

  calculationDisplays.forEach(({ output, def, values, fileCounts, lookupTables, formulaVariables, fieldPrices }) => {
    const result = evalFormula(def.formula || '', base, qty, optionsPerUnit, '', values, '', fileCounts, lookupTables, formulaVariables, fieldPrices);
    output.textContent = String(def.result_text || '{result}').replace(/\{result\}/g, String(result));
    if (def.calculation_type === 'price') optionsPerUnit += result / qty;
  });

  const productTotal = base * qty;
  const finalUnitPrice = Math.max(0, base + optionsPerUnit);
  const optionsTotal = (finalUnitPrice - base) * qty;
  const grand = finalUnitPrice * qty;
  let displayed = { product_total: productTotal, options_total: optionsTotal, grand_total: grand };
  const previewUrl = totalsEl?.getAttribute('data-opf-price-preview-url');
  if (previewUrl) {
    const requestId = ++pricePreviewRequestId;
    const productId = Number(totalsEl.getAttribute('data-opf-tax-product-id') || totalsEl.getAttribute('data-product-id'));
    const status = totalsEl.querySelector('.opf-price-preview-status');
    totalsEl.setAttribute('aria-busy', 'true');
    totalsEl.querySelectorAll('.opf-product-total, .opf-options-total, .opf-grand-total, .wapf-product-total, .wapf-options-total, .wapf-grand-total').forEach((node) => { node.textContent = ''; });
    if (status) {
      status.hidden = false;
      status.textContent = 'Updating price…';
    }
    try {
      const response = await fetch(previewUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ product_id: productId, options: optionsPerUnit, quantity: qty }),
      });
      if (!response.ok) throw new Error(`Price preview failed (${response.status}).`);
      const preview = await response.json();
      if (requestId !== pricePreviewRequestId) return;
      if (![preview.product_total, preview.options_total, preview.grand_total].every((value) => Number.isFinite(Number(value)))) {
        throw new Error('Price preview returned invalid totals.');
      }
      displayed = preview;
    } catch (error) {
      if (requestId !== pricePreviewRequestId) return;
      totalsEl.setAttribute('data-opf-price-preview-error', '1');
      totalsEl.removeAttribute('aria-busy');
      if (status) status.textContent = 'The price preview could not be updated. The final price will be calculated by WooCommerce.';
      return;
    }
    totalsEl.removeAttribute('data-opf-price-preview-error');
    totalsEl.removeAttribute('aria-busy');
    if (status) status.hidden = true;
  }
  const fmtEl = (el, amount) => {
    if (!el) return;
    el.innerHTML = fmtMoney(amount);
  };
  if (totalsEl) {
    fmtEl(totalsEl.querySelector('.opf-product-total, .wapf-product-total'), displayed.product_total);
    fmtEl(totalsEl.querySelector('.opf-options-total, .wapf-options-total'), displayed.options_total);
    fmtEl(totalsEl.querySelector('.opf-grand-total, .wapf-grand-total'), displayed.grand_total);
  }

  document.dispatchEvent(new CustomEvent('opf:pricing', {
    detail: {
      base: productTotal,
      options: optionsTotal,
      final: grand,
      quantity: qty,
      displayed: {
        base: displayed.product_total,
        options: displayed.options_total,
        final: displayed.grand_total,
      },
    },
  }));
};

const initTotals = () => {
  const container = document.querySelector('[data-opf-fields]');
  if (!container) return;
  let timer = null;
	const schedule = ( event ) => {
		if ( event && 'change' === event.type && event.target && typeof event.target.closest === 'function' ) {
			const field = event.target.closest( '[data-opf-field]' );
			const group = field && field.closest( '[data-opf-group]' );
			if ( field && group ) lastImageFieldByGroup.set( group.getAttribute( 'data-opf-group' ), field.getAttribute( 'data-opf-field' ) );
		}
		clearTimeout(timer);
    timer = setTimeout(writeTotals, 50);
  };
  container.addEventListener('input', schedule);
  container.addEventListener('change', schedule);
  document.querySelectorAll('form.cart input[name="quantity"], form.cart .qty').forEach((input) => {
    input.addEventListener('input', schedule);
    input.addEventListener('change', schedule);
  });
  writeTotals();
};

const initVariationFields = () => {
	const fieldsRoot = document.querySelector( '.opf-fields[data-opf-variation-url]' );
	const form = document.querySelector( 'form.variations_form' );
	if ( ! fieldsRoot || ! form || ! window.jQuery ) return;
	const wrapper = fieldsRoot.querySelector( '.opf-wrapper' );
	if ( ! wrapper ) return;
	let requestId = 0;
	const addButton = form.querySelector( '.single_add_to_cart_button' );
	let activeVariation = null;
	const syncButtonAvailability = () => {
		if ( ! addButton ) return;
		const unavailable = activeVariation
			? ! activeVariation.is_purchasable || ! activeVariation.is_in_stock || ! activeVariation.variation_is_visible
			: addButton.classList.contains( 'disabled' ) || addButton.classList.contains( 'wc-variation-is-unavailable' ) || addButton.classList.contains( 'wc-variation-selection-needed' );
		const disabled = '1' === form.dataset.opfVariationLoading || unavailable;
		addButton.disabled = disabled;
		addButton.setAttribute( 'aria-disabled', disabled ? 'true' : 'false' );
	};
	const setLoading = ( loading ) => {
		form.dataset.opfVariationLoading = loading ? '1' : '0';
		syncButtonAvailability();
	};
	const refreshGroupIds = () => {
		const input = fieldsRoot.querySelector( 'input[name="opf_field_groups"]' );
		if ( input ) input.value = Array.from( wrapper.querySelectorAll( '[data-opf-group]' ) ).map( ( group ) => group.getAttribute( 'data-opf-group' ) ).join( ',' );
	};
	const replaceVariationGroups = ( html, registry ) => {
		wrapper.querySelectorAll( '.opf-variation-error' ).forEach( ( notice ) => notice.remove() );
		wrapper.querySelectorAll( '[data-opf-variation-group]' ).forEach( ( group ) => group.remove() );
		const fragment = document.createElement( 'template' );
		fragment.innerHTML = html || '';
		const incoming = Array.from( fragment.content.querySelectorAll( '[data-opf-group]' ) );
		incoming.forEach( ( group ) => group.setAttribute( 'data-opf-variation-group', '1' ) );
		wrapper.appendChild( fragment.content );
		Object.keys( registry?.fields || {} ).forEach( ( gid ) => { window.OPF_FIELDS[ gid ] = registry.fields[ gid ]; } );
		[ 'lookup_tables', 'image_rules', 'formula_variables', 'acf_variables' ].forEach( ( key ) => {
			const globalKey = { lookup_tables: 'OPF_LOOKUP_TABLES', image_rules: 'OPF_IMAGE_RULES', formula_variables: 'OPF_FORMULA_VARIABLES', acf_variables: 'OPF_ACF_VARIABLES' }[ key ];
			Object.keys( registry?.[ key ] || {} ).forEach( ( gid ) => { window[ globalKey ][ gid ] = registry[ key ][ gid ]; } );
		} );
		incoming.forEach( ( group ) => {
			init( group );
			initUploaders( group );
		} );
		refreshGroupIds();
	};
	const updateBasePrice = ( value, taxProductId = 0 ) => {
		const price = Number( value );
		if ( ! Number.isFinite( price ) ) return;
		const totals = document.querySelector( '.opf-product-totals, .wapf-product-totals' );
		if ( totals ) {
			totals.setAttribute( 'data-product-price', String( price ) );
			if ( taxProductId ) totals.setAttribute( 'data-opf-tax-product-id', String( taxProductId ) );
		}
		fieldsRoot.querySelectorAll( '[data-opf-group]' ).forEach( ( group ) => group.setAttribute( 'data-opf-product-price', String( price ) ) );
		writeTotals();
	};
	window.jQuery( form ).on( 'found_variation.opf', async ( event, variation ) => {
		const id = Number( variation?.variation_id || 0 );
		const currentRequest = ++requestId;
		activeVariation = variation || null;
		setLoading( true );
		replaceVariationGroups( '', {} );
		if ( ! id ) { setLoading( false ); return; }
		const url = new URL( fieldsRoot.dataset.opfVariationUrl, window.location.href );
		url.searchParams.set( 'variation_id', String( id ) );
		try {
			const response = await fetch( url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } } );
			if ( ! response.ok ) throw new Error( `Variation fields request failed (${ response.status }).` );
			const payload = await response.json();
			if ( currentRequest !== requestId ) return;
			replaceVariationGroups( payload.html || '', payload.registry || {} );
			updateBasePrice( payload.base_price, id );
			setLoading( false );
		} catch ( error ) {
			if ( currentRequest === requestId ) {
				const notice = document.createElement( 'div' );
				notice.className = 'opf-variation-error';
				notice.setAttribute( 'role', 'alert' );
				notice.textContent = 'Product options could not be loaded. Please choose the variation again.';
				wrapper.appendChild( notice );
				setLoading( true );
			}
		}
	} );
	const resetVariation = () => {
		requestId += 1;
		activeVariation = null;
		setLoading( false );
		replaceVariationGroups( '', {} );
		updateBasePrice( fieldsRoot.dataset.opfBasePrice, Number( fieldsRoot.dataset.opfProductId ) );
	};
	window.jQuery( form ).on( 'reset_data.opf hide_variation.opf', resetVariation );
	const singleVariation = form.querySelector( '.single_variation' );
	if ( singleVariation ) window.jQuery( singleVariation ).on( 'show_variation.opf', syncButtonAvailability );
	form.addEventListener( 'submit', ( event ) => {
		if ( '1' === form.dataset.opfVariationLoading ) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}, true );
};

const livePreviewFieldValue = ( groupId, fieldId ) => {
	const group = document.querySelector( `.opf-field-group[data-opf-group="${ groupId }"]` );
	const field = group?.querySelector( `[data-opf-field="${ fieldId }"]` );
	if ( ! field || field.closest( '.opf-hide, .opf-field--hidden' ) ) return '';
	const controls = Array.from( field.querySelectorAll( 'input, textarea, select' ) );
	const selected = controls.filter( ( control ) => ( 'checkbox' === control.type || 'radio' === control.type ) && control.checked );
	if ( selected.length ) return selected.map( ( control ) => control.value ).join( ', ' );
	if ( ! controls.length || 'checkbox' === controls[ 0 ].type || 'radio' === controls[ 0 ].type ) return '';
	return String( controls[ 0 ].value || '' );
};

const livePreviewSlide = ( item ) => {
	const gallery = document.querySelector( '.woocommerce-product-gallery__wrapper, .woocommerce-product-gallery' );
	if ( ! gallery ) return null;
	const slides = Array.from( gallery.querySelectorAll( '.woocommerce-product-gallery__image, .woocommerce-product-gallery__image--placeholder' ) );
	const index = 'main' === item.target ? 0 : Number( item.target_index );
	return Number.isInteger( index ) && index >= 0 ? slides[ index ] || null : null;
};

const initLivePreviews = () => {
	const previews = Array.isArray( window.OPF_LIVE_PREVIEWS ) ? window.OPF_LIVE_PREVIEWS : [];
	const nodes = [];
	previews.forEach( ( item ) => {
		if ( ! [ 'text', 'upload' ].includes( item.source ) ) return;
		const slide = livePreviewSlide( item );
		if ( ! slide ) return;
		slide.classList.add( 'opf-preview-target' );
		let layer = slide.querySelector( `[data-opf-live-preview="${ item.id }"]` );
		if ( ! layer ) {
			layer = document.createElement( 'div' );
			layer.className = 'opf-live-preview-layer';
			layer.dataset.opfLivePreview = item.id;
			layer.setAttribute( 'aria-hidden', 'true' );
			if ( 'text' === item.source ) {
				const text = document.createElement( 'span' );
				text.className = 'opf-live-preview-text';
				layer.appendChild( text );
			} else {
				const image = document.createElement( 'img' );
				image.className = 'opf-live-preview-image';
				image.alt = '';
				image.style.width = '100%';
				image.style.height = '100%';
				image.style.maxWidth = 'none';
				layer.appendChild( image );
			}
			slide.appendChild( layer );
		}
		layer.style.left = `${ item.box.x }%`;
		layer.style.top = `${ item.box.y }%`;
		layer.style.width = `${ item.box.width }%`;
		layer.style.height = `${ item.box.height }%`;
		nodes.push( { item, layer, text: layer.querySelector( '.opf-live-preview-text' ), image: layer.querySelector( '.opf-live-preview-image' ) } );
	} );
	if ( ! nodes.length ) return;
	const update = () => nodes.forEach( ( { item, layer, text, image } ) => {
		if ( 'upload' === item.source ) {
			if ( ! image ) return;
			const key = `${ item.group_id }:${ item.field_id }`;
			const urls = window.OPF_UPLOAD_PREVIEW_URLS?.[ key ];
			const url = Array.isArray( urls ) ? urls[ 0 ] || '' : '';
			layer.hidden = '' === url;
			if ( url && image.src !== url ) image.src = url;
			image.style.objectFit = 'contain' === item.image?.fit ? 'contain' : 'fill';
			image.style.borderRadius = 'oval' === item.image?.shape ? '50%' : '0';
			return;
		}
		if ( ! text ) return;
		const value = livePreviewFieldValue( item.group_id, item.field_id ).slice( 0, 500 );
		text.textContent = value;
		layer.hidden = '' === value;
		const style = item.text || {};
		text.style.color = style.color || '#000000';
		text.style.fontFamily = style.font_family || 'Arial, sans-serif';
		text.style.fontSize = `${ Number( style.font_size ) || 24 }px`;
		text.style.setProperty( '--opf-preview-mobile-size', `${ Number( style.mobile_font_size ) || 18 }px` );
		text.style.fontWeight = String( style.font_weight || 'normal' );
		text.style.fontStyle = 'italic' === style.font_style ? 'italic' : 'normal';
		text.style.textAlign = style.alignment || 'center';
		layer.style.justifyContent = { left: 'flex-start', center: 'center', right: 'flex-end' }[ style.alignment ] || 'center';
		layer.style.setProperty( '--opf-preview-mobile-alignment', { left: 'flex-start', center: 'center', right: 'flex-end' }[ style.mobile_alignment ] || 'center' );
		Object.entries( item.dynamic || {} ).forEach( ( [ property, config ] ) => {
			const selected = livePreviewFieldValue( item.group_id, config.field_id );
			const dynamicValue = config.values?.[ selected ] ?? config.default;
			if ( dynamicValue === null || dynamicValue === undefined ) return;
			if ( 'color' === property ) text.style.color = dynamicValue;
			if ( 'font_family' === property ) text.style.fontFamily = dynamicValue;
			if ( 'font_size' === property ) text.style.fontSize = `${ Number( dynamicValue ) }px`;
			if ( 'alignment' === property ) {
				text.style.textAlign = dynamicValue;
				layer.style.justifyContent = { left: 'flex-start', center: 'center', right: 'flex-end' }[ dynamicValue ] || 'center';
			}
		} );
	} );
	if ( ! window.OPF_LIVE_PREVIEWS_BOUND ) {
		window.OPF_LIVE_PREVIEWS_BOUND = true;
		document.addEventListener( 'input', update );
		document.addEventListener( 'change', update );
		document.addEventListener( 'opf:upload-preview-change', update );
		const observer = new MutationObserver( update );
		observer.observe( document.querySelector( '.opf-fields' ) || document.body, { childList: true, subtree: true } );
	}
	update();
};

const initLayeredImages = () => {
	const configs = Array.isArray( window.OPF_LAYERED_IMAGES ) ? window.OPF_LAYERED_IMAGES : [];
	if ( ! configs.length || window.OPF_LAYERED_IMAGES_BOUND ) return;
	window.OPF_LAYERED_IMAGES_BOUND = true;
	const instances = [];
	configs.forEach( ( config, configIndex ) => {
		if ( ! config.enabled || ! config.base_url || ! Array.isArray( config.layers ) || ! config.layers.length ) return;
		const gallery = document.querySelector( '.woocommerce-product-gallery__wrapper, .woocommerce-product-gallery' );
		const slides = gallery ? Array.from( gallery.querySelectorAll( '.woocommerce-product-gallery__image, .woocommerce-product-gallery__image--placeholder' ) ) : [];
		const slide = Number.isInteger( Number( config.target_index ) ) ? slides[ Number( config.target_index ) ] : null;
		if ( ! slide ) return;
		let stack = slide.querySelector( `[data-opf-layered-image="${ configIndex }"]` );
		if ( ! stack ) {
			stack = document.createElement( 'div' );
			stack.className = 'opf-layered-image-stack';
			stack.dataset.opfLayeredImage = String( configIndex );
			stack.setAttribute( 'aria-hidden', 'true' );
			const base = document.createElement( 'img' );
			base.className = 'opf-layered-image-stack__base';
			base.alt = '';
			base.src = config.base_url;
			stack.appendChild( base );
			const layerNodes = config.layers.map( ( layer ) => {
				const image = document.createElement( 'img' );
				image.className = 'opf-layered-image-stack__layer';
				image.alt = '';
				image.src = layer.url;
				image.hidden = true;
				stack.appendChild( image );
				return { config: layer, image };
			} );
			slide.appendChild( stack );
			instances.push( { config, slide, stack, layerNodes, didAutoScroll: false, wasActive: false } );
		} else {
			const layerNodes = Array.from( stack.querySelectorAll( '.opf-layered-image-stack__layer' ) ).map( ( image, index ) => ( { config: config.layers[ index ], image } ) );
			instances.push( { config, slide, stack, layerNodes, didAutoScroll: false, wasActive: false } );
		}
	} );
	if ( ! instances.length ) return;
	const update = () => instances.forEach( ( instance ) => {
		let hasChoice = false;
		instance.layerNodes.forEach( ( { config, image } ) => {
			const selected = livePreviewFieldValues( config.group_id, config.field_id );
			const active = selected.includes( String( config.choice ) );
			image.hidden = ! active;
			hasChoice = hasChoice || active;
		} );
		const active = hasChoice || ! instance.config.delay;
		instance.stack.hidden = ! active;
		instance.slide.classList.toggle( 'opf-layered-image-active', active );
		if ( active && ! instance.wasActive && hasChoice && instance.config.auto_scroll && ! instance.didAutoScroll ) {
			instance.slide.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			instance.didAutoScroll = true;
		}
		instance.wasActive = active;
	} );
	document.addEventListener( 'input', update );
	document.addEventListener( 'change', update );
	new MutationObserver( update ).observe( document.querySelector( '.opf-fields' ) || document.body, { childList: true, subtree: true } );
	update();
};

const livePreviewFieldValues = ( groupId, fieldId ) => {
	const group = document.querySelector( `.opf-field-group[data-opf-group="${ groupId }"]` );
	const field = group?.querySelector( `[data-opf-field="${ fieldId }"]` );
	if ( ! field || field.closest( '.opf-hide, .opf-field--hidden' ) ) return [];
	const controls = Array.from( field.querySelectorAll( 'input, select' ) );
	const checked = controls.filter( ( control ) => ( 'checkbox' === control.type || 'radio' === control.type ) && control.checked ).map( ( control ) => String( control.value ) );
	if ( checked.length ) return checked;
	const select = controls.find( ( control ) => 'select-one' === control.type || 'select-multiple' === control.type );
	if ( select ) return Array.from( select.selectedOptions ).map( ( option ) => String( option.value ) ).filter( Boolean );
	return [];
};

const initAll = () => {
	init();
	initUploaders();
	initTotals();
	initVariationFields();
	initLivePreviews();
	initLayeredImages();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAll);
} else {
  initAll();
}
