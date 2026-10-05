(() => {
	const initialize = () => {
		const base = document.querySelector('#_opf_layered_base');
		const choices = Array.from(document.querySelectorAll('.opf-layered-choice-image'));
		if (!base || !choices.length) return;
		const transparencyCache = new Map();
		const filterChoices = () => {
			const selected = base.selectedOptions[0];
			const width = Number(selected?.dataset.width || 0);
			const height = Number(selected?.dataset.height || 0);
			choices.forEach((select) => {
				Array.from(select.options).forEach((option) => {
					if (!option.value) {
						option.disabled = false;
						return;
					}
					const valid = !!width && !!height && '1' === option.dataset.alpha && !option.dataset.layerInvalid && Number(option.dataset.width) === width && Number(option.dataset.height) === height;
					option.disabled = !valid;
					if (!valid && option.selected) select.value = '';
				});
			});
		};
		const hasTransparentPixels = async (attachment) => {
			if (attachment.mime !== 'image/png' || !attachment.url || !window.createImageBitmap) return false;
			if (transparencyCache.has(attachment.url)) return transparencyCache.get(attachment.url);
			try {
				const response = await fetch(attachment.url, { credentials: 'same-origin' });
				if (!response.ok) return false;
				const bitmap = await createImageBitmap(await response.blob());
				if (bitmap.width * bitmap.height > 16000000) {
					bitmap.close();
					return false;
				}
				const canvas = document.createElement('canvas');
				canvas.width = bitmap.width;
				canvas.height = bitmap.height;
				const context = canvas.getContext('2d', { willReadFrequently: true });
				context.drawImage(bitmap, 0, 0);
				bitmap.close();
				const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
				for (let index = 3; index < pixels.length; index += 4) {
					if (pixels[index] < 255) {
						transparencyCache.set(attachment.url, true);
						return true;
					}
				}
			} catch (error) {
				transparencyCache.set(attachment.url, false);
				return false;
			}
			transparencyCache.set(attachment.url, false);
			return false;
		};
		const showFeedback = (select, message) => {
			const button = select.parentElement.querySelector('[data-opf-layered-upload]');
			if (!button) return;
			const feedback = button.parentElement.querySelector('[data-opf-layered-upload-feedback]') || document.createElement('span');
			feedback.dataset.opfLayeredUploadFeedback = '1';
			feedback.setAttribute('role', 'status');
			feedback.textContent = message;
			if (!feedback.isConnected) button.insertAdjacentElement('afterend', feedback);
		};
		const validateSelectedChoice = async (select) => {
			const option = select.selectedOptions[0];
			if (!option?.value) return;
			showFeedback(select, '');
			const imageId = option.value;
			const alpha = await hasTransparentPixels( { mime: 'image/png', url: option.dataset.url } );
			if ( select.value !== imageId ) return;
			option.dataset.alpha = alpha ? '1' : '0';
			filterChoices();
			const baseOption = base.selectedOptions[0];
			const matchesBase = !!baseOption && Number(option.dataset.width) === Number(baseOption.dataset.width) && Number(option.dataset.height) === Number(baseOption.dataset.height);
			if (!alpha || !matchesBase) {
				select.value = '';
				showFeedback(select, 'Choose a PNG with transparent pixels and dimensions matching the base image.');
			} else {
				showFeedback(select, '');
			}
		};
		base.addEventListener('change', filterChoices);
		choices.forEach((select) => {
			select.addEventListener('change', () => { validateSelectedChoice(select); });
			if (select.value) validateSelectedChoice(select);
		});
		document.querySelectorAll('[data-opf-layered-upload]').forEach((button) => {
			button.addEventListener('click', () => {
				const select = document.getElementById(button.dataset.opfLayeredUpload);
				if (!select || !window.wp?.media) return;
				const frame = window.wp.media({
					title: 'Choose or upload a product image',
					button: { text: 'Use image' },
					multiple: false,
					library: { type: 'image' },
				});
				frame.on('select', async () => {
					const attachment = frame.state().get('selection').first()?.toJSON();
					if (!attachment?.id) return;
					let option = Array.from(select.options).find((candidate) => candidate.value === String(attachment.id));
					if (!option) {
						option = new Option(attachment.title || `Image ${attachment.id}`, String(attachment.id));
						select.add(option);
					}
					option.dataset.width = String(attachment.width || 0);
					option.dataset.height = String(attachment.height || 0);
					option.dataset.url = attachment.url || '';
					option.dataset.alpha = await hasTransparentPixels(attachment) ? '1' : '0';
					option.removeAttribute('data-layer-invalid');
					select.value = String(attachment.id);
					select.dispatchEvent(new Event('change', { bubbles: true }));
					const baseOption = base.selectedOptions[0];
					const matchesBase = !!baseOption && Number(option.dataset.width) === Number(baseOption.dataset.width) && Number(option.dataset.height) === Number(baseOption.dataset.height);
					if (select.classList.contains('opf-layered-choice-image') && ( '1' !== option.dataset.alpha || !matchesBase )) {
						select.value = '';
						showFeedback(select, 'Choose a PNG with transparent pixels and dimensions matching the base image.');
					} else {
						showFeedback(select, '');
					}
				});
				frame.open();
			});
		});
		filterChoices();
	};
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
	else initialize();
})();
