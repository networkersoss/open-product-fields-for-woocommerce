( () => {
	const form = document.querySelector( '#mainform' );
	const fontFile = document.querySelector( '#opf-preview-font-file' );
	if ( form && fontFile && form.contains( fontFile ) ) {
		form.enctype = 'multipart/form-data';
	}
} )();
