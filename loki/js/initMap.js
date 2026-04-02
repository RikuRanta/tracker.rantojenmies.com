	
	var refreshIntervalId;
	var intTimer;
	var $paths = $("#path");
	var $places = $("#place");
	var $events = $("#events");
	var statusIntervalId;
	var advanceToNextPathAfterEngineHourSave = false;
	var trackPointEditModeEnabled = false;
	var trackEditOriginalPoints = [];
	var trackEditDraftPoints = [];
	var trackEditDraftDirty = false;
	var trackEditTempPointId = -1;

	function extractApiErrorMessage(err, fallbackText) {
		fallbackText = fallbackText || 'Toiminto epäonnistui.';
		if (!err) return fallbackText;

		if (err.responseText) {
			try {
				var parsed = JSON.parse(err.responseText);
				if (parsed && parsed.error) return parsed.error;
			} catch (e) {
				if (String(err.responseText).trim() !== '') return String(err.responseText);
			}
		}

		if (err.message) return err.message;
		return fallbackText;
	}

	function apiRequest(url, method, headersObj, payload) {
		return new Promise(function(resolve, reject) {
			corsRequest(
				url,
				method,
				headersObj,
				payload,
				function(respText) {
					try {
						resolve(respText ? JSON.parse(respText) : {});
					} catch (e) {
						reject(e);
					}
				},
				function(err) { reject(err); }
			);
		});
	}

	function getTrackEditDraftStorageKey(pathId) {
		return 'track-edit-draft-v1:' + String(pathId || 'none');
	}

	function cloneEditPoint(point) {
		return {
			Id: Number(point.Id),
			Source: String(point.Source || 'archive'),
			Timestamp: point.Timestamp || null,
			Lat: Number(point.Lat),
			Lon: Number(point.Lon),
			PathInfo: point.PathInfo || null,
			IsNew: !!point.IsNew
		};
	}

	function setTrackEditButtonsVisible(visible) {
		if (visible) {
			$("#track-edit-save").show();
			$("#track-edit-cancel").show();
		} else {
			$("#track-edit-save").hide();
			$("#track-edit-cancel").hide();
		}
	}

	function updateTrackEditButtonsState() {
		$("#track-edit-save").prop('disabled', !trackEditDraftDirty);
	}

	function persistTrackEditDraft(pathId) {
		if (!pathId) return;
		try {
			localStorage.setItem(getTrackEditDraftStorageKey(pathId), JSON.stringify(trackEditDraftPoints));
		} catch (e) {}
	}

	function clearTrackEditDraft(pathId) {
		if (!pathId) return;
		try {
			localStorage.removeItem(getTrackEditDraftStorageKey(pathId));
		} catch (e) {}
	}

	function applyDraftToOverlay() {
		if (window.navionics && typeof window.navionics.setTrackEditPoints === 'function') {
			window.navionics.setTrackEditPoints(trackEditDraftPoints);
		}
	}

	function setTrackEditDirty(pathId, dirty) {
		trackEditDraftDirty = !!dirty;
		persistTrackEditDraft(pathId);
		applyDraftToOverlay();
		updateTrackEditButtonsState();
	}

	function findNearestDraftManualPoint(lon, lat) {
		var nearest = null;
		var nearestDist = Number.POSITIVE_INFINITY;
		for (var i = 0; i < trackEditDraftPoints.length; i++) {
			var p = trackEditDraftPoints[i];
			if (!p || String(p.Source) !== 'manual') continue;
			var d = getDistanceSquared(lat, lon, p.Lat, p.Lon);
			if (d < nearestDist) {
				nearestDist = d;
				nearest = p;
			}
		}
		return nearest;
	}

	function pointToSegmentDistanceSquared(px, py, x1, y1, x2, y2) {
		var dx = x2 - x1;
		var dy = y2 - y1;
		if (dx === 0 && dy === 0) {
			var ddx = px - x1;
			var ddy = py - y1;
			return ddx * ddx + ddy * ddy;
		}

		var t = ((px - x1) * dx + (py - y1) * dy) / (dx * dx + dy * dy);
		if (t < 0) t = 0;
		else if (t > 1) t = 1;

		var projX = x1 + t * dx;
		var projY = y1 + t * dy;
		var distX = px - projX;
		var distY = py - projY;
		return distX * distX + distY * distY;
	}

	function getInsertIndexForPointList(points, lon, lat) {
		if (!Array.isArray(points) || points.length < 2) {
			return Array.isArray(points) ? points.length : 0;
		}

		var bestSegmentIndex = -1;
		var bestDistance = Number.POSITIVE_INFINITY;

		for (var i = 0; i < points.length - 1; i++) {
			var a = points[i];
			var b = points[i + 1];
			if (!a || !b) continue;
			if (!isFinite(Number(a.Lon)) || !isFinite(Number(a.Lat)) || !isFinite(Number(b.Lon)) || !isFinite(Number(b.Lat))) continue;

			var d = pointToSegmentDistanceSquared(
				Number(lon), Number(lat),
				Number(a.Lon), Number(a.Lat),
				Number(b.Lon), Number(b.Lat)
			);
			if (d < bestDistance) {
				bestDistance = d;
				bestSegmentIndex = i;
			}
		}

		if (bestSegmentIndex === -1) {
			return points.length;
		}

		return bestSegmentIndex + 1;
	}

	function getInsertIndexForDraftPoint(lon, lat) {
		return getInsertIndexForPointList(trackEditDraftPoints, lon, lat);
	}

	function getTimestampForManualDraftPoint(index) {
		var previousTimestamp = null;
		var nextTimestamp = null;

		for (var i = index - 1; i >= 0; i--) {
			var previousPoint = trackEditDraftPoints[i];
			if (!previousPoint || !previousPoint.Timestamp) continue;
			if (String(previousPoint.Source) === 'manual') continue;
			previousTimestamp = previousPoint.Timestamp;
			break;
		}

		for (var j = index + 1; j < trackEditDraftPoints.length; j++) {
			var nextPoint = trackEditDraftPoints[j];
			if (!nextPoint || !nextPoint.Timestamp) continue;
			if (String(nextPoint.Source) === 'manual') continue;
			nextTimestamp = nextPoint.Timestamp;
			break;
		}

		return previousTimestamp || nextTimestamp || null;
	}

	function getDraftManualSavePoints() {
		var manualPoints = [];
		for (var i = 0; i < trackEditDraftPoints.length; i++) {
			var point = trackEditDraftPoints[i];
			if (!point || String(point.Source) !== 'manual') continue;

			manualPoints.push({
				Id: Number(point.Id),
				Lat: Number(point.Lat),
				Lon: Number(point.Lon),
				Timestamp: getTimestampForManualDraftPoint(i) || point.Timestamp || null
			});
		}
		return manualPoints;
	}

	function draftManualPointsChanged() {
		var originalManualPoints = [];
		var draftManualPoints = [];

		for (var i = 0; i < trackEditOriginalPoints.length; i++) {
			if (trackEditOriginalPoints[i] && String(trackEditOriginalPoints[i].Source) === 'manual') {
				originalManualPoints.push(trackEditOriginalPoints[i]);
			}
		}

		for (var j = 0; j < trackEditDraftPoints.length; j++) {
			if (trackEditDraftPoints[j] && String(trackEditDraftPoints[j].Source) === 'manual') {
				draftManualPoints.push(trackEditDraftPoints[j]);
			}
		}

		if (originalManualPoints.length !== draftManualPoints.length) {
			return true;
		}

		for (var k = 0; k < draftManualPoints.length; k++) {
			var originalPoint = originalManualPoints[k];
			var draftPoint = draftManualPoints[k];
			if (!originalPoint || !draftPoint) return true;
			if (!!draftPoint.IsNew) return true;
			if (Number(originalPoint.Id) !== Number(draftPoint.Id)) return true;
			if (Number(originalPoint.Lat) !== Number(draftPoint.Lat)) return true;
			if (Number(originalPoint.Lon) !== Number(draftPoint.Lon)) return true;
		}

		return false;
	}

	function loadTrackEditSessionForPath(pathId) {
		if (!trackPointEditModeEnabled || !pathId) {
			trackEditOriginalPoints = [];
			trackEditDraftPoints = [];
			trackEditDraftDirty = false;
			applyDraftToOverlay();
			updateTrackEditButtonsState();
			return;
		}

		requestEditablePoints(pathId, function(serverPoints) {
			trackEditOriginalPoints = Array.isArray(serverPoints) ? serverPoints.map(cloneEditPoint) : [];

			var draftLoaded = false;
			try {
				var rawDraft = localStorage.getItem(getTrackEditDraftStorageKey(pathId));
				if (rawDraft) {
					var parsedDraft = JSON.parse(rawDraft);
					if (Array.isArray(parsedDraft)) {
						trackEditDraftPoints = parsedDraft.map(cloneEditPoint);
						draftLoaded = true;
					}
				}
			} catch (e) {}

			if (!draftLoaded) {
				trackEditDraftPoints = trackEditOriginalPoints.map(cloneEditPoint);
				trackEditDraftDirty = false;
				clearTrackEditDraft(pathId);
			} else {
				trackEditDraftDirty = true;
			}

			applyDraftToOverlay();
			updateTrackEditButtonsState();
		}, function(err) {
			trackEditOriginalPoints = [];
			trackEditDraftPoints = [];
			trackEditDraftDirty = false;
			applyDraftToOverlay();
			updateTrackEditButtonsState();
			alert('Muokkauspisteiden lataus epäonnistui: ' + extractApiErrorMessage(err, 'Tuntematon virhe'));
		});
	}

	function getSelectedTrackPathId() {
		var pathId = $("#path option:selected").data("path-id");
		return (typeof pathId !== 'undefined' && pathId !== null && pathId !== '') ? Number(pathId) : null;
	}

	function getDistanceSquared(aLat, aLon, bLat, bLon) {
		var dLat = Number(aLat) - Number(bLat);
		var dLon = Number(aLon) - Number(bLon);
		return dLat * dLat + dLon * dLon;
	}

	function requestManualPoints(pathId, onDone, onError) {
		if (!pathId) {
			if (typeof onError === 'function') onError();
			return;
		}

		corsRequest(
			apiUrl + '/path/manual-points/' + String(pathId),
			'GET',
			headers,
			'',
			function(respText) {
				try {
					var parsed = JSON.parse(respText);
					if (typeof onDone === 'function') onDone(parsed);
				} catch (e) {
					if (typeof onError === 'function') onError(e);
				}
			},
			function(err) {
				if (typeof onError === 'function') onError(err);
			}
		);
	}

	function requestEditablePoints(pathId, onDone, onError) {
		if (!pathId) {
			if (typeof onError === 'function') onError();
			return;
		}

		corsRequest(
			apiUrl + '/path/edit-points/' + String(pathId),
			'GET',
			headers,
			'',
			function(respText) {
				try {
					var parsed = JSON.parse(respText);
					if (typeof onDone === 'function') onDone(parsed);
				} catch (e) {
					if (typeof onError === 'function') onError(e);
				}
			},
			function(err) {
				if (typeof onError === 'function') onError(err);
			}
		);
	}

	function refreshEditablePointsOverlay(pathId) {
		loadTrackEditSessionForPath(pathId);
	}

	function setTrackPointEditMode(enabled) {
		if (typeof trackerUserAuthenticated === 'undefined' || trackerUserAuthenticated !== true) return;

		trackPointEditModeEnabled = !!enabled;
		var $btn = $("#track-editmode-toggle");
		if ($btn.length) {
			$btn.toggleClass('active', trackPointEditModeEnabled);
			$btn.text(trackPointEditModeEnabled ? 'Muokkaustila: päällä' : 'Aloita muokkaus');
		}
		setTrackEditButtonsVisible(trackPointEditModeEnabled);

		if (window.navionics && typeof window.navionics.setTrackEditMode === 'function') {
			window.navionics.setTrackEditMode(trackPointEditModeEnabled, {
				onAdd: function(lon, lat) {
				if (!trackPointEditModeEnabled) return;

				var pathId = getSelectedTrackPathId();
				if (!pathId) {
					alert('Valitse ensin reitti listasta.');
					return;
				}

				var selected = $("#path option:selected");
				if (String(selected.data('group')) === '1') {
					alert('Koontimatkaan ei voi lisätä manuaalipisteitä. Valitse yksittäinen reitti.');
					return;
				}

				var insertIndex = getInsertIndexForDraftPoint(lon, lat);
				trackEditDraftPoints.splice(insertIndex, 0, {
					Id: trackEditTempPointId--,
					Source: 'manual',
					Timestamp: null,
					Lat: Number(lat),
					Lon: Number(lon),
					PathInfo: null,
					IsNew: true
				});
				setTrackEditDirty(pathId, true);
			},
				onRemove: function(lon, lat) {
					if (!trackPointEditModeEnabled) return;

					var pathId = getSelectedTrackPathId();
					if (!pathId) {
						alert('Valitse ensin reitti listasta.');
						return;
					}

					var selected = $("#path option:selected");
					if (String(selected.data('group')) === '1') {
						alert('Koontimatkaan ei voi lisätä tai poistaa manuaalipisteitä. Valitse yksittäinen reitti.');
						return;
					}

					var nearest = findNearestDraftManualPoint(lon, lat);
					if (!nearest) {
						alert('Poistettavaa manuaalipistettä ei löytynyt.');
						return;
					}

					trackEditDraftPoints = trackEditDraftPoints.filter(function(p) {
						return !(String(p.Source) === 'manual' && Number(p.Id) === Number(nearest.Id));
					});
					setTrackEditDirty(pathId, true);
				},
				onMove: function(point) {
					if (!trackPointEditModeEnabled || !point || !point.Id || !point.Source) return;

					var pathId = getSelectedTrackPathId();
					if (!pathId) return;
					for (var i = 0; i < trackEditDraftPoints.length; i++) {
						var p = trackEditDraftPoints[i];
						if (Number(p.Id) === Number(point.Id) && String(p.Source) === String(point.Source)) {
							p.Lat = Number(point.Lat);
							p.Lon = Number(point.Lon);
							setTrackEditDirty(pathId, true);
							break;
						}
					}
				}
			});
		}

		if (trackPointEditModeEnabled) {
			loadTrackEditSessionForPath(getSelectedTrackPathId());
		} else if (window.navionics && typeof window.navionics.setTrackEditPoints === 'function') {
			window.navionics.setTrackEditPoints([]);
		}

		updateTrackEditButtonsState();
	}

	function saveTrackEditDraftChanges() {
		var pathId = getSelectedTrackPathId();
		if (!pathId || !trackPointEditModeEnabled) return;
		if (!trackEditDraftDirty) return;

		var postHeaders = $.extend({}, headers, {
			"X-Api-Pathid": String(pathId)
		});

		var originalMap = {};
		for (var i = 0; i < trackEditOriginalPoints.length; i++) {
			var op = trackEditOriginalPoints[i];
			originalMap[String(op.Source) + ':' + String(op.Id)] = op;
		}

		var draftMap = {};
		for (var j = 0; j < trackEditDraftPoints.length; j++) {
			var dp = trackEditDraftPoints[j];
			draftMap[String(dp.Source) + ':' + String(dp.Id)] = dp;
		}

		var requests = [];
		var manualPointsChanged = draftManualPointsChanged();
		var draftManualSavePoints = manualPointsChanged ? getDraftManualSavePoints() : [];

		/* Manuaalipisteet rakennetaan tarvittaessa uudelleen tarkasti luonnoksen jarjestyksessa. */
		if (manualPointsChanged) {
			for (var k = 0; k < trackEditOriginalPoints.length; k++) {
				var oldPoint = trackEditOriginalPoints[k];
				if (String(oldPoint.Source) !== 'manual') continue;
				requests.push(function(pointId) {
					return function() {
						return apiRequest(apiUrl + '/path/manual-point-delete', 'POST', postHeaders, JSON.stringify({Id: Number(pointId), Path_Id: Number(pathId)}));
					};
				}(oldPoint.Id));
			}
		}

		/* Arkistopisteiden siirrot */
		for (var m = 0; m < trackEditDraftPoints.length; m++) {
			var newPoint = trackEditDraftPoints[m];
			if (String(newPoint.Source) === 'manual') {
				continue;
			}

			var key = String(newPoint.Source) + ':' + String(newPoint.Id);
			var old = originalMap[key];
			if (!old) continue;

			if (Number(old.Lat) !== Number(newPoint.Lat) || Number(old.Lon) !== Number(newPoint.Lon)) {
				requests.push(function(point) {
					return function() {
						return apiRequest(apiUrl + '/path/edit-point', 'POST', postHeaders, JSON.stringify({
							Path_Id: Number(pathId),
							Id: Number(point.Id),
							Source: String(point.Source),
							Lat: Number(point.Lat),
							Lon: Number(point.Lon)
						}));
					};
				}(newPoint));
			}
		}

		if (requests.length === 0) {
			trackEditDraftDirty = false;
			clearTrackEditDraft(pathId);
			updateTrackEditButtonsState();
			return;
		}

		Show('c-loading');
		$("#track-edit-save").prop('disabled', true);

		var chain = Promise.resolve();
		for (var r = 0; r < requests.length; r++) {
			chain = chain.then(requests[r]);
		}

		chain.then(function() {
			clearTrackEditDraft(pathId);
			trackEditDraftDirty = false;
			refreshApiData(initLog, function() {
				Hide('c-loading');
				loadTrackEditSessionForPath(pathId);
			});
		}).catch(function(err) {
			Hide('c-loading');

		if (manualPointsChanged) {
			for (var n = 0; n < draftManualSavePoints.length; n++) {
				requests.push(function(point) {
					return function() {
						return apiRequest(apiUrl + '/path/manual-point', 'POST', postHeaders, JSON.stringify({
							Path_Id: Number(pathId),
							Lat: Number(point.Lat),
							Lon: Number(point.Lon),
							Timestamp: point.Timestamp
						}));
					};
				}(draftManualSavePoints[n]));
			}
		}
			alert('Tallennus epäonnistui: ' + extractApiErrorMessage(err, 'Tuntematon virhe'));
			updateTrackEditButtonsState();
		});
	}

	function cancelTrackEditDraftChanges() {
		var pathId = getSelectedTrackPathId();
		if (!pathId) return;
		clearTrackEditDraft(pathId);
		trackEditDraftPoints = trackEditOriginalPoints.map(cloneEditPoint);
		trackEditDraftDirty = false;
		applyDraftToOverlay();
		updateTrackEditButtonsState();
	}

	function getNextTrackPathUrl(currentUrl) {
		if (!currentUrl) return null;

		var $current = $paths.find('option').filter(function() {
			return $(this).val() === currentUrl;
		}).first();
		if ($current.length === 0) return null;

		var $next = $current.nextAll('option').filter(function() {
			var val = $(this).val();
			return !this.disabled && val && val !== 'live-position';
		}).first();

		return $next.length ? $next.val() : null;
	}

	function openEngineHoursInputForCurrentPath() {
		$("#track-enginehours-edit").trigger("click");
		$("#track-enginehours-input").focus();
		$("#track-enginehours-input").select();
	}
	
	/* Haetaan tiedot rajapinnasta */
	function refreshApiData(initCallback, onDone, onError) {
		
		callback = typeof initCallback !== 'undefined' ? initCallback : callback;
		var method = "GET";
		var data = "";
		var wrappedCallback = function(resp) {
			if (typeof callback === 'function') callback(resp);
			if (typeof onDone === 'function') onDone(resp);
		};
		var errback = function(err) {
			if (typeof onError === 'function') onError(err);
		};
		var xhr = corsRequest(url, method, headers, data, wrappedCallback, errback);
		
	}
	
	/* Tarkistetaan onko LIVE!-sijainti saatavilla */
	function checkLiveStatus(statusUri) {
		
		clearInterval(statusIntervalId);
		
		/* Ajetaanko ensimmäistä kertaa? */
		if (livePositionEnabled == 'unset') {
			
			/* LIVE!-sijainti aktiivinen */
			if (corsResponse['live'][0].Live == 1) {
				setLiveStatus('enabled');
			}
			/* Ei LIVE!-sijaintia */
			else {
				setLiveStatus('disabled');
				
				/* Päivitetään LIVE!-sijainnin status tietyllä intervallilla */
				statusIntervalId = setInterval( function (){
					
					$.getJSON(statusUri, function( data ) {
						
						/* Enabloidaan LIVE!-sijainti käyttöliittymästä */
						if (data[0].Live == 1) {
							setLiveStatus('enabled');
							return false;
						}
						/* Jollei LIVE!-tietoa ole saatavilla, näytetään loki */
						else {
							setLiveStatus('disabled');
						}
						
					});
					
				}, (refreshInterval * 1000));
				
			}
			
		}
		else {
		
			/* LIVE!-sijainti aktiivinen */
			if (livePositionEnabled == true) {
				setLiveStatus('enabled');
			}
			/* Ei LIVE!-sijaintia */
			else {
				setLiveStatus('disabled');								
			}
			
				
			/* Päivitetään LIVE!-sijainnin status tietyllä intervallilla */
			statusIntervalId = setInterval( function (){
				
				$.getJSON(statusUri, function( data ) {
					
					/* Enabloidaan LIVE!-sijainti käyttöliittymästä */
					if (data[0].Live == 1) {
						setLiveStatus('enabled');
						return false;
					}
					/* Jollei LIVE!-tietoa ole saatavilla, näytetään loki */
					else {
						setLiveStatus('disabled');								
					}
					
				});
				
			}, (refreshInterval * 1000));
			
		}
		
	}
	
	
	/* Asetetaan LIVE!-sijainnin status ja näkyvyys */
	function setLiveStatus(enabled) {
		
		/* Enabloidaan LIVE!-sijainti käyttöliittymästä */
		if (enabled == 'enabled') {
			livePositionEnabled = true
			$('#live').attr('data-callback', 'initLivePosition');	
			$('#live').removeAttr('data-disabled');
			$('#live').removeClass('live-passive').addClass('live');
			clearInterval(statusIntervalId);
		}
		/* Jollei LIVE!-tietoa ole saatavilla, näytetään loki */
		else {
			livePosition = false;
			livePositionEnabled = false;
			$('#live').removeAttr('data-callback');	
			$('#live').attr('data-disabled', 'true');
			$('#live').removeClass('live').addClass('live-passive');
		}
		
	}
	
	
	/* Näytetään LIVE!-sijainti */
	function initLivePosition() {
		
		/* Jollei LIVE!-tietoa ole saatavilla, näytetään loki */
		if (corsResponse['live'][0].Live == 0 && (livePositionEnabled == false || livePositionEnabled == 'unset')) {
			
			/* Tarkistetaan LIVE!-sijainnin status tietyllä intervallilla */
			checkLiveStatus(corsResponse['live'][0].UrlInfo);
			
			/* Disabloidaan LIVE!-sijainti käyttöliittymästä */
			livePosition = false;
			initLog(corsResponse, true);
			
			return false;
			
		}
		
		/* Parsitaan JSON */
		uri = corsResponse['live'][0].UrlLast;
		uriInfo = corsResponse['live'][0].UrlInfo;
		
		/* Piilotetaan vasemman reunan valikko */
		updateMenu('live', 'sijainti', null);
		slideLeft.close();
		
		/* Päivitetään sijainti kartalle */		
		$.getJSON(uriInfo, function( data ) {
						
			/* Keskitetään kartta */
			navionics.setSafeCenter(data[0].Lon,data[0].Lat);
			navionics.setZoom(11);
			
			/* Asetetaan päivityssykli */
			timer(refreshInterval);		
			
			/* Näytetään seuravaan päivityksen ajankohta */
			$("#position-info").html('<p>' + data[0].Info + ' <span id="position-next-refresh"></span> <a id="stop-refresh" href="#" onclick="stopRefresh(initLog)">Lopeta päivitys.</a></p>');
			$("#c-menu--slide-bottom").css( "display", "block" );
			$("#c-mask").css( "display", "none" );	
			slideBottom.open();

			/* Ladataan kml */
			viewKml(uri, false);
		});
		
		/* Päivitetään sijainti tietyllä intervallilla */
		refreshIntervalId = setInterval( function (){
			
			$.getJSON(uriInfo, function( data ) {
				
				/* Nollataan ja asetetaan uusi laskuri */
				clearInterval(intTimer);
				timer(refreshInterval);
				
				/* Tarkistetaan LIVE!-sijainnin status */
				if (data[0].Live == false) {
					livePositionEnabled = false;
					stopRefresh(initLog);
					return false;
				}
				
				/* Näytetään seuravaan päivityksen ajankohta */
				$("#position-info").html('<p>' + data[0].Info + ' <span id="position-next-refresh"></span> <a id="stop-refresh" href="#" onclick="stopRefresh(initLog)">Lopeta päivitys.</a></p>');
				
				/* Keskitetään kartta */
				navionics.setSafeCenter(data[0].Lon,data[0].Lat);
				
				/* Ladataan kml */
				viewKml(uri, false);
			});
			
		}, (refreshInterval * 1000));
		
	};


	/* Lopetetaan sijainnin päivittäminen */
	function stopRefresh(initCallback) {
		
		/* Nollataan laskurit ja piilotetaan alareunan valikko */
		clearInterval(intTimer);
		clearInterval(refreshIntervalId);
		clearInterval(statusIntervalId);
		
		slideBottom.close();
		livePosition = false;
		
		/* Tarkistetaan onko LIVE!-sijainti käytettävissä */
		if (corsResponse && corsResponse['live'] && corsResponse['live'][0]) { checkLiveStatus(corsResponse['live'][0].UrlInfo); }
		
		/* Enabloidaan maski */
		$("#c-mask").css( "display", "inline" );
		
		/* Ajetaan funktio, jos sellainen on annettu */
		if (typeof initCallback !== 'undefined') {
			
			initCallback(null, true);
			/* Päivitetään menun sisältö */
			updateMenu('track', 'Loki', null);
			
		}
		
	}


	
	/* Näytetään reitti karttapohjan päällä */
	function initLog(resp, force) {

		/* navionics.hideBalloons(); */
		
		resp = typeof resp !== 'undefined' ? resp : corsResponse;	
		force = typeof force !== 'undefined' ? force : false;
		
		if (corsResponse['paths']) {
			
			var last = corsResponse['paths'].length - 1;
			var strStartDate_Prev = "";
			var lastVisible;
			var matchedActivePath = false;
			
			/* Listataan reitit pudostusvalikkoon */
			$paths.find("option").remove();  	
			$.each(corsResponse['paths'], function(index, item) { 
						
				if (item.Visible == 1 && item.Ready == 1) {
					
					/* Valikon välitosikko */
					var strStartDate = $.format.date(item.Start, "yyyy - M");
					if (strStartDate_Prev != strStartDate) {
						$paths.append("<option value=\"\" disabled=disabled>" + strStartDate + "</option>");
						strStartDate_Prev = strStartDate;
					}		
					
					/* Lisätään matka valikkoon */
					$paths.append("<option value=\"" + item.Url + "\" data-sticky-link=\"" + item.NameUrl + "\" data-path-id=\"" + item.Id + "\" data-group=\"" + item.Group + "\">&nbsp;" + item.Name + "</option>"); 
					lastVisible = index;
					
					/* Onko reitti annettu urlissa? */
					if (item.NameUrl == activePath) {
						$paths.val(item.Url);
						matchedActivePath = true;
						updateTrackInfo(item);	
						/* Päivitetään URL */
						window.history.pushState("object or string", siteHeader, "/"+siteFolder+"/"+item.NameUrl);
						$("#c-mask").css( "display", "none" );	
					}
					
				}

				/* Oletuksena näytetään viimeinen matka (ellei reittiä ole annettu urlissa) */
				if (index == last && activePath == '' && item.Visible == 1 && item.Ready == 1) {
					
					$paths.val(corsResponse['paths'][lastVisible].Url);
					updateTrackInfo(corsResponse['paths'][lastVisible]);					

					if (corsResponse['paths'][last].Ready == '0') {
						$paths.append("<option value=\"live-position\">-- LIVE!-sijainti --</option>");
						window.history.pushState("object or string", siteHeader, "/"+siteFolder+"/sijainti-nyt");
					}
					else { 
						window.history.pushState("object or string", siteHeader, "/"+siteFolder+"/"+corsResponse['paths'][lastVisible].NameUrl);
					}

				}
			
			});

			/* Jos URL:ssa annettua reittiä ei löydy, fallback viimeisimpään näkyvään */
			if (activePath !== '' && matchedActivePath === false && typeof lastVisible !== 'undefined') {
				$paths.val(corsResponse['paths'][lastVisible].Url);
				updateTrackInfo(corsResponse['paths'][lastVisible]);
				activePath = corsResponse['paths'][lastVisible].NameUrl;
				window.history.pushState("object or string", siteHeader, "/"+siteFolder+"/"+activePath);
			}
			
			/* Valitun reitin url */
			uri = $paths.val();
			if (!uri && typeof lastVisible !== 'undefined') {
				uri = corsResponse['paths'][lastVisible].Url;
				$paths.val(uri);
			}
			if (!uri) {
				var firstValid = $paths.find('option[value!=""]').first().val();
				if (firstValid) {
					uri = firstValid;
					$paths.val(uri);
				}
			}
			updateRegenButtonLabel();
			
			/* Päivitetään menun näkyvyys */
			updateMenu('track', 'Loki', null);
			
			/* Tarkistetaan LIVE!-sijainnin status tietyllä intervallilla */
			if (corsResponse['live'][0]) { checkLiveStatus(corsResponse['live'][0].UrlInfo); }

			if (corsResponse['paths'][last].Ready == '0' && force == false || livePosition == true) {
				/* Näytetään LIVE!-sijainti, jos viimeinen reitti on kesken (ellei näytetä pakotetusti jotain muuta) */
				/* $("#c-button--slide-left").css( "display", "none" ); */
				initLivePosition();
			}
			else {
				/* Näytetään oletuksena viimeisin reitti */	
				$("#c-menu--slide-bottom").css( "display", "none" );
				if (uri) viewKml(uri, true);
			}
		
		}
		else {
			/* Disabloidaan loki-linkki, jollei kuljettuja reittejä ole olemassa */
			$('#track').removeClass('track').addClass('track-passive');
			$('#track').attr('data-disabled', 'true');
			/* Näytetään oletuksena paikat, jollei reittejä löydy */
			initPlaces();
			/* Piilotetaan latausikkuna, kun reitti on ladattu */
			Hide('c-loading');
		}
		
		/* Näytetään vasemman reunan valikko 
		slideLeft.open();
		*/
		
	};
		


	/* Näytetään satamat */
	function initPlaces() {
		
		navionics.hideBalloons();
		
		var last = corsResponse['places'].length - 1;
		
		/* Listataan reitit pudostusvalikkoon */
		$places.find("option").remove();  	
		$.each(corsResponse['places'], function(index, item) { 
									
			/* Lisätään satama valikkoon */
			$places.append("<option value=\"" + item.Url + "\" data-group=\"" + item.Group + "\">" + item.Name + "</option>"); 
			
			/* Onko satama annettu urlissa? */
			if (item.NameUrl == activePlace) {
				$places.val(item.Url);
				updatePlaceInfo(item);	
				navionics.setSafeCenter(item.Lon,item.Lat);
				navionics.setZoom(10);
				$places.attr('data-group', item.Group);
				$("#places-name-edit").show();

				/* Päivitetään URL */
				window.history.pushState("object or string", siteHeader, "/"+siteFolder+"/satamat/"+item.NameUrl);
				$("#c-mask").css( "display", "none" );									
			}
				
		});
		
		/* Oletuksena näytetään ensimmäinen satama (ellei satamaa ole annettu urlissa) */
		if (activePlace == '') {
			$places.val(corsResponse['places'][0].Url);
			updatePlaceInfo(corsResponse['places'][0]);
			activePlace = corsResponse['places'][0].NameUrl;
			/* Päivitetään URL */
			window.history.pushState("object or string", siteHeader, "/"+siteFolder+"/satamat/"+activePlace);
		}			
				
		/* Päivitetään menun näkyvyys */
		updateMenu('places', 'Satamat', null);
		
		/* Tarkistetaan LIVE!-sijainnin status tietyllä intervallilla */
		if (corsResponse['live'][0]) { checkLiveStatus(corsResponse['live'][0].UrlInfo); }

		/* Näytetään satama kartalla */
		viewKml($places.val(), $places.find(':selected').attr('data-group'));
		
		/* Näytetään vasemman reunan valikko */
		slideLeft.open();
		
	};


	
	/* Näytetään tapahtumat */
	function initEvents() {
		
		navionics.hideBalloons();
		
		var last = corsResponse['events'].length - 1;
		var strStartDate_Prev = "";

		/* Listataan tapahtumat pudostusvalikkoon */
		$events.find("option").remove();  	
		$.each(corsResponse['events'], function(index, item) { 

			/* Piilotetaan moottorituntitapahtumat */
			if (item.Type === 'EngineHours') return;

			/* Valikon välitosikko */
			var strStartDate = $.format.date(item.Timestamp, "yyyy - M");
			if (strStartDate_Prev != strStartDate) {
				$events.append("<option value=\"\" disabled=disabled>" + strStartDate + "</option>");
				strStartDate_Prev = strStartDate;
			}		

			/* Lisätään tapahtuma valikkoon */
			$events.append("<option value=\"" + item.Url + "\" data-name-url=\"" + item.NameUrl + "\">" + item.Name + "</option>"); 
						
			/* Onko satama annettu urlissa? */
			if (item.NameUrl == activeEvent) {
				$events.val(item.Url);
				updateEventsInfo(item);	
				navionics.setSafeCenter(item.Lon,item.Lat);
				navionics.setZoom(10);
				//$events.attr('data-group', item.Group);
				//$("#places-name-edit").show();

				/* Päivitetään URL */
				window.history.pushState("object or string", siteHeader, "/"+siteFolder+"/tapahtumat/"+item.NameUrl);
				$("#c-mask").css( "display", "none" );									
			}
				
		});
		
		/* Oletuksena näytetään viimeisin tapahtuma (ellei tapahtumaa ole annettu urlissa) */
		if (activeEvent == '') {
			$events.val(corsResponse['events'][last].Url);
			updateEventsInfo(corsResponse['events'][last]);
			activeEvent = corsResponse['events'][last].NameUrl;
			/* Päivitetään URL */
			window.history.pushState("object or string", siteHeader, "/"+siteFolder+"/tapahtumat/"+activeEvent);

		}			
				
		/* Päivitetään menun näkyvyys */
		updateMenu('events', 'Tapahtumat', null);
		
		/* Tarkistetaan LIVE!-sijainnin status tietyllä intervallilla */
		if (corsResponse['live'][0]) { checkLiveStatus(corsResponse['live'][0].UrlInfo); }

		/* Näytetään satama kartalla */
		viewKml($events.val(), $events.find(':selected').attr('data-group'));
		
		/* Näytetään vasemman reunan valikko */
		slideLeft.open();
		
	};	
	



	/* LOKI: Päivitetään vasemman reunan valikon sisältö */
	function updateTrackInfo(data) {
				
		var strDate = $.format.date(data.Start, "d.M.yyyy");
		var strStart = $.format.date(data.Start, "H:mm");
		var strEnd = $.format.date(data.End, "H:mm");

		$("#track-date").html("Pvm: "+strDate);		
		$("#track-time").html("Aika: "+strStart+"-"+strEnd);
		$("#track-duration").html("Kesto: "+data.Duration);
		$("#track-distance").html("Matka: "+data.Distance+" mailia");
		$("#track-speed").html("Keskinopeus: "+data.Speed_Avg+" solmua");
		$("#track-enginehours").html("Moottoritunnit: "+data.EngineHours+"<br />(yht. "+data.EngineHourMeter+")");
		$("#track-enginehours").data("hour-meter", data.EngineHourMeter);
		$("#track-sticky-link").html("Pysyvä linkki: <a href=\"https://www.rantojenmies.com/"+siteFolder+"/"+data.NameUrl+"\" style=\"font-size:60%;letter-spacing:normal;font-weight:normal;word-wrap:break-word;color:#dcdcdc\" target=\"_blank\">https://www.rantojenmies.com/"+siteFolder+"/"+data.NameUrl+"</a>");
	}

	function updateRegenButtonLabel() {
		var $btn = $("#track-groupkml-regen");
		if ($btn.length === 0) return;
		var selected = $("#path option:selected");
		var isGroup = String(selected.data("group")) === "1";
		$btn.text(isGroup ? "Generoi koontimatka uudelleen" : "Generoi reitti uudelleen");
	}

	

	/* SATAMAT: Päivitetään vasemman reunan valikon sisältö */
	function updatePlaceInfo(data) {
		
		if (data.Group == 0) {

			$('.desc, .visited, .visited-paths').show();
			let desc = data.Description != '' ? data.Description : '(kohteen kuvaus puuttuu)';

			$("#places-desc").css('display', 'block')
			$("#places-desc").html(desc);
			if(document.getElementById('places-desc-input') !== null) $("#places-desc-input").val(data.Description);
			if(document.getElementById('places-name-input') !== null) $("#places-name-input").val(data.Name);
			
			$("#places-visited").css('display', 'block')
			$("#places-visited").html("Ed. käynti: "+data.LastVisited);
			
			$("#places-visited-paths").css('display', 'block')
			var visitedList = data.Visited.split('|');
			var visitedHtml = '';
			$.each(visitedList, function(index, item) { 
				visitedHtml += '<a onClick="activePath=\''+item+'\'; initLog();" href="#" style=\"font-size:60%;letter-spacing:normal;font-weight:normal;word-wrap:break-word;color:#dcdcdc\">'+item+'</a><br />';	
			});

			$("#places-visited-paths").html("Aiemmat käynnit:<br />"+visitedHtml);
			
		}
		else {
			$('.desc, .visited, .visited-paths').hide();
		}
	
		$("#places-sticky-link").html("Pysyvä linkki: <a href=\"https://www.rantojenmies.com/"+siteFolder+"/satamat/"+data.NameUrl+"\" style=\"font-size:60%;letter-spacing:normal;font-weight:normal;word-wrap:break-word;color:#dcdcdc\" target=\"_blank\">https://www.rantojenmies.com/"+siteFolder+"/satamat/"+data.NameUrl+"</a>");

	}	


	/* TAPAHTUMAT: Päivitetään vasemman reunan valikon sisältö */
	function updateEventsInfo(data) {
				
		var strDate = $.format.date(data.Timestamp, "d.M.yyyy");
		var strTime = $.format.date(data.Timestamp, "H:mm");

		let unit = data.Type == 'Fuel' ? ' litraa' : ' kg';

		$("#events-datetime").html("Ajankohta:<br />"+strDate+' klo '+strTime);		
		$("#events-desc").html(data.Description);
		$("#events-amount").html("Määrä: "+data.Amount+unit);
		$("#events-price").html("Hinta: "+data.Price+" €");
		if (data.Type == 'Fuel') {
			$("#events-enginehours").html("Moottorin tunnit: "+data.EngineHourMeter);
			$("#events-enginehours").show();
		}
		else {
			$("#events-enginehours").hide();
		}

	}	


	/* Vaihdetaan reittiä */
	$("#path").on("change", function() {
		
		var selected = $(this).val();
		activePath = selected;
		updateRegenButtonLabel();
		
		/* Näytetään viimeisin sijainti */
		if (selected == "live-position") {
			initLivePosition();
			slideLeft.close();			
		}
		else {
			
			/* Lopetetaan LIVE!-sijainnin näyttäminen */
			stopRefresh();
			/* Näytetään reitti */
			viewKml($(this).val(), true);
			/* Päivitetään reitin tiedot valikkon */
			if (corsResponse && corsResponse['paths']) {
				$.each(corsResponse['paths'], function(index, item) { 
					if (item.Url == selected) {
						updateTrackInfo(item);	
						activePath = item.NameUrl;
						/* Päivitetään URL */
						window.history.pushState("object or string", siteHeader, "/"+siteFolder+"/"+item.NameUrl);
					}
				});
			}
			
		}

		if (trackPointEditModeEnabled) {
			refreshEditablePointsOverlay(getSelectedTrackPathId());
		}
			  
	});


	/* Vaihdetaan satamaa */
	$("#place").on("change", function() {
		
		var selected = $(this).val();
		activePlace = null;
		$("#places-desc-input").hide();
		$("#places-desc-edit").show();

		/* Lopetetaan LIVE!-sijainnin näyttäminen */
		stopRefresh();
			
		/* Päivitetään reitin tiedot valikkon */
		$.each(corsResponse['places'], function(index, item) { 
		
			/* Päivitetään valitun sataman tiedot valikkoon */
			if (item.Url == selected) {
				
				updatePlaceInfo(item);
				/* Keskitetään kartta */
				navionics.setSafeCenter(item.Lon,item.Lat);
				navionics.setZoom(10);
				$("#place").attr('data-group', item.Group);
				activePlace = item.NameUrl;
				$("#places-name-edit").show();

				/* Päivitetään URL */
				window.history.pushState("object or string", siteHeader, "/"+siteFolder+"/satamat/"+item.NameUrl);
			}
			
		});
		
		/* Näytetään satama kartalla */
		viewKml($(this).val(), $("#place").attr('data-group'));
	
	});
	

	/* Vaihdetaan tapahtumaa */
	$("#events").on("change", function() {
		
		var selected = $(this).val();
		activeEvent = null;
		//$("#places-desc-input").hide();
		//$("#places-desc-edit").show();

		/* Lopetetaan LIVE!-sijainnin näyttäminen */
		stopRefresh();
			
		/* Päivitetään reitin tiedot valikkon */
		$.each(corsResponse['events'], function(index, item) { 
		
			/* Päivitetään valitun sataman tiedot valikkoon */
			if (item.Url == selected) {
				
				updateEventsInfo(item);
				/* Keskitetään kartta */
				navionics.setSafeCenter(item.Lon,item.Lat);
				navionics.setZoom(10);
				$("#events").attr('data-group', item.Group);
				activeEvent = item.NameUrl;
				//$("#places-name-edit").show();

				/* Päivitetään URL */
				window.history.pushState("object or string", siteHeader, "/"+siteFolder+"/tapahtumat/"+item.NameUrl);
			}
			
		});
		
		/* Näytetään satama kartalla */
		viewKml($(this).val(), $("#place").attr('data-group'));
	
	});	


	/* Tallennetaan sataman nimi */
	$("#places-name-save").on("click", function() {
		
		/* Haetaan reittitiedosto */
		var method = "POST";
		var url = apiUrl+'/place/'+activePlace;
		var obj = {"name": $("#places-name-input").val(), "description": $("#places-desc-input").val()}
		var data = JSON.stringify(obj);
		var xhr = corsRequest(url, method, headers, data);
		
		/* Päivitetään tiedot */
		refreshApiData(initPlaces);
		$("#places-places").show();
		$("#places-name-edit").show();
		$("#places-name-input").hide();
		$("#places-name-save").hide();
		$("#places-name-cancel").hide();

	});	

	/* Tallennetaan sataman kuvaus */
	$("#places-desc-save").on("click",  function() {
		
		/* Haetaan reittitiedosto */
		var method = "POST";
		var url = apiUrl+'/place/'+activePlace;
		var obj = {"name": $("#places-name-input").val(), "description": $("#places-desc-input").val()}
		var data = JSON.stringify(obj);
		var xhr = corsRequest(url, method, headers, data);
		
		/* Päivitetään tiedot */
		refreshApiData(initPlaces);
		$("#places-desc").show();
		$("#places-desc-edit").show();
		$("#places-desc-input").hide();
		$("#places-desc-save").hide();
		$("#places-desc-cancel").hide();
	  
	});		

	

	/* Tallennetaan moottoritunnit Enter-näppäimellä */
	$("#track-enginehours-input").on("keydown", function(e) {
		if (e.which === 13 || e.keyCode === 13) {
			e.preventDefault();
			advanceToNextPathAfterEngineHourSave = true;
			$("#track-enginehours-save").trigger("click");
		}
	});

	/* Tallennetaan matkan moottoritunnit */
	$("#track-enginehours-save").on("click", function() {
		
		/* Haetaan reittitiedosto */
		var method = "POST";
		var selectedPathUrl = $paths.val();
		var nextPathUrl = advanceToNextPathAfterEngineHourSave ? getNextTrackPathUrl(selectedPathUrl) : null;
		let path_id = $("#path option:selected").data("path-id");
		var url = apiUrl+'/path/'+path_id;
		var obj = { "EngineHourMeter": $("#track-enginehours-input").val() }
		var data = JSON.stringify(obj);
		var xhr = corsRequest(url, method, headers, data);
		
		
		/* Päivitetään tiedot */
		refreshApiData(initLog, function() {
			if (advanceToNextPathAfterEngineHourSave && nextPathUrl) {
				$paths.val(nextPathUrl);
				$paths.trigger("change");
				openEngineHoursInputForCurrentPath();
			}

			advanceToNextPathAfterEngineHourSave = false;
		});
		$("#track-enginehours").show();
		$("#track-enginehours-edit").show();
		$("#track-enginehours-input").hide();
		$("#track-enginehours-save").hide();
		$("#track-enginehours-cancel").hide();
	  
	});		

	/* Generoidaan valitun reitin KML uudelleen */
	$("#track-groupkml-regen").on("click", function() {

		var selected = $("#path option:selected");
		var pathId = selected.data("path-id") || $("#path option[value='" + $("#path").val() + "']").data("path-id");
		var isGroup = String(selected.data("group")) === "1";

		if (!pathId) {
			alert('Valitse ensin reitti listasta.');
			return;
		}

		Show('c-loading');

		var method = "POST";
		var url = apiUrl + (isGroup ? '/path/generate-group-kml' : '/path/generate-kml');
		var data = '';
		var postHeaders = $.extend({}, headers, {
			"X-Api-Pathid": String(pathId)
		});

		corsRequest(
			url,
			method,
			postHeaders,
			data,
			function() {
				refreshApiData(
					initLog,
					function() { Hide('c-loading'); },
					function() {
						Hide('c-loading');
						alert('Tietojen päivitys epäonnistui.');
					}
				);
			},
			function() {
				Hide('c-loading');
				alert('KML:n uudelleengenerointi epäonnistui.');
			}
		);

	});

	function saveTrackEditDraftChangesActive() {
		var pathId = getSelectedTrackPathId();
		if (!pathId || !trackPointEditModeEnabled) return;
		if (!trackEditDraftDirty) return;

		var postHeaders = $.extend({}, headers, {
			"X-Api-Pathid": String(pathId)
		});

		var originalMap = {};
		for (var i = 0; i < trackEditOriginalPoints.length; i++) {
			var originalPoint = trackEditOriginalPoints[i];
			originalMap[String(originalPoint.Source) + ':' + String(originalPoint.Id)] = originalPoint;
		}

		var requests = [];
		var manualPointsChanged = draftManualPointsChanged();
		var draftManualSavePoints = manualPointsChanged ? getDraftManualSavePoints() : [];

		if (manualPointsChanged) {
			for (var j = 0; j < trackEditOriginalPoints.length; j++) {
				var oldPoint = trackEditOriginalPoints[j];
				if (String(oldPoint.Source) !== 'manual') continue;
				requests.push(function(pointId) {
					return function() {
						return apiRequest(apiUrl + '/path/manual-point-delete', 'POST', postHeaders, JSON.stringify({
							Id: Number(pointId),
							Path_Id: Number(pathId)
						}));
					};
				}(oldPoint.Id));
			}
		}

		for (var k = 0; k < trackEditDraftPoints.length; k++) {
			var draftPoint = trackEditDraftPoints[k];
			if (String(draftPoint.Source) === 'manual') continue;

			var originalKey = String(draftPoint.Source) + ':' + String(draftPoint.Id);
			var originalArchivePoint = originalMap[originalKey];
			if (!originalArchivePoint) continue;

			if (Number(originalArchivePoint.Lat) !== Number(draftPoint.Lat) || Number(originalArchivePoint.Lon) !== Number(draftPoint.Lon)) {
				requests.push(function(point) {
					return function() {
						return apiRequest(apiUrl + '/path/edit-point', 'POST', postHeaders, JSON.stringify({
							Path_Id: Number(pathId),
							Id: Number(point.Id),
							Source: String(point.Source),
							Lat: Number(point.Lat),
							Lon: Number(point.Lon)
						}));
					};
				}(draftPoint));
			}
		}

		if (manualPointsChanged) {
			for (var m = 0; m < draftManualSavePoints.length; m++) {
				requests.push(function(point) {
					return function() {
						return apiRequest(apiUrl + '/path/manual-point', 'POST', postHeaders, JSON.stringify({
							Path_Id: Number(pathId),
							Lat: Number(point.Lat),
							Lon: Number(point.Lon),
							Timestamp: point.Timestamp
						}));
					};
				}(draftManualSavePoints[m]));
			}
		}

		if (requests.length === 0) {
			trackEditDraftDirty = false;
			clearTrackEditDraft(pathId);
			updateTrackEditButtonsState();
			return;
		}

		Show('c-loading');
		$("#track-edit-save").prop('disabled', true);

		var chain = Promise.resolve();
		for (var n = 0; n < requests.length; n++) {
			chain = chain.then(requests[n]);
		}

		chain.then(function() {
			clearTrackEditDraft(pathId);
			trackEditDraftDirty = false;
			refreshApiData(initLog, function() {
				Hide('c-loading');
				loadTrackEditSessionForPath(pathId);
			});
		}).catch(function(err) {
			Hide('c-loading');
			alert('Tallennus epäonnistui: ' + extractApiErrorMessage(err, 'Tuntematon virhe'));
			updateTrackEditButtonsState();
		});
	}

	/* Muokkaustilan kytkin manuaalipisteiden lisäykseen */
	$("#track-editmode-toggle").on("click", function() {
		setTrackPointEditMode(!trackPointEditModeEnabled);
	});

	$("#track-edit-save").on("click", function() {
		saveTrackEditDraftChangesActive();
	});

	$("#track-edit-cancel").on("click", function() {
		cancelTrackEditDraftChanges();
	});

	if (typeof trackerUserAuthenticated !== 'undefined' && trackerUserAuthenticated === true) {
		setTrackPointEditMode(false);
	}

	/* Generoidaan satamien koonti-KML uudelleen */
	$("#places-summarykml-regen").on("click", function() {

		Show('c-loading');

		var method = "POST";
		var url = apiUrl + '/place/generate-summary-kml';
		var data = '';

		corsRequest(
			url,
			method,
			headers,
			data,
			function() {
				refreshApiData(
					initPlaces,
					function() { Hide('c-loading'); },
					function() {
						Hide('c-loading');
						alert('Tietojen päivitys epäonnistui.');
					}
				);
			},
			function() {
				Hide('c-loading');
				alert('Satamien uudelleengenerointi epäonnistui.');
			}
		);

	});

	/* Muokataan kohteen nimeä */
	$("#places-name-edit").on("click", function() {
		
		$("#places-places").hide();
		$("#places-name-edit").hide();
		$("#places-name-save").show();
		$("#places-name-cancel").show();
		$("#places-name-input").show();
	  
	});	

	/* Muokataan kohteen nimeä (cancel) */
	$("#places-name-cancel").on("click", function() {
		
		$("#places-name-input").hide();
		$("#places-name-save").hide();
		$("#places-name-cancel").hide();
		$("#places-places").show();
		$("#places-name-edit").show();
	  
	});	
	

	/* Muokataan kohteen kuvausta */
	$("#places-desc-edit").on("click", function() {
		
		$("#places-desc").hide();
		$("#places-desc-edit").hide();
		$("#places-desc-save").show();
		$("#places-desc-cancel").show();
		$("#places-desc-input").show();

	  
	});

	/* Muokataan matkan moottoritunteja (cancel) */
	$("#places-desc-cancel").on("click", function() {
		
		$("#places-desc-input").hide();
		$("#places-desc-save").hide();
		$("#places-desc-cancel").hide();
		$("#places-desc").show();
		$("#places-desc-edit").show();
	  
	});	

	
	/* Muokataan matkan moottoritunteja */
	$("#track-enginehours-edit").on("click", function() {
		
		$("#track-enginehours").hide();
		$("#track-enginehours-edit").hide();
		$("#track-enginehours-save").show();
		$("#track-enginehours-cancel").show();
		$("#track-enginehours-input").val($("#track-enginehours").data("hour-meter"));
		$("#track-enginehours-input").show();
	  
	});	

	/* Muokataan matkan moottoritunteja (cancel) */
	$("#track-enginehours-cancel").on("click", function() {
		
		$("#track-enginehours-input").hide();
		$("#track-enginehours-save").hide();
		$("#track-enginehours-cancel").hide();
		$("#track-enginehours").show();
		$("#track-enginehours-edit").show();
	  
	});			
	