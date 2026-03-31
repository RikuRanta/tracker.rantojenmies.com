function ShowMe(Id) {
	var e = document.getElementById(Id);
	if(e.style.display == 'none')
	  $('#'+Id).show();
	else
	  $('#'+Id).hide();
};

function Show(Id) {
	var e = document.getElementById(Id);
	$('#'+Id).show();
};

function Hide(Id) {
	var e = document.getElementById(Id);
	$('#'+Id).hide();
};