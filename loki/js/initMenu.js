	
	
	/* Generoidaan menu (vasen reuna)*/
	var slideLeft = new Menu({
	  wrapper: '#o-wrapper',
	  type: 'slide-left',
	  menuOpenerClass: '.c-button',
	  maskId: '#c-mask'
	});
	
	var slideLeftBtn = document.querySelector('#c-button--slide-left');
	$("#c-button--slide-left").css( "z-index", 11 );
	$("#c-loading").css( "z-index", 199 );
	
	slideLeftBtn.addEventListener('click', function(e) {
	  e.preventDefault;
	  slideLeft.open();
	}); 

	
	/* Generoidaan menu (LIVE!-sijainti)*/
	var slideBottom = new Menu({
	  wrapper: '#o-wrapper',
	  type: 'slide-bottom',
	  menuOpenerClass: '.c-button',
	  maskId: '#c-mask'
	});
	
	/* Valikon muutokset */
	$('#actions-menu').click(function(event){
		
		event.stopPropagation();
		var liClass = event.target.id;
		var title = event.target.title;
		var callback = event.target.getAttribute('data-callback');		
		var disabled = event.target.getAttribute('data-disabled');
		
		if (liClass == 'rantojenmies') {
			/* 
			  var win = window.open('https://www.rantojenmies.com', '_blank'); 
			  win.focus();
			*/
			location.replace('https://www.rantojenmies.com');
			return false;
		}
		
		/* Päivitetään menun sisältö */
		if (disabled !== 'true') updateMenu(liClass, title, callback);
		
	});
	
	
	/* Päivitetään menun sisältö */
	function updateMenu(id, title, callback) {
		
		/* Suoritetaan annettu funktio (jos on ajettavaa) */
		if (callback !== null) window[callback]();	
		/* Menun otsikko */
		$("#menu-header").html(title);
		/* Piilotetaan ylimääriset valikosta */
		$('.c-menu__item').not('.actions-menu').hide();
		/* Näytetään annetun luokan jäsenet */
		$('.'+id).show();		

		/* Piilotetaan lisätietokentät ryhmiltä */
		let group = $('#place').find(":selected").data('group');
		if (id == 'places' && group == 'Summary') { $('.desc, .visited, .visited-paths').hide(); }

	}
	
	function timer(sec) {
		
		intTimer = setInterval(function () {
			$("#position-next-refresh").html(' (aikaa päivitykseen ' + --sec + ' sekuntia...)');
		}, 1000);				
		
	}

	
	