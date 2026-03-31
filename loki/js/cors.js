/**
 * Make a X-Domain request to url and callback.
 *
 * @param url {String}
 * @param method {String} HTTP verb ('GET', 'POST', 'DELETE', etc.)
 * @param data {String} request body
 * @param callback {Function} to callback on completion
 * @param errback {Function} to callback on error
 */
 
var corsResponse;
var corsResponse_POST;

function corsRequest(url, method, headers, data, callback, errback) {
	
    var req;
    
    if(XMLHttpRequest) {
        req = new XMLHttpRequest();

        if('withCredentials' in req) {
            req.open(method, url, true);
            req.withCredentials = true;
            req.onerror = errback;
            req.onreadystatechange = function() {
                if (req.readyState === 4) {
                    if (req.status >= 200 && req.status < 400) {
                        /* Parsitaan JSON */
                        try {
                            if (method == 'GET') corsResponse = JSON.parse(req.responseText);
                            else if (method == 'POST') corsResponse_POST = JSON.parse(req.responseText);
                        }
                        catch (parseError) {
                            if (typeof errback === 'function') {
                                errback(new Error('Invalid JSON response: ' + req.responseText));
                            }
                            return;
                        }
                        if (typeof(callback) == 'function') { callback(req.responseText); }
                    } else {
                        if (typeof errback === 'function') errback(new Error('Response returned with non-OK status'));
                    }
                }
            };
			if(headers){
				for(var key in headers){
					req.setRequestHeader(key,headers[key]);
				}
			}		
            req.send(data);
        }
    } else if(XDomainRequest) {
        req = new XDomainRequest();
        req.open(method, url);
        req.onerror = errback;
        req.onload = function() {
            /* Parsitaan JSON */
            try {
                if (method == 'GET') corsResponse = JSON.parse(req.responseText);
                else if (method == 'POST') corsResponse_POST = JSON.parse(req.responseText);
            }
            catch (parseError) {
                if (typeof errback === 'function') {
                    errback(new Error('Invalid JSON response: ' + req.responseText));
                }
                return;
            }
            if (typeof(callback) == 'function') { callback(req.responseText); }
        };
		if(headers){
			for(var key in headers){
				req.setRequestHeader(key,headers[key]);
			}
		}		
        req.send(data);
    } else {
        errback(new Error('CORS not supported'));
    }
	
};
